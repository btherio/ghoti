<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
// No production credentials, DB, mail, or network access required.
chdir(dirname(__DIR__));
require_once 'ghoti.php';
require_once 'mod/login/login.async.php';
require_once 'mod/login/login.db.php';
require_once 'mod/filemanager/filemanager.async.php';
require_once 'mod/comments/comments.async.php';
ghoti::$ghotiLog = sys_get_temp_dir().'/ghoti-security-test-'.getmypid().'.log';
$checks = 0;
function check($condition, $message){
    global $checks;
    if(!$condition){ throw new RuntimeException('FAIL: '.$message); }
    $checks++;
}
function rejects($callback){ try{ $callback(); return false; }catch(Throwable $e){ return true; } }
$_SESSION = array('csrf_token'=>'test-token');
putenv('GHOTI_SETUP_KEY');
check(!ghoti_setup_access_ok('', 'test-token'), 'setup denies missing operator key despite valid CSRF');
putenv('GHOTI_SETUP_KEY=operator-test-key');
check(!ghoti_setup_access_ok('wrong', 'test-token'), 'setup rejects wrong key');
check(!ghoti_setup_access_ok('operator-test-key', 'wrong'), 'setup rejects wrong CSRF');
check(ghoti_setup_access_ok('operator-test-key', 'test-token'), 'setup accepts both valid secrets');
check(!ghoti_async_is_registered('getPage'), 'internal HTML renderer is not public RPC');
check(!ghoti_csrf_verify(array('test-token')), 'CSRF rejects structured input');

$_SERVER['HTTP_HOST'] = 'attacker.invalid';
$_SERVER['REQUEST_URI'] = '//attacker.invalid/poisoned';
foreach(array('', 'http://example.test', 'https://user:pass@example.test', 'https://example.test?evil=1', "https://example.test\n", 'https://example.test#fragment') as $base){
    putenv('GHOTI_PUBLIC_URL='.$base);
    check(rejects(function(){ ghoti_password_reset_url(); }), 'invalid public URL is rejected');
}
putenv('GHOTI_PUBLIC_URL=https://example.test/subdir/');
check(ghoti_password_reset_url() === 'https://example.test/subdir/password-reset.php', 'host/path poisoning cannot change reset URL');

foreach(array('=1+1', '+SUM(1,2)', '-1+2', '@SUM(1)', "\t=1", " \r=1") as $value){
    check(ghoti_csv_cell($value) === "'".$value, 'CSV formula prefixed');
}
check(ghoti_csv_cell('normal text') === 'normal text', 'ordinary CSV text preserved');
check(ghoti_csv_cell('123') === '123', 'ordinary CSV numbers preserved');

class SessionDb{
    public $fingerprint = 'current';
    function credentialFingerprint($id){ return $this->fingerprint; }
}
$db = new SessionDb();
function sessionFixture(){ $_SESSION = array('loggedIn'=>true,'userId'=>7,'admin'=>true,'credentialFingerprint'=>'current','last_activity'=>time(),'pageId'=>42); }
sessionFixture(); check(ghoti_validate_session($db), 'current credentials accepted');
sessionFixture(); $db->fingerprint='changed'; check(!ghoti_validate_session($db) && ghoti_current_user_id() === 0 && !isset($_SESSION['pageId']), 'password change revokes session and private page context');
sessionFixture(); $db->fingerprint=null; check(!ghoti_validate_session($db), 'deleted user or DB failure revokes session');
sessionFixture(); $db->fingerprint='current'; $_SESSION['last_activity']=time()-1801; check(!ghoti_validate_session($db), 'standalone downloads enforce inactivity expiry');
sessionFixture(); unset($_SESSION['credentialFingerprint']); check(!ghoti_validate_session($db), 'legacy session fails closed');

$dir = sys_get_temp_dir().'/ghoti-throttle-test-'.getmypid();
mkdir($dir);
try{
    $file = $dir.'/state.json';
    $a = new login_throttle($file); $b = new login_throttle($file);
    $a->recordFailure('ip:test'); $b->recordFailure('ip:test');
    check($a->countWindow('ip:test', 600) === 2, 'stale instances preserve each other\'s updates');
    $children = array();
    for($i=0;$i<12;$i++){
        $pid = pcntl_fork();
        if($pid === 0){ (new login_throttle($file))->recordFailure('ip:test'); exit(0); }
        check($pid > 0, 'fork worker'); $children[]=$pid;
    }
    foreach($children as $pid){ pcntl_waitpid($pid, $status); check(pcntl_wexitstatus($status) === 0, 'concurrent writer succeeded'); }
    check($a->countWindow('ip:test',600) === 14, 'concurrent throttle writes do not disappear');
    check($a->isBlocked('ip:test') > 0, 'shared throttle blocks across instances');
    $a->clear('ip:test'); check($b->countWindow('ip:test',600) === 0, 'clear observed by existing instances');
    file_put_contents($file, 'broken');
    check(rejects(function() use ($a){ $a->isBlocked('ip:test'); }), 'corrupt throttle fails closed');
}finally{ if(is_file($dir.'/state.json')) unlink($dir.'/state.json'); rmdir($dir); }

