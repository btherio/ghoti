<?php
/*
 * ghoti.setup.php - first-run / recovery database setup.
 *
 * When the app cannot reach its database (a fresh install pointed at the wrong
 * host, or an outage), index.php hands control here instead of bootstrapping
 * the module stack against a dead connection. We render a small self-contained
 * page with a modal to enter the connection details, test them live, and - on
 * success - write them permanently to db.config.local.php (the untracked local
 * override that db.config.php already merges over its defaults).
 *
 * Security model (same as any web installer, e.g. WordPress's install screen):
 * this flow is ONLY reachable while ghotidb::isConfigured() is false. There are
 * no users/admins to authenticate against when the database is down, so it
 * can't be gated on login. To keep the exposure minimal while it IS open:
 *   - every field is strictly validated, the driver is whitelisted, values are
 *     written with var_export() (no PHP injection into the generated file),
 *     and the target path is fixed (not an arbitrary-file-write primitive);
 *   - the async calls carry the session CSRF token (same as every other
 *     endpoint), so a cross-site request can't drive the form;
 *   - if the GHOTI_SETUP_KEY environment variable is set, the setup page and
 *     its endpoints additionally require that key (?k=... / payload "k"),
 *     turning the outage window into a locked door instead of an open portal;
 *   - raw PDO error text is flattened and truncated so it stays useful for the
 *     operator without becoming a network-probing oracle.
 * The moment a working config is saved and connects, index.php stops diverting
 * here and the setup endpoints below refuse.
 */

/* Drivers we actually support. The data layer is written for MySQL/MariaDB. */
function ghoti_setup_allowed_drivers(){
	return array('mysql');
}

/* Optional setup key from the environment; '' means "no key configured". */
function ghoti_setup_configured_key(){
	$key = getenv('GHOTI_SETUP_KEY');
	return is_string($key) ? $key : '';
}

/*
 * Validate + normalize a submitted config array. Returns a clean config array,
 * or throws Exception with a user-facing message. Host/database/charset are
 * constrained to a safe charset because they are interpolated into the PDO DSN
 * string (where ';' would let an attacker inject extra DSN parameters). The
 * username/password are passed to PDO as separate arguments, never into the
 * DSN, so they may contain any characters.
 */
function ghoti_setup_sanitize_config($input){
	if(!is_array($input)){
		throw new Exception("Invalid settings.");
	}
	$v = ghoti_validate();

	$driver = strtolower(trim((string)($input['driver'] ?? 'mysql')));
	if(!in_array($driver, ghoti_setup_allowed_drivers(), true)){
		throw new Exception("Unsupported database driver.");
	}

	$host = trim((string)($input['host'] ?? ''));
	if($host === '' || !preg_match('/^[A-Za-z0-9_.\-]{1,255}$/', $host)){
		throw new Exception("Enter a valid host name or IP address.");
	}

	$portRaw = $input['port'] ?? '3306';
	if($portRaw === '' || $portRaw === null){ $portRaw = '3306'; }
	$port = $v->intInRange($portRaw, 1, 65535, "port");

	$database = trim((string)($input['database'] ?? ''));
	if($database === '' || !preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $database)){
		throw new Exception("Enter a valid database name.");
	}

	$username = trim((string)($input['username'] ?? ''));
	if($username === '' || strlen($username) > 128){
		throw new Exception("Enter a database username.");
	}

	$password = (string)($input['password'] ?? '');
	if(strlen($password) > 512){
		throw new Exception("Password is too long.");
	}

	$charset = trim((string)($input['charset'] ?? 'utf8mb4'));
	if($charset === ''){ $charset = 'utf8mb4'; }
	if(!preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $charset)){
		throw new Exception("Enter a valid charset (letters, numbers, _ and - only).");
	}

	return array(
		'driver'   => $driver,
		'host'     => $host,
		'port'     => (string)$port,
		'database' => $database,
		'username' => $username,
		'password' => $password,
		'charset'  => $charset,
	);
}

/*
 * Try to open a PDO connection with the given (already sanitized) config.
 * Returns true on success, or a short human-readable error string on failure.
 */
function ghoti_setup_test_config($cfg){
	try{
		$dsn = "{$cfg['driver']}:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset={$cfg['charset']}";
		$pdo = new PDO($dsn, $cfg['username'], $cfg['password'], array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_TIMEOUT => 5,
		));
		$pdo = null; // close immediately; we only wanted to prove it connects
		return true;
	}catch (Throwable $e){
		//Flatten + truncate the driver's message: keep it useful for the
		//operator ("access denied", "unknown database", ...) without dumping a
		//multi-line DSN/stack blob that doubles as a network-probing oracle.
		$msg = preg_replace('/\s+/', ' ', $e->getMessage());
		$msg = trim((string)$msg);
		if(strlen($msg) > 200){ $msg = substr($msg, 0, 200).'...'; }
		return "Connection failed: ".$msg;
	}
}

