"""The Apache log endpoints are plain URLs outside the RPC layer: check over
loopback that both refuse an unauthenticated caller. No database, no real logs."""
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

root = Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='ghoti-apachelog-http-') as tmp:
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    env = dict(os.environ)
    env['APACHE_LOG_DIR'] = tmp
    proc = subprocess.Popen(
        ['php', '-d', 'session.save_path=' + tmp, '-S', f'127.0.0.1:{port}', '-t', str(root)],
        env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        def request(path, session_id=None):
            req = urllib.request.Request(f'http://127.0.0.1:{port}' + path)
            if session_id:
                req.add_header('Cookie', 'ghoti=' + session_id)
            try:
                with urllib.request.urlopen(req, timeout=10) as response:
                    return response.status, response.read().decode('utf-8', 'replace')
            except urllib.error.HTTPError as error:
                return error.code, error.read().decode('utf-8', 'replace')

        for attempt in range(50):
            try:
                request('/mod/analytics/apachelog.export.php')
                break
            except urllib.error.URLError:
                if proc.poll() is not None:
                    raise RuntimeError('PHP test server could not start')
                time.sleep(.1)
        else:
            raise RuntimeError('PHP test server did not become ready')

        cases = [
            # No session at all.
            '/mod/analytics/apachelog.export.php',
            '/mod/analytics/apachelog.stream.php',
            # A guessed CSRF token is not a session either.
            '/mod/analytics/apachelog.export.php?token=deadbeef&file=ZXJyb3JfbG9n',
            '/mod/analytics/apachelog.stream.php?token=deadbeef&file=ZXJyb3JfbG9n',
            # A traversal attempt must be refused as unauthenticated, not analyzed.
            '/mod/analytics/apachelog.export.php?token=deadbeef&file=Li4vLi4vZXRjL3Bhc3N3ZA',
        ]
        for path in cases:
            status, body = request(path)
            assert status == 403, f'{path} answered {status}, expected 403'
            assert 'passwd' not in body and 'root:' not in body, f'{path} leaked file content'
            print(f'PASS HTTP 403 {path.split("/")[-1].split("?")[0]}')

        # A signed-in admin session is still refused without the session's own CSRF
        # token: the token check is the reason these plain URLs are safe, so it has
        # to be exercised on its own, past the "are you logged in" gate. The session
        # file is written directly - no database is available to log in against.
        session_id = 'apachelogtestsession'
        session_file = Path(tmp, 'sess_' + session_id)
        session_file.write_text(
            'loggedIn|b:1;userId|i:1;last_activity|i:' + str(int(time.time())) + ';'
            'csrf_token|s:8:"realtokn";')
        for path in ['/mod/analytics/apachelog.export.php?token=wrongtok&file=ZXJyb3JfbG9n',
                     '/mod/analytics/apachelog.stream.php?token=wrongtok&file=ZXJyb3JfbG9n',
                     '/mod/analytics/apachelog.export.php?file=ZXJyb3JfbG9n']:
            status, body = request(path, session_id)
            assert status == 403, f'{path} with a bad token answered {status}, expected 403'
            print(f'PASS HTTP 403 {path.split("/")[-1].split("?")[0]} (signed in, wrong CSRF token)')

        # The session survived: each of those requests stopped at the token check
        # rather than being thrown out as signed-out, which is what makes the cases
        # above a real test of the CSRF branch and not a repeat of the first gate.
        # (A request carrying the right token continues into database-backed session
        # validation, which has no database here, so it is not asserted.)
        assert 'loggedIn' in session_file.read_text(), 'Session was discarded before the CSRF check'
        print('PASS session survives a rejected token (the CSRF branch is what refused)')

        # These scripts run with the working directory set to their own folder, so a
        # relative runtime path would quietly create a second log file beside them.
        assert not Path(root, 'mod/analytics/ghoti.log').exists(), 'Endpoint wrote a log outside the application root'
        print('PASS no stray runtime files beside the endpoints')

        print('PASS: Apache log stream and export endpoints deny unauthenticated callers')
    finally:
        proc.terminate()
        proc.wait(timeout=10)
