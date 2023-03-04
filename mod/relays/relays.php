<?php
/*
 * Created on Jan 17, 2021
 * relays Module for raspi wired relays 
 * requires install of 'gpio' from wiringpi package
 *
 */
include_once('relays.db.php');
class relays{
	public $relaysdb;
	public function __construct(){
		$this->relaysdb = new relaysdb();
		}
		
    public function switchRelay($id,$pin,$state="off",$address){
        if($pin == 1){ // if pin is 1 then we have a wifi relay to switch
            ghoti::log("Switching wifi relay at ". $address ." to ". $state ."...");
            try{
                if(shell_exec("wget http://$address/relay/$state >> /dev/null") == 0){//if result is positive (ie, we have successfully switched relay pin
                //save state to database
                ghoti::log("Saving relay state to database: ".$id.":".$state."...");
                $this->relaysdb->saveRelayState($id,$state);
                } else {
                    ghoti::log("failed to switch relay state");
                }
            }catch(Exception $e){
                $this->ghoti->log($e->getMessage());
                return False;
            }
        } else { // if pin is not 1 we have a hardwired gpio relay
            //exec gpio-relay.sh $pin $state
            ghoti::log("Switching pin ". $pin ." to ". $state ."...");
            try{
                if(shell_exec("/srv/http/smartent/mod/relays/gpio-relay.sh $pin $state >> /dev/null") == 0){ //if result is positive (ie, we have successfully switched relay pin
                    //save state to database
                    ghoti::log("Saving relay state to database: ".$id.":".$state."...");
                    $this->relaysdb->saveRelayState($id,$state);
                } else {
                    ghoti::log("failed to switch relay state");
                }
                return True;
            } catch(Exception $e){
                $this->ghoti->log($e->getMessage());
                return False;
            }
        }

    }
}
?>
