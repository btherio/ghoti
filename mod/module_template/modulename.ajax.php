<?php
/*
 * Created on Jan 17, 2021
 * Modulename module sajax code
 */

function addModulename($name,$pin){
	$userId = checkLogin();
	try{
		$_SESSION["ghotiObj"]->validate->checkExists($name);
		$_SESSION["ghotiObj"]->validate->checkExists($pin);
	}catch(Exception $e){
		ghoti::log("Modulename.ajax.php: $e\n");
		return $e->getMessage();
	}
	
	if($_SESSION["ModulenameObj"]->Modulenamedb->checkDupe($name,$pin)){ // returns true if this is a duplicate
        $_SESSION["ghotiObj"]->log("Blocked attempt to add duplicate Modulename $name at $pin");
		return False;
    } else if (!$_SESSION["ModulenameObj"]->Modulenamedb->addModulename($name,$pin)){ //if adding the Modulename to the db fails
        $_SESSION["ghotiObj"]->log("Failed to add Modulename.");		
        return False;
    } else {
        //succss!;
        return True;
    }
    return False;
}
sajax_export("addModulename");

function getModulename(){
//returns list of Modulename from database table,
    return array($_SESSION["ModulenameObj"]->Modulenamedb->getModulename());
}
sajax_export("getModulename");

function deleteModulename($id){
	try{
		$_SESSION["ghotiObj"]->validate->checkNumber($id);
	}catch(Exception $e){
		ghoti::log("Modulename.ajax.php: $e\n");
		return False;
	}
 	$_SESSION["ModulenameObj"]->Modulenamedb->deleteModulename($id);
	return True;
}
sajax_export("deleteModulename");

function saveModulename($id,$name,$pin){
	try{
		$_SESSION["ghotiObj"]->validate->checkNumber($id);
		$_SESSION["ghotiObj"]->validate->checkExists($name);
		$_SESSION["ghotiObj"]->validate->checkExists($pin);
	}catch(Exception $e){
		ghoti::log("Modulename.ajax.php: $e\n");
		return False;
	}
	$_SESSION["ghotiObj"]->log("Saving Modulename($id:$name:$pin)");
	return $_SESSION["ModulenameObj"]->Modulenamedb->modifyModulename($id,$name,$pin);
}
sajax_export("saveModulename");

function switchModulename($id,$pin,$state){
	try{
		$_SESSION["ghotiObj"]->validate->checkNumber($id);
		$_SESSION["ghotiObj"]->validate->checkNumber($pin);
		$_SESSION["ghotiObj"]->validate->checkExists($state);
	}catch(Exception $e){
		ghoti::log("Modulename.ajax.php: $e\n");
		return False;
	}
	return $_SESSION["ModulenameObj"]->switchModulename($id,$pin,$state);
}
sajax_export("switchModulename");
?>
