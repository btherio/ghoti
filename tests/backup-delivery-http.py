"""Exercise the real backup endpoint with isolated authentication/mail fixtures."""
import http.cookiejar
import json
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
with tempfile.TemporaryDirectory(prefix='ghoti-backup-http-') as tmp:
    base = Path(tmp)
    for name in ('backup.php', 'ghoti.backup.delivery.php', 'ghoti.backup.lib.php'):
        shutil.copyfile(root / name, base / name)
    (base / 'mod/login').mkdir(parents=True)
    (base / 'mod/mail').mkdir(parents=True)
    (base / 'ghoti.php').write_text('''<?php
class ghotidb {}
class ghoti {
 public static $sessionName='backupfixture', $siteTitle='Fixture';
 public static function loadSettings(){}
 public static function enabledModules(){return ($_GET['mode'] ?? '') === 'absent' ? array() : array('mail');}
 public static function logWarn(...$args){}
 public static function logInfo(...$args){}
 public static function logException(...$args){}
}
function ghoti_security_enforce_ip_access(){}
function ghoti_request_method(){return $_SERVER['REQUEST_METHOD'];}
function ghoti_remote_addr(){return '127.0.0.1';}
function ghoti_csrf_verify($token){return $token === 'fixture-token';}
function ghoti_validate_session($db){return true;}
function ghoti_admin_emails($refresh=false){return array('first@example.test','second@example.test');}
''')
    (base / 'mod/login/login.db.php').write_text("<?php class logindb {public function isAdmin($id){return $id === 1;}}")
    (base / 'mod/mail/mail.php').write_text('''<?php
class FixtureMailDb {
 public function getSettings($strict=false){
  if(($_GET['mode'] ?? '') === 'unreadable'){throw new RuntimeException('database unavailable');}
  return array('enabled'=>($_GET['mode'] ?? '') !== 'disabled','successfulTestAt'=>($_GET['mode'] ?? '') === 'untested' ? 0 : 123);
 }
}
class mail {
 public $maildb;
 public function __construct(){$this->maildb=new FixtureMailDb();}
 public function send($to,$name,$subject,$body,$attachments){
  file_put_contents(__DIR__.'/../../deliveries.jsonl',json_encode(array('to'=>$to,'path'=>$attachments[0]['path'],'exists'=>is_file($attachments[0]['path'])))."\\n",FILE_APPEND);
  return ($_GET['mode'] ?? '') === 'failure' ? 'mock failure' : true;
 }
}
''')
    (base / 'login.php').write_text("<?php session_name('backupfixture'); session_start(); $_SESSION['loggedIn']=true; $_SESSION['userId']=1; echo 'ok';")
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    proc = subprocess.Popen(['php', '-d', 'session.save_path='+tmp, '-S', f'127.0.0.1:{port}', '-t', tmp], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(path, data=None):
        body = None if data is None else urllib.parse.urlencode(data).encode()
        try:
            response = client.open(f'http://127.0.0.1:{port}/'+path, data=body, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read()
    try:
        for attempt in range(50):
            try:
                request('login.php')
                break
            except urllib.error.URLError:
                time.sleep(.1)
        else:
            raise RuntimeError('Test server did not start')
        checks = 0
        for kind in ('files', 'database'):
            code, headers, body = request(f'backup.php?action=download-{kind}&token=fixture-token&mode=ready')
            assert code == 403 and 'Content-Disposition' not in headers, (code, body)
            checks += 1
        for mode in ('absent', 'disabled', 'untested'):
            code, headers, body = request(f'backup.php?action=download-files&token=fixture-token&mode={mode}')
            assert code == 200 and body[:2] == b'PK' and 'attachment' in headers['Content-Disposition'], (code,body)
            checks += 1
        for mode, expected in (('ready', 200), ('failure', 502)):
            code, headers, body = request(f'backup.php?action=email-files&mode={mode}', {'token':'fixture-token'})
            assert code == expected and 'Content-Disposition' not in headers, (code,body)
            deliveries = [json.loads(line) for line in (base / 'deliveries.jsonl').read_text().splitlines()]
            assert [item['to'] for item in deliveries[-2:]] == ['first@example.test','second@example.test']
            assert all(item['exists'] and not Path(item['path']).exists() for item in deliveries)
            checks += 1
        for action, mode, data, expected in (
            ('email-files','ready',None,403), # no token
            ('email-files','ready',{'token':'bad'},403),
            ('email-files','untested',{'token':'fixture-token'},403),
            ('download-files','unreadable',None,403),
        ):
            code, headers, body = request(f'backup.php?action={action}&mode={mode}', data)
            assert code == expected and 'Content-Disposition' not in headers, (code,body)
            checks += 1
        code, headers, body = request('backup.php?action=email-files&mode=ready&token=fixture-token')
        assert code == 405 and 'Content-Disposition' not in headers
        code, headers, body = request('backup.php?action=download-files&mode=unreadable&token=fixture-token')
        assert code == 500 and 'Content-Disposition' not in headers
        print(f'PASS: {checks+2} backup HTTP checks; isolated fixtures, no real mail or database')
    finally:
        proc.terminate()
        proc.wait(timeout=5)
