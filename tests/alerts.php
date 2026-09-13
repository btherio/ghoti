<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
require_once __DIR__.'/../ghoti.php';
$checks = 0;
function alertCheck($ok, $label){ global $checks; if(!$ok){throw new RuntimeException($label);} $checks++; }
$file = tempnam(sys_get_temp_dir(), 'ghoti-alert-test-');
try {
    $limiter = new GhotiAlertLimiter($file);
    $sent = array();
    $service = new GhotiAlertService($limiter, function($to, $subject, $body) use (&$sent, &$service){
        $sent[] = array($to,$subject,$body);
        // Simulate logging inside SMTP delivery; no recursion or duplicate email.
        alertCheck(!$service->notify('critical-error', 1000), 'Recursive alert');
        return true;
    });
    ghoti::$criticalAlertEmail = 'admin@example.test';
    ghoti::$enableCriticalAlerts = false;
    alertCheck(!$service->notify('critical-error', 1000) && !$sent, 'Disabled alerts sent mail');
    ghoti::$enableCriticalAlerts = true;
    alertCheck(ghoti_alert_category(ghoti::LOG_LEVEL_ERROR, 'runtime:unhandled', 'secret') === 'critical-error', 'Error ignored');
    alertCheck(ghoti_alert_category(ghoti::LOG_LEVEL_INFO, 'login.async.php:login', 'Login failed') === null, 'Info triggers alert');
    alertCheck(ghoti_alert_category(ghoti::LOG_LEVEL_WARN, 'login.async.php:login', 'Login failed for secret') === 'failed-login', 'Failed login ignored');
    alertCheck(ghoti_alert_category(ghoti::LOG_LEVEL_WARN, 'login.async.php:login', 'Login blocked (server throttle)') === 'failed-login', 'Throttled login ignored');
    alertCheck(ghoti_alert_category(ghoti::LOG_LEVEL_WARN, 'ghoti.async.php:dispatch', 'Rejected CSRF') === 'suspicious-access', 'Suspicious RPC ignored');
    alertCheck(ghoti_alert_category(ghoti::LOG_LEVEL_WARN, 'unrelated', 'Login failed') === null, 'Unrelated warning misclassified');
    alertCheck($service->notify('critical-error', 1000), 'First critical event not sent');
    alertCheck(!$service->notify('critical-error', 1001), 'Duplicate critical event sent');
    for($i=0;$i<4;$i++){alertCheck(!$service->notify('failed-login', 1000+$i), 'Login threshold too low');}
    alertCheck($service->notify('failed-login', 1004), 'Fifth failure did not alert');
    alertCheck(!$service->notify('failed-login', 1005), 'Threshold emitted repeated mail');
    for($i=0;$i<4;$i++){alertCheck(!$service->notify('suspicious-access', 1000+$i), 'Intrusion threshold too low');}
    alertCheck($service->notify('suspicious-access', 1004), 'Fifth suspicious event did not alert');
    alertCheck($service->notify('critical-error', 1900), 'Cooldown never expires');
    $otherProcess = new GhotiAlertLimiter($file);
    alertCheck(!$otherProcess->record('critical-error', 1, 1901)['send'], 'State not shared across requests');
    alertCheck(!str_contains(json_encode($sent), 'secret'), 'Raw message leaked into mail');
    alertCheck(!str_contains(file_get_contents($file), '@'), 'Personal data persisted in counters');
    ghoti::$criticalAlertEmail = "bad\r\nBcc: attacker@example.test";
    alertCheck(!$service->notify('critical-error', 2800), 'Invalid recipient accepted');
    alertCheck(is_string(ghoti::saveSettings(array('enableCriticalAlerts'=>1,'criticalAlertEmail'=>''))), 'Missing recipient accepted');
    ghoti::$criticalAlertEmail = 'admin@example.test';
    file_put_contents($file, '');
    $attempts=0;
    $failure = new GhotiAlertService(new GhotiAlertLimiter($file), function() use (&$attempts){$attempts++; throw new RuntimeException('SMTP secret');});
    alertCheck(!$failure->notify('critical-error', 4000), 'Delivery exception escaped');
    alertCheck(!$failure->notify('critical-error', 4001) && $attempts===1, 'Delivery failure causes flood');
    file_put_contents($file, 'not JSON');
    alertCheck(!$failure->notify('critical-error', 5000) && $attempts===1, 'Corrupt state sends unthrottled mail');
    echo "PASS: $checks critical-alert assertions; all mail mocked\n";
} finally {unlink($file);}
