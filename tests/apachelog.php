<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
// Run: php tests/apachelog.php - no database, no web server, no real log directory.
require_once __DIR__.'/../mod/analytics/apachelog.php';
$checks = 0;
function apacheCheck($ok, $label){ global $checks; if(!$ok){throw new RuntimeException($label);} $checks++; }

$dir = sys_get_temp_dir().'/ghoti-apachelog-test-'.bin2hex(random_bytes(6));
mkdir($dir);
putenv('APACHE_LOG_DIR='.$dir);

try {
    file_put_contents($dir.'/error_log', implode("\n", array(
        '[Fri Sep 12 10:11:12.123456 2025] [core:error] [pid 1] [client 203.0.113.9:52233] AH00037: Symbolic link not allowed: /srv/http/x',
        '[Fri Sep 12 10:12:13.123456 2025] [proxy_fcgi:error] [pid 2] [client 198.51.100.3:4422] AH01067: Failed to read FastCGI header',
        '    continued stack frame for the entry above',
        '[Fri Sep 12 10:13:14.123456 2025] [ssl:warn] [pid 3] AH01909: server certificate does NOT include an ID which matches the server name',
        'a line no parser recognizes',
        ''
    )));
    file_put_contents($dir.'/access_log', implode("\n", array(
        '203.0.113.9 - - [12/Sep/2025:10:15:00 +0000] "GET /missing HTTP/1.1" 404 209 "-" "curl/8.0"',
        '198.51.100.3 - - [12/Sep/2025:10:15:02 +0000] "POST /api HTTP/1.1" 502 512 "-" "Mozilla/5.0"',
        ''
    )));
    file_put_contents($dir.'/old_log.gz', "not really gzip");

    $config = apache_log_config();
    apacheCheck($config['log_dir'] === $dir, 'Log directory not taken from the environment');

    $files = apache_list_log_files($config);
    $byName = array();
    foreach($files as $file){ $byName[$file['name']] = $file; }
    apacheCheck(count($files) === 3, 'Log listing missed a file');
    apacheCheck($byName['error_log']['supported'] === true, 'Readable log reported unsupported');
    apacheCheck($byName['old_log.gz']['supported'] === false, 'Compressed log offered for analysis');

    // Selection is a base64url file NAME, never a path: anything that would leave
    // the configured directory has to be refused before a file is opened.
    foreach(array('../../etc/passwd', '/etc/passwd', 'error_log/../../../etc/passwd', "error_log\0") as $bad){
        $blocked = false;
        try { apache_safe_log_path(apache_base64url_encode($bad), $config); }
        catch(ApacheLogHttpException $e){ $blocked = $e->getStatus() >= 400; }
        apacheCheck($blocked, 'Path traversal accepted: '.$bad);
    }
    $blocked = false;
    try { apache_safe_log_path('!!! not base64 !!!', $config); }
    catch(ApacheLogHttpException $e){ $blocked = true; }
    apacheCheck($blocked, 'Malformed file identifier accepted');

    $blocked = false;
    try { apache_safe_log_path(apache_base64url_encode('old_log.gz'), $config); }
    catch(ApacheLogHttpException $e){ $blocked = $e->getStatus() === 415; }
    apacheCheck($blocked, 'Compressed log accepted for analysis');

    list($path, $name) = apache_safe_log_path($byName['error_log']['id'], $config);
    apacheCheck($name === 'error_log' && $path === $dir.'/error_log', 'Resolved the wrong log file');

    $report = apache_analyze_file($path, $name, $config, 100);
    apacheCheck($report['entryCount'] === 3, 'Entry count wrong: '.$report['entryCount']);
    apacheCheck($report['ignoredLines'] === 1, 'Unparsable line not counted as ignored');
    apacheCheck(strpos($report['entries'][1]['message'], 'continued stack frame') !== false, 'Continuation line not folded into its entry');
    apacheCheck($report['severityCounts'] === array('error'=>2,'warn'=>1), 'Severity counts wrong: '.json_encode($report['severityCounts']));
    apacheCheck($report['uniqueClients'] === 2, 'Unique client count wrong');
    apacheCheck($report['live'] === false, 'One-shot analysis reported as live');
    apacheCheck(!empty($report['recommendations']), 'No recommendations produced');

    list($accessPath, $accessName) = apache_safe_log_path($byName['access_log']['id'], $config);
    $accessReport = apache_analyze_file($accessPath, $accessName, $config, 100);
    apacheCheck($accessReport['entryCount'] === 2, 'Access log entries not parsed');
    apacheCheck(isset($accessReport['categoryCounts']['Missing resources'], $accessReport['categoryCounts']['Proxy & timeouts']),
        'Access log statuses not categorized: '.json_encode($accessReport['categoryCounts']));

    // The entry limit bounds what is shipped for browsing; the metrics still
    // describe every parsed entry.
    $limited = apache_analyze_file($path, $name, $config, 1);
    apacheCheck($limited['entryCount'] === 3 && count($limited['entries']) === 1 && $limited['entriesLimited'] === true,
        'Entry limit changed the summary instead of the entry list');

    // Only the tail of a large file is read.
    $big = $dir.'/big_log';
    file_put_contents($big, str_repeat("[Fri Sep 12 10:11:12.123456 2025] [core:error] [pid 1] AH00037: padding\n", 400));
    $window = apache_analyze_file($big, 'big_log', array_merge($config, array('max_initial_bytes' => 2048)), 100);
    apacheCheck($window['initialWindowTruncated'] === true && $window['bytesAnalyzed'] <= 2048, 'Read window not truncated');
    apacheCheck($window['entryCount'] > 0 && $window['entryCount'] < 400, 'Truncated window parsed the whole file');

    $pdf = apache_build_pdf($report);
    apacheCheck(strncmp($pdf, '%PDF-', 5) === 0 && strpos($pdf, '%%EOF') !== false, 'PDF export is not a PDF');

    // The tool's sudo-backed log clearing is deliberately absent: nothing in the
    // CMS may truncate a log or shell out to do it.
    foreach(array('apache_clear_log_file','apache_sudo_clear_exception') as $gone){
        apacheCheck(!function_exists($gone), 'Log clearing came back: '.$gone);
    }
    // Comments are stripped first so the file may still describe what it dropped.
    $source = '';
    foreach(token_get_all(file_get_contents(__DIR__.'/../mod/analytics/apachelog.php')) as $token){
        if(is_array($token) && in_array($token[0], array(T_COMMENT, T_DOC_COMMENT), true)){ continue; }
        $source .= is_array($token) ? $token[1] : $token;
    }
    foreach(array('proc_open','shell_exec','passthru','ftruncate','file_put_contents','fwrite','fputs','sudo_bin') as $forbidden){
        apacheCheck(stripos($source, $forbidden) === false, 'Analyzer can write or shell out: '.$forbidden);
    }

    echo "PASS: $checks Apache log analyzer assertions; read-only, no database\n";
} finally {
    foreach(glob($dir.'/*') as $file){ unlink($file); }
    rmdir($dir);
}
