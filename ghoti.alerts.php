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
            //'start' lets the history list exactly the events this count covers.
            return array('send'=>$allowed, 'count'=>$bucket['count'], 'start'=>$bucket['start']);
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
/*
 * What each alert was about, for Analytics -> Alerts.
 *
 * The e-mail deliberately carries no log lines, account names or addresses, so
 * the details have to be somewhere an administrator can find them. They are
 * kept here, in two files beside critical-alerts.json:
 *
 *   pending - the recent events per category, rewritten on every event. Kept
 *             small on purpose: a brute-force burst writes it once per attempt.
 *   archive - one record per alert (newest first, capped), with the events that
 *             counted towards it and how each administrator's delivery went.
 *             Written only when an alert fires - at most once per category per
 *             15 minutes.
 *
 * Unlike critical-alerts.json these DO hold request data - the same log lines
 * ghoti.log holds, usernames and IPs included - so both are denied over HTTP,
 * gitignored and left out of backups, exactly like ghoti.log.
 *
 * Nothing here may call ghoti::log*(): this runs inside the logger, and a log
 * line from here would re-enter the alerter.
 */
class GhotiAlertHistory {
    const MAX_PENDING = 25;   //events kept per category
    const MAX_ALERTS  = 100;  //alerts kept in the archive
    const MAX_LINE    = 500;  //characters kept per log line

    //Relative to the application directory; tests point these elsewhere.
    public static $pendingFile = 'critical-alerts.pending.json';
    public static $archiveFile = 'critical-alerts.history.json';

    private $pendingPath;
    private $archivePath;

    public function __construct($pendingPath, $archivePath){
        $this->pendingPath = $pendingPath;
        $this->archivePath = $archivePath;
    }

    public static function forSite(){
        $resolve = function($file){ return $file !== '' && $file[0] === '/' ? $file : __DIR__.'/'.$file; };
        return new self($resolve(self::$pendingFile), $resolve(self::$archiveFile));
    }

    //Remember one event that counted towards $category's current window.
    public function addEvent($category, $windowStart, $now, $level, $context, $line){
        $this->update($this->pendingPath, function($state) use ($category, $windowStart, $now, $level, $context, $line){
            $events = array();
            foreach((array)($state[$category] ?? array()) as $event){
                if((int)($event['time'] ?? 0) >= $windowStart){ $events[] = $event; }
            }
            $events[] = array(
                'time'    => (int)$now,
                'level'   => self::levelName($level),
                'context' => self::clip($context, 120),
                'line'    => self::clip($line, self::MAX_LINE),
            );
            $state[$category] = array_slice($events, -self::MAX_PENDING);
            return $state;
        });
    }

    //Open an alert record before it is delivered, so a delivery that dies
    //half-way still leaves a trace. Returns the new alert's number.
    public function openAlert($category, $label, $now, $count, $windowStart, array $recipients){
        $pending = $this->read($this->pendingPath);
        $events = array();
        foreach((array)($pending[$category] ?? array()) as $event){
            if((int)($event['time'] ?? 0) >= $windowStart){ $events[] = $event; }
        }
        $id = 0;
        $this->update($this->archivePath, function($state) use (&$id, $category, $label, $now, $count, $windowStart, $recipients, $events){
            $id = (int)($state['nextId'] ?? 1);
            $deliveries = array();
            foreach($recipients as $address){ $deliveries[] = array('to' => (string)$address, 'status' => 'pending', 'detail' => ''); }
            $alerts = (array)($state['alerts'] ?? array());
            array_unshift($alerts, array(
                'id'          => $id,
                'category'    => (string)$category,
                'label'       => (string)$label,
                'time'        => (int)$now,
                'windowStart' => (int)$windowStart,
                'count'       => (int)$count,
                'events'      => $events,
                'status'      => 'sending',
                'deliveries'  => $deliveries,
            ));
            $state['nextId'] = $id + 1;
            $state['alerts'] = array_slice($alerts, 0, self::MAX_ALERTS);
            return $state;
        });
        return $id;
    }

    //Record how delivery went: $results is address => true or a failure reason.
    public function finishAlert($id, array $results){
        $this->update($this->archivePath, function($state) use ($id, $results){
            foreach((array)($state['alerts'] ?? array()) as $i => $alert){
                if((int)$alert['id'] !== (int)$id){ continue; }
                $sent = 0;
                foreach($alert['deliveries'] as $j => $delivery){
                    $result = $results[$delivery['to']] ?? 'Not attempted.';
                    $ok = $result === true;
                    if($ok){ $sent++; }
                    $state['alerts'][$i]['deliveries'][$j]['status'] = $ok ? 'sent' : 'failed';
                    $state['alerts'][$i]['deliveries'][$j]['detail'] = $ok ? '' : self::clip(is_string($result) ? $result : 'Delivery failed.', 200);
                }
                $total = count($alert['deliveries']);
                $state['alerts'][$i]['status'] = $sent === $total ? 'sent' : ($sent === 0 ? 'failed' : 'partial');
                break;
            }
            return $state;
        });
    }

    //Every alert kept, newest first. An unreadable archive reads as empty.
    public function alerts(){
        $state = $this->read($this->archivePath);
        return is_array($state['alerts'] ?? null) ? $state['alerts'] : array();
    }

    private static function levelName($level){
        if(is_string($level)){ return $level; }
        if($level >= ghoti::LOG_LEVEL_ERROR){ return 'ERROR'; }
        return $level >= ghoti::LOG_LEVEL_WARN ? 'WARN' : 'INFO';
    }

