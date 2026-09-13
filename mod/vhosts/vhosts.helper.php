<?php
/*
 * vhosts.helper.php - the bridge from PHP (running as `http`) to the root
 * helper script, via one narrow sudoers rule.
 *
 * Every privileged action in this module funnels through run(), and run() only
 * ever executes: sudo -n <helperPath> <verb> [args]. The verb comes from a
 * fixed whitelist in this file, never from the request. Arguments are escaped
 * with escapeshellarg() AND re-validated by the helper itself, and new vhost
 * config is piped on stdin rather than passed as an argument, so no admin-typed
 * text is ever word-split by a shell.
 *
 * When the helper is not installed (or sudo refuses it) every call fails
 * cleanly with ok=false and a message the UI shows next to install
 * instructions - the read-only half of the module keeps working.
 */

class VhostsHelper{
	//The complete set of things this module is allowed to ask root to do.
	private static $verbs = array(
		'ping', 'configtest', 'reload', 'vhost-map', 'list-certs',
		'write', 'delete', 'issue', 'renew',
	);

	private $settings;
	private $available = null; //memoized ping result for this request

	public function __construct($settings){
		$this->settings = $settings;
	}

	//True when the helper is installed, executable, and callable through sudo
	//without a password. Probed at most once per request.
	public function isAvailable(){
		if($this->available === null){
			$result = $this->run('ping');
			$this->available = $result['ok'];
		}
		return $this->available;
	}

	//Writes are additionally gated on the module's master switch, so an admin
	//can leave the helper installed while keeping the panel read-only.
	public function canWrite(){
		return !empty($this->settings['enabled']) && $this->isAvailable();
	}

	public function helperPath(){
		return (string)$this->settings['helperPath'];
	}

	/*
	 * Run one helper verb.
	 *
	 * Returns array('ok' => bool, 'output' => string, 'status' => int). Never
	 * throws and never leaks a raw shell error to the caller - stderr is folded
	 * into 'output' because the helper's diagnostics (configtest failures,
	 * certbot errors) are exactly what the admin needs to see.
	 *
	 * $stdin, when given, is piped to the process instead of being passed as an
	 * argument - that is how vhost config reaches `write`.
	 */
	public function run($verb, $args = array(), $stdin = null){
		if(!in_array($verb, self::$verbs, true)){
			ghoti::logError("vhosts.helper.php:run", "refused unknown verb '".ghoti_validate()->logLine($verb)."'");
			return self::failure("Unsupported operation.");
		}
		$path = $this->helperPath();
		if($path === '' || !preg_match('#^/[A-Za-z0-9._/-]+$#', $path)){
			return self::failure("The helper path is not a valid absolute path.");
		}
		if(!function_exists('proc_open')){
			return self::failure("PHP cannot start processes here (proc_open is disabled), so privileged actions are unavailable.");
		}

		$command = 'sudo -n '.escapeshellarg($path).' '.escapeshellarg($verb);
		foreach($args as $arg){
			$command .= ' '.escapeshellarg((string)$arg);
		}

		$descriptors = array(0 => array('pipe','r'), 1 => array('pipe','w'), 2 => array('pipe','w'));
		$pipes = array();
		//Keep certbot/apachectl from inheriting the web request's environment.
		$env = array('PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin', 'LC_ALL' => 'C');
		$process = @proc_open($command, $descriptors, $pipes, '/', $env);
		if(!is_resource($process)){
			ghoti::logError("vhosts.helper.php:run", "proc_open failed for verb '".$verb."'");
			return self::failure("Could not start the vhost helper.");
		}

		if($stdin !== null){ fwrite($pipes[0], (string)$stdin); }
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);

		$output = trim((string)$stdout."\n".(string)$stderr);
		if($status !== 0){
			ghoti::logWarn("vhosts.helper.php:run", "verb '".$verb."' exited ".$status.": ".ghoti_validate()->logLine(substr($output, 0, 500)));
			return array('ok' => false, 'output' => $output, 'status' => $status);
		}
		return array('ok' => true, 'output' => $output, 'status' => 0);
	}

	//Parses `certbot certificates` into one entry per certificate. certbot's
	//output is a stable, human-readable block format; anything it prints that
	//we don't recognise is ignored rather than guessed at.
	public function listCertificates(){
		$result = $this->run('list-certs');
		if(!$result['ok']){ return array('ok' => false, 'error' => $result['output'], 'certs' => array()); }

		$certs = array();
		$current = null;
		foreach(preg_split('/\r\n|\r|\n/', $result['output']) as $line){
			$line = trim($line);
			if(preg_match('/^Certificate Name:\s*(.+)$/i', $line, $m)){
				if($current !== null){ $certs[] = $current; }
				$current = array('name' => $m[1], 'domains' => array(), 'expiry' => '', 'daysLeft' => null, 'certPath' => '', 'keyPath' => '', 'serial' => '');
				continue;
			}
			if($current === null){ continue; }
			if(preg_match('/^Domains:\s*(.+)$/i', $line, $m)){
				$current['domains'] = array_values(array_filter(preg_split('/\s+/', $m[1])));
			}elseif(preg_match('/^Expiry Date:\s*(.+)$/i', $line, $m)){
				$current['expiry'] = trim($m[1]);
				if(preg_match('/VALID:\s*([0-9]+)\s*day/i', $m[1], $d)){
					$current['daysLeft'] = (int)$d[1];
				}elseif(preg_match('/INVALID|EXPIRED/i', $m[1])){
					$current['daysLeft'] = 0;
				}
			}elseif(preg_match('/^Certificate Path:\s*(.+)$/i', $line, $m)){
				$current['certPath'] = trim($m[1]);
			}elseif(preg_match('/^Private Key Path:\s*(.+)$/i', $line, $m)){
				$current['keyPath'] = trim($m[1]);
			}elseif(preg_match('/^Serial Number:\s*(.+)$/i', $line, $m)){
				$current['serial'] = trim($m[1]);
			}
		}
		if($current !== null){ $certs[] = $current; }
		return array('ok' => true, 'error' => '', 'certs' => $certs);
	}

	private static function failure($message){
		return array('ok' => false, 'output' => $message, 'status' => -1);
	}
}
?>
