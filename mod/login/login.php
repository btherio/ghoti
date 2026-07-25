<?php
/*
 * Created on Apr 3, 2009
 */
include_once('login.db.php');
include_once('login.async.php'); //endpoints + class loginui
class login{
	public function __construct(){
		$this->logindb = new logindb();
		$this->loginui = new loginui();
	}
}
?>
