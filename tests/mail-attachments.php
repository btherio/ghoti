<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
require __DIR__.'/../mod/mail/mail.smtp.php';
$checks = 0;
function attachmentCheck($ok, $label){ global $checks; if(!$ok){ throw new RuntimeException($label); } $checks++; }
$path = tempnam(sys_get_temp_dir(), 'ghoti-mail-fixture-');
$capture = tempnam(sys_get_temp_dir(), 'ghoti-smtp-capture-');
$ownerPid = getmypid();
register_shutdown_function(function() use($path,$capture,$ownerPid){ if(getmypid() === $ownerPid){ @unlink($path); @unlink($capture); } });
$bytes = random_bytes(200003);
file_put_contents($path, $bytes);
$attachment = array('path'=>$path,'name'=>'ghoti-site-fixture.zip','type'=>'application/zip');
$client = new MailSmtpClient(array('fromAddress'=>'site@example.test','fromName'=>'Site'));
$write = new ReflectionMethod(MailSmtpClient::class, 'writeMessage');
$stream = fopen('php://temp', 'w+');
$write->invoke($client,$stream,'admin@example.test','Admin','Backup',"Hello\n.leading\n.\nEnd",array($attachment));
rewind($stream); $message = stream_get_contents($stream); fclose($stream);
attachmentCheck(str_contains($message,'Content-Type: multipart/mixed; boundary="'), 'MIME multipart header missing');
attachmentCheck(str_contains($message, 'Content-Disposition: attachment; filename="ghoti-site-fixture.zip"'), 'Attachment disposition missing');
attachmentCheck(str_contains($message,"\r\n..leading\r\n..\r\n"), 'SMTP dot transparency broken');
attachmentCheck(preg_match('/Content-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--/s', $message, $match) === 1, 'Attachment data missing');
attachmentCheck(base64_decode(str_replace("\r\n", '', $match[1]), true) === $bytes, 'Attachment bytes changed across chunks');
attachmentCheck(max(array_map('strlen', explode("\r\n", $match[1]))) <= 76, 'Base64 line exceeds MIME limit');
foreach(array(array_merge($attachment,array('name'=>"bad\r\nInjected: yes")),array_merge($attachment,array('path'=>'/not-a-file')),array_merge($attachment,array('type'=>"application/zip\r\nInjected: yes"))) as $bad){
    $stream = fopen('php://temp','w+');
    try{ $write->invoke($client,$stream,'admin@example.test','','Backup','Body',array($bad)); throw new LogicException('Bad attachment accepted'); }
    catch(RuntimeException $e){ attachmentCheck(true, 'Invalid attachment rejected'); }
    finally{ fclose($stream); }
}
// The HTML part carries admin-written text (Admin Menu -> Send Email), so it
// must be wire-encoded exactly like the plain-text part: CRLF endings and
// dot-stuffing. A leading "." that reaches the MTA unstuffed is deleted.
// Shaped like ghoti_mail_body_html()'s output: a soft break puts the author's
// own text at the start of a physical line, dot and all.
$htmlMessage = "<p>pi is 3.14<br />\n.leading dot<br />\n.\n</p>";
$stream = fopen('php://temp', 'w+');
$write->invoke($client,$stream,'admin@example.test','','HTML test',"Text\n.leading dot",array(),$htmlMessage);
rewind($stream); $alternative = stream_get_contents($stream); fclose($stream);
attachmentCheck(str_contains($alternative,'Content-Type: multipart/alternative; boundary="'), 'HTML mail is not multipart/alternative');
attachmentCheck(preg_match('/Content-Type: text\/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n(.*?)\r\n--/s', $alternative, $htmlPart) === 1, 'HTML part missing');
attachmentCheck(str_contains($htmlPart[1], "\r\n..leading dot"), 'HTML part is not dot-stuffed');
attachmentCheck(str_contains($htmlPart[1], "\r\n..\r\n"), 'A lone dot line in the HTML part is not stuffed');
attachmentCheck(str_contains($htmlPart[1], "pi is 3.14"), 'Dot-stuffing altered a dot inside a line');
attachmentCheck(!preg_match('/(?<!\r)\n/', $htmlPart[1]), 'HTML part contains a bare newline');
attachmentCheck(str_contains($alternative, "Text\r\n..leading dot"), 'Plain-text part lost its dot-stuffing alongside HTML');
// The same encoding must survive the multipart/mixed wrapper used with attachments.
$stream = fopen('php://temp', 'w+');
$write->invoke($client,$stream,'admin@example.test','','HTML + attachment','Text body',array($attachment),$htmlMessage);
rewind($stream); $mixed = stream_get_contents($stream); fclose($stream);
attachmentCheck(str_contains($mixed,'Content-Type: multipart/mixed; boundary="') && str_contains($mixed,'Content-Type: multipart/alternative; boundary="'), 'HTML mail with an attachment lost a MIME layer');
attachmentCheck(str_contains($mixed, "..leading dot"), 'HTML part is not dot-stuffed when attachments are present');

$stream = fopen('php://temp', 'w+');
$write->invoke($client,$stream,'admin@example.test','','Plain test','No attachment.',array());
rewind($stream); $plain = stream_get_contents($stream); fclose($stream);
attachmentCheck(str_contains($plain, 'Content-Type: text/plain; charset=UTF-8') && !str_contains($plain,'multipart/mixed'), 'Existing plain-text mail changed');
// Exercise the actual SMTP conversation against a loopback-only fake relay.
if(function_exists('pcntl_fork')){
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if(!$server){ throw new RuntimeException($error); }
    $port = (int)substr(strrchr(stream_socket_get_name($server,false), ':'),1);
    $pid = pcntl_fork();
    if($pid === -1){ throw new RuntimeException('fork failed'); }
    if($pid === 0){
        $socket = stream_socket_accept($server,5);
        if(!$socket){ exit(2); }
        stream_set_timeout($socket,5);
        fwrite($socket,"220 fixture ESMTP\r\n");
        $data = false; $payload = '';
        while(($line = fgets($socket)) !== false){
            if($data){
                if($line === ".\r\n"){ file_put_contents($capture,$payload); $data=false; fwrite($socket,"250 queued\r\n"); }
                else { $payload .= $line; }
            }elseif(str_starts_with($line,'DATA')){ $data=true; fwrite($socket,"354 go ahead\r\n"); }
            elseif(str_starts_with($line,'QUIT')){ break; }
            else{ fwrite($socket,"250 OK\r\n"); }
        }
        fclose($socket); fclose($server);
        exit(0);
    }
    fclose($server);
    $smtp = new MailSmtpClient(array('smtpHost'=>'127.0.0.1','smtpPort'=>$port,'fromAddress'=>'site@example.test'),3);
    $sent = $smtp->send('admin@example.test','','Backup','Attached.',array($attachment));
    pcntl_waitpid($pid,$status);
    attachmentCheck($sent, 'SMTP send failed: '.$smtp->lastError);
    $wire = file_get_contents($capture);
    attachmentCheck(preg_match('/Content-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--/s',$wire,$match) === 1, 'SMTP attachment missing');
    attachmentCheck(base64_decode(str_replace("\r\n",'',$match[1]),true) === $bytes, 'SMTP attachment corrupted');
}
echo "PASS: $checks attachment assertions; fake loopback SMTP only\n";
