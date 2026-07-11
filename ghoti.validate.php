<?php
/*
 * ghoti.validate.php - central input validation / sanitization.
 *
 * Every value that arrives from the browser (async endpoint arguments, GET/POST
 * data) is untrusted. The database layer binds all parameters so SQL injection
 * is already covered, but that says nothing about:
 *   - values echoed back into HTML/attributes (stored XSS),
 *   - values used as URLs in href/src (javascript:/data: scheme injection),
 *   - values written into the log file (log/CRLF injection),
 *   - unbounded input (huge passwords -> slow-hash DoS, huge bodies -> memory),
 *   - values that must be a specific shape (ids, groups, booleans).
 *
 * This class is the single place those rules live. Two styles are offered:
 *
 *   Legacy throwing checks - checkExists()/checkNumber()/checkEmail() - kept for
 *   the existing `try{ ... }catch(Exception $e){ return $e->getMessage(); }`
 *   endpoint pattern. checkEmail() previously swallowed its own exception and
 *   never actually reported a bad address; that is fixed here.
 *
 *   Typed sanitizers - id()/intInRange()/boolInt()/text()/username()/email()/
 *   password()/url()/pageGroup()/linkGroup()/comment()/logLine() - each RETURNS
 *   a clean, normalized value or throws Exception with a user-safe message. Use
 *   these at the top of every endpoint.
 *
 * Get an instance anywhere (endpoints, module code) via ghoti_validate(); it
 * does not depend on any $_SESSION service object being populated.
 */

class validate{

	/* -------- defense-in-depth size limits (characters, unless noted) -------- */
	const MIN_PASSWORD   = 8;
	const MAX_PASSWORD   = 4096;   // cap candidate length so password_hash/verify can't be used as a slow-hash DoS
	const MAX_USERNAME   = 20;
	const MAX_EMAIL      = 190;    // fits a utf8mb4 unique index; RFC allows 254 but 190 is plenty here
	const MAX_PAGE_TITLE = 120;
	const MAX_PAGE_BODY  = 200000; // authored page HTML
	const MAX_COMMENT    = 4000;
	const MAX_LINK_NAME  = 120;
	const MAX_GROUP      = 40;
	const MAX_URL        = 2048;
	const MAX_TEXT       = 255;
	const MAX_LOG_LINE   = 2000;

	/* ================================================================== *
	 *  Legacy throwing validators (kept for existing try/catch callers)
	 * ================================================================== */

	// Throws unless $var has a non-empty, non-whitespace value.
	public function checkExists($var){
		if($var === null || (is_string($var) && trim($var) === '') || (is_array($var) && count($var) === 0)){
			throw new Exception("Required field missing.");
		}
		if(!is_string($var) && !is_numeric($var) && empty($var)){
			throw new Exception("Required field missing.");
		}
		return true;
	}

	// Throws unless $var is numeric.
	public function checkNumber($var){
		if(!is_numeric($var)){
			throw new Exception("Input must be numeric.");
		}
		return true;
	}

	// Throws unless $email is a syntactically valid address. (Previously this
	// caught its own exception and logged it, so an invalid address slipped
	// straight through - the check did nothing. Now it actually reports.)
	public function checkEmail($email){
		$email = trim((string)$email);
		if($email === '' || strlen($email) > self::MAX_EMAIL || !filter_var($email, FILTER_VALIDATE_EMAIL)){
			throw new Exception("Invalid email address.");
		}
		return $email;
	}

	/* ================================================================== *
	 *  Typed sanitizers - return a clean value or throw Exception
	 * ================================================================== */

	// Positive integer identifier (page id, user id, ...). Rejects 0, negatives,
	// floats and non-numeric junk. Returns an int.
	public function id($var, $label = "identifier"){
		if(is_string($var)){ $var = trim($var); }
		// Accept only plain digit strings / real ints; reject "1e3", "0x1A", "1.5",
		// "-1", etc. so an id is always a genuine positive integer.
		if(!preg_match('/^[0-9]+$/', (string)$var)){
			throw new Exception("Invalid $label.");
		}
		$n = (int)$var;
		if($n <= 0){
			throw new Exception("Invalid $label.");
		}
		return $n;
	}

	// Integer constrained to [$min,$max]. Non-numeric input throws.
	public function intInRange($var, $min, $max, $label = "value"){
		if(is_string($var)){ $var = trim($var); }
		if(!is_numeric($var) || !preg_match('/^[+-]?[0-9]+$/', (string)$var)){
			throw new Exception("Invalid $label.");
		}
		$n = (int)$var;
		if($n < $min){ $n = $min; }
		if($n > $max){ $n = $max; }
		return $n;
	}

	// Normalize a checkbox/boolean-ish value to 0 or 1.
	public function boolInt($var){
		if(is_bool($var)){ return $var ? 1 : 0; }
		if(is_numeric($var)){ return ((int)$var) !== 0 ? 1 : 0; }
		$s = strtolower(trim((string)$var));
		return ($s === '' || $s === '0' || $s === 'false' || $s === 'no' || $s === 'off') ? 0 : 1;
	}

