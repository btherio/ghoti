<?php
/*
 * ghoti.totp.php - authenticator-app codes (RFC 6238 TOTP over RFC 4226 HOTP).
 *
 * The parameters every mainstream authenticator app assumes: HMAC-SHA1,
 * 30-second steps, 6 digits. Pure functions only - storage and the sign-in
 * flow live in the login module.
 *
 * Replay: a code stays valid for its whole step (and the one either side, for
 * clock drift), so a code seen over someone's shoulder could otherwise be used
 * again. ghoti_totp_verify() returns the step it matched, and the caller stores
 * it; a later sign-in must match a strictly later step.
 */

const GHOTI_TOTP_PERIOD = 30;
const GHOTI_TOTP_DIGITS = 6;
const GHOTI_TOTP_DRIFT  = 1; //steps accepted either side of now

//A new shared secret: 160 random bits (the HMAC-SHA1 block the RFC suggests),
//base32 as apps expect it.
function ghoti_totp_new_secret(){
	return ghoti_base32_encode(random_bytes(20));
}

function ghoti_base32_encode($bytes){
	$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$bits = '';
	foreach(str_split($bytes) as $byte){ $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT); }
	$out = '';
	foreach(str_split($bits, 5) as $chunk){ $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))]; }
	return $out;
}

//Returns the raw bytes, or null for anything that is not base32.
function ghoti_base32_decode($text){
	$text = strtoupper(preg_replace('/[\s=-]+/', '', (string)$text));
	if($text === '' || !preg_match('/^[A-Z2-7]+$/', $text)){ return null; }
	$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$bits = '';
	foreach(str_split($text) as $char){ $bits .= str_pad(decbin(strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT); }
	$out = '';
	foreach(str_split($bits, 8) as $byte){
		if(strlen($byte) === 8){ $out .= chr(bindec($byte)); }
	}
	return $out;
}

//The code for one time step.
function ghoti_totp_code($secret, $step){
	$key = ghoti_base32_decode($secret);
	if($key === null){ return null; }
	$hash = hash_hmac('sha1', pack('J', (int)$step), $key, true);
	$offset = ord($hash[19]) & 0x0f;
	$value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16)
		| (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
	return str_pad((string)($value % (10 ** GHOTI_TOTP_DIGITS)), GHOTI_TOTP_DIGITS, '0', STR_PAD_LEFT);
}

function ghoti_totp_step($time = null){
	return intdiv($time ?? time(), GHOTI_TOTP_PERIOD);
}

/*
 * The step $code matches within the drift window, or false. Steps at or
 * before $lastStep are refused so a code cannot be used twice.
 */
function ghoti_totp_verify($secret, $code, $time = null, $lastStep = 0){
	$code = preg_replace('/\D+/', '', (string)$code);
	if(strlen($code) !== GHOTI_TOTP_DIGITS){ return false; }
	$now = ghoti_totp_step($time);
	for($step = $now - GHOTI_TOTP_DRIFT; $step <= $now + GHOTI_TOTP_DRIFT; $step++){
		if($step <= (int)$lastStep){ continue; }
		$expected = ghoti_totp_code($secret, $step);
		if($expected !== null && hash_equals($expected, $code)){ return $step; }
	}
	return false;
}

//The otpauth:// URI an authenticator app reads from the QR code.
function ghoti_totp_uri($secret, $accountName, $issuer){
	$issuer = str_replace(':', '', (string)$issuer);
	$label = rawurlencode($issuer).':'.rawurlencode(str_replace(':', '', (string)$accountName));
	return 'otpauth://totp/'.$label.'?'.http_build_query(array(
		'secret' => $secret,
		'issuer' => $issuer,
		'algorithm' => 'SHA1',
		'digits' => GHOTI_TOTP_DIGITS,
		'period' => GHOTI_TOTP_PERIOD,
	), '', '&', PHP_QUERY_RFC3986);
}
?>
