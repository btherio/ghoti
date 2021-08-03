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
		
    public function switchRelay($id,$pin,$state="off"){
        //exec gpio-relay.sh $pin $state
        try{
            ghoti::log("Switching pin ". $pin ." to ". $state ."...");
            
            if(shell_exec("/srv/http/ghotiCMS/mod/relays/gpio-relay.sh $pin $state") == 0){ //if result is positive (ie, we have successfully switched relay pin
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
?>
