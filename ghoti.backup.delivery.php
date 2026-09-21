<?php
/* Delivery policy is shared by the workspace and transfer endpoint. */
require_once __DIR__.'/ghoti.backup.lib.php';

function ghoti_backup_email_html($kind, $filename, $siteTitle){
    $isFilesBackup = $kind === 'files';
    $backupType = $isFilesBackup ? 'site files' : 'database';
    $htmlEscaped = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

    $siteName = $htmlEscaped($siteTitle);
    $safeFilename = $htmlEscaped($filename);
    $createdTime = $htmlEscaped(gmdate('Y-m-d H:i:s') . ' UTC');
    $backupTypeEscaped = $htmlEscaped($backupType);

    $template = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style type="text/css">
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #333; line-height: 1.6; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header-bar { background: linear-gradient(135deg, #78ffc8 0%, #6be8b3 100%); color: #07080c; padding: 24px; border-radius: 8px 8px 0 0; text-align: center; }
        .header-bar h1 { margin: 0; font-size: 28px; font-weight: 600; word-break: break-word; }
        .content { background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 0 0 8px 8px; padding: 24px; }
        .content p { margin: 12px 0; }
        .backup-details { background: white; border-left: 4px solid #78ffc8; padding: 16px; margin: 20px 0; border-radius: 4px; }
        .detail-row { display: flex; margin: 8px 0; }
        .detail-label { font-weight: 600; width: 100px; color: #555; }
        .detail-value { color: #333; word-break: break-all; flex: 1; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc857; padding: 12px; margin: 16px 0; border-radius: 4px; color: #333; font-size: 14px; }
        .footer { text-align: center; color: #777; font-size: 12px; margin-top: 24px; padding-top: 16px; border-top: 1px solid #e0e0e0; }
        a { color: #78ffc8; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-bar">
            <h1>{site_name}</h1>
            <p style="margin: 8px 0 0 0; font-size: 14px; opacity: 0.9;">{backup_type_title} Backup</p>
        </div>
        <div class="content">
            <p>An administrator requested this backup of <strong>{site_name}</strong>.</p>

            <div class="backup-details">
                <div class="detail-row">
                    <div class="detail-label">Filename:</div>
                    <div class="detail-value">{filename}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Type:</div>
                    <div class="detail-value">{backup_type}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Created:</div>
                    <div class="detail-value">{created_time}</div>
                </div>
            </div>

            <div class="warning">
                <strong>Important:</strong> Keep the matching site ZIP and SQL dump together in secure storage.
            </div>

            <p style="color: #777; font-size: 14px; margin-top: 20px;">
                This email was sent because you are an administrator on {site_name}.
                Do not forward or share this email, as it contains sensitive backup files.
            </p>
        </div>
        <div class="footer">
            <p>{site_name} — Backup System</p>
        </div>
    </div>
</body>
</html>
HTML;

    // The template is a nowdoc so its CSS braces stay literal; the values are
    // substituted here, already escaped above.
    return strtr($template, array(
        '{site_name}'          => $siteName,
        '{filename}'           => $safeFilename,
        '{backup_type}'        => $backupTypeEscaped,
        '{backup_type_title}'  => ucfirst($backupTypeEscaped),
        '{created_time}'       => $createdTime,
    ));
}

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
                $htmlBody = ghoti_backup_email_html($kind, $filename, ghoti::$siteTitle);
                $plainTextBody = "An administrator requested this backup of ".ghoti::$siteTitle.".\n\nThe backup is attached as ".$filename.".\n"
                    ."Keep the matching site ZIP and SQL dump together in secure storage.\nCreated: ".gmdate('c')."\n";
                $delivered = $mailer->send($address, '', ghoti::$siteTitle.' — '.($kind === 'files' ? 'site files' : 'database').' backup',
                    $plainTextBody, array($attachment), $htmlBody);
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
