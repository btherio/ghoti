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

    /* ---------------- history: what each alert was about ---------------- */
    $pendingFile = tempnam(sys_get_temp_dir(), 'ghoti-alert-pending-');
    $archiveFile = tempnam(sys_get_temp_dir(), 'ghoti-alert-history-');
    $limiterFile = tempnam(sys_get_temp_dir(), 'ghoti-alert-limit-');
    try {
        $history = new GhotiAlertHistory($pendingFile, $archiveFile);
        $mails = array();
        $admins = array('one@example.test', 'two@example.test');
        $service = new GhotiAlertService(new GhotiAlertLimiter($limiterFile), function($to, $subject, $body) use (&$mails){
            $mails[] = array($to, $subject, $body);
            return $to === 'two@example.test' ? 'The message could not be sent.' : true;
        }, $recipients, $history);

        // Four failed logins count but do not alert; the fifth does, and the
        // alert lists all five with their raw log lines.
        for($i = 0; $i < 5; $i++){
            $service->notify('failed-login', 10000 + $i, ghoti::LOG_LEVEL_WARN, 'login.async.php:login', "Login failed for 'mallory$i' from 203.0.113.$i");
        }
        $alerts = $history->alerts();
        alertCheck(count($alerts) === 1, 'Expected exactly one recorded alert');
        $alert = $alerts[0];
        alertCheck($alert['id'] === 1 && $alert['category'] === 'failed-login' && $alert['count'] === 5, 'The alert record is wrong');
        alertCheck(count($alert['events']) === 5 && strpos($alert['events'][4]['line'], "mallory4") !== false, 'The alert does not list the events behind it');
        alertCheck($alert['events'][0]['context'] === 'login.async.php:login' && $alert['events'][0]['level'] === 'WARN', 'Event context/level not kept');
        alertCheck($alert['status'] === 'partial', 'A half-delivered alert is not marked partial: '.$alert['status']);
        $byTo = array_column($alert['deliveries'], null, 'to');
        alertCheck($byTo['one@example.test']['status'] === 'sent' && $byTo['two@example.test']['status'] === 'failed', 'Per-admin delivery not recorded');
        alertCheck($byTo['two@example.test']['detail'] === 'The message could not be sent.', 'The failure reason was not kept');

        // The e-mail names the alert, and still carries none of the details.
        alertCheck(strpos($mails[0][1], '#1') !== false && strpos($mails[0][2], 'Alert reference: #1') !== false, 'The e-mail does not name the alert');
        alertCheck(strpos($mails[0][2], 'Analytics > Alerts') !== false, 'The e-mail does not say where the details are');
        alertCheck(!str_contains(json_encode($mails), 'mallory') && !str_contains(json_encode($mails), '203.0.113'), 'Raw log details leaked into the e-mail');
        alertCheck(!str_contains(file_get_contents($limiterFile), 'mallory'), 'Request data reached the counter file');

        // A transport that throws is still recorded, as a failure.
        $admins = array('one@example.test');
        $throwing = new GhotiAlertService(new GhotiAlertLimiter($limiterFile), function(){ throw new RuntimeException('SMTP exploded'); }, $recipients, $history);
        alertCheck(!$throwing->notify('critical-error', 20000, ghoti::LOG_LEVEL_ERROR, 'runtime:fatal', 'Fatal error in x.php:1'), 'A throwing transport reported success');
        $latest = $history->alerts()[0];
        alertCheck($latest['id'] === 2 && $latest['status'] === 'failed', 'A failed alert left no trace');
        alertCheck(strpos($latest['events'][0]['line'], 'Fatal error in x.php') !== false, 'The failed alert lost its event');

        // Events are listed for the limiter's window only - not a rolling one.
        $service2 = new GhotiAlertService(new GhotiAlertLimiter($limiterFile), function(){ return true; }, $recipients, $history);
        $service2->notify('critical-error', 20000 + GhotiAlertLimiter::WINDOW + 5, ghoti::LOG_LEVEL_ERROR, 'runtime:fatal', 'second window');
        $latest = $history->alerts()[0];
        alertCheck(count($latest['events']) === 1 && $latest['events'][0]['line'] === 'second window', 'Events from an earlier window were attached');

        // Caps: events per category, alerts kept, characters per line.
        for($i = 0; $i < 40; $i++){ $history->addEvent('failed-login', 0, 30000 + $i, 'WARN', 'ctx', str_repeat('x', 2000)); }
        $pending = json_decode(file_get_contents($pendingFile), true);
        alertCheck(count($pending['failed-login']) === GhotiAlertHistory::MAX_PENDING, 'Pending events are not capped');
        alertCheck(mb_strlen($pending['failed-login'][0]['line']) === GhotiAlertHistory::MAX_LINE, 'Log lines are not capped');
        for($i = 0; $i < 120; $i++){ $history->openAlert('critical-error', 'x', 40000 + $i, 1, 40000 + $i, array('a@example.test')); }
        alertCheck(count($history->alerts()) === GhotiAlertHistory::MAX_ALERTS, 'The archive is not capped');
        alertCheck($history->alerts()[0]['id'] > $history->alerts()[1]['id'], 'The archive is not newest first');

        // Control characters in a line cannot forge structure.
        $history->addEvent('suspicious-access', 0, 50000, 'WARN', "ctx\r\nX", "line\r\nForged: yes");
        $pending = json_decode(file_get_contents($pendingFile), true);
        alertCheck(strpos($pending['suspicious-access'][0]['line'], "\n") === false, 'A newline survived in a stored line');

        // A corrupt archive is started afresh rather than blocking alerts.
        file_put_contents($archiveFile, '{not json');
        alertCheck($history->openAlert('critical-error', 'x', 60000, 1, 60000, array()) === 1 && count($history->alerts()) === 1, 'A corrupt archive blocked new alerts');

        // A service built without a history writes none.
        $bare = tempnam(sys_get_temp_dir(), 'ghoti-alert-bare-');
        $before = array(filesize($pendingFile), filesize($archiveFile));
        (new GhotiAlertService(new GhotiAlertLimiter($bare), function(){ return true; }, $recipients))->notify('critical-error', 70000, ghoti::LOG_LEVEL_ERROR, 'x', 'y');
        clearstatcache();
        alertCheck(array(filesize($pendingFile), filesize($archiveFile)) === $before, 'A history was written without one being given');
        @unlink($bare);

        // The Analytics renderer escapes every stored string.
        require_once __DIR__.'/../mod/analytics/analytics.async.php';
        $history->addEvent('failed-login', 0, 80000, 'WARN', 'login.async.php:login', 'Login failed for \'<script>alert(1)</script>\'');
        $id = $history->openAlert('failed-login', 'Repeated failed login attempts', 80001, 5, 80000, array('<b>@example.test'));
        $render = new ReflectionMethod('analyticsui', 'alertsCard');
        $render->setAccessible(true);
        $html = $render->invoke(new analyticsui(), $history->alerts());
        alertCheck(strpos($html, '<script>alert') === false && strpos($html, '&lt;script&gt;') !== false, 'A stored log line was not escaped');
        alertCheck(strpos($html, 'id="alert-'.$id.'"') !== false && strpos($html, '#'.$id) !== false, 'The alert cannot be found by its number');
        alertCheck(strpos($html, 'open') !== false, 'The newest alert is not open');
    } finally { @unlink($pendingFile); @unlink($archiveFile); @unlink($limiterFile); }

    // The site files are protected like ghoti.log.
    foreach(array('critical-alerts.pending.json', 'critical-alerts.history.json') as $name){
        alertCheck(strpos(file_get_contents(__DIR__.'/../.htaccess'), str_replace('.', '\\.', $name)) !== false, "$name is not denied over HTTP");
        alertCheck(strpos(file_get_contents(__DIR__.'/../.gitignore'), $name) !== false, "$name is not gitignored");
        alertCheck(ghoti_backup_excluded_path($name), "$name would be put in backups");
    }
    echo "PASS: $checks critical-alert assertions; all mail mocked\n";
} finally {unlink($file);}
