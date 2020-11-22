<?php
/*
 * Created on Apr 8, 2009
 */

class validate{
	public function checkEmail($email){
        //eregi is deprecated, i guess this is what happens when you have a 10 year gap in development activity.
        //if(!eregi("^[a-z0-9]+([-_\.]?[a-z0-9])+@[a-z0-9]+([-_\.]?[a-z0-9])+\.[a-z]{2,4}", $email))
        
        
        try{
            if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            throw new Exception("Invalid email address");
        }catch (Exception $e){
            ghoti::log("$e");
        }
	}
  public function checkExists($var){
      if(empty($var))
        throw new Exception("Required field missing.");
  }
  public function checkNumber($var){
    if(!is_numeric($var))
      throw new Exception("Input must be numeric.");
  }
}
?>
