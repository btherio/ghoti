<?php
/* Delivery policy is shared by the workspace and transfer endpoint. */
require_once __DIR__.'/ghoti.backup.lib.php';

function ghoti_backup_uses_email($moduleEnabled, array $settings){
    return $moduleEnabled && !empty($settings['enabled']) && (int)($settings['successfulTestAt'] ?? 0) > 0;
}

function ghoti_backup_email_mailer(){
    if(!in_array('mail', ghoti::enabledModules(), true) || !is_file(__DIR__.'/mod/mail/mail.php')){ return null; }
    require_once __DIR__.'/mod/mail/mail.php';
    $mailer = new mail();
    return ghoti_backup_uses_email(true, $mailer->maildb->getSettings(true)) ? $mailer : null;
}

function ghoti_backup_export_allowed($action, $emailMode){
    if(strpos($action, 'download-') === 0){ return !$emailMode; }
    if(strpos($action, 'email-') === 0){ return $emailMode; }
    return false;
}

// Every recipient gets the same generated file in a separate message. Never fall
// back to a download after partial delivery or an SMTP rejection.
function ghoti_backup_email_export($kind, $mailer, array $recipients, callable $create = null){
    if(!in_array($kind, array('files', 'database'), true)){ throw new InvalidArgumentException('Unknown backup type.'); }
    $addresses = array();
    foreach($recipients as $recipient){
        if(!is_string($recipient) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)){
            throw new RuntimeException('An administrator email address is invalid. Correct it in Manage Users.');
        }
        $addresses[strtolower($recipient)] = $recipient;
    }
    if(!$addresses){ throw new RuntimeException('No administrator has a valid email address. Add one in Manage Users.'); }
    $create = $create ?: function($kind){
        return $kind === 'files' ? ghoti_backup_create_site_archive(__DIR__) : ghoti_backup_create_sql_dump();
    };
    $result = $create($kind);
    $path = $result['path'];
    register_shutdown_function(function() use ($path){ if(is_file($path)){ @unlink($path); } });
    $extension = $kind === 'files' ? 'zip' : 'sql';
    $filename = 'ghoti-'.($kind === 'files' ? 'site' : 'database').'-'.gmdate('Ymd-His').'.'.$extension;
    $attachment = array('path'=>$path, 'name'=>$filename, 'type'=>$kind === 'files' ? 'application/zip' : 'application/sql');
    $sent = 0;
    $failed = 0;
    try{
        foreach($addresses as $address){
            try{
                //Was a bespoke template with the ghoticms accent hardcoded into it,
                //which came out wrong on every other theme. The shared renderer
                //reads the colours from the site's own stylesheet instead.
                $subject = ghoti::$siteTitle.' — '.($kind === 'files' ? 'site files' : 'database').' backup';
                $plainTextBody = "An administrator requested this backup of ".ghoti::$siteTitle.".\n\nThe backup is attached as ".$filename.".\n"
                    ."Keep the matching site ZIP and SQL dump together in secure storage.\nCreated: ".gmdate('c')."\n";
                $delivered = ghoti_mail_send_themed($mailer, $address, '', $subject, $plainTextBody, 'operator', array($attachment), $plainTextBody);
            }catch(Throwable $e){
                $delivered = false;
                ghoti::logWarn('backup:email', 'Backup delivery raised an exception for '.$address);
            }
            if($delivered === true){ $sent++; }
            else {
                $failed++;
                ghoti::logWarn('backup:email', 'Backup delivery failed for '.$address);
            }
        }
    }finally{ @unlink($path); }
    return array('sent'=>$sent, 'failed'=>$failed);
}
