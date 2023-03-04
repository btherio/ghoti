<?php
/*
 * Created on Jan 17, 2021
 * relays module sajax code
 */

function addRelay($name,$pin,$address="0"){
	$userId = checkLogin();
	try{
		$_SESSION["ghotiObj"]->validate->checkExists($name);
		if($pin == 1){
			$_SESSION["ghotiObj"]->validate->checkExists($address);
		} else {
			$_SESSION["ghotiObj"]->validate->checkExists($pin);
		}
			if($_SESSION["relaysObj"]->relaysdb->checkDupe($name,$address)){ // returns true if this is a duplicate
				$_SESSION["ghotiObj"]->log("Blocked attempt to add duplicate Relay $name at $address");
				throw new Exception('Duplicate name or address detected.');
			} else if (!$_SESSION["relaysObj"]->relaysdb->addRelay($name,$pin,"off",$address)){ //if adding the Relay to the db fails
				$_SESSION["ghotiObj"]->log("Failed to add Relay.");
				throw new Exception('Failed to add Relay.');
			} else {
				//succss!;
				return True;
			}
	}catch(Exception $e){
		ghoti::log("relays.ajax.php: $e\n");
		return $e->getMessage();
	}
	

    return False;
}
sajax_export("addRelay");

function getRelays($limit){
//returns list of relays from database table,
    return array($_SESSION["relaysObj"]->relaysdb->getRelays($limit));
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

function getRelayNameByPin($pin,$index=0){
//returns list of relays from database table,
    if($index > 0){
        return array($index, $_SESSION["relaysObj"]->relaysdb->getRelayNameByPin($pin));
    }else{
        return array($_SESSION["relaysObj"]->relaysdb->getRelayNameByPin($pin));
    }
}
sajax_export("getRelayNameByPin");

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

function saveRelay($id,$name,$pin,$address){
	try{
		$_SESSION["ghotiObj"]->validate->checkNumber($id);
		$_SESSION["ghotiObj"]->validate->checkExists($name);
		$_SESSION["ghotiObj"]->validate->checkExists($pin);
		$_SESSION["ghotiObj"]->validate->checkExists($address);
	}catch(Exception $e){
		ghoti::log("relays.ajax.php: $e\n");
		return False;
	}
	$_SESSION["ghotiObj"]->log("Saving Relay($id:$name:$pin:$address)");
	return $_SESSION["relaysObj"]->relaysdb->modifyRelay($id,$name,$pin,$address);
}
sajax_export("saveRelay");

function switchRelay($id,$pin,$state,$address){
	try{
		$_SESSION["ghotiObj"]->validate->checkNumber($id);
		$_SESSION["ghotiObj"]->validate->checkNumber($pin);
		$_SESSION["ghotiObj"]->validate->checkExists($state);
		$_SESSION["ghotiObj"]->validate->checkExists($address);
	}catch(Exception $e){
		ghoti::log("relays.ajax.php: $e\n");
		return False;
	}
	return $_SESSION["relaysObj"]->switchRelay($id,$pin,$state,$address);
}
sajax_export("switchRelay");
?>
