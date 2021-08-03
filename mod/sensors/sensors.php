<?php
/*
 * Created on Dec 20,2020
 * Sensors Module for nodemcu/d1mini dht/thermistor sensors 
 *
 */
include_once('sensors.db.php');
class sensors{
	public $sensorsdb;
    public int $checkAP;
    public string $listAP;
    
	public function __construct(){
		$this->sensorsdb = new sensorsdb();
		}
	
	##checks for running AP and Lists clients

    
    public function checkAP(){
        //$checkAP = 1; //for testing only
        try{
            //system("/usr/bin/create_ap --list-running | grep -c wlan0",$checkAP);
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
        $listAP = "test listing....";
        try{
            //system('/usr/bin/create_ap --list-clients wlan0',$listAP);
            exec('cat mod/sensors/listAP.leases',$listAP);
           // $contents = file_get_contents("/tmp/create_ap.wlan0.conf.current/dnsmasq.leases");
           // $contents = explode("\n", $contents);
           // $listAP = $contents;
        } catch(exception $e){
           die("EXECUTION ERROR $e");
        }
        return $listAP;
    }
}
?>
