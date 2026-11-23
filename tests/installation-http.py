"""Exercise the wizard with PHP's HTTP server and an isolated MySQL/MariaDB schema.

Set GHOTI_INSTALL_TEST_DB_PORT to opt in. The test creates and drops two random
schemas. GHOTI_INSTALL_TEST_DB_HOST, _USER and _PASSWORD are optional.
"""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import stat
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid


ROOT = Path(__file__).resolve().parents[1]
KEY = 'ghoti-wizard-test-key'
TITLE = 'Install fixture'
ADMIN_PASSWORD = 'first-admin-test-password'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, url):
        return None


def client():
    return urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def write_extension(app, name, *, requires=(), sql=None, entry='<?php\n'):
    module = app / 'mod' / name
    module.mkdir()
    (module / 'module.json').write_text(json.dumps({'apiVersion': 1, 'name': name, 'description': 'Install fixture',
                                                  'requires': requires, 'schema': sql is not None}))
    (module / (name + '.php')).write_text(entry)
    (module / (name + '.async.php')).write_text('<?php\n')
    schema = module / (name + '.sql')
    if sql is not None:
        schema.write_text(sql)
    return schema


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


def run_flow(root, config, database, existing):
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    env = dict(os.environ, GHOTI_SETUP_KEY=KEY, GHOTI_DB_CONFIG='', GHOTI_DB_DRIVER='mysql',
               GHOTI_DB_HOST=config['host'], GHOTI_DB_PORT=config['port'],
               GHOTI_DB_NAME=database + '_missing', GHOTI_DB_USER=config['username'],
               GHOTI_DB_PASSWORD=config['password'], GHOTI_DB_CHARSET='utf8mb4')
    sessions = root / 'sessions'
    sessions.mkdir()
    log_path = root / 'server.log'
    with log_path.open('w') as log:
        server = subprocess.Popen(['php', '-d', 'session.save_path=' + str(sessions), '-d', 'display_errors=0',
                                   '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
                                   '-S', f'127.0.0.1:{port}', '-t', str(root)],
                                  cwd=root, env=env, stdout=log, stderr=log)
    browser = client()
    base_url = f'http://127.0.0.1:{port}/'
    route = 'ghoti/index.php'

    def request(path=route, data=None, browser=browser, expected=200, raw_json=False):
        headers = {}
        if data is not None:
            if raw_json:
                data = json.dumps(data).encode()
                headers['Content-Type'] = 'application/json'
            else:
                data = urllib.parse.urlencode(data).encode()
        try:
            response = browser.open(urllib.request.Request(base_url + path, data, headers), timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            body = response.read().decode()
            assert response.code == expected, (path, response.code, expected, body[:600])
            return response.headers, body

    def action(name, token, step='', expected=200, **fields):
        path = route + ('?step=' + step if step else '')
        return request(path, dict(fields, ghoti_setup_action=name, token=token), expected=expected)

    def token_from(body):
        return re.search(r'name="token" value="([a-f0-9]+)"', body)[1]

    try:
        for attempt in range(50):
            try:
                request(expected=403)
                break
            except urllib.error.URLError:
                if server.poll() is not None:
                    raise RuntimeError('PHP test server stopped')
                time.sleep(.1)
        else:
            raise RuntimeError('PHP test server did not start')

        app = root / 'ghoti'
        credentials = dict(config, database=database)
        config_path = app / 'db.config.local.php'
        state_path = app / '.ghoti-install'
        settings_path = app / 'ghoti.settings.json'
        headers, _ = request(route + '?k=' + KEY, expected=303)
        assert headers['Location'] == 'index.php'
        headers, body = request()
        assert KEY not in body and headers['Referrer-Policy'] == 'no-referrer'
        token = token_from(body)
        action('save-database', 'invalid', expected=403, **credentials)
        assert not config_path.exists()
        _, body = action('save-database', token, expected=422, **dict(credentials, port='65536'))
        assert 'Enter a database port between 1 and 65535' in body
        _, body = action('save-database', token, expected=422, **dict(credentials, database=database + '_missing'))
        assert 'Connection failed' in body and not config_path.exists()
        _, body = action('test-database', token, **credentials)
        assert 'Database connection succeeded' in body and not config_path.exists()
        headers, _ = action('save-database', token, expected=303, **credentials)
        assert headers['Location'] == 'index.php?step=site'
        assert stat.S_IMODE(config_path.stat().st_mode) == 0o600

        # Upgrade a pre-approval users table on an established site.
        fixture = root / 'legacy'
        copy_app(fixture)
        legacy_env = dict(env, GHOTI_DB_NAME=existing)
        bootstrap = subprocess.run(['php', 'bin/create-admin.php', 'legacy', 'legacy@example.test'],
                                   input=ADMIN_PASSWORD + '\n', cwd=fixture,
                                   env=legacy_env, text=True, capture_output=True)
        if bootstrap.returncode:
            raise RuntimeError(bootstrap.stderr + (fixture / 'ghoti.log').read_text())
        subprocess.run(['php', '-r', 'require "ghoti.php"; '
                        'ghotidb::openConnection(ghotidb::loadConfig())->exec("ALTER TABLE users DROP COLUMN approvalPending");'],
                       cwd=fixture, env=legacy_env, check=True)
        provisioned_path = fixture / 'db.provisioned.json'
        provisioned = json.loads(provisioned_path.read_text())
        provisioned.update({'moduleStamp.login': 'old', 'moduleSchema.login': 'old'})
        provisioned_path.write_text(json.dumps(provisioned))
        (fixture / 'db.config.local.php').write_text("<?php return ['database'=>'" + existing + "'];")
        legacy_browser = client()
        legacy = request('legacy/index.php', browser=legacy_browser)[1]
        assert 'Under Construction.' in legacy and 'Set up Ghoti' not in legacy
        _, legacy_login = request('legacy/index.php', browser=legacy_browser, raw_json=True,
                                  data={'__ghoti_async': 1, 'fn': 'login', 'args': ['legacy', ADMIN_PASSWORD],
                                        'token': rpc_token(legacy)})
        assert int(json.loads(legacy_login)['result']) == 1

        # A reachable but empty database still requires a setup session.
        request(browser=client(), expected=403)
        assert 'Use current connection' in request()[1]
        action('use-database', token, expected=303)
        _, body = request(route + '?step=site')
        assert 'Site title' in body and 'Continue to checks' in body
        token = token_from(body)
        action('save-site', token, 'site', 422, siteTitle=TITLE, defaultTheme='ghoticms', seoSiteUrl='http://example.test')
        headers, _ = action('save-site', token, 'site', 303, siteTitle=TITLE, defaultTheme='ghoticms',
                            seoSiteUrl='https://example.test/ghoti/')
        assert headers['Location'] == 'index.php?step=checks'
        assert not settings_path.exists()

        gallery = app / 'files/gallery'
        gallery.chmod(0o555)
        try:
            _, body = action('run-checks', token, 'checks', 422)
            assert re.search(r'Failed</strong>\s+Gallery directory', body)
            action('finish', token, 'checks', 422)
            assert not settings_path.exists() and state_path.read_text() == ''
        finally:
            gallery.chmod(0o755)
        _, body = action('run-checks', token, 'checks')
        assert re.search(r'Passed</strong>\s+Gallery directory', body)
        assert not list(app.rglob('.ghoti-check-*'))

        admin = dict(adminName='fixture-admin', adminEmail='admin@example.test', adminPassword=ADMIN_PASSWORD)
        _, body = action('finish', token, 'checks', 422, **dict(admin, adminPassword='short'))
        assert 'Password must be at least' in body and not settings_path.exists()

        # Restore a selection with a dependency, a disabled schema, and a code-only extension.
        invalid_sql = 'This fixture schema fails until fixed.'
        write_extension(app, 'install_parent', sql='CREATE TABLE IF NOT EXISTS install_parent (id INT PRIMARY KEY) ENGINE=InnoDB;')
        child_schema = write_extension(app, 'install_child', requires=('install_parent',), sql=invalid_sql)
        write_extension(app, 'install_disabled', sql=invalid_sql)
        write_extension(app, 'install_code', entry="<?php file_put_contents(__DIR__.'/loaded', 'yes');")
        loaded = app / 'mod/install_code/loaded'
        selection = {'install_child': True, 'install_parent': True, 'install_disabled': False, 'install_code': True}
        settings_path.write_text(json.dumps({'extensionModules': selection}))
        _, body = action('finish', token, 'checks', 422, **admin)
        assert 'could not prepare the enabled modules' in body
        assert state_path.read_text().strip() != 'complete' and not loaded.exists()
        child_schema.write_text('CREATE TABLE IF NOT EXISTS install_child (id INT PRIMARY KEY, '
                                'parentId INT, FOREIGN KEY (parentId) REFERENCES install_parent(id)) ENGINE=InnoDB;')

        headers, _ = action('finish', token, 'checks', 303, **admin)
        assert headers['Location'] == 'index.php'
        assert state_path.read_text().strip() == 'complete' and not loaded.exists()
        settings = json.loads(settings_path.read_text())
        assert settings['siteTitle'] == TITLE and settings['seoSiteUrl'] == 'https://example.test/ghoti'
        assert settings['extensionModules'] == selection
        assert settings['seoAllowIndexing'] is False and ADMIN_PASSWORD not in settings_path.read_text()

        _, body = request(browser=client())
        assert TITLE in body and 'Under Construction.' in body and 'Set up Ghoti' not in body and loaded.exists()
        action('finish', token, 'checks', 409, **admin)
        _, body = request()
        csrf = rpc_token(body)
        _, body = request(data={'__ghoti_async': 1, 'fn': 'login', 'args': ['fixture-admin', ADMIN_PASSWORD],
                                'token': csrf}, raw_json=True)
        login = json.loads(body)
        assert login['ok'] and int(login['result']) == 1, login
        _, body = request(data={'__ghoti_async': 1, 'fn': 'isAdmin', 'args': [1], 'token': csrf}, raw_json=True)
        assert json.loads(body) == {'ok': True, 'result': True}

        # After completion, an outage can only repair the saved connection.
        good_config = config_path.read_text()
        broken_config = app / 'broken-config.php'
        broken_config.write_text(good_config.replace(database, database + '_missing'))
        broken_config.replace(config_path)
        request(browser=client(), expected=403)
        request(route + '?k=' + KEY, expected=303)
        _, body = request()
        assert 'Recover the database connection' in body and 'Site settings' not in body
        token = token_from(body)
        action('finish', token, 'checks', 422, **admin)
        action('save-database', token, expected=303, **credentials)
        assert json.loads(settings_path.read_text()) == settings
        _, body = request()
        assert TITLE in body
        run_approval_flow(request, browser, rpc_token(body))
    except Exception:
        print(log_path.read_text())
        raise
    finally:
        server.terminate()
        server.wait(timeout=10)


def copy_app(target):
    paths = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')
    for name in filter(None, paths):
        destination = target / name
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT / name, destination)


