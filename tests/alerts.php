<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
require_once __DIR__.'/../ghoti.php';
$checks = 0;
function alertCheck($ok, $label){ global $checks; if(!$ok){throw new RuntimeException($label);} $checks++; }
$file = tempnam(sys_get_temp_dir(), 'ghoti-alert-test-');
try {
    $limiter = new GhotiAlertLimiter($file);
    $sent = array();
    // Recipients are the admin accounts in production; a fixture stands in for
    // the users table here so the suite needs no database.
    $admins = array('admin@example.test');
    $recipients = function() use (&$admins){ return $admins; };
    $service = new GhotiAlertService($limiter, function($to, $subject, $body) use (&$sent, &$service){
        $sent[] = array($to,$subject,$body);
        // Simulate logging inside SMTP delivery; no recursion or duplicate email.
        alertCheck(!$service->notify('critical-error', 1000), 'Recursive alert');
        return true;
    }, $recipients);
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
    // A header-injection attempt stored on an admin account must never reach the
    // mailer, and must not take valid recipients down with it.
    $admins = array("bad\r\nBcc: attacker@example.test");
    alertCheck(!$service->notify('critical-error', 2800), 'Invalid recipient accepted');
    $admins = array();
    alertCheck(!$service->notify('critical-error', 2810), 'Alert sent with no administrators');
    $before = count($sent);
    $admins = array("bad\r\nBcc: attacker@example.test", 'one@example.test', 'two@example.test');
    alertCheck($service->notify('critical-error', 2820), 'Valid admins skipped after an invalid one');
    $fanout = array_slice($sent, $before);
    alertCheck(count($fanout) === 2, 'Alert not delivered to each administrator');
    alertCheck($fanout[0][0] === 'one@example.test' && $fanout[1][0] === 'two@example.test', 'Recipients addressed as a list');
    alertCheck(!str_contains(json_encode($fanout), 'attacker@example.test'), 'Invalid recipient reached the mailer');
    $admins = array('admin@example.test');
    file_put_contents($file, '');
    $attempts=0;
    $failure = new GhotiAlertService(new GhotiAlertLimiter($file), function() use (&$attempts){$attempts++; throw new RuntimeException('SMTP secret');}, $recipients);
    alertCheck(!$failure->notify('critical-error', 4000), 'Delivery exception escaped');
    alertCheck(!$failure->notify('critical-error', 4001) && $attempts===1, 'Delivery failure causes flood');
    file_put_contents($file, 'not JSON');
    alertCheck(!$failure->notify('critical-error', 5000) && $attempts===1, 'Corrupt state sends unthrottled mail');
    echo "PASS: $checks critical-alert assertions; all mail mocked\n";
} finally {unlink($file);}
