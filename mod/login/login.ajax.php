<?php
/*
 * Created on Apr 3, 2009
 */
function checkGetLogin(){
//  if($_GET && isset($_GET['login'])){
//		//return True;
//	} else { return false; }
	return false;
}
function login($username,$password){
 	ghoti::log("Login attempt($username) from ".$_SERVER['REMOTE_ADDR']."");
	$id = $_SESSION["loginObj"]->logindb->authenticate($username,$password);
	return $id;
}

function addUser($username,$email,$password){
  try{
    $_SESSION["ghotiObj"]->validate->checkExists($username);
    $_SESSION["ghotiObj"]->validate->checkEmail($email);
  }catch (Exception $e) {
    ghoti::log($e->getMessage());
    return $e->getMessage();
    }
  
	if($_SESSION["loginObj"]->logindb->checkDuplicate($username,$email))
		return "Username or Email is already registered!";
	if($_SESSION["loginObj"]->logindb->addUser($username,$password,$email)){
		ghoti::log("Registered $username from ".$_SERVER['REMOTE_ADDR']."");
		return True; //This is good.
	}else
		return "Error!";
}

function saveUser($name,$email,$id){
  try{
    $_SESSION["ghotiObj"]->validate->checkExists($name);
    $_SESSION["ghotiObj"]->validate->checkEmail($email);
		$_SESSION["ghotiObj"]->validate->checkNumber($id);
  }catch (Exception $e) {
    return $e->getMessage();
  }
	ghoti::log("Attempting to save user info for $name from ".$_SERVER['REMOTE_ADDR']."");
	return $_SESSION["loginObj"]->logindb->updateUser($id,$name,$email);
}

function deleteUser($id){
	if($id == 0){ //if we are passed a zero, just delete the logged in user.
		$id = $_SESSION['userId'];	
	}
	ghoti::log("Attempting to delete userID: $id from ".$_SERVER['REMOTE_ADDR']."");
	return $_SESSION["loginObj"]->logindb->deleteUser($id);
}

function setSessionVars($id){
	ghoti::log("Logged in USER($id) from ".$_SERVER['REMOTE_ADDR']."");
	try{
		$_SESSION["loggedIn"] = true;
		$_SESSION["userId"] = $id;
		if(isAdmin($id)){
			ghoti::log("Logged in ADMIN($id) from ".$_SERVER['REMOTE_ADDR']."");
			$_SESSION["admin"] = true;	
		}
	}catch (exception $e){
			ghoti::log("[Exception] $e");
			return false;
	}
	//session_write_close();
	return true;	
}
function changePassword($password,$newPassword){
	$userName = $_SESSION["loginObj"]->logindb->getUserNameById($_SESSION["userId"]);
  try{
    $_SESSION["ghotiObj"]->validate->checkExists($userName);
    $_SESSION["ghotiObj"]->validate->checkExists($password);
    $_SESSION["ghotiObj"]->validate->checkExists($newPassword);
    }catch (Exception $e) {
    return $e->getMessage();
  }
	
	ghoti::log("Change password for $userName from ".$_SERVER['REMOTE_ADDR'].".");
	$id = $_SESSION["loginObj"]->logindb->authenticate($userName,$password);
	if ($id > 0){ //success
		return $_SESSION["loginObj"]->logindb->changePassword($id,$newPassword);
	}else{
		ghoti::log("Auth failed for user ".$id."(".$_SERVER['REMOTE_ADDR'].") trying to change password");
		return False;
	}
}
function logout(){
	try{
	    $_SESSION['userId'] = 0;
	    $_SESSION['loggedIn'] = false;
		unset($_SESSION['loggedIn']);
		unset($_SESSION['userId']);
		unset($_SESSION['admin']);
		
        $_SESSION['userId'] = 0;
	    $_SESSION['loggedIn'] = false;
		
		session_unset();
		unset($_GET["login"]);
		session_destroy();
		session_unset();
		unset($_COOKIE[ghoti::$sessionName]);
		//setcookie(ghoti::$sessionName, FALSE, time() - 3600);
		session_write_close();
	 }catch (Exception $e) {
    return $e->getMessage();
  	}
	return true;
}
function isAdmin($id){
	return $_SESSION["loginObj"]->logindb->isAdmin($id);
}
function printAdminMenu(){
	return $_SESSION["loginObj"]->loginui->printAdminMenu();
}
function printManageUserForm(){
	$userList = $_SESSION["loginObj"]->logindb->getUserList();
	return $_SESSION["loginObj"]->loginui->printManageUserForm($userList);
}
function printLoginForm(){
	return $_SESSION["loginObj"]->loginui->printLoginForm();
}
function toggleAdmin($id){
  ghoti::log("Toggling admin status for userID: $id from ".$_SERVER['REMOTE_ADDR']."");
	return $_SESSION["loginObj"]->logindb->toggleAdmin($id);
}	
function checkLogin(){
    //if(isset($_SESSION["loggedIn"]) && $_SESSION["loggedIn"] == true && isset($_SESSION["userId"]) && $_SESSION["userId"] > 0){
    if($_SESSION["loggedIn"] == true && $_SESSION["userId"] > 0){
		ghoti::log("Checking login, found uid" .$_SESSION["userId"]."");
	    return $_SESSION["userId"];   
    }
	else
		return false;
}
function getLoggedInId(){
	return $_SESSION['userId'];			
}
function printSystemMenu(){
	return $_SESSION["loginObj"]->loginui->printSystemMenu();
}
function printRegisterForm(){
	return $_SESSION["loginObj"]->loginui->printRegisterForm();
}
sajax_export("checkGetLogin");
sajax_export("addUser");
sajax_export("changePassword");
sajax_export("checkLogin");
sajax_export("deleteUser");
sajax_export("getLoggedInId");
sajax_export("isAdmin");
sajax_export("login");
sajax_export("logout");
sajax_export("printAdminMenu");
sajax_export("printManageUserForm");
sajax_export("printLoginForm");
sajax_export("printSystemMenu");
sajax_export("printRegisterForm");
sajax_export("setSessionVars");
sajax_export("saveUser");
sajax_export("toggleAdmin");
?>
