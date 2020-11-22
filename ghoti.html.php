<?php
/*
 *Created July 20, 2010.
 *
 */
//This can't be a good idea...
class html{
	public static function divStart($id=null,$class=null){
		if(!$id){
			return "<div class=\"$class\">\n";	
		}else if(!$class){
			return "<div id=\"$id\">\n";
		}else if(!$id && !$class){
			return "<div>\n";
		}else{
			return "<div id=\"$id\" class=\"$class\">\n";	
		}
	}
	public static function divEnd(){
		return "</div>\n";
	}
}
?>