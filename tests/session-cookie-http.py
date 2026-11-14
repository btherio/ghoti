"""Real endpoint/session regression with isolated auth and analytics DB fixtures.

Uses index.php's actual startup through session_start(), then seeds a fixture
login. This does not test the password sign-in flow or require a live database.
All download handlers, authorization checks and the Ghoti name helper are real.
"""
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

root = Path(__file__).resolve().parent.parent
endpoints = {
    'backup.php?action=download-files': b'PK',
    'mod/filemanager/filemanager.download.php?name=fixture.txt': b'fixture download\n',
    'mod/analytics/analytics.export.php?days=1': b'id,createdAt,',
    'mod/analytics/apachelog.export.php?file=ZXJyb3JfbG9n': b'%PDF-',
    'mod/analytics/apachelog.stream.php?file=ZXJyb3JfbG9n': b'event: analysis',
}

with tempfile.TemporaryDirectory(prefix='ghoti-session-http-') as tmp:
    base = Path(tmp)
    sessions = base / 'sessions'
    sessions.mkdir()
    logs = base / 'logs'
    logs.mkdir()
    (logs / 'error_log').write_text('[Sat Nov 14 12:00:00.000000 2026] [core:error] [pid 1] fixture error\n')
    # Copy only versioned PHP sources: never operator credentials/runtime state.
    sources = subprocess.check_output(['git', 'ls-files', '-z', '--', '*.php'], cwd=root).decode().split('\0')
    for install in ('one', 'two'):
        target = base / install
        for name in filter(None, sources):
            dest = target / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(root / name, dest)
        # No real mail/DB. Backup policy uses its normal no-mail download mode.
        (target / 'mod/mail/mail.php').unlink()
        (target / 'mod/login/login.db.php').write_text('''<?php
class logindb {
    public function credentialFingerprint($id){ return 'fixture-credential'; }
    public function isAdmin($id){ return (int)$id === 1; }
}
''')
        (target / 'mod/analytics/analytics.db.php').write_text('''<?php
class analyticsdb {
    public function getExportRows($days){ return array(); }
}
''')
        (target / 'fixture.txt').write_text('fixture download\n')
        index = (root / 'index.php').read_text()
        startup, separator, _ = index.partition('@session_start();')
        assert separator, 'index session bootstrap not found'
        (target / 'index.php').write_text(startup + separator + '''
if(isset($_GET['login'])){
    $_SESSION['loggedIn'] = true;
    $_SESSION['userId'] = (int)$_GET['login'];
    $_SESSION['credentialFingerprint'] = 'fixture-credential';
    $_SESSION['last_activity'] = time();
}
if(isset($_GET['logout'])){ $_SESSION = array(); }
header('Content-Type: application/json');
echo json_encode(array('name'=>session_name(), 'token'=>ghoti_csrf_token(),
    'loggedIn'=>!empty($_SESSION['loggedIn'])));
''')

    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    env = dict(os.environ, APACHE_LOG_DIR=str(logs), APACHE_LOG_STREAM_MAX_SECONDS='30')
    with (base / 'server.log').open('w+') as server_log:
        proc = subprocess.Popen(['php', '-d', 'session.save_path='+str(sessions),
            '-d', 'display_errors=0', '-S', f'127.0.0.1:{port}', '-t', tmp],
            env=env, stdout=server_log, stderr=server_log)
        jar = http.cookiejar.CookieJar()
        client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

        def request(path):
            try:
                response = client.open(f'http://127.0.0.1:{port}/'+path, timeout=40)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                return response.status, response.headers, response.read()

        def index(install, query=''):
            status, _, body = request(install+'/index.php'+query)
            assert status == 200, (status, body)
            return json.loads(body)

        def check_endpoints(install, token, expected):
            for endpoint, signature in endpoints.items():
                status, headers, body = request(install+'/'+endpoint+'&token='+urllib.parse.quote(token))
                assert status == expected, (install, endpoint, status, body[:200])
                if expected == 200:
                    assert signature in body, (endpoint, body[:200])
                    if 'stream.php' not in endpoint:
                        assert 'attachment' in headers.get('Content-Disposition', ''), endpoint
                else:
                    assert 'Content-Disposition' not in headers, endpoint

        try:
            for attempt in range(50):
                try:
                    index('one')
                    break
                except urllib.error.URLError:
                    if proc.poll() is not None:
                        raise RuntimeError('PHP test server stopped')
                    time.sleep(.1)
            else:
                raise RuntimeError('PHP test server did not start')

            check_endpoints('one', 'guessed-token', 403)
            admin = index('one', '?login=1')
            expected_name = 'ghoti_'+hashlib.sha256(str(base / 'one').encode()).hexdigest()[:12]
            assert admin['name'] == expected_name, 'Existing index cookie format changed'
            check_endpoints('one', 'wrong-token', 403)
            check_endpoints('one', admin['token'], 200)
            print('PASS: index cookie authorizes backup, File Manager, CSV, PDF and SSE; bad CSRF denied')

            # Store uses token authorization, but must still join the same session.
            before = {(cookie.name, cookie.value) for cookie in jar}
            status, _, _ = request('one/mod/store/store.download.php')
            assert status == 404
            assert {(cookie.name, cookie.value) for cookie in jar} == before, 'Store created a different session'

            other = index('two')
            assert not other['loggedIn'] and other['name'] != admin['name']
            check_endpoints('two', admin['token'], 403)
            assert index('one')['loggedIn'], 'Second install replaced the first session'
            print('PASS: two installs on one host remain isolated with one browser cookie jar')

            member = index('one', '?login=2')
            check_endpoints('one', member['token'], 403)
            index('one', '?logout=1')
            check_endpoints('one', admin['token'], 403)
            print('PASS: non-admin and signed-out requests are denied at every protected endpoint')
        except Exception:
            server_log.flush()
            server_log.seek(0)
            print(server_log.read())
            raise
        finally:
            proc.terminate()
            proc.wait(timeout=5)
