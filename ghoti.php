<?php
/*
 * Created on Mar 1, 2009
 *
 */
include_once('ghoti.async.php'); //async RPC layer + core endpoints + class ghotiui
include_once('ghoti.db.php');
include_once('ghoti.validate.php');
include_once('ghoti.privacy.php');
include_once(__DIR__.'/ghoti.security.php');
include_once(__DIR__.'/ghoti.alerts.php');
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
	public static $allowRegister = False; 			//allow or disallow new registrations [UI]
	public static $backgroundImg = ""; //shared theme background; blank uses theme defaults
	public static $headerImg = "gfx/ghoti-logo.png"; //header image to use            [UI]
	public static $enableThemeChanger = True;      //enable theme changing dropdown [UI]
	public static $privacyOperator = "";
	public static $privacyRegion = "";
	public static $hideLoginButton = False;       //hide public sign-in links      [UI]
	public static $showHelpTips = True;           //contextual admin how-to panels [UI]
	public static $securityAutoBlacklist = True;  //persistently block abusive IPs [UI]
	public static $securityFailedLoginThreshold = 10;
	public static $securityFailureWindowMinutes = 15;
	public static $securityBlacklistDurationMinutes = 1440;
	public static $securityIpBlacklist = "";      //manual IP/CIDR deny rules       [UI]
	public static $securityIpAllowlist = "";      //automatic-block exemptions      [UI]
	public static $sessionTimeoutMinutes = 30;    //authenticated idle timeout       [UI]
	public static $enableTwoFactor = False;       //emailed sign-in codes for admins [UI]
	public static $twoFactorAllUsers = False;     //...and for ordinary members too [UI]
	public static $enableBoards = False;          //optional message-board module [UI]
	public static $enableVhosts = False;          //optional privileged module [UI]
	public static $enableStore = False;           //optional shop module        [UI]
	public static $enableBpong = False;           //optional pong module        [UI]
	public static $enableCriticalAlerts = False;
	public static $enableDebug = False;            //enable debug logging           [UI]
	//Search and sharing (Site Settings -> SEO; see ghoti.seo.php).
	public static $seoSiteUrl = "";               //https base URL; blank uses GHOTI_PUBLIC_URL [UI]
	public static $seoHomeTitle = "";             //home page <title>; blank uses the site title [UI]
	public static $seoTitleFormat = "{page} | {site}"; //other pages' <title>  [UI]
	public static $seoDescription = "";           //meta description           [UI]
	public static $seoKeywords = "";              //meta keywords              [UI]
	public static $seoShareImage = "";            //og:image, a local image path [UI]
	public static $seoTwitterHandle = "";         //twitter:site               [UI]
	public static $seoAllowIndexing = True;       //off = noindex everywhere   [UI]
	public static $seoStructuredData = True;      //JSON-LD WebSite/Organization [UI]
	public static $seoGoogleVerification = "";    //Search Console token       [UI]
	public static $seoBingVerification = "";      //Bing Webmaster token       [UI]
	public static $seoRobotsExtra = "";           //extra robots.txt rules     [UI]

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
		'backgroundImg'      => 'background',
		'allowRegister'      => 'bool',
		'enableThemeChanger' => 'bool',
		'hideLoginButton'    => 'bool',
		'showHelpTips'       => 'bool',
		'securityAutoBlacklist' => 'bool',
		'securityFailedLoginThreshold' => 'int',
		'securityFailureWindowMinutes' => 'int',
		'securityBlacklistDurationMinutes' => 'int',
		'securityIpBlacklist' => 'ip_list',
		'securityIpAllowlist' => 'ip_list',
		'sessionTimeoutMinutes' => 'int',
		'privacyOperator'    => 'text',
		'privacyRegion'      => 'text',
		'enableDebug'        => 'bool',
		'enableTwoFactor'    => 'bool',
		'twoFactorAllUsers'  => 'bool',
		'enableBoards'       => 'bool',
		'enableVhosts'       => 'bool',
		'enableStore'        => 'bool',
		'enableBpong'        => 'bool',
		'enableCriticalAlerts' => 'bool',
		'seoSiteUrl'         => 'url',
		'seoHomeTitle'       => 'text',
		'seoTitleFormat'     => 'text',
		'seoDescription'     => 'longtext',
		'seoKeywords'        => 'longtext',
		'seoShareImage'      => 'background',
		'seoTwitterHandle'   => 'handle',
		'seoAllowIndexing'   => 'bool',
		'seoStructuredData'  => 'bool',
		'seoGoogleVerification' => 'token',
		'seoBingVerification'   => 'token',
		'seoRobotsExtra'     => 'robots',
	);

