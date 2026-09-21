<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
require __DIR__.'/../ghoti.php';
require_once __DIR__.'/../ghoti.backup.php';
require_once __DIR__.'/../mod/mail/mail.db.php';
require_once __DIR__.'/../mod/mail/mail.async.php';
$checks = 0;
function backupCheck($ok, $label){ global $checks; if(!$ok){ throw new RuntimeException($label); } $checks++; }
function isAdmin($userId){ return !empty($GLOBALS['backupTestAdmin']); }
$log = tempnam(sys_get_temp_dir(), 'ghoti-backup-test-log-');
ghoti::$ghotiLog = $log;
ghoti::$enableCriticalAlerts = false;
register_shutdown_function(function() use ($log){ @unlink($log); });
$_SESSION = array('loggedIn'=>true, 'userId'=>1);
$GLOBALS['backupTestAdmin'] = true;

foreach(array(false, true) as $module){
    foreach(array(false, true) as $enabled){
        foreach(array(0, 12345) as $tested){
            $email = ghoti_backup_uses_email($module, array('enabled'=>$enabled,'successfulTestAt'=>$tested));
            backupCheck($email === ($module && $enabled && $tested > 0), 'Incorrect mail policy');
            foreach(array('files', 'database') as $kind){
                backupCheck(ghoti_backup_export_allowed('download-'.$kind, $email) === !$email, 'Download gate disagrees with policy');
                backupCheck(ghoti_backup_export_allowed('email-'.$kind, $email) === $email, 'Email gate disagrees with policy');
            }
        }
    }
}
backupCheck(!ghoti_backup_uses_email(true, array('enabled'=>true)), 'Old installs are considered tested');
$emailHtml = ghoti_backup_render_workspace(true);
$downloadHtml = ghoti_backup_render_workspace(false);
backupCheck(!str_contains($emailHtml, 'action=download-') && str_contains($emailHtml, 'Email site ZIP to all admins') && str_contains($emailHtml, 'Email SQL dump to all admins'), 'Email UI exposes downloads or omits an export');
backupCheck(str_contains($downloadHtml, 'action=download-files') && str_contains($downloadHtml, 'action=download-database'), 'Fallback download links missing');
backupCheck(!str_contains($downloadHtml, 'onsubmit="emailGhotiBackup'), 'Untested mail has send controls');
backupCheck(str_contains($emailHtml, 'name="token"') && str_contains($emailHtml, 'restore-files-form'), 'CSRF or restore forms missing');

class BackupMailerFake{
    public $calls = array();
    public $fail = '';
    public function send($to, $name, $subject, $body, array $attachments){
        backupCheck(count($attachments) === 1 && is_file($attachments[0]['path']), 'Attachment missing during send');
        backupCheck(file_get_contents($attachments[0]['path']) === 'fixture backup', 'Attachment content changed');
        $this->calls[] = array('to'=>$to, 'attachment'=>$attachments[0]);
        if($to === $this->fail){ throw new RuntimeException('simulated delivery outage'); }
        return true;
    }
}
$created = array();
$factory = function($kind) use (&$created){
    $path = ghoti_backup_temp_path('ghoti-export-test-');
    file_put_contents($path, 'fixture backup');
    $created[] = $path;
    return array('path'=>$path);
};
foreach(array('files'=>'application/zip', 'database'=>'application/sql') as $kind=>$mime){
    $mailer = new BackupMailerFake();
    $result = ghoti_backup_email_export($kind, $mailer, array('first@example.test','second@example.test','FIRST@example.test'), $factory);
    backupCheck($result === array('sent'=>2,'failed'=>0), 'Admins were not deduplicated and all sent');
    backupCheck(count($mailer->calls) === 2 && $mailer->calls[0]['attachment']['path'] === $mailer->calls[1]['attachment']['path'], 'Backup regenerated per recipient');
    backupCheck($mailer->calls[0]['attachment']['type'] === $mime, 'Wrong attachment MIME type');
    backupCheck(!file_exists(end($created)), 'Successful delivery left backup file behind');
}
$mailer = new BackupMailerFake();
$mailer->fail = 'second@example.test';
$result = ghoti_backup_email_export('files', $mailer, array('first@example.test','second@example.test','third@example.test'), $factory);
backupCheck($result === array('sent'=>2,'failed'=>1) && count($mailer->calls) === 3, 'Failure stopped remaining admin deliveries');
backupCheck(!file_exists(end($created)), 'Partial failure left backup file behind');
$mailer->fail = 'first@example.test';
$result = ghoti_backup_email_export('database', $mailer, array('first@example.test'), $factory);
backupCheck($result === array('sent'=>0,'failed'=>1) && !file_exists(end($created)), 'Complete failure was reported as success or leaked file');
$count = count($created);
foreach(array(array(), array("bad\r\n@example.test")) as $recipients){
    try{ ghoti_backup_email_export('files', $mailer, $recipients, $factory); backupCheck(false, 'Invalid recipients accepted'); }
    catch(RuntimeException $e){ backupCheck(count($created) === $count, 'Generated backup without valid recipients'); }
}

