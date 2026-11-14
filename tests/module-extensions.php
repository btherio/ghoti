<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require_once 'ghoti.php';
$checks = 0;
function extensionCheck(bool $ok, string $message): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function extensionReject(callable $callback, string $message): void {
    try { $callback(); } catch (InvalidArgumentException|RuntimeException $error) { extensionCheck(true,$message); return; }
    throw new RuntimeException($message);
}
$root = sys_get_temp_dir().'/ghoti-modules-'.bin2hex(random_bytes(8));
mkdir($root,0700);
function writeFixture(string $root, string $id, array $extra=[]): void {
    mkdir($root.'/'.$id,0700);
    file_put_contents($root.'/'.$id.'/module.json',json_encode(array_replace(['apiVersion'=>1,'name'=>ucfirst($id),'description'=>'Fixture module','schema'=>false],$extra),JSON_THROW_ON_ERROR));
    file_put_contents($root.'/'.$id.'/'.$id.'.php','<?php throw new RuntimeException("Discovery executed module code");');
    file_put_contents($root.'/'.$id.'/'.$id.'.async.php','<?php // fixture');
    if ($extra['schema'] ?? false) file_put_contents($root.'/'.$id.'/'.$id.'.sql','CREATE TABLE IF NOT EXISTS '.$id.' (id int PRIMARY KEY);');
}
try {
    writeFixture($root,'tools',['schema'=>true,'legacyEnabledSetting'=>'enableTools']);
    writeFixture($root,'automation',['requires'=>['tools']]);
    $registry = new GhotiModuleRegistry($root);
    extensionCheck(array_keys($registry->getDefinitions())===['automation','tools'],'manifests are discovered deterministically without executing their PHP');
    extensionCheck($registry->getEnabled([],[])===[],'installed modules are disabled by default');
    extensionCheck($registry->getEnabled(['tools'=>true,'automation'=>true],[])===['tools','automation'],'dependency loads before its consumer');
    extensionCheck($registry->getEnabled(['automation'=>true],[])===[],'disabled dependency prevents its consumer from loading');
    extensionCheck($registry->getEnabled(['unknown'=>true],[])===[],'unavailable configured module never becomes executable');
    extensionCheck($registry->allowsSchema('tools') && !$registry->allowsSchema('automation'),'schema provisioning requires an installed declaration');
    extensionCheck(!$registry->allowsSchema('../tools'),'schema names cannot traverse directories');
    extensionCheck($registry->readSettings(['enableTools'=>true])===['tools'=>true],'legacy switch is migrated by its manifest');
    extensionCheck($registry->readSettings(['enableTools'=>true,'extensionModules'=>['tools'=>false]])===['tools'=>false],'new settings take precedence over legacy flags');
    extensionCheck($registry->readSettings(['extensionModules'=>['unavailable'=>true]])===['unavailable'=>true],'temporarily absent module preferences survive loading');
    extensionReject(fn()=>$registry->validateSelection(['automation'=>true],[],[]),'enabling a consumer without its dependency is rejected');
    extensionReject(fn()=>$registry->validateSelection(['tools'=>false],['tools'=>true,'automation'=>true],[]),'disabling an in-use dependency is rejected');
    extensionReject(fn()=>$registry->validateSelection(['missing'=>true],[],[]),'browser cannot enable an uninstalled module');
    extensionReject(fn()=>$registry->validateSelection(['tools'=>['true']],[],[]),'enabled values must be booleans');
    extensionReject(fn()=>$registry->readSettings(['extensionModules'=>['../bad'=>true]]),'saved traversal names are rejected');
    extensionReject(fn()=>$registry->readSettings(['extensionModules'=>'tools']),'saved module state must be a map');
    $selection=$registry->validateSelection(['tools'=>1,'automation'=>true],['unavailable'=>true],[]);
    extensionCheck($selection===['unavailable'=>true,'tools'=>true,'automation'=>true],'valid changes preserve unrelated module state');
    writeFixture($root,'boardhelper',['requires'=>['boards']]);
    $registry=new GhotiModuleRegistry($root);
    extensionCheck($registry->getEnabled(['boardhelper'=>true],['boards'])===['boardhelper'],'extensions may depend on an enabled built-in module');
    extensionCheck($registry->getEnabled(['boardhelper'=>true],[])===[],'disabled built-in dependency fails closed');
    writeFixture($root,'cycleone',['requires'=>['cycletwo']]);
    writeFixture($root,'cycletwo',['requires'=>['cycleone']]);
    $registry=new GhotiModuleRegistry($root);
    extensionReject(fn()=>$registry->getEnabled(['cycleone'=>true,'cycletwo'=>true],[]),'dependency cycles are rejected');
    writeFixture($root,'future',['apiVersion'=>99]);
    extensionReject(fn()=>(new GhotiModuleRegistry($root))->getDefinitions(),'unsupported API versions are rejected');
    unlink($root.'/future/module.json');
    symlink($root.'/tools/module.json',$root.'/future/module.json');
    extensionReject(fn()=>(new GhotiModuleRegistry($root))->getDefinitions(),'linked manifests cannot impersonate another module');
    unlink($root.'/future/module.json');
    file_put_contents($root.'/future/module.json','{ invalid JSON');
    extensionReject(fn()=>(new GhotiModuleRegistry($root))->getDefinitions(),'invalid JSON fails clearly');
    file_put_contents($root.'/future/module.json',json_encode(['apiVersion'=>1,'name'=>'Future','description'=>'Fixture']));
    unlink($root.'/future/future.php');
    extensionReject(fn()=>(new GhotiModuleRegistry($root))->getDefinitions(),'an incomplete installation cannot be loaded');
    unlink($root.'/future/module.json');
    writeFixture($root,'login');
    extensionReject(fn()=>(new GhotiModuleRegistry($root))->getDefinitions(),'extensions cannot shadow built-in module names');

    $events=[];
    ghoti_add_hook('fixture.ready',function($context)use(&$events){$events[]='late:'.$context['value'];},20);
    ghoti_add_hook('fixture.ready',function($context)use(&$events){$events[]='first:'.$context['value'];},5);
    ghoti_add_hook('fixture.ready',function($context)use(&$events){$events[]='second:'.$context['value'];},5);
    ghoti_run_hook('fixture.ready',['value'=>'ok']);
    extensionCheck($events===['first:ok','second:ok','late:ok'],'hook order is stable by priority and registration order');
    ghoti_add_filter('fixture.value',fn($value,$context)=>$value.$context['suffix'],5);
    ghoti_add_filter('fixture.value',fn($value)=>strtoupper($value),20);
    extensionCheck(ghoti_apply_filters('fixture.value','a',['suffix'=>'b'])==='AB','filters pass results and context through the chain');
    extensionCheck(ghoti_apply_filters('fixture.unused',42)===42,'unused filters preserve their input');
    extensionReject(fn()=>ghoti_add_hook('../bad',fn()=>null),'hook names reject paths');
    ghoti_add_hook('fixture.failure',fn()=>throw new RuntimeException('expected'));
    extensionReject(fn()=>ghoti_run_hook('fixture.failure'),'callback exceptions are not swallowed');
    // Exercise the real framework loader/settings with a temporary installed module.
    $probe = 'extensionprobe_'.bin2hex(random_bytes(4));
    $moduleRoot = dirname(__DIR__).'/mod';
    writeFixture($moduleRoot,$probe,['legacyEnabledSetting'=>'enableFixtureProbe']);
    $savedSettings = ghoti::currentSettings();
    $savedFile = ghoti::$settingsFile; $savedLog = ghoti::$ghotiLog;
    ghoti::$settingsFile = 'ghoti.settings.extension-test-'.bin2hex(random_bytes(4)).'.json';
    ghoti::$ghotiLog = $root.'/test.log';
    try {
        $loader=(new ReflectionClass(ghoti::class))->newInstanceWithoutConstructor();
        $loader->loadModules(array_values(array_intersect(ghoti::enabledModules(),[$probe])));
        extensionCheck(!isset($GLOBALS['extension_probe_loaded']),'disabled installed modules are not executed by normal loading');
        file_put_contents($moduleRoot.'/'.$probe.'/'.$probe.'.php', '<?php $GLOBALS["extension_probe_loaded"] = true; ghoti_add_hook("request.init", function(){ $_SESSION["probeService"] = new stdClass(); }); ghoti_add_hook("request.cleanup", function(){ unset($_SESSION["probeService"]); });');
        file_put_contents(ghoti::settingsPath(), json_encode(['enableFixtureProbe'=>true]));
        ghoti::loadSettings();
        extensionCheck(ghoti::isModuleEnabled($probe),'legacy manifest flag enables the real framework loader');
        $loader->loadModules([$probe]);
        extensionCheck(!empty($GLOBALS['extension_probe_loaded']),'enabled manifest module code is loaded');
        $_SESSION=['userId'=>42]; ghoti_run_hook('request.init');
        extensionCheck(isset($_SESSION['probeService']),'module initialization uses the public request hook');
        ghoti_free_request_objects();
        extensionCheck(!isset($_SESSION['probeService']) && $_SESSION['userId']===42,'cleanup removes module services while preserving session identity');
        extensionCheck(ghoti::saveSettings(['extensionModules'=>[$probe=>false]])===true,'module switches save through native Site Settings');
        ghoti::$extensionModules=[]; ghoti::loadSettings();
        extensionCheck(!ghoti::isModuleEnabled($probe),'disabled state survives settings reload');
        $saved=file_get_contents(ghoti::settingsPath());
        extensionCheck(!str_contains($saved,'enableFixtureProbe'),'next save replaces the legacy flag with the generic map');
        extensionCheck(ghoti::saveSettings(['extensionModules'=>['unknown_module'=>true]])!==true,'unknown module settings fail through the public save API');
        extensionCheck(file_get_contents(ghoti::settingsPath())===$saved,'rejected module settings do not rewrite the settings file');
        extensionReject(fn()=>$loader->loadModules(['../probe']),'unregistered module entry points cannot be loaded');
    } finally {
        unlink(ghoti::settingsPath()); ghoti::$settingsFile=$savedFile; ghoti::$ghotiLog=$savedLog;
        foreach($savedSettings as $key=>$value) ghoti::$$key=$value;
        foreach(glob($moduleRoot.'/'.$probe.'/*') as $file) unlink($file);
        rmdir($moduleRoot.'/'.$probe);
    }
    $source=file_get_contents('index.php');
    extensionCheck(strpos($source,"ghoti_run_hook('request.init')")>strpos($source,'ghoti_validate_session('),'request initialization follows session validation');
    extensionCheck(strpos($source,"ghoti_run_hook('request.init')")<strpos($source,'ghoti_async_handle_request();'),'initialization precedes endpoint dispatch');
    extensionCheck(str_contains(file_get_contents('ghoti.header.php'),"ghoti_run_hook('document.head'"),'shared document invokes module assets hook');
    extensionCheck(str_contains(file_get_contents('ghoti.body.php'),'getDefaultPage()'),'native Home is server-rendered for every installation');
} finally {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}
    rmdir($root);
}
echo "PASS: $checks extension API assertions\n";