################################################################
	public $ghotidb,$ghotiui,$pageList; //php typing practise.

	public function __construct(){
		//construct
		$this->ghotidb = new ghotidb();
		$this->ghotiui = new ghotiui();
		$this->validate = new validate();
	}
	public static function enabledModules(){
		$modules = array('links','login','banners','analytics','gallery','filemanager','mail');
		if(self::$enableBoards){ $modules[] = 'boards'; }
		if(self::$enableVhosts){ $modules[] = 'vhosts'; }
		if(self::$enableStore){ $modules[] = 'store'; }
		if(self::$enableBpong){ $modules[] = 'bpong'; }
		return $modules;
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
		//One entry, one line. Callers interpolate what visitors typed (a login
		//name, a path), and a CR/LF in it would otherwise let a signed-out visitor
		//forge whole entries - which the Errors tab and critical alerts read back.
		//Tabs survive; every other control character becomes a space.
		$context = preg_replace('/[\x00-\x08\x0A-\x1F\x7F]+/', ' ', (string)$context);
		$line = preg_replace('/[\x00-\x08\x0A-\x1F\x7F]+/', ' ', (string)$line);
		$levelName = isset(self::$levelNames[$level]) ? self::$levelNames[$level] : 'INFO';
		$prefix = $levelName;
		if($context !== null && $context !== ''){
			$prefix .= ' ['.$context.']';
		}
		try{
			self::rotateLogIfNeeded();
			$fh = @fopen(ghoti::$ghotiLog, 'a');
			if(!$fh){ throw new RuntimeException('Application log is not writable'); }
			//Use PHP's date() instead of shelling out to `date` - the backtick spawned
			//a process on every log line (slow), and on Windows `date` blocks waiting
			//for interactive input, hanging the whole request.
			fwrite($fh,"[".date('D M j g:i:s A T Y')."] ".$prefix.": ".$line."\n");
			fclose($fh);
		}catch (Throwable $e){
			error_log('Ghoti could not write the application log.');
			ghoti_alert_log($level, $context, $line);
			return false;
		}
		ghoti_alert_log($level, $context, $line);
		return True;
	}
	/* ---------------- Site Settings (admin-editable) ---------------- */

	//A request-only visibility override; authentication is still required.
	public static function showLoginButton(){
		return !self::$hideLoginButton || (isset($_GET['theme']) && $_GET['theme'] === 'login');
	}

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

		$ranges = array(
			'securityFailedLoginThreshold' => array(3, 100, 'Failed-login threshold'),
			'securityFailureWindowMinutes' => array(1, 1440, 'Failure window'),
			'securityBlacklistDurationMinutes' => array(5, 43200, 'Blacklist duration'),
			'sessionTimeoutMinutes' => array(5, 1440, 'Session timeout'),
		);
		foreach($ranges as $key => $range){
			if(!array_key_exists($key, $settings)){ continue; }
			if(!is_scalar($settings[$key]) || !preg_match('/^\d+$/', (string)$settings[$key])){
				return $range[2]." must be a whole number.";
			}
			$value = (int)$settings[$key];
			if($value < $range[0] || $value > $range[1]){
				return $range[2]." must be between ".$range[0]." and ".$range[1].".";
			}
			$settings[$key] = $value;
		}
		foreach(array('securityIpBlacklist','securityIpAllowlist') as $key){
			if(!array_key_exists($key, $settings)){ continue; }
			try{ $settings[$key] = ghoti_security_normalize_ip_list($settings[$key]); }
			catch(InvalidArgumentException $e){ return $e->getMessage(); }
		}
		$currentIp = ghoti_remote_addr();
		if($currentIp !== '' && isset($settings['securityIpBlacklist'])
			&& ghoti_security_ip_matches_list($currentIp, $settings['securityIpBlacklist'])){
			return "The manual blacklist includes your current IP address. Remove it before saving.";
		}

		//Alerts go to the admin accounts themselves, so there is nothing to
		//validate here beyond "somebody would actually receive them".
		$alertsEnabled = array_key_exists('enableCriticalAlerts', $settings) ? self::sanitizeSetting('bool', $settings['enableCriticalAlerts']) : self::$enableCriticalAlerts;
		if($alertsEnabled && !ghoti_admin_emails(true)){
			return "No administrator account has a valid e-mail address. Add one in Manage Users before enabling alerts.";
		}

		/*
		 * Two-factor authentication may only be switched on when outbound mail has
		 * been PROVEN to work, because the code is delivered by email and there is
		 * no fallback by design: enabling it against an untested mail server locks
		 * every administrator out of the site.
		 *
		 * Server-side on purpose. The Site Settings form disables the control when
		 * mail is unverified, but that is a courtesy to the person using it, not
		 * the control - this is.
		 */
		$twoFactorWanted = array_key_exists('enableTwoFactor', $settings) ? self::sanitizeSetting('bool', $settings['enableTwoFactor']) : self::$enableTwoFactor;
		$allUsersWanted  = array_key_exists('twoFactorAllUsers', $settings) ? self::sanitizeSetting('bool', $settings['twoFactorAllUsers']) : self::$twoFactorAllUsers;

		//Both switches arrive in the same save, so the RESULTING state is what is
		//judged: extending codes to members while the feature itself is off would
		//be a setting that silently does nothing.
		if($allUsersWanted && !$twoFactorWanted){
			return "Codes for all members need two-factor authentication itself to be on. Tick both, or neither.";
		}
		//Widening the requirement to every member is the same risk as switching it
		//on in the first place - more so, since it puts the mail server in front of
		//every sign-in on the site - so it is gated the same way.
		//One exception for switching it on for administrators: when every one of
		//them already has an authenticator app, none needs mail to sign in, so
		//untested mail cannot lock anybody out. Members default to e-mail, so
		//widening to them still needs tested mail.
		$adminsOnApp = function_exists('login_2fa_all_admins_enrolled') && login_2fa_all_admins_enrolled();
		if(($twoFactorWanted && !self::$enableTwoFactor && !$adminsOnApp) || ($allUsersWanted && !self::$twoFactorAllUsers)){
			//A recorded successful test is the whole check, and it is a stronger
			//one than it looks: mailDeliverTestMessage() refuses outright when no
			//administrator has a usable address, and records the marker only after
			//a message actually reached one. So this already proves both that mail
			//works and that an administrator could receive a code.
			//
			//Deliberately NOT also requiring ghoti_admin_emails() here: it returns
			//an empty list both when there genuinely are no admin addresses and
			//when the directory could not be read, and refusing on the second case
			//would block the setting for a reason that is not true. The per-account
			//case is caught at sign-in instead, by login_2fa_begin(), which refuses
			//an administrator with no address and says so.
			if(!function_exists('ghoti_mail_verified') || !ghoti_mail_verified()){
				return "Two-factor authentication needs outbound mail that has been tested (or, for administrators only, every administrator set up with an authenticator app). Open Site Settings → Mail, send a test message, then enable it.";
			}
		}

		//Give explicit feedback for a bad theme rather than silently ignoring it.
		if(isset($settings['defaultTheme']) && $settings['defaultTheme'] !== ''
			&& !self::isValidTheme($settings['defaultTheme'])){
			return "That theme doesn't exist.";
		}

		if(array_key_exists('backgroundImg', $settings) && self::sanitizeSetting('background', $settings['backgroundImg']) === null){
			return "Background image must be an existing local PNG, JPEG, GIF, WebP or AVIF image path, or blank to use theme defaults.";
		}

		//The SEO fields refuse rather than silently drop a bad value: a
		//verification token or URL that vanished on save would look like it
		//had been accepted.
		$seoChecks = array(
			'seoSiteUrl' => array('url', "Site URL must be a full https:// address with no query string, e.g. https://example.com."),
			'seoShareImage' => array('background', "Share image must be an existing local PNG, JPEG, GIF, WebP or AVIF image path, or blank."),
			'seoTwitterHandle' => array('handle', "Twitter/X handle must be letters, digits and underscores, up to 15 characters."),
			'seoGoogleVerification' => array('token', "The Google verification code must be the content value only: letters, digits, - and _."),
			'seoBingVerification' => array('token', "The Bing verification code must be the content value only: letters, digits, - and _."),
			'seoRobotsExtra' => array('robots', "Extra robots.txt rules must be lines of User-agent, Allow, Disallow, Crawl-delay or Sitemap, at most 2000 characters."),
		);
		foreach($seoChecks as $key => $check){
			if(array_key_exists($key, $settings) && self::sanitizeSetting($check[0], $settings[$key]) === null){ return $check[1]; }
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
			case 'email':
				return is_string($value) && ($value === '' || filter_var($value, FILTER_VALIDATE_EMAIL)) ? $value : null;
			case 'bool':
				return (bool)(is_string($value) ? ($value !== '' && $value !== '0' && strtolower($value) !== 'false') : $value);
			case 'int':
				return is_scalar($value) && preg_match('/^\d+$/', (string)$value) ? (int)$value : null;
			case 'ip_list':
				try{ return ghoti_security_normalize_ip_list($value); }
				catch(InvalidArgumentException $e){ return null; }
			case 'theme':
				return self::isValidTheme($value) ? (string)$value : null;
			case 'background':
				if(!is_string($value)){ return null; }
				$value = trim($value);
				if($value === ''){ return ''; }
				if(strlen($value) > 200 || !preg_match('#^(?:[A-Za-z0-9_-][A-Za-z0-9_. -]*/)*[A-Za-z0-9_-][A-Za-z0-9_. -]*\.(?:png|jpe?g|gif|webp|avif)$#i', $value)){ return null; }
				$path = realpath(__DIR__.'/'.$value);
				if($path === false || !str_starts_with($path, __DIR__.DIRECTORY_SEPARATOR) || !is_file($path)){ return null; }
				$image = @getimagesize($path);
				return $image && in_array($image['mime'], array('image/png','image/jpeg','image/gif','image/webp','image/avif'), true) ? $value : null;
			case 'path':
				//Used as an <img src> in themes (printed unescaped), so strip
				//anything that could break out of the attribute or inject markup.
				$v = str_replace(array('<','>','"',"'","\\","\r","\n"," "), '', (string)$value);
				$v = trim($v);
				return substr($v, 0, 200);
			case 'url':
				if(!is_string($value)){ return null; }
				if(trim($value) === ''){ return ''; }
				$url = ghoti_seo_clean_url($value);
				return $url === '' ? null : $url;
			case 'handle':
				if(!is_string($value)){ return null; }
				$value = ltrim(trim($value), '@');
				return $value === '' || preg_match('/^[A-Za-z0-9_]{1,15}$/', $value) ? $value : null;
			case 'token':
				if(!is_string($value)){ return null; }
				$value = trim($value);
				return $value === '' || preg_match('/^[A-Za-z0-9_-]{1,100}$/', $value) ? $value : null;
			case 'longtext':
				//A single line, like 'text', but long enough for a description.
				$v = strip_tags((string)$value);
				$v = preg_replace('/\s+/', ' ', str_replace('"', '', $v));
				return trim(mb_substr($v, 0, 300));
			case 'robots':
				if(!is_string($value)){ return null; }
				$value = trim(str_replace("\r\n", "\n", $value));
				if(strlen($value) > 2000){ return null; }
				$lines = array();
				foreach(explode("\n", $value) as $line){
					$line = trim($line);
					if($line === '' || $line[0] === '#'){ $lines[] = $line; continue; }
					if(!preg_match('/^(User-agent|Allow|Disallow|Crawl-delay|Sitemap)\s*:\s*[\x21-\x7e]*$/i', $line)){ return null; }
					$lines[] = $line;
				}
				return implode("\n", $lines);
			case 'text':
			default:
				$v = strip_tags((string)$value);
				$v = str_replace(array('"',"\r","\n"), '', $v);
				return trim(substr($v, 0, 120));
		}
	}

	//Themes with image backdrops share one override; other themes keep their own design.
	public static function themeBackground($fallback){
		$path = self::sanitizeSetting('background', self::$backgroundImg);
		return $path !== null && $path !== '' ? $path : $fallback;
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
include_once('ghoti.documentation.php');
include_once('ghoti.backup.php');
include_once('ghoti.mail.php');
include_once('ghoti.seo.php');
include_once('ghoti.totp.php');
?>