/* Absolute path of the untracked local override we write. */
function ghoti_setup_local_config_path(){
	return __DIR__.'/db.config.local.php';
}

/*
 * Endpoint: validate + test + permanently persist a submitted config.
 * Returns true on success, or an error message string. Refuses once the app is
 * already configured (defence in depth - index.php shouldn't route here then).
 */
function ghoti_setup_save_config($input){
	if(ghotidb::isConfigured()){
		return "The database is already configured.";
	}
	try{
		$cfg = ghoti_setup_sanitize_config($input);
	}catch (Exception $e){
		return $e->getMessage();
	}

	$test = ghoti_setup_test_config($cfg);
	if($test !== true){
		return $test; // don't persist a config that doesn't actually connect
	}

	//Write valid PHP that returns the config array. var_export() safely encodes
	//every value, so nothing the operator typed can break out into code.
	$php  = "<?php\n";
	$php .= "/* Database connection override written by the ghoti setup screen.\n";
	$php .= " * Generated ".date('c').". Safe to edit or delete (delete to re-run setup). */\n";
	$php .= "return ".var_export($cfg, true).";\n";

	if(@file_put_contents(ghoti_setup_local_config_path(), $php, LOCK_EX) === false){
		ghoti::log("ghoti.setup.php: could not write ".ghoti_setup_local_config_path());
		return "Connected, but could not write db.config.local.php. Check that the web server can write to the ghoti directory.";
	}

	@chmod(ghoti_setup_local_config_path(), 0600); // credentials file - keep it tight where the OS honours it
	ghoti::log("ghoti.setup.php: database configured for {$cfg['username']}@{$cfg['host']}:{$cfg['port']}/{$cfg['database']} from ".(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''));
	return true;
}

/*
 * True only when the presented setup key (when one is configured) matches AND
 * the request carries the session CSRF token.
 */
function ghoti_setup_access_ok($presentedKey, $token){
	$key = ghoti_setup_configured_key();
	if($key !== '' && (!is_string($presentedKey) || !hash_equals($key, $presentedKey))){
		return false;
	}
	return ghoti_csrf_verify($token);
}

/*
 * Entry point called from index.php when the DB is unreachable. If this request
 * is the setup form's async POST, dispatch it and exit; otherwise render the
 * setup page and exit. Setup endpoints are handled HERE (not via the normal
 * async registry) so they exist only on this path, while the DB is down.
 */
function ghoti_setup_dispatch(){
	$key = ghoti_setup_configured_key();
	$req = ghoti_async_read_request();
	if($req !== null){
		list($fn, $args, $token) = $req;
		$payload = isset($args[0]) ? $args[0] : array();
		$presentedKey = (is_array($payload) && isset($payload['k'])) ? (string)$payload['k'] : '';
		if(!ghoti_setup_access_ok($presentedKey, $token)){
			//logLine() strips CR/LF so an attacker-chosen fn can't forge log entries.
			ghoti::log("ghoti.setup.php denied setup request '".ghoti_validate()->logLine($fn)."' (bad key/token) from ".(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''));
			ghoti_async_send_json(array('ok' => false, 'error' => 'Setup access denied.'), 403);
			exit;
		}
		if($fn === 'saveDbConfig'){
			ghoti_async_send_json(array('ok' => true, 'result' => ghoti_setup_save_config($payload)));
		}else if($fn === 'testDbConfig'){
			try{
				$cfg = ghoti_setup_sanitize_config($payload);
				$result = ghoti_setup_test_config($cfg);
				ghoti_async_send_json(array('ok' => true, 'result' => ($result === true ? "Connection succeeded." : $result)));
			}catch (Exception $e){
				ghoti_async_send_json(array('ok' => true, 'result' => $e->getMessage()));
			}
		}else{
			//Any other endpoint is unavailable until the database is configured.
			ghoti_async_send_json(array('ok' => false, 'error' => 'The site is not configured yet. Reload to finish database setup.'), 503);
		}
		exit;
	}

	//Page render: when a setup key is configured, require it in the URL.
	if($key !== ''){
		$presentedKey = isset($_GET['k']) ? (string)$_GET['k'] : '';
		if(!is_string($presentedKey) || !hash_equals($key, $presentedKey)){
			ghoti::log("ghoti.setup.php denied setup page (missing/bad key) from ".(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''));
			http_response_code(403);
			header('Content-Type: text/plain; charset=utf-8');
			echo "Database setup is locked. Set GHOTI_SETUP_KEY and open this page with ?k=<key>.";
			exit;
		}
	}
	ghoti_setup_render_page();
	exit;
}

