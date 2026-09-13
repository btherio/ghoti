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
    $expired = !isset($_SESSION['last_activity']) || time() - (int)$_SESSION['last_activity'] > 1800;
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
