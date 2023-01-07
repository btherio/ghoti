<?php
/*
 * Created on Dec 20,2020
 * Sensors Module for nodemcu/d1mini dht/thermistor sensors 
 *
 */
include_once('sensors.db.php');
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
require_once 'lib/PHPMailer/src/Exception.php';
require_once 'lib/PHPMailer/src/PHPMailer.php';
require_once 'lib/PHPMailer/src/SMTP.php';

class sensors{
	public $sensorsdb;
    public int $checkAP;
    public string $listAP;
    
	public function __construct(){
		$this->sensorsdb = new sensorsdb();
		}
	
	##checks for running AP and Lists clients
    public function checkAP(){
        // $checkAP = 1; //for testing only
        try{
            // system("/usr/bin/create_ap --list-running | grep -c wlan0",$checkAP);
            $checkAP = shell_exec("ps aux|grep -c [c]reate_ap");
        }catch(exception $e){
            die("EXECUTION ERROR $e");
        }
        if($checkAP > 0){
            return true; //at least one instance running
        }else{
            return false; //no instances running...
        }
    }
    public function listAP(){
        //Lists hosts connected to this machine's AP
        // this relies on root cron entry like:  
        //   */5 * * * * cp -R /tmp/create_ap.wlan0.conf.*/dnsmasq.leases /srv/http/smartent/mod/sensors/listAP.leases
        //
        
        //$listAP = "test listing....";
        try{
            exec('cat mod/sensors/listAP.leases',$listAP);
        } catch(exception $e){
           die("EXECUTION ERROR $e");
        }
        return $listAP;
    }


    public function sendAlarm($name,$data,$setpoint,$id,$alarmType){
        $smtpEmail = 'btherio@gmail.com';
        $smtpPW = 'INSERT PASSWORD HERE';
        $smtpHost = 'smtp.gmail.com';
        $smtpPort = 465;
        $alarmAddress = "7802356747@msg.telus.com";
        
        if($alarmType == "911"){

            ghoti::log("Attempting to send alarm email to: $alarmAddress \n");

            //Create a new PHPMailer instance
            $mail = new PHPMailer(True);
            //Tell PHPMailer to use SMTP
            $mail->isSMTP();
            //Enable SMTP debugging
            //SMTP::DEBUG_OFF = off (for production use)
            //SMTP::DEBUG_CLIENT = client messages
            //SMTP::DEBUG_SERVER = client and server messages
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            //Set the hostname of the mail server
            $mail->Host = $smtpHost;
            //Set the SMTP port number - likely to be 25, 465 or 587
            $mail->Port = $smtpPort;
            //ssl
            $mail->SMTPSecure = "ssl";
            //Whether to use SMTP authentication
            $mail->SMTPAuth = TRUE;
            //Username to use for SMTP authentication
            $mail->Username = $smtpEmail;
            //Password to use for SMTP authentication
            $mail->Password = $smtpPW;
            //Set who the message is to be sent from
            $mail->setFrom($smtpEmail, 'SmarTEND');
            //Set an alternative reply-to address
            $mail->addReplyTo($smtpEmail, 'SmarTEND');
            //Set who the message is to be sent to
            $mail->addAddress($alarmAddress, '');
            //Set the subject line
            $mail->Subject = 'SmarTEND';
            $mail->Body = "Alarm: Currently $name has exceeded limit of $setpoint at $data.";


            try{
                //send the message, check for errors
                if (!$mail->send()) {
                    ghoti::log( "Mailer Error: " . $mail->ErrorInfo . "\n" );
                } else {
                    ghoti::log( "Alarm sent for $name!\n" );
                    //mark setpoint as alarm-sent, to be reset by cron at scheduled intervals. ie: send alarm once per hour or once per day
                    $this->sensorsdb = new sensorsdb();
                    $this->sensorsdb->setAlarmSent($id,$setpoint);
                    $this->sensorsdb->__destruct;
                }
            } catch (Exception $e){
                /* PHPMailer exception. */
                ghoti::log($e->errorMessage());
            } catch (\Exception $e) {
                /* PHP exception (note the backslash to select the global namespace Exception class). */
                ghoti::log($e->getMessage());
            }
        } else {
        //must be 922, ignore for now
        //ghoti::log("922 alarm received, already sent");
        }
    }
}
?>
