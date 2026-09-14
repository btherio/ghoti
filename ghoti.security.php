<?php
/* Security helpers shared by the main application and standalone endpoints. */
function ghoti_password_reset_url(){
    // Never derive credential-bearing links from Host, forwarded headers or URI.
    $base = getenv('GHOTI_PUBLIC_URL');
    $parts = is_string($base) ? parse_url($base) : false;
    if(!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || preg_match('/[\x00-\x20\x7f\\\\]/', $base)
        || filter_var($base, FILTER_VALIDATE_URL) === false){
        throw new RuntimeException('Password recovery is unavailable. Ask the site operator to configure GHOTI_PUBLIC_URL with the HTTPS site address.');
    }
    return rtrim($base, '/').'/password-reset.php';
}

function ghoti_validate_session($db){
    if(ghoti_current_user_id() === 0){ return false; }
    $saved = $_SESSION['credentialFingerprint'] ?? null;
    $current = $db->credentialFingerprint(ghoti_current_user_id());
    $timeoutMinutes = class_exists('ghoti') ? max(5, min(1440, (int)ghoti::$sessionTimeoutMinutes)) : 30;
    $expired = !isset($_SESSION['last_activity']) || time() - (int)$_SESSION['last_activity'] > $timeoutMinutes * 60;
    if($expired || !is_string($saved) || !is_string($current) || !hash_equals($current, $saved)){
        foreach(array('loggedIn','userId','admin','credentialFingerprint','pageId') as $key){ unset($_SESSION[$key]); }
        return false;
    }
    return true;
}

function ghoti_csv_cell($value){
    $value = (string)$value;
    // Quoting CSV fields does not stop spreadsheet formula interpretation.
    if(preg_match('/^[\x00-\x20]*[=+@-]/', $value) || preg_match('/^[\t\r\n]/', $value)){
        return "'".$value;
    }
    return $value;
}

function ghoti_safe_url_attribute($url){
    try{ return htmlspecialchars(ghoti_validate()->url($url, false), ENT_QUOTES, 'UTF-8'); }
    catch(Throwable $e){ return ''; }
}

/* Persistent automatic IP blocks are runtime state, separate from the manual
 * rules in Site Settings. Locking the file keeps concurrent login failures and
 * admin unblock actions from overwriting one another. */
class GhotiIpBlockStore {
    public static $file = __DIR__.'/security.blacklist.json';

    private static function access($callback, $write = false){
        $handle = @fopen(self::$file, 'c+');
        if($handle === false){ throw new RuntimeException('IP blacklist storage is unavailable.'); }
        try{
            if(!flock($handle, LOCK_EX)){ throw new RuntimeException('IP blacklist storage is unavailable.'); }
            rewind($handle);
            $raw = stream_get_contents($handle);
            $data = $raw === '' ? array() : json_decode($raw, true);
            if(!is_array($data)){ throw new RuntimeException('IP blacklist storage is corrupt.'); }
            $now = time();
            foreach($data as $ip => $entry){
                if(!is_array($entry) || empty($entry['expires']) || (int)$entry['expires'] <= $now){
                    unset($data[$ip]);
                    $write = true;
                }
            }
            $result = $callback($data);
            if($write){
                if(count($data) > 5000){
                    uasort($data, function($a, $b){ return (int)($b['created'] ?? 0) <=> (int)($a['created'] ?? 0); });
                    $data = array_slice($data, 0, 5000, true);
                }
                $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                rewind($handle);
                if(fwrite($handle, $json) !== strlen($json) || !ftruncate($handle, strlen($json)) || !fflush($handle)){
                    throw new RuntimeException('IP blacklist storage is unavailable.');
                }
            }
            return $result;
        }finally{ fclose($handle); }
    }

    public static function block($ip, $durationSeconds, $failures){
        if(filter_var($ip, FILTER_VALIDATE_IP) === false){ return false; }
        $durationSeconds = max(300, min(2592000, (int)$durationSeconds));
        self::access(function(&$data) use ($ip, $durationSeconds, $failures){
            $data[$ip] = array(
                'created' => time(),
                'expires' => time() + $durationSeconds,
                'failures' => max(0, (int)$failures),
                'reason' => 'repeated_failed_logins',
            );
        }, true);
        return true;
    }

    public static function remove($ip){
        return self::access(function(&$data) use ($ip){
            $existed = isset($data[$ip]);
            unset($data[$ip]);
            return $existed;
        }, true);
    }

    public static function get($ip){
        return self::access(function($data) use ($ip){ return $data[$ip] ?? null; });
    }

    public static function active(){
        return self::access(function($data){
            uasort($data, function($a, $b){ return (int)($b['expires'] ?? 0) <=> (int)($a['expires'] ?? 0); });
            return $data;
        });
    }
}

function ghoti_security_normalize_ip_rule($rule){
    $rule = trim((string)$rule);
    if($rule === ''){ throw new InvalidArgumentException('IP rules cannot be blank.'); }
    $ip = $rule;
    $prefix = null;
    if(strpos($rule, '/') !== false){
        list($ip, $prefixText) = array_pad(explode('/', $rule, 2), 2, '');
        if($prefixText === '' || !preg_match('/^\d+$/', $prefixText)){
            throw new InvalidArgumentException("Invalid IP or CIDR rule: ".$rule);
        }
        $prefix = (int)$prefixText;
    }
    if(filter_var($ip, FILTER_VALIDATE_IP) === false){
        throw new InvalidArgumentException("Invalid IP or CIDR rule: ".$rule);
    }
    $packed = inet_pton($ip);
    $maxBits = strlen($packed) * 8;
    if($prefix !== null && ($prefix < 0 || $prefix > $maxBits)){
        throw new InvalidArgumentException("Invalid IP or CIDR rule: ".$rule);
    }
    $canonical = inet_ntop($packed);
    return $prefix === null ? $canonical : $canonical.'/'.$prefix;
}

