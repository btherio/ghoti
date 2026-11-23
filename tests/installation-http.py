"""Exercise browser and CLI setup on disposable SQLite installations."""
from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
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
import urllib.parse
import urllib.request


ROOT = Path(__file__).resolve().parents[1]
ADMIN_PASSWORD = 'first-admin-test-password'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, url):
        return None


def client():
    return urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


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


def run_cli_setup(app, env):
    copy_checkout(app)
    command = ['php', 'bin/create-admin.php', '--sqlite', 'shell-admin', 'shell-admin@example.test']
    options = dict(cwd=app, input=ADMIN_PASSWORD + '\n', capture_output=True, text=True, timeout=30)
    config = app / 'db.config.local.php'
    configured = subprocess.run(command, env=dict(env, GHOTI_DB_DRIVER='sqlite'), **options)
    assert configured.returncode == 1 and not config.exists() and not (app / '.ghoti-data').exists()

    result = subprocess.run(command, env=env, **options)
    assert result.returncode == 0 and 'Administrator created.' in result.stdout, result.stderr
    assert config.stat().st_mode & 0o777 == 0o600
    original = config.read_bytes()
    repeated = subprocess.run(command, env=env, **options)
    assert repeated.returncode == 1 and config.read_bytes() == original
    database, = app.glob('.ghoti-data/site-*/ghoti.sqlite')
    with sqlite3.connect(database) as connection:
        assert connection.execute('SELECT userName, admin FROM users').fetchall() == [('shell-admin', 1)]
        assert connection.execute('SELECT title FROM pages').fetchall() == [('Home',)]


@contextmanager
def test_server(root, env):
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    with (root / f'server-{port}.log').open('w+') as log:
        server = subprocess.Popen(['php', '-d', f'session.save_path={root / "sessions"}', '-d', 'display_errors=0',
                                   '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S', f'127.0.0.1:{port}', '-t', str(root)], cwd=root,
                                  env=env, stdout=log, stderr=log)
        try:
            for _ in range(50):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=0.2):
                        break
                except OSError:
                    if server.poll() is not None:
                        raise RuntimeError('PHP test server failed to start')
                    time.sleep(0.1)
            else:
                raise RuntimeError('PHP test server failed to respond')
            yield f'http://127.0.0.1:{port}/'
        except Exception:
            log.seek(0)
            print(log.read())
            raise
        finally:
            server.terminate()
            server.wait(timeout=5)


def main():
    drivers = subprocess.check_output(['php', '-r', 'echo implode(",", PDO::getAvailableDrivers());'], text=True)
    if 'sqlite' not in drivers.split(','):
        print('SKIP: pdo_sqlite is required for the HTTP installation tests')
        return
    with tempfile.TemporaryDirectory(prefix='ghoti-install-test-') as temp:
        root = Path(temp)
        app = root / 'ghoti'
        copy_checkout(app)
        (root / 'sessions').mkdir()
        local_config = app / 'db.config.local.php'
        env = {key: value for key, value in os.environ.items() if not key.startswith('GHOTI_DB_') and key != 'GHOTI_SETUP_KEY'}
        run_cli_setup(root / 'cli', env)
        route = 'ghoti/index.php'
        admin = client()
        reserve = client()

        with test_server(root, env) as url, test_server(root, env) as second_url:
            def request(path=route, data=None, browser=admin, expected=200, raw_json=False, base=url):
                headers = {}
                payload = None
                if data is not None:
                    if raw_json:
                        headers['Content-Type'] = 'application/json'
                        payload = json.dumps(data).encode()
                    else:
                        headers['Content-Type'] = 'application/x-www-form-urlencoded'
                        payload = urllib.parse.urlencode(data).encode()
                try:
                    response = browser.open(urllib.request.Request(base + path, payload, headers), timeout=15)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    body = response.read().decode()
                    codes = expected if isinstance(expected, tuple) else (expected,)
                    assert response.code in codes, (path, response.code, codes, body[:600])
                    return response.headers, body

            def setup_form(**options):
                headers, body = request(**options)
                assert 'Set up Ghoti' in body and 'Create site' in body and 'GHOTI_SETUP_KEY' not in body
                assert 'no-store' in headers['Cache-Control']
                assert "form-action 'self'" in headers['Content-Security-Policy']
                return re.search(r'name="token" value="([a-f0-9]+)"', body)[1]

            def details(name, token):
                return {'ghoti_install': '1', 'adminName': name, 'adminEmail': name + '@example.test',
                        'adminPassword': ADMIN_PASSWORD, 'token': token}

            token = setup_form(path='ghoti/')
            other_token = setup_form(path='ghoti/', browser=reserve, base=second_url)
            request(data=details('admin', 'wrong-token'), expected=403)
            request(data=details('admin', token), browser=client(), expected=403)
            _, body = request(data=dict(details('admin', token), adminEmail='invalid'), expected=422)
            assert ADMIN_PASSWORD not in body
            assert not local_config.exists() and not (app / '.ghoti-data').exists()
            for name in ('testDbConfig', 'saveDbConfig'):
                _, body = request(data={'__ghoti_async': 1, 'fn': name, 'args': []}, expected=503, raw_json=True)
                assert json.loads(body) == {'ok': False, 'error': 'Database unavailable.'}
            request('ghoti/bin/create-admin.php', expected=404)

            with ThreadPoolExecutor(max_workers=2) as workers:
                first = workers.submit(request, data=details('admin', token), expected=(303, 503))
                second = workers.submit(request, data=details('reserve', other_token), browser=reserve,
                                        expected=(303, 503), base=second_url)
                redirects = [result.result()[0].get('Location') for result in (first, second)]
            assert 'index.php?login' in redirects, (redirects, (app / 'ghoti.log').read_text())
            assert local_config.stat().st_mode & 0o777 == 0o600
            databases = list(app.glob('.ghoti-data/site-*/ghoti.sqlite'))
            assert len(databases) == 1, databases
            with sqlite3.connect(databases[0]) as connection:
                users = connection.execute('SELECT userName, admin FROM users').fetchall()
                assert len(users) == 1 and users[0][1] == 1, users
                assert connection.execute('SELECT title FROM pages').fetchall() == [('Home',)]
            assert not list(app.glob('.ghoti-config-*'))
            request(data=details('another', token), expected=303)

            _, body = request(path=route + '?login')
            assert 'Set up Ghoti' not in body
            token = rpc_token(body)
            _, body = request(data={'__ghoti_async': 1, 'fn': 'login', 'args': [users[0][0], ADMIN_PASSWORD],
                                    'token': token}, raw_json=True)
            assert int(json.loads(body)['result']) > 0, body
            run_approval_flow(request, admin, token)

            saved = local_config.read_text()
            local_config.write_text("<?php return array('driver' => 'sqlite', 'sqlite_path' => 'invalid');")
            headers, body = request(browser=client(), expected=503)
            assert 'Retry-After' in headers and 'Set-Cookie' not in headers and 'Create site' not in body
            request(data=details('another', token), expected=503)
            local_config.unlink()
            _, body = request(expected=503)
            assert 'Create site' not in body
            local_config.write_text(saved)

    print('PASS: CLI and browser installation, concurrent setup, HTTP maintenance, sign-in and registration approval')


if __name__ == '__main__':
    main()
