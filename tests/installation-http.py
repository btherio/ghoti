"""Exercise operator setup and registration approval on a disposable SQLite install."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.request


ROOT = Path(__file__).resolve().parents[1]
ADMIN_PASSWORD = 'first-admin-test-password'


def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def run_approval_flow(request, admin, admin_token):
    pending = {'accountApproval': 'pending'}
    password = 'pending-account-test-password'

    def rpc(browser, token, name, *args, expected=200):
        _, body = request(data={'__ghoti_async': 1, 'fn': name, 'args': args, 'token': token},
                          raw_json=True, browser=browser, expected=expected)
        response = json.loads(body)
        assert response['ok'] == (expected == 200), response
        return response.get('result')

    def register(browser, token, username):
        form = rpc(browser, token, 'printRegisterForm')
        challenge = re.search(r'What is (\d+) \+ (\d+)\?', form)
        answer = str(sum(map(int, challenge.groups())))
        return rpc(browser, token, 'addUser', username, username + '@example.test', password, answer)

    form = rpc(admin, admin_token, 'printSiteSettingsForm')
    assert re.search(r'id="set-allowRegister"[^\n]*\n[^\n]*id="set-adminVerifiesNewAccounts"[^\n]*Admin verifies new accounts', form)
    assert rpc(admin, admin_token, 'saveSiteSettings', {'allowRegister': 1, 'adminVerifiesNewAccounts': 1}) is True
    visitor = client()
    visitor_token = rpc_token(request(browser=visitor)[1])
    assert register(visitor, visitor_token, 'waiting') == pending
    assert rpc(visitor, visitor_token, 'login', 'waiting', 'wrong-password') is False
    assert rpc(visitor, visitor_token, 'login', 'waiting', password) == pending
    assert rpc(visitor, visitor_token, 'checkLogin') is False
    assert register(visitor, visitor_token, 'declined') == pending

    users = rpc(admin, admin_token, 'printManageUserForm')
    assert 'Pending approvals' in users and 'waiting@example.test' in users and 'declined@example.test' in users
    waiting_id = int(re.search(r'Pending account waiting".*?reviewUserRegistration\((\d+),', users, re.S)[1])
    declined_id = int(re.search(r'Pending account declined".*?reviewUserRegistration\((\d+),', users, re.S)[1])
    assert rpc(visitor, visitor_token, 'reviewUserRegistration', waiting_id, 'approve') == 'Admin access required.'
    assert 'Pending approvals' not in rpc(visitor, visitor_token, 'printManageUserForm')
    rpc(admin, 'incorrect-csrf-token', 'reviewUserRegistration', waiting_id, 'approve', expected=403)
    assert rpc(admin, admin_token, 'toggleAdmin', waiting_id) == 'Select an approved user account.'
    assert rpc(visitor, visitor_token, 'login', 'waiting', password) == pending

    assert rpc(admin, admin_token, 'reviewUserRegistration', waiting_id, 'approve') is True
    assert 'no longer awaiting approval' in rpc(admin, admin_token, 'reviewUserRegistration', waiting_id, 'decline')
    assert int(rpc(visitor, visitor_token, 'login', 'waiting', password)) == waiting_id
    assert rpc(visitor, visitor_token, 'isAdmin', waiting_id) is False
    assert rpc(visitor, visitor_token, 'reviewUserRegistration', declined_id, 'approve') == 'Admin access required.'

    assert rpc(admin, admin_token, 'saveSiteSettings', {'adminVerifiesNewAccounts': 0}) is True
    applicant = client()
    applicant_token = rpc_token(request(browser=applicant)[1])
    assert rpc(applicant, applicant_token, 'login', 'declined', password) == pending
    assert rpc(admin, admin_token, 'reviewUserRegistration', declined_id, 'decline') is True
    assert rpc(applicant, applicant_token, 'login', 'declined', password) is False
    assert register(applicant, applicant_token, 'instant') is True
    assert int(rpc(applicant, applicant_token, 'login', 'instant', password)) > 0
    assert 'Pending approvals' not in rpc(admin, admin_token, 'printManageUserForm')


def rpc_token(body):
    return re.search(r'var GHOTI_CSRF_TOKEN = "([a-f0-9]+)"', body)[1]


def copy_checkout(app):
    tracked = subprocess.check_output(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], cwd=ROOT)
    for name in filter(None, tracked.split(b'\0')):
        relative = Path(os.fsdecode(name))
        if not (ROOT / relative).is_file():
            continue
        target = app / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT / relative, target)


def main():
    drivers = subprocess.check_output(['php', '-r', 'echo implode(",", PDO::getAvailableDrivers());'], text=True)
    if 'sqlite' not in drivers.split(','):
        print('SKIP: pdo_sqlite is required for the HTTP installation tests')
        return
    with tempfile.TemporaryDirectory(prefix='ghoti-install-test-') as temp:
        root = Path(temp)
        app = root / 'ghoti'
        copy_checkout(app)
        sessions = root / 'sessions'
        sessions.mkdir()
        local_config = app / 'db.config.local.php'
        db_path = app / '.ghoti-data/ghoti.sqlite'
        env = {key: value for key, value in os.environ.items() if not key.startswith('GHOTI_DB_')}
        # The temporary database name keeps the pre-install request isolated.
        server_env = dict(env, GHOTI_DB_NAME='ghoti_install_test_unconfigured', GHOTI_DB_PASSWORD='no-install-test-account')
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 0))
            port = probe.getsockname()[1]
        url = f'http://127.0.0.1:{port}/'
        admin = client()

        def request(path='ghoti/index.php', data=None, browser=admin, expected=200, raw_json=False):
            headers = {'Content-Type': 'application/json'} if raw_json else {}
            payload = json.dumps(data).encode() if data is not None else None
            try:
                response = browser.open(urllib.request.Request(url + path, payload, headers), timeout=15)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                body = response.read().decode()
                assert response.code == expected, (path, response.code, expected, body[:600])
                return response.headers, body

        with (root / 'server.log').open('w+') as log:
            server = subprocess.Popen(['php', '-d', 'session.save_path=' + str(sessions), '-d', 'display_errors=0',
                                       '-S', f'127.0.0.1:{port}', '-t', str(root)], cwd=root,
                                      env=server_env, stdout=log, stderr=log)
            try:
                for _ in range(50):
                    try:
                        headers, body = request(expected=503)
                        break
                    except urllib.error.URLError:
                        if server.poll() is not None:
                            raise RuntimeError('PHP test server failed to start')
                        time.sleep(0.1)
                else:
                    raise RuntimeError('PHP test server failed to respond')
                assert headers['Retry-After'] == '60' and 'Set-Cookie' not in headers
                assert 'Database unavailable.' in body and not local_config.exists()
                for name in ('testDbConfig', 'saveDbConfig'):
                    _, body = request(data={'__ghoti_async': 1, 'fn': name, 'args': []}, expected=503, raw_json=True)
                    assert json.loads(body) == {'ok': False, 'error': 'Database unavailable.'}
                request('ghoti/bin/create-admin.php', expected=404)
                assert not local_config.exists() and not db_path.exists()

                commands = [['php', 'bin/create-admin.php', '--sqlite', name, name + '@example.test']
                            for name in ('admin', 'reserve')]
                results = []
                processes = [subprocess.Popen(command, cwd=app, env=env, text=True,
                                              stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                             for command in commands]
                try:
                    for process in processes:
                        process.stdin.write(ADMIN_PASSWORD + '\n')
                        process.stdin.flush()
                    for process in processes:
                        stdout, stderr = process.communicate(timeout=20)
                        results.append((process.returncode, stdout, stderr))
                finally:
                    for process in processes:
                        if process.poll() is None:
                            process.terminate()
                            process.wait(timeout=5)
                assert sorted(result[0] for result in results) == [0, 1], results
                assert any('already contains accounts' in result[2] for result in results), results
                assert local_config.stat().st_mode & 0o777 == 0o600
                with sqlite3.connect(db_path) as connection:
                    users = connection.execute('SELECT userName, admin FROM users').fetchall()
                    assert len(users) == 1 and users[0][1] == 1, users
                    assert connection.execute('SELECT title FROM pages').fetchall() == [('Home',)]
                assert not list(app.glob('.ghoti-config-*'))

                token = rpc_token(request()[1])
                _, body = request(data={'__ghoti_async': 1, 'fn': 'login', 'args': [users[0][0], ADMIN_PASSWORD],
                                        'token': token}, raw_json=True)
                assert int(json.loads(body)['result']) > 0, body
                run_approval_flow(request, admin, token)
            except Exception:
                log.seek(0)
                print(log.read())
                if (app / 'ghoti.log').exists():
                    print((app / 'ghoti.log').read_text())
                raise
            finally:
                server.terminate()
                server.wait(timeout=5)
    print('PASS: CLI installation, concurrent setup, HTTP maintenance, sign-in and registration approval')


if __name__ == '__main__':
    main()