	// General plain-text field: strip tags, remove control chars, collapse to a
	// single trimmed line, enforce a max length. If $required, empties throw.
	public function text($var, $max = self::MAX_TEXT, $required = true, $label = "field"){
		$v = strip_tags((string)$var);
		// drop control chars (including CR/LF/NUL) that have no place in a one-line field
		$v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
		if($v === null){ $v = ''; } // preg_replace can return null on bad UTF-8
		$v = trim($v);
		if($max > 0 && function_exists('mb_substr')){
			$v = mb_substr($v, 0, $max);
		}elseif($max > 0){
			$v = substr($v, 0, $max);
		}
		if($required && $v === ''){
			throw new Exception(ucfirst($label)." is required.");
		}
		return $v;
	}

	// Multi-line text (comments, etc.): keep newlines, strip other control chars,
	// strip tags, cap length.
	public function multilineText($var, $max, $required = true, $label = "field"){
		$v = strip_tags((string)$var);
		$v = str_replace("\r\n", "\n", $v);
		$v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $v);
		if($v === null){ $v = ''; }
		$v = trim($v);
		if($max > 0 && function_exists('mb_substr')){
			$v = mb_substr($v, 0, $max);
		}elseif($max > 0){
			$v = substr($v, 0, $max);
		}
		if($required && $v === ''){
			throw new Exception(ucfirst($label)." is required.");
		}
		return $v;
	}

	// Username: letters, numbers and _.- only, 1..MAX_USERNAME. Matches the
	// charset enforced at registration; keeping it here means every code path
	// that touches a username agrees on the rule.
	public function username($var){
		$v = trim((string)$var);
		if(!preg_match('/^[A-Za-z0-9_.-]{1,'.self::MAX_USERNAME.'}$/', $v)){
			throw new Exception("Username may only contain letters, numbers, and _.- (max ".self::MAX_USERNAME." characters).");
		}
		return $v;
	}

	// Email (typed variant): returns the normalized address or throws.
	public function email($var){
		return $this->checkEmail($var);
	}

	// Password: enforce the length window. Returns the password unchanged (never
	// trimmed - leading/trailing spaces can be intentional). Upper bound guards
	// against slow-hash DoS.
	public function password($var){
		$p = (string)$var;
		$len = strlen($p);
		if($len < self::MIN_PASSWORD){
			throw new Exception("Password must be at least ".self::MIN_PASSWORD." characters.");
		}
		if($len > self::MAX_PASSWORD){
			throw new Exception("Password is too long.");
		}
		return $p;
	}

	// Page visibility group: only 'public' or 'private'.
	public function pageGroup($var){
		$v = strtolower(trim((string)$var));
		if($v !== 'public' && $v !== 'private'){
			throw new Exception("Invalid page group.");
		}
		return $v;
	}

	// Link group / short slug: letters, numbers, space, _ and -, capped. Defaults
	// to "default" only when not required.
	public function linkGroup($var, $required = false){
		$v = trim((string)$var);
		$v = preg_replace('/[^A-Za-z0-9 _-]+/', '', $v);
		if($v === null){ $v = ''; }
		$v = trim(substr($v, 0, self::MAX_GROUP));
		if($v === ''){
			if($required){ throw new Exception("Group is required."); }
			return 'default';
		}
		return $v;
	}

	// A URL destined for an href/src attribute. Allows http(s), mailto and
	// scheme-relative paths (anything with no scheme, e.g. "/x" or "a/b.png");
	// rejects javascript:, data:, vbscript:, file:, etc. - the schemes that turn
	// a stored URL into script execution. Returns the trimmed URL or throws.
	public function url($var, $required = true, $label = "URL"){
		$v = trim((string)$var);
		if($v === ''){
			if($required){ throw new Exception(ucfirst($label)." is required."); }
			return '';
		}
		if(strlen($v) > self::MAX_URL){
			throw new Exception(ucfirst($label)." is too long.");
		}
		// Reject anything with control chars/whitespace embedded (used to smuggle
		// "java\tscript:"). Strip them before scheme detection so obfuscation fails.
		$probe = preg_replace('/[\x00-\x20]+/', '', $v);
		if($probe === null){ $probe = $v; }
		// Does it start with a scheme? (scheme = alpha *( alpha | digit | + - . ) : )
		if(preg_match('#^([A-Za-z][A-Za-z0-9+.\-]*):#', $probe, $m)){
			$scheme = strtolower($m[1]);
			$allowed = array('http', 'https', 'mailto');
			if(!in_array($scheme, $allowed, true)){
				throw new Exception("That $label uses a disallowed scheme. Use http:// or https://.");
			}
		}
		// No scheme => treat as a relative/site-local URL, which is safe in href/src.
		return $v;
	}

	// A single log line: strip CR/LF and control chars (prevents forging extra log
	// entries) and cap the length. Always returns a string.
	public function logLine($var){
		$v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$var);
		if($v === null){ $v = ''; }
		$v = trim($v);
		return substr($v, 0, self::MAX_LOG_LINE);
	}
}

/*
 * Shared validator instance. Endpoints call ghoti_validate()->id($x) etc.
 * without depending on $_SESSION['ghotiObj'] being present (it isn't on every
 * code path, and relying on it made validation skippable).
 */
function ghoti_validate(){
	static $instance = null;
	if($instance === null){
		$instance = new validate();
	}
	return $instance;
}
?>
