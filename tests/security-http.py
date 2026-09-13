"""Exercise request parsing/CSRF/setup over loopback; no database or mail."""
import http.cookiejar
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

root = Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='ghoti-http-test-') as tmp:
    router = Path(tmp) / 'router.php'
    # PHP single-quoted literal, with only the two necessary escapes.
    literal = str(root).replace('\\', '\\\\').replace("'", "\\'")
    router.write_text("<?php\nchdir('" + literal + "');\nrequire_once 'ghoti.php';\n"
        "ghoti::$ghotiLog=__DIR__.'/test.log';\nsession_start();\n"
        "$path=parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);\n"
        "if($path==='/token'){ header('Content-Type: application/json'); echo json_encode(array('token'=>ghoti_csrf_token())); exit; }\n"
        "if($path==='/setup'){ ghoti_setup_dispatch(); }\nghoti_async_handle_request();\n")
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    env = dict(os.environ)
    env.pop('GHOTI_SETUP_KEY', None)
    proc = subprocess.Popen(['php', '-d', 'session.save_path='+tmp, '-S', f'127.0.0.1:{port}', str(router)], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        def request(path, data=None):
            req = urllib.request.Request(f'http://127.0.0.1:{port}'+path, data=None if data is None else json.dumps(data).encode(), headers={'Content-Type':'application/json'})
            try:
                with client.open(req, timeout=5) as response:
                    return response.status, response.read().decode()
            except urllib.error.HTTPError as error:
                return error.code, error.read().decode()
        for attempt in range(50):
            try:
                status, body = request('/token')
                break
            except urllib.error.URLError:
                if proc.poll() is not None:
                    raise RuntimeError('PHP test server could not start')
                time.sleep(.1)
        else:
            raise RuntimeError('PHP test server did not become ready')
        assert status == 200
        token = json.loads(body)['token']
        cases = [
            ('/setup', None, 503),
            ('/setup', {'__ghoti_async':1, 'fn':'saveDbConfig', 'args':[{}], 'token':token}, 503),
            ('/rpc', {'__ghoti_async':1,'fn':'getDefaultPage','args':[],'token':'wrong'},403),
            ('/rpc', {'__ghoti_async':1,'fn':'getPage','args':['<script>alert(1)</script>'],'token':token},400),
            ('/rpc', {'__ghoti_async':1,'fn':['getPage'],'token':token},400),
            ('/rpc', {'__ghoti_async':1,'fn':'getPage','token':[]},400),
            ('/rpc', {'__ghoti_async':1,'fn':'getPage','token':token,'args':['x'*1048576]},413),
        ]
        for path, data, expected in cases:
            code, body = request(path, data)
            assert code == expected, (code, expected, body)
            assert '<script>' not in body
            print('PASS HTTP', expected, path)
    finally:
        proc.terminate()
        proc.wait(timeout=5)
