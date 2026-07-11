<?php
/*
 * Created on Mar 1, 2009
 *
 */
include_once('ghoti.html.php');
include_once('ghoti.async.php'); //async RPC layer + core endpoints + class ghotiui
include_once('ghoti.db.php');
include_once('ghoti.validate.php');

class ghoti {
#################################################################
### Defaults. Most of these are now editable at runtime from the admin menu
### (Admin Menu -> Site Settings); the saved values live in ghoti.settings.json
### and are applied over these defaults by loadSettings(). The two infra
### settings below ($ghotiLog, $sessionName) stay file-only on purpose - see
### the note next to them.
#################################################################

	public static $siteTitle = "ghoti";	        //title of the website          [UI]
	public static $defaultPageTitle = "Home"; 		//this page must exist          [UI]
	public static $defaultTheme = "prosimii";		//default theme                 [UI]
	public static $allowRegister = True; 			//allow or disallow new registrations [UI]
	public static $headerImg = "gfx/ghoti-5s.png"; //header image to use            [UI]
	public static $enableThemeChanger = True;      //enable theme changing dropdown [UI]
	public static $enableDebug = False;            //enable debug logging           [UI]

	// Not exposed in the UI on purpose:
	//  - $ghotiLog is an arbitrary filesystem path (letting the browser set it
	//    would be an arbitrary-file-write primitive).
	//  - $sessionName is read in index.php before settings load, and changing it
	//    logs everyone out; it is a per-install infra choice.
 	public static $ghotiLog = "ghoti.log";      	//log file to use. Should be writable by apache
	public static $sessionName = "ghoti"; 	//change the session name for each installation of GhotiCMS that you have on the server or they will use each others cookies

	//Untracked, per-install file that stores admin-edited settings (see .gitignore).
	public static $settingsFile = "ghoti.settings.json";

	//Keys that Site Settings manages, with their type. Drives load + save.
	private static $settingsSchema = array(
		'siteTitle'          => 'text',
		'defaultPageTitle'   => 'text',
		'defaultTheme'       => 'theme',
		'headerImg'          => 'path',
		'allowRegister'      => 'bool',
		'enableThemeChanger' => 'bool',
		'enableDebug'        => 'bool',
	);

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
			include_once "mod/".$moduleName."/".$moduleName.".async.php";
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
	      //Use PHP's date() instead of shelling out to `date` - the backtick spawned
	      //a process on every log line (slow), and on Windows `date` blocks waiting
	      //for interactive input, hanging the whole request.
	      fwrite($fh,"[".date('D M j g:i:s A T Y')."]".$line."\n");
	      fclose($fh);
	    }catch (Exception $e){
	      return $e->getMessage();
	    }
    	return True;
	}
	public static function debug($line){
		#logs a  debug line to a logfile if enabled
		if(ghoti::$enableDebug == True){
			try{
				$fh = fopen(ghoti::$ghotiLog, 'a') or die("Failed opening ".ghoti::$ghotiLog);
				fwrite($fh,"[".date('D M j g:i:s A T Y')."]DEBUG:".$line."\n");
				fclose($fh);
			  }catch (Exception $e){
				return $e->getMessage();
			  }
		}
    	return True;
	}
	/* ---------------- Site Settings (admin-editable) ---------------- */

	//Absolute path to the settings file, resolved next to this file.
	public static function settingsPath(){
		return __DIR__.'/'.self::$settingsFile;
	}

	//The current values of the UI-managed settings, as an associative array.
	public static function currentSettings(){
		$out = array();
		foreach(self::$settingsSchema as $key => $type){
			$out[$key] = self::$$key; //read the matching static property
		}
		return $out;
	}

	//Apply saved settings (if any) over the compiled-in defaults. Safe to call
	//more than once; called once early in index.php.
	public static function loadSettings(){
		$file = self::settingsPath();
		if(!is_file($file)){ return; }
		$data = json_decode(@file_get_contents($file), true);
		if(!is_array($data)){ return; }
		foreach(self::$settingsSchema as $key => $type){
			if(!array_key_exists($key, $data)){ continue; }
			$value = self::sanitizeSetting($type, $data[$key]);
			if($value !== null){ self::$$key = $value; }
		}
	}

	//Validate + persist admin-submitted settings. Returns true or an error string.
	public static function saveSettings($settings){
		if(!is_array($settings)){ return "Invalid settings."; }

		//Give explicit feedback for a bad theme rather than silently ignoring it.
		if(isset($settings['defaultTheme']) && $settings['defaultTheme'] !== ''
			&& !self::isValidTheme($settings['defaultTheme'])){
			return "That theme doesn't exist.";
		}

		$clean = self::currentSettings(); //start from what's active, override per key
		foreach(self::$settingsSchema as $key => $type){
			if(!array_key_exists($key, $settings)){ continue; }
			$value = self::sanitizeSetting($type, $settings[$key]);
			if($value !== null){ $clean[$key] = $value; }
		}

		$json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if($json === false){ return "Could not encode settings."; }
		if(@file_put_contents(self::settingsPath(), $json, LOCK_EX) === false){
			ghoti::log("ghoti.php saveSettings: could not write ".self::settingsPath());
			return "Could not write the settings file. Check that it is writable by the web server.";
		}
		ghoti::log("Site settings updated by UID:".($_SESSION['userId'] ?? '?')." from ".($_SERVER['REMOTE_ADDR'] ?? ''));
		self::loadSettings(); //reflect immediately for the rest of this request
		return true;
	}

	//Coerce/validate one value for its type. Returns the clean value, or null to
	//skip (e.g. an invalid theme).
	private static function sanitizeSetting($type, $value){
		switch($type){
			case 'bool':
				return (bool)(is_string($value) ? ($value !== '' && $value !== '0' && strtolower($value) !== 'false') : $value);
			case 'theme':
				return self::isValidTheme($value) ? (string)$value : null;
			case 'path':
				//Used as an <img src> in themes (printed unescaped), so strip
				//anything that could break out of the attribute or inject markup.
				$v = str_replace(array('<','>','"',"'","\\","\r","\n"," "), '', (string)$value);
				$v = trim($v);
				return substr($v, 0, 200);
			case 'text':
			default:
				$v = strip_tags((string)$value);
				$v = str_replace(array('"',"\r","\n"), '', $v);
				return trim(substr($v, 0, 120));
		}
	}

	//A theme is valid only if it is a safe name AND its stylesheet loader exists.
	public static function isValidTheme($theme){
		return is_string($theme)
			&& preg_match('/^[A-Za-z0-9_-]+$/', $theme)
			&& is_file(__DIR__."/css/".$theme."/".$theme.".php");
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