    private static function clip($text, $max){
        $text = preg_replace('/[\x00-\x08\x0A-\x1F\x7F]+/', ' ', (string)$text);
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1)."\u{2026}" : $text;
    }

    private function read($path){
        if(!is_file($path)){ return array(); }
        $raw = @file_get_contents($path);
        $state = $raw === false || $raw === '' ? array() : json_decode($raw, true);
        return is_array($state) ? $state : array();
    }

    //Locked read-modify-write, the same way GhotiAlertLimiter does it. A corrupt
    //file is started afresh rather than blocking every alert after it.
    private function update($path, callable $change){
        $handle = @fopen($path, 'c+');
        if(!$handle){ throw new RuntimeException('Alert history is unavailable'); }
        try {
            if(!flock($handle, LOCK_EX)){ throw new RuntimeException('Alert history lock failed'); }
            $raw = stream_get_contents($handle);
            $state = $raw === '' ? array() : json_decode($raw, true);
            if(!is_array($state)){ $state = array(); }
            $json = json_encode($change($state), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            rewind($handle);
            if(fwrite($handle, $json) !== strlen($json) || !ftruncate($handle, strlen($json)) || !fflush($handle)){
                throw new RuntimeException('Alert history could not be saved');
            }
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
    private $history;
    private $busy = false;
    /* $recipients returns the addresses to alert - the admin accounts in
     * production, a fixture under test. Resolved per notification rather than
     * once, so adding an admin takes effect without a restart. */
    /* $history (a GhotiAlertHistory) is optional so tests and callers that do
     * not want a record written get none; ghoti_alert_log() passes the site's. */
    public function __construct($limiter, callable $transport, callable $recipients = null, $history = null){
        $this->limiter = $limiter;
        $this->transport = $transport;
        $this->recipients = $recipients ?: 'ghoti_admin_emails';
        $this->history = $history;
    }
    /* $level/$context/$line are the log entry that triggered this call. They go
     * to the history only - never into the e-mail. */
    public function notify($category, $now, $level = null, $context = '', $line = ''){
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
            $windowStart = (int)($decision['start'] ?? $now);
            //The history is a convenience for reading an alert later. Failing to
            //write it must never cost the alert itself.
            if($this->history && $line !== ''){
                try { $this->history->addEvent($category, $windowStart, $now, $level, $context, $line); }
                catch(Throwable $e){ error_log('Ghoti alert history could not record an event; check that the application directory is writable.'); }
            }
            if(!$decision['send']){ return false; }
            $alertId = 0;
            if($this->history){
                try { $alertId = $this->history->openAlert($category, $labels[$category], $now, $decision['count'], $windowStart, $to); }
                catch(Throwable $e){ error_log('Ghoti alert history could not record an alert; check that the application directory is writable.'); }
            }
            $site = preg_replace('/[\r\n\x00-\x1f\x7f]/', ' ', ghoti::$siteTitle);
            $body = $labels[$category]."\nSite: ".$site."\nTime (UTC): ".gmdate('Y-m-d H:i:s', $now)
                ."\nEvents in the current 15-minute window: ".$decision['count']
                .($alertId > 0 ? "\nAlert reference: #".$alertId : '')
                ."\n\nWhat happened: sign in and open Analytics > Alerts".($alertId > 0 ? ", alert #".$alertId : '')
                .". It lists every event behind this alert, with the log messages, and who this e-mail reached."
                ."\nThese are application signals and do not confirm an intrusion."
                ."\nRaw log messages, passwords, account names, IP addresses and session tokens are not included in this e-mail."
                ."\nAt most one delivery is attempted per category every 15 minutes, including after a failed delivery."
                ."\nEvery administrator account receives this alert. Manage alerts in Site Settings; delivery uses Site Settings → Mail.\n";
            //One delivery per admin rather than one message addressed to all of
            //them: admins do not see each other's addresses, and a mailer that
            //does not parse recipient lists still works. A failure for one
            //admin must not cancel the rest.
            //Each delivery is caught on its own, so a transport that throws for one
            //administrator neither skips the rest nor goes unrecorded.
            $delivered = false;
            $results = array();
            $subject = '[Ghoti alert'.($alertId > 0 ? ' #'.$alertId : '').'] '.$labels[$category];
            foreach($to as $address){
                try { $result = ($this->transport)($address, $subject, $body); }
                catch(Throwable $e){ $result = 'The mail transport failed.'; }
                $results[$address] = $result === true ? true : (is_string($result) ? $result : 'Delivery failed.');
                if($result === true){ $delivered = true; }
                else { error_log('Ghoti critical alert could not be delivered to an administrator; check Site Settings → Mail.'); }
            }
            if($this->history && $alertId > 0){
                try { $this->history->finishAlert($alertId, $results); }
                catch(Throwable $e){ error_log('Ghoti alert history could not record an alert; check that the application directory is writable.'); }
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
            //The themed pair is built here rather than in GhotiAlertService, so
            //the service keeps its three-argument transport contract.
            if(function_exists('ghoti_mail_send_themed')){
                return ghoti_mail_send_themed($mailer, $to, '', $subject, $body, 'operator');
            }
            return $mailer->send($to, '', $subject, $body);
        }, null, GhotiAlertHistory::forSite());
    }
    $service->notify($category, time(), $level, $context, $line);
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
