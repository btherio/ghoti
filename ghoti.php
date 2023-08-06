<?php
/*
 * Created on Mar 1, 2009
 *
 */
include_once('ghoti.ui.php');
include_once('ghoti.db.php');
include_once('ghoti.validate.php');
include_once('ghoti.html.php');

class ghoti {
#################################################################	
### Configure things here...
#################################################################	

	public static $siteTitle = "SmarTEND";	        //title of the website
	public static $defaultPageTitle = "Home"; 		//this page must exist
	public static $defaultTheme = "sinorca";		//default theme
	public static $allowRegister = False; 			//allow or disallow new registrations
 	public static $ghotiLog = "ghoti.log";      	//log file to use. Should be writable by apache
	public static $sessionName = "ghoti-SmarTEND"; 	//change the session name for each installation of GhotiCMS that you have on the server or they will use each others cookies
	public static $headerImg = "gfx/SmarTEND2.png"; //header image to use
	public static $enableThemeChanger = False;      //enable theme changing dropdown
	
################################################################
	public $ghotidb,$ghotiui,$pageList; //php typing practise.

	public function __construct(){
		//construct
		$this->ghotidb = new ghotidb();
		$this->ghotiui = new ghotiui();
		$this->validate = new validate();
		$this->html = new html();
	}
	public function loadModules($modules){
		foreach ($modules as $moduleName){
			include_once "mod/".$moduleName."/".$moduleName.".php";
			include_once "mod/".$moduleName."/".$moduleName.".ajax.php";
		}
	}

	public function printPageMenu($newDiv=True){
		//session_start();
        $this->ghotiui = new ghotiui();
        try{
            $_SESSION["ghotiObj"]->ghotidb = new ghotidb();
            $pageList = $_SESSION["ghotiObj"]->ghotidb->getPageList();
            $pageMenu = $this->ghotiui->printPageMenu($pageList,$newDiv);
        } catch (Exception $e){
            return $e->getMessage();
        }
        return $pageMenu;
	}

	public static function log($line){
		#logs a line to a logfile
		try{
	      $fh = fopen(ghoti::$ghotiLog, 'a') or die("Failed opening ".ghoti::$ghotiLog);
	      fwrite($fh,"[".chop(`date`)."]".$line."\n");
	      fclose($fh);  
	    }catch (Exception $e){
	      return $e->getMessage();
	    }
    	return True;
	}
	function themeChanger(){
        /*opens xml file. parses the xml into an array
        *and uses ghotiui to print a theme changing dropdown box
        */
        if($this::$enableThemeChanger == True){
                $xml = file_get_contents("themes.xml");
                $p = xml_parser_create();//create a parser
                xml_parse_into_struct($p, $xml, $array, $index); //parse the shit
                xml_parser_free($p); //kill the parser
                return $this->ghotiui->printThemeChanger($array);
        } else {
                return ""; //send a blank
        }
    }
}
?>