check(fm_clean_dir('../outside') === false, 'parent traversal rejected');
check(fm_clean_name('.env') === false, 'dotfile access rejected');
check(!fm_path_allowed('/site', '/site/.git/config'), 'denied ancestor rejected');
check(!fm_path_allowed('/site', '/site/mod/login.throttle.json.tmp'), 'throttle temporary file rejected');
check(!fm_path_allowed('/site', '/site-other/file'), 'sibling prefix rejected');
check(fm_path_allowed('/site', '/site/gfx/logo.png'), 'normal asset accepted');
$fixture = __DIR__.'/path-fixture-'.getmypid();
mkdir($fixture); mkdir($fixture.'/.private'); file_put_contents($fixture.'/.private/data.txt','secret');
symlink($fixture.'/.private', $fixture.'/alias');
try{
    check(fm_resolve_dir('tests/'.basename($fixture).'/alias') === false, 'real symlink into hidden directory rejected');
}finally{ unlink($fixture.'/alias'); unlink($fixture.'/.private/data.txt'); rmdir($fixture.'/.private'); rmdir($fixture); }

foreach(array('javascript:alert(1)', "java\tscript:alert(1)", 'data:text/html,test', 'file:///etc/passwd') as $url){
    check(rejects(function() use ($url){ ghoti_validate()->url($url); }), 'dangerous URL rejected');
}
check(ghoti_validate()->url('/assets/a.png') === '/assets/a.png', 'relative asset URL allowed');
$_SESSION=array();
check(fmSaveTextFile('', 'index.php', 'malicious') === 'Admin access required.', 'anonymous file write denied');
check(savePage(1, 'title', 'content') === false, 'anonymous page write denied');
check(deleteComment(1) === false, 'anonymous comment delete denied');
check(setSessionVars(1) === false, 'client cannot elevate session by ID');

// Model SQL effects/failures to exercise the real reset method without a DB.
class ResetDb extends logindb{
    public $events=array(); public $used=false; public $winDuringLock=false; public $failPassword=false; public $locked=true;
    public function __construct(){}
    protected function hashPassword($password){ return 'new-hash'; }
    public function validatePasswordResetToken($token){ return $this->used ? null : 7; }
    protected function queryArray($sql, array $params=array()){
        if($sql === 'select database()'){ return array(array('test')); }
        if(strpos($sql,'GET_LOCK') !== false){ $this->events[]='lock'; if($this->winDuringLock) $this->used=true; return array(array($this->locked ? 1 : 0)); }
        if(strpos($sql,'RELEASE_LOCK') !== false){ $this->events[]='unlock'; return array(array(1)); }
        throw new RuntimeException('Unexpected query');
    }
    protected function query($sql, array $params=array()){
        if(strpos($sql,'update password_resets') === 0){ $this->events[]='consume'; $this->used=true; return; }
        if(strpos($sql,'update users') === 0){ $this->events[]='password'; if($this->failPassword) throw new RuntimeException('write failed'); return; }
        throw new RuntimeException('Unexpected write');
    }
}
$r=new ResetDb(); check($r->resetPasswordWithToken('token','safe-password') === true, 'reset succeeds');
check($r->events === array('lock','consume','password','unlock'), 'reset consumes all links before password write under lock');
check($r->resetPasswordWithToken('token','another-password') !== true, 'used reset cannot replay');
$r=new ResetDb(); $r->winDuringLock=true; check($r->resetPasswordWithToken('token','safe-password') !== true && $r->events === array('lock','unlock'), 'competing reset rejected after lock');
$r=new ResetDb(); $r->failPassword=true; check($r->resetPasswordWithToken('token','safe-password') !== true && $r->used, 'password write failure leaves token consumed');
check(end($r->events) === 'unlock', 'lock released on exception');
$r=new ResetDb(); $r->locked=false; check($r->resetPasswordWithToken('token','safe-password') !== true && $r->events === array('lock'), 'lock failure cannot change password');
$r=new ResetDb(); check($r->changePassword(7,'safe-password') === true && $r->used, 'authenticated password change retires reset links');
check(ghoti_safe_url_attribute('javascript:alert(1)') === '', 'legacy banner script URL suppressed');
check(ghoti_safe_url_attribute('/image.png?a=1&b=2') === '/image.png?a=1&amp;b=2', 'safe URL attribute escaped');
class RegistrationDb extends logindb{
    public $writes=array();
    public function __construct(){}
    public function checkDuplicate($name,$email){ return false; }
    protected function hashPassword($password){ return 'hash'; }
    protected function query($sql, array $params=array()){
        $this->writes[]=array($sql,$params);
        return (object)array('fields'=>array(1));
    }
}
$r=new RegistrationDb(); check($r->addUser('first','safe-password','first@example.test'), 'ordinary registration succeeds at data layer');
check(count($r->writes) === 1 && $r->writes[0][1][3] === 0, 'first public registrant receives no admin promotion');
check(ghoti::$allowRegister === false, 'fresh installs disable public registration');
class PrivatePageDb{ function getPageById($id){ return array(array('body','title','private')); } }
$_SESSION=array('pageId'=>42, 'ghotiObj'=>(object)array('ghotidb'=>new PrivatePageDb()));
check(getPageComments() === '', 'stale public-page session cannot read newly private comments');
if(is_file(ghoti::$ghotiLog)){ unlink(ghoti::$ghotiLog); }
echo "PASS: $checks security assertions\n";
