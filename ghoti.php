<?php
/*
 * Created on Mar 1, 2009
 *
 */
include_once('ghoti.html.php');
include_once('ghoti.async.php'); //async RPC layer + core endpoints + class ghotiui
include_once('ghoti.db.php');
include_once('ghoti.validate.php');
include_once('ghoti.setup.php'); //DB-unreachable fallback: setup screen + saveDbConfig

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
	public static $defaultTheme = "ghoticms";		//default theme                 [UI]
	public static $allowRegister = True; 			//allow or disallow new registrations [UI]
	public static $headerImg = "gfx/ghoti-5s.png"; //header image to use            [UI]
	public static $enableThemeChanger = True;      //enable theme changing dropdown [UI]
	public static $enableDebug = False;            //enable debug logging           [UI]

	/* ---------------- Logging levels ----------------
	 * Higher number = more severe. A line is written only when its level is
	 * >= the effective threshold (DEBUG when $enableDebug is on, INFO
	 * otherwise) - so ERROR/WARN/INFO always show up, DEBUG is opt-in noise.
	 * $enableDebug stays the single admin-facing switch on purpose (no new
	 * Site Settings UI needed); these constants just make call sites and log
	 * output self-describing instead of everything looking like one bucket. */
	const LOG_LEVEL_DEBUG = 10;
	const LOG_LEVEL_INFO  = 20;
	const LOG_LEVEL_WARN  = 30;
	const LOG_LEVEL_ERROR = 40;
	private static $levelNames = array(
		self::LOG_LEVEL_DEBUG => 'DEBUG',
		self::LOG_LEVEL_INFO  => 'INFO',
		self::LOG_LEVEL_WARN  => 'WARN',
		self::LOG_LEVEL_ERROR => 'ERROR',
	);

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

	//Rotate ghoti.log when it exceeds 5MB: shift .1 -> .2, current -> .1. Best
	//effort - the log is a diagnostic, never a reason to fail a request.
	private static function rotateLogIfNeeded(){
		$path = ghoti::$ghotiLog;
		if(!is_file($path)){ return; }
		clearstatcache(true, $path);
		if(@filesize($path) < 5 * 1024 * 1024){ return; }
		for($i = 2; $i >= 1; $i--){
			$from = $path.'.'.$i;
			$to   = $path.'.'.($i + 1);
			if(is_file($from)){ @rename($from, $to); }
		}
		if(is_file($path)){ @rename($path, $path.'.1'); }
	}

	public static function log($line){
		#logs a line to a logfile (kept for backward compatibility - treated as INFO)
		return self::writeLog(self::LOG_LEVEL_INFO, null, $line);
	}
	public static function debug($line){
		#logs a debug line to a logfile if enabled (kept for backward compatibility)
		return self::writeLog(self::LOG_LEVEL_DEBUG, null, $line);
	}

	/* ---------------- Leveled logging core ----------------
	 * Every log/debug/warn/error call in the codebase should route through
	 * writeLog() (directly or via the convenience wrappers below) so format,
	 * rotation, and the debug on/off switch stay in exactly one place. */

	//Convenience wrappers. $context is an optional string identifying the
	//call site (module/function), so log lines are greppable by origin
	//without hand-prefixing every message (e.g. "login.db.php:authenticate").
	public static function logInfo($context, $line){ return self::writeLog(self::LOG_LEVEL_INFO, $context, $line); }
	public static function logWarn($context, $line){ return self::writeLog(self::LOG_LEVEL_WARN, $context, $line); }
	public static function logError($context, $line){ return self::writeLog(self::LOG_LEVEL_ERROR, $context, $line); }
	public static function logDebug($context, $line){ return self::writeLog(self::LOG_LEVEL_DEBUG, $context, $line); }

	//Format + log a caught exception/Throwable in one call, including its
	//class and (in debug mode) a compact stack trace - callers no longer
	//need to hand-roll "$e->getMessage()" string building at every catch site.
	public static function logException($context, Throwable $e, $extra = ''){
		$line = get_class($e).': '.$e->getMessage().($extra !== '' ? ' ('.$extra.')' : '');
		self::writeLog(self::LOG_LEVEL_ERROR, $context, $line);
		if(self::$enableDebug){
			self::writeLog(self::LOG_LEVEL_DEBUG, $context, "trace: ".str_replace("\n", ' | ', $e->getTraceAsString()));
		}
	}

	//Single choke point every log line passes through. Handles the
	//debug-gate, rotation, context tag, and actual file write.
	private static function writeLog($level, $context, $line){
		//DEBUG-level lines are silently dropped unless debug logging is on -
		//this is the "debug logging" half of the architecture: call sites
		//don't need their own if(enableDebug) checks.
		if($level === self::LOG_LEVEL_DEBUG && self::$enableDebug !== True){
			return True;
		}
		$levelName = isset(self::$levelNames[$level]) ? self::$levelNames[$level] : 'INFO';
		$prefix = $levelName;
		if($context !== null && $context !== ''){
			$prefix .= ' ['.$context.']';
		}
		try{
			self::rotateLogIfNeeded();
			$fh = fopen(ghoti::$ghotiLog, 'a') or die("Failed opening ".ghoti::$ghotiLog);
			//Use PHP's date() instead of shelling out to `date` - the backtick spawned
			//a process on every log line (slow), and on Windows `date` blocks waiting
			//for interactive input, hanging the whole request.
			fwrite($fh,"[".date('D M j g:i:s A T Y')."] ".$prefix.": ".$line."\n");
			fclose($fh);
		}catch (Exception $e){
			return $e->getMessage();
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
			ghoti::logError("ghoti.php:saveSettings", "could not write ".self::settingsPath());
			return "Could not write the settings file. Check that it is writable by the web server.";
		}
		ghoti::logInfo("ghoti.php:saveSettings", "Site settings updated by UID:".($_SESSION['userId'] ?? '?')." from ".($_SERVER['REMOTE_ADDR'] ?? ''));
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