function ghoti_security_normalize_ip_list($value){
    if(!is_scalar($value) && $value !== null){ throw new InvalidArgumentException('IP rules must be plain text.'); }
    $parts = preg_split('/[\r\n,]+/', (string)$value);
    $rules = array();
    foreach($parts as $part){
        $part = trim(preg_replace('/\s+#.*$/', '', $part));
        if($part === ''){ continue; }
        $rules[] = ghoti_security_normalize_ip_rule($part);
        if(count($rules) > 500){ throw new InvalidArgumentException('Use no more than 500 IP or CIDR rules.'); }
    }
    return implode("\n", array_values(array_unique($rules)));
}

function ghoti_security_ip_matches_rule($ip, $rule){
    if(filter_var($ip, FILTER_VALIDATE_IP) === false){ return false; }
    try{ $rule = ghoti_security_normalize_ip_rule($rule); }
    catch(InvalidArgumentException $e){ return false; }
    if(strpos($rule, '/') === false){ return hash_equals($rule, inet_ntop(inet_pton($ip))); }
    list($network, $prefixText) = explode('/', $rule, 2);
    $addressBytes = inet_pton($ip);
    $networkBytes = inet_pton($network);
    if($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)){ return false; }
    $bits = (int)$prefixText;
    $wholeBytes = intdiv($bits, 8);
    $remaining = $bits % 8;
    if($wholeBytes > 0 && substr($addressBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)){ return false; }
    if($remaining === 0){ return true; }
    $mask = (0xff << (8 - $remaining)) & 0xff;
    return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
}

function ghoti_security_ip_matches_list($ip, $list){
    foreach(preg_split('/[\r\n,]+/', (string)$list) as $rule){
        if(trim($rule) !== '' && ghoti_security_ip_matches_rule($ip, trim($rule))){ return true; }
    }
    return false;
}

function ghoti_security_block_status($ip){
    if(filter_var($ip, FILTER_VALIDATE_IP) === false){ return null; }
    if(class_exists('ghoti') && ghoti_security_ip_matches_list($ip, ghoti::$securityIpBlacklist)){
        return array('type'=>'manual', 'expires'=>null, 'failures'=>null);
    }
    if(class_exists('ghoti') && ghoti_security_ip_matches_list($ip, ghoti::$securityIpAllowlist)){
        return null;
    }
    try{
        $entry = GhotiIpBlockStore::get($ip);
        return is_array($entry) ? array_merge(array('type'=>'automatic'), $entry) : null;
    }catch(Throwable $e){
        if(class_exists('ghoti')){ ghoti::logException('ghoti.security.php:blacklist', $e); }
        return null;
    }
}

function ghoti_security_record_failed_login($throttle){
    if(!class_exists('ghoti') || !ghoti::$securityAutoBlacklist){ return false; }
    $ip = function_exists('loginRemoteAddr') ? loginRemoteAddr() : ($_SERVER['REMOTE_ADDR'] ?? '');
    if(filter_var($ip, FILTER_VALIDATE_IP) === false || ghoti_security_ip_matches_list($ip, ghoti::$securityIpAllowlist)){ return false; }
    $window = max(1, min(1440, (int)ghoti::$securityFailureWindowMinutes)) * 60;
    $threshold = max(3, min(100, (int)ghoti::$securityFailedLoginThreshold));
    $count = $throttle->countWindow('ip:'.$ip, $window);
    if($count < $threshold){ return false; }
    try{
        GhotiIpBlockStore::block($ip, max(5, min(43200, (int)ghoti::$securityBlacklistDurationMinutes)) * 60, $count);
        ghoti::logWarn('ghoti.security.php:autoBlacklist', "Automatically blacklisted $ip after $count failed login attempts.");
        return true;
    }catch(Throwable $e){
        ghoti::logException('ghoti.security.php:autoBlacklist', $e);
        return false;
    }
}

function ghoti_security_enforce_ip_access(){
    if(PHP_SAPI === 'cli'){ return; }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $status = ghoti_security_block_status($ip);
    if($status === null){ return; }
    ghoti::logWarn('ghoti.security.php:deny', "Blocked request from $ip (".$status['type'].").");
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo "Access denied.\n";
    exit;
}

function clearAutoBlockedIp($ip){
    if(!ghoti_require_admin()){ return 'Admin access required.'; }
    if(!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false){ return 'Invalid IP address.'; }
    try{
        GhotiIpBlockStore::remove(inet_ntop(inet_pton($ip)));
        ghoti::logInfo('ghoti.security.php:unblock', 'Removed automatic block for '.$ip.' by uid '.ghoti_current_user_id());
        return true;
    }catch(Throwable $e){
        ghoti::logException('ghoti.security.php:unblock', $e);
        return 'Could not update the automatic blacklist.';
    }
}

if(function_exists('ghoti_async_register')){ ghoti_async_register('clearAutoBlockedIp'); }