class BackupMailDbSpy extends maildb{
    public $settings;
    public $calls = array();
    public function __construct(){ $this->settings = self::defaultSettings(); }
    public function __destruct(){}
    public function getSettings($throwOnError = false){ return $this->settings; }
    protected function query($sql, array $params = array()){
        $this->calls[] = array($sql,$params);
        if(str_starts_with($sql, 'update mail set successfulTestAt')){ $this->settings['successfulTestAt'] = $params[0]; }
        return true;
    }
}
class BackupTestMailFake{
    public $maildb;
    public $result = true;
    public $sentTo = array();
    public $failFor = '';
    public function __construct(){ $this->maildb = new BackupMailDbSpy(); }
    public function send($to,$name,$subject,$body){
        $this->sentTo[] = $to;
        return $to === $this->failFor ? false : $this->result;
    }
}
$mail = new BackupTestMailFake();
$_SESSION['mailObj'] = $mail;
$admins = array('admin@example.test');
$mail->result = 'SMTP rejected test';
backupCheck(mailDeliverTestMessage($mail, $admins) === 'SMTP rejected test' && $mail->maildb->settings['successfulTestAt'] === 0, 'Failed test recorded success');
$mail->result = true;
backupCheck(mailDeliverTestMessage($mail, $admins) === true && $mail->maildb->settings['successfulTestAt'] > 0, 'Successful test not persisted');
$stamp = $mail->maildb->settings['successfulTestAt'];
$mail->result = false;
mailDeliverTestMessage($mail, $admins);
backupCheck($mail->maildb->settings['successfulTestAt'] === $stamp, 'Failed test erased prior successful test');
$mail->maildb->saveSettings(array('successfulTestAt'=>0));
$last = end($mail->maildb->calls);
backupCheck(!str_contains($last[0], 'successfulTestAt'), 'Settings save can overwrite test history');

// The test message addresses every administrator, one message each.
$mail = new BackupTestMailFake();
$_SESSION['mailObj'] = $mail;
$everyAdmin = array('first@example.test','second@example.test','third@example.test');
backupCheck(mailDeliverTestMessage($mail, $everyAdmin) === true && $mail->sentTo === $everyAdmin, 'Test message did not reach every administrator individually');
$mail = new BackupTestMailFake();
$mail->failFor = 'second@example.test';
$partial = mailDeliverTestMessage($mail, $everyAdmin);
backupCheck(is_string($partial) && str_contains($partial, 'Sent to 2 of 3') && str_contains($partial, 'second@example.test'), 'Partial test delivery misreported');
backupCheck(count($mail->sentTo) === 3, 'One failed administrator stopped the remaining test deliveries');
backupCheck($mail->maildb->settings['successfulTestAt'] > 0, 'Partially delivered test was not recorded');
$mail = new BackupTestMailFake();
backupCheck(mailDeliverTestMessage($mail, array()) === 'No administrator account has a valid e-mail address. Add one in Manage Users, then test again.'
    && $mail->sentTo === array(), 'Test sent with no administrator addresses');
$_SESSION['mailObj'] = $mail;
$GLOBALS['backupTestAdmin'] = false;
backupCheck(sendTestMail() === 'Admin access required.', 'Non-admin sent a test');
backupCheck(str_contains(printBackupRestore(), 'Admin access required'), 'Non-admin can open backups');
echo "PASS: $checks backup delivery assertions; no database or real mail\n";