/* Render the standalone setup page (its own HTML - the normal theme/header
 * bootstraps modules that need the database). */
function ghoti_setup_render_page(){
	$cfg = ghotidb::loadConfig();
	$esc = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES); };
	//Prefill everything except the password (never echo a secret into the page).
	$host     = $esc($cfg['host']     ?? '');
	$port     = $esc($cfg['port']     ?? '3306');
	$database = $esc($cfg['database'] ?? '');
	$username = $esc($cfg['username'] ?? '');
	$charset  = $esc($cfg['charset']  ?? 'utf8mb4');
	$endpoint = $esc(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'index.php');
	//CSRF token (same session token the async layer uses) + optional setup key.
	$csrfJs   = json_encode(ghoti_csrf_token());
	$keyJs    = json_encode(isset($_GET['k']) ? (string)$_GET['k'] : '');

	if(!headers_sent()){
		http_response_code(503); // "not ready yet" - correct while unconfigured
		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: no-store');
		header('Retry-After: 60');
		header('X-Content-Type-Options: nosniff');
		header('X-Frame-Options: DENY');
		header('Referrer-Policy: no-referrer'); // the setup URL carries ?k= - don't leak it
	}

	echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex,nofollow" />
<title>ghoti &middot; Database setup</title>
<style>
:root{
	--bg:#0f1216; --surface:#171b21; --surface-2:#1e242c; --border:#2b333d;
	--text:#e8edf3; --muted:#9aa7b4; --accent:#2a78d6; --accent-2:#1c5cab;
	--danger:#e5534b; --ok:#3fb950; --radius:14px;
}
@media (prefers-color-scheme: light){
	:root{ --bg:#eef1f5; --surface:#ffffff; --surface-2:#f4f6f9; --border:#d7dee6;
		--text:#1b2530; --muted:#5b6875; }
}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
	background:var(--bg);color:var(--text);
	font:15px/1.6 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;padding:24px}
.setup-card{width:100%;max-width:520px;background:var(--surface);border:1px solid var(--border);
	border-radius:var(--radius);box-shadow:0 24px 60px rgba(0,0,0,.35);overflow:hidden}
.setup-head{padding:22px 26px 6px}
.setup-head h1{margin:0 0 4px;font-size:1.35rem;letter-spacing:.2px}
.setup-head p{margin:0;color:var(--muted);font-size:.92rem}
.setup-body{padding:14px 26px 22px}
.setup-note{background:var(--surface-2);border:1px solid var(--border);border-radius:10px;
	padding:10px 12px;color:var(--muted);font-size:.85rem;margin:6px 0 16px}
label{display:block;margin:0 0 12px}
label span{display:block;font-size:.82rem;color:var(--muted);margin-bottom:4px}
input,select{width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;
	background:var(--surface-2);color:var(--text);font-size:.95rem;outline:none}
input:focus,select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(42,120,214,.25)}
.row{display:flex;gap:12px}
.row .grow{flex:1}
.row .port{width:110px}
.actions{display:flex;gap:10px;align-items:center;margin-top:8px}
button{appearance:none;border:1px solid var(--border);border-radius:9px;padding:10px 16px;
	font-size:.95rem;font-weight:600;cursor:pointer;background:var(--surface-2);color:var(--text);
	position:relative;box-shadow:inset 0 1px 0 rgba(255,255,255,.06);
	transition:transform .12s ease,box-shadow .18s ease,filter .18s ease}
button:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(0,0,0,.28)}
button:active{transform:translateY(1px);box-shadow:inset 0 2px 5px rgba(0,0,0,.22)}
button:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(42,120,214,.45)}
button.primary{background:linear-gradient(180deg,color-mix(in srgb,var(--accent) 88%,#fff 12%),var(--accent));border-color:var(--accent);color:#fff}
button.primary:hover{filter:saturate(1.1) brightness(1.05)}
button:disabled{opacity:.6;cursor:progress;transform:none;filter:grayscale(.45);box-shadow:none}
button.is-busy{pointer-events:none;color:transparent}
button.is-busy > *{visibility:hidden}
button.is-busy::after{content:"";position:absolute;inset:0;margin:auto;width:1.1em;height:1.1em;
	border:2px solid color-mix(in srgb,var(--text) 30%,transparent);border-top-color:var(--text);
	border-radius:50%;animation:ghotiSpin .7s linear infinite}
@keyframes ghotiSpin{to{transform:rotate(360deg)}}
.msg{margin-left:auto;font-size:.88rem;min-height:1.2em}
.msg.ok{color:var(--ok)} .msg.err{color:var(--danger)}
.setup-foot{padding:0 26px 20px;color:var(--muted);font-size:.78rem}
code{background:var(--surface-2);padding:1px 5px;border-radius:5px}
@media (max-width:420px){
	body{align-items:flex-start;padding:8px;overflow-wrap:anywhere}
	.setup-card{border-radius:10px}
	.setup-head{padding:16px 14px 5px}
	.setup-body{padding:10px 14px 16px}
	.setup-foot{padding:0 14px 14px}
	.row{flex-direction:column;gap:0}
	.row .port{width:100%}
	.actions{align-items:stretch;flex-direction:column}
	.actions button{width:100%}
	.msg{margin-left:0;text-align:center}
}
</style>
</head>
<body>
<div class="setup-card">
	<div class="setup-head">
		<h1>Database setup</h1>
		<p>ghoti can't reach its database yet. Enter the connection details to finish.</p>
	</div>
	<div class="setup-body">
		<div class="setup-note">These are saved to <code>db.config.local.php</code> in the ghoti directory (kept out of version control). Delete that file to run this setup again.</div>
		<form id="dbSetupForm" onsubmit="return false;">
			<label><span>Driver</span>
				<select id="db-driver"><option value="mysql" selected>MySQL / MariaDB</option></select>
			</label>
			<div class="row">
				<label class="grow"><span>Host</span><input id="db-host" value="{$host}" autocomplete="off" spellcheck="false" placeholder="localhost or 10.0.0.17" /></label>
				<label class="port"><span>Port</span><input id="db-port" value="{$port}" autocomplete="off" inputmode="numeric" /></label>
			</div>
			<label><span>Database name</span><input id="db-database" value="{$database}" autocomplete="off" spellcheck="false" /></label>
			<label><span>Username</span><input id="db-username" value="{$username}" autocomplete="off" spellcheck="false" /></label>
			<label><span>Password</span><input id="db-password" type="password" value="" autocomplete="off" /></label>
			<label><span>Charset</span><input id="db-charset" value="{$charset}" autocomplete="off" spellcheck="false" /></label>
			<div class="actions">
				<button type="button" id="db-test">Test connection</button>
				<button type="button" class="primary" id="db-save">Save &amp; continue</button>
				<span class="msg" id="db-msg"></span>
			</div>
		</form>
	</div>
	<div class="setup-foot">Once saved and connected, this screen disappears automatically.</div>
</div>
<script>
(function(){
	var ENDPOINT = "{$endpoint}";
	var CSRF_TOKEN = {$csrfJs};
	var SETUP_KEY = {$keyJs};
	function fields(){
		return {
			driver:   document.getElementById('db-driver').value,
			host:     document.getElementById('db-host').value,
			port:     document.getElementById('db-port').value,
			database: document.getElementById('db-database').value,
			username: document.getElementById('db-username').value,
			password: document.getElementById('db-password').value,
			charset:  document.getElementById('db-charset').value,
			k:        SETUP_KEY
		};
	}
	var msg = document.getElementById('db-msg');
	function setMsg(text, cls){ msg.className = 'msg ' + (cls||''); msg.textContent = text || ''; }
	function call(fn, onResult){
		var testBtn = document.getElementById('db-test');
		var saveBtn = document.getElementById('db-save');
		testBtn.disabled = saveBtn.disabled = true;
		testBtn.classList.add('is-busy');
		saveBtn.classList.add('is-busy');
		setMsg('Working...', '');
		fetch(ENDPOINT, {
			method:'POST', credentials:'same-origin',
			headers:{'Content-Type':'application/json'},
			body: JSON.stringify({ __ghoti_async:1, fn:fn, args:[fields()], token:CSRF_TOKEN })
		}).then(function(r){ return r.json(); }).then(function(data){
			testBtn.disabled = saveBtn.disabled = false;
			testBtn.classList.remove('is-busy');
			saveBtn.classList.remove('is-busy');
			onResult(data && data.ok ? data.result : (data && data.error) || 'Request failed.');
		}).catch(function(){
			testBtn.disabled = saveBtn.disabled = false;
			testBtn.classList.remove('is-busy');
			saveBtn.classList.remove('is-busy');
			setMsg('Network error - is the server reachable?', 'err');
		});
	}
	document.getElementById('db-test').addEventListener('click', function(){
		call('testDbConfig', function(result){
			setMsg(result, result === 'Connection succeeded.' ? 'ok' : 'err');
		});
	});
	document.getElementById('db-save').addEventListener('click', function(){
		call('saveDbConfig', function(result){
			if(result === true){
				setMsg('Saved. Starting up...', 'ok');
				setTimeout(function(){ location.reload(); }, 900);
			}else{
				setMsg(result, 'err');
			}
		});
	});
})();
</script>
</body>
</html>
HTML;
}
?>
