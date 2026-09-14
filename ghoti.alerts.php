<?php
/* Site-wide operational alerts. State contains counters/timestamps, never request data. */
class GhotiAlertLimiter {
    private $path;
    const WINDOW = 900;
    public function __construct($path){ $this->path = $path; }
    public function record($category, $threshold, $now){
        $handle = @fopen($this->path, 'c+');
        if(!$handle){ throw new RuntimeException('Alert state is unavailable'); }
        try {
            if(!flock($handle, LOCK_EX)){ throw new RuntimeException('Alert state lock failed'); }
            $raw = stream_get_contents($handle);
            $state = $raw === '' ? array() : json_decode($raw, true);
            if(!is_array($state)){ throw new RuntimeException('Alert state is invalid'); }
            $bucket = $state[$category] ?? array('start'=>$now, 'count'=>0, 'sent'=>null);
            if($now - $bucket['start'] >= self::WINDOW){ $bucket['start'] = $now; $bucket['count'] = 0; }
            $bucket['count'] = min(1000000, $bucket['count'] + 1);
            $allowed = $bucket['count'] >= $threshold && ($bucket['sent'] === null || $now - $bucket['sent'] >= self::WINDOW);
            // Reserve before sending, so simultaneous requests cannot duplicate alerts.
            if($allowed){ $bucket['sent'] = $now; }
            $state[$category] = $bucket;
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($handle);
            if(fwrite($handle, $json) !== strlen($json) || !ftruncate($handle, strlen($json)) || !fflush($handle)){
                throw new RuntimeException('Alert state could not be saved');
            }
            return array('send'=>$allowed, 'count'=>$bucket['count']);
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
function ghoti_alert_category($level, $context, $line){
    if($level >= ghoti::LOG_LEVEL_ERROR){ return 'critical-error'; }
    if($level !== ghoti::LOG_LEVEL_WARN){ return null; }
    if($context === 'login.async.php:login' && preg_match('/^(Login failed|Login blocked|Blocked login attempt)/', (string)$line)){
        return 'failed-login';
    }
    if(in_array($context, array('ghoti.async.php:dispatch','ghoti.async.php:ghoti_require_admin','login.async.php:setSessionVars'), true)){
        return 'suspicious-access';
    }
    return null;
}
class GhotiAlertService {
    private $limiter;
    private $transport;
    private $recipients;
    private $busy = false;
    /* $recipients returns the addresses to alert - the admin accounts in
     * production, a fixture under test. Resolved per notification rather than
     * once, so adding an admin takes effect without a restart. */
    public function __construct($limiter, callable $transport, callable $recipients = null){
        $this->limiter = $limiter;
        $this->transport = $transport;
        $this->recipients = $recipients ?: 'ghoti_admin_emails';
    }
    public function notify($category, $now){
        if($this->busy || !ghoti::$enableCriticalAlerts){ return false; }
        $labels = array('critical-error'=>'Critical application error', 'failed-login'=>'Repeated failed login attempts', 'suspicious-access'=>'Repeated suspicious access attempts');
        if(!isset($labels[$category])){ return false; }
        //Set before resolving recipients: that reads the database, which logs on
        //failure, which re-enters this method.
        $this->busy = true;
        try {
            $to = array();
            foreach((array)call_user_func($this->recipients) as $address){
                if(filter_var($address, FILTER_VALIDATE_EMAIL)){ $to[] = $address; }
            }
            if(!$to){ return false; }
            $decision = $this->limiter->record($category, $category === 'critical-error' ? 1 : 5, $now);
            if(!$decision['send']){ return false; }
            $site = preg_replace('/[\r\n\x00-\x1f\x7f]/', ' ', ghoti::$siteTitle);
            $body = $labels[$category]."\nSite: ".$site."\nTime (UTC): ".gmdate('Y-m-d H:i:s', $now)
                ."\nEvents in the current 15-minute window: ".$decision['count']
                ."\n\nReview the protected application log and administrator analytics dashboard for details."
                ."\nThese are application signals and do not confirm an intrusion."
                ."\nRaw log messages, passwords, account names, IP addresses and session tokens are not included."
                ."\nAt most one delivery is attempted per category every 15 minutes, including after a failed delivery."
                ."\nEvery administrator account receives this alert. Manage alerts in Site Settings; delivery uses Mail Settings.\n";
            //One delivery per admin rather than one message addressed to all of
            //them: admins do not see each other's addresses, and a mailer that
            //does not parse recipient lists still works. A failure for one
            //admin must not cancel the rest.
            $delivered = false;
            foreach($to as $address){
                $result = ($this->transport)($address, '[Ghoti alert] '.$labels[$category], $body);
                if($result === true){ $delivered = true; }
                else { error_log('Ghoti critical alert could not be delivered to an administrator; check Mail Settings.'); }
            }
            return $delivered;
        } catch(Throwable $e){
            // Do not call ghoti::logError here: that would recursively send alerts.
            error_log('Ghoti critical alert unavailable; check mail configuration and alert-state permissions.');
            return false;
        } finally { $this->busy = false; }
    }
}
function ghoti_alert_log($level, $context, $line){
    if(!ghoti::$enableCriticalAlerts){ return; }
    $category = ghoti_alert_category($level, $context, $line);
    if($category === null){ return; }
    static $service;
    if(!$service){
        $service = new GhotiAlertService(new GhotiAlertLimiter(__DIR__.'/critical-alerts.json'), function($to, $subject, $body){
            require_once __DIR__.'/mod/mail/mail.php';
            $mailer = new mail();
            return $mailer->send($to, '', $subject, $body);
        });
    }
    $service->notify($category, time());
}
function ghoti_install_error_handlers(){
    static $installed = false;
    if($installed){ return; }
    $installed = true;
    ini_set('display_errors', '0');
    set_exception_handler(function(Throwable $e){
        ghoti::logException('runtime:unhandled', $e);
        if(!headers_sent()){ http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
        echo 'The site encountered an unexpected error. Please try again later.';
        exit(1);
    });
    register_shutdown_function(function(){
        $error = error_get_last();
        if($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)){
            ghoti::logError('runtime:fatal', 'Fatal error in '.basename($error['file']).':'.$error['line']);
        }
    });
}
