<?php
/*
 * Created on Jan 17, 2021
 * relays module sajax code
 */

function addRelay($name,$pin){
	$userId = checkLogin();
	try{
		$_SESSION["ghotiObj"]->validate->checkExists($name);
		$_SESSION["ghotiObj"]->validate->checkExists($pin);
	}catch(Exception $e){
		ghoti::log("relays.ajax.php: $e\n");
		return $e->getMessage();
	}
	
	if($_SESSION["relaysObj"]->relaysdb->checkDupe($name,$pin)){ // returns true if this is a duplicate
        $_SESSION["ghotiObj"]->log("Blocked attempt to add duplicate Relay $name at $pin");
		return False;
    } else if (!$_SESSION["relaysObj"]->relaysdb->addRelay($name,$pin)){ //if adding the Relay to the db fails
        $_SESSION["ghotiObj"]->log("Failed to add Relay.");		
        return False;
    } else {
        //succss!;
        return True;
    }
    return False;
}
sajax_export("addRelay");

function getRelays(){
//returns list of relays from database table,
    return array($_SESSION["relaysObj"]->relaysdb->getRelays());
}
sajax_export("getRelays");

function getRelayById($id,$index=0){
//returns list of relays from database table,
    if($index > 0){
        return array($index, $_SESSION["relaysObj"]->relaysdb->getRelayById($id));
    }else{
        return array($_SESSION["relaysObj"]->relaysdb->getRelayById($id));
    }
}
sajax_export("getRelayById");

function deleteRelay($id){
	try{
		$_SESSION["ghotiObj"]->validate->checkNumber($id);
	}catch(Exception $e){
		ghoti::log("relays.ajax.php: $e\n");
		return False;
	}
 	$_SESSION["relaysObj"]->relaysdb->deleteRelay($id);
	return True;
}
sajax_export("deleteRelay");

function saveRelay($id,$name,$pin){
	try{
		$_SESSION["ghotiObj"]->validate->checkNumber($id);
		$_SESSION["ghotiObj"]->validate->checkExists($name);
		$_SESSION["ghotiObj"]->validate->checkExists($pin);
	}catch(Exception $e){
		ghoti::log("relays.ajax.php: $e\n");
		return False;
	}
	$_SESSION["ghotiObj"]->log("Saving Relay($id:$name:$pin)");
	return $_SESSION["relaysObj"]->relaysdb->modifyRelay($id,$name,$pin);
}
sajax_export("saveRelay");

function switchRelay($id,$pin,$state){
	try{
		$_SESSION["ghotiObj"]->validate->checkNumber($id);
		$_SESSION["ghotiObj"]->validate->checkNumber($pin);
		$_SESSION["ghotiObj"]->validate->checkExists($state);
	}catch(Exception $e){
		ghoti::log("relays.ajax.php: $e\n");
		return False;
	}
	return $_SESSION["relaysObj"]->switchRelay($id,$pin,$state);
}
sajax_export("switchRelay");
?>