def main():
    port = os.getenv('GHOTI_INSTALL_TEST_DB_PORT')
    if not port:
        raise SystemExit('Set GHOTI_INSTALL_TEST_DB_PORT for a disposable MySQL/MariaDB server.')
    config = dict(host=os.getenv('GHOTI_INSTALL_TEST_DB_HOST', '127.0.0.1'), port=port,
                  username=os.getenv('GHOTI_INSTALL_TEST_DB_USER', 'root'),
                  password=os.getenv('GHOTI_INSTALL_TEST_DB_PASSWORD', ''), charset='utf8mb4')
    schema = 'ghoti_wizard_' + uuid.uuid4().hex[:12]
    legacy = schema + '_legacy'
    db_env = dict(os.environ, TEST_CONFIG=json.dumps(config))
    php = """
        $config = json_decode(getenv('TEST_CONFIG'), true);
        $db = new PDO('mysql:host='.$config['host'].';port='.$config['port'],
            $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach(array_slice($argv, 1) as $sql){ $db->exec($sql); }
    """
    subprocess.run(['php', '-r', php, f'CREATE DATABASE {schema}', f'CREATE DATABASE {legacy}'],
                   env=db_env, check=True)
    try:
        with tempfile.TemporaryDirectory(prefix='ghoti-install-test-') as tmp:
            root = Path(tmp)
            copy_app(root / 'ghoti')
            run_flow(root, config, schema, legacy)
    finally:
        subprocess.run(['php', '-r', php, f'DROP DATABASE {schema}', f'DROP DATABASE {legacy}'],
                       env=db_env, check=True)
    print('Installation wizard HTTP checks passed.')


if __name__ == '__main__':
    main()
