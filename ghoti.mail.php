<?php
/*
 * ghoti.mail.php - admin "Send Email" workspace (core, not a module).
 *
 * Lets an administrator write one message and deliver it to a single user,
 * a chosen set of users, or everyone with a usable address. Transport is the
 * mail module's SMTP client (Admin Menu -> Mail Settings); this file owns the
 * recipient policy, the message body, and the look of the message.
 *
 * Theming: the HTML part is painted with the design tokens of the site's own
 * default theme, so a message looks like the site that sent it whichever
 * theme is installed. Tokens are read straight out of the theme stylesheet
 * (:root { --accent: ... }) rather than duplicated here, which means a new
 * theme is styled correctly the moment it is added - see ghoti_mail_palette().
 */

require_once __DIR__.'/ghoti.security.php';

//A single synchronous request does the sending, so the list has to stay small
//enough to finish inside max_execution_time. Past this the admin is told to
//narrow the selection rather than being left guessing which half went out.
const GHOTI_MAIL_MAX_RECIPIENTS = 200;
const GHOTI_MAIL_MAX_SUBJECT = 190;
const GHOTI_MAIL_MAX_MESSAGE = 20000;

/* ================================================================== *
 *  Theme palette
 *
 *  Themes declare their colours as custom properties on :root. Token
 *  NAMES differ between themes (ghoticms/smurfius use --bg-deep and
 *  --text-primary, prosimii and cyber use --bg and --text), so each role
 *  below lists the names it accepts, best first. Everything resolves to
 *  an opaque hex colour: mail clients are far more reliable with hex and
 *  bgcolor than with rgba(), and half the themes are dark, so a value
 *  that silently fails to apply leaves text unreadable.
 * ================================================================== */

//Role => candidate custom-property names, in preference order.
function ghoti_mail_token_roles(){
	return array(
		'bg'       => array('bg-deep','bg','background','bg-page'),
		'surface'  => array('bg-panel','surface','bg-elevated','surface-2'),
		'inset'    => array('bg-elevated','surface-2','surface-3','bg-panel','bg-1','surface'),
		'text'     => array('text-primary','text','text-1'),
		'muted'    => array('text-muted','muted','text-2','text-dim','text-secondary'),
		'border'   => array('border-dim','border','border-strong','border-glow'),
		'accent'   => array('accent','accent-hot','cyber-cyan'),
		'onAccent' => array('on-accent'),
	);
}

/* The stylesheet that carries a theme's tokens. Themes ship several CSS files
 * (prosimii has four, only one of which declares :root), so prefer the
 * conventional names, then any stylesheet that actually has a :root block.
 * Print stylesheets are excluded: they are deliberately monochrome. */
function ghoti_mail_theme_stylesheet($theme){
	if(!is_string($theme) || !preg_match('/^[A-Za-z0-9_-]+$/', $theme)){ return ''; }
	$dir = __DIR__.'/css/'.$theme;
	if(!is_dir($dir)){ return ''; }
	$files = glob($dir.'/*.css');
	if(!$files){ return ''; }
	$candidates = array();
	foreach($files as $file){
		if(preg_match('/-print\.css$/', $file)){ continue; }
		$candidates[] = $file;
	}
	if(!$candidates){ return ''; }
	sort($candidates);
	$preferred = array($dir.'/'.$theme.'.css', $dir.'/style.css', $dir.'/'.$theme.'-modern.css');
	foreach($preferred as $path){
		if(in_array($path, $candidates, true) && ghoti_mail_has_root_block($path)){ return $path; }
	}
	foreach($candidates as $path){
		if(ghoti_mail_has_root_block($path)){ return $path; }
	}
	return '';
}

function ghoti_mail_has_root_block($path){
	$css = @file_get_contents($path, false, null, 0, 262144);
	return is_string($css) && ghoti_mail_parse_tokens($css) !== array();
}

/*
 * Every --token: value pair a theme declares at document level. Themes do not
 * agree on where that is: most use :root, but the cyber theme scopes its
 * palette to body.cyber-theme because it is applied as a body class. Both are
 * read, :root first, and the first declaration of a name wins - so a
 * media-query or state override later in the file cannot replace the base
 * value the message should use.
 *
 * Intentionally a shallow scan rather than a CSS parser: tokens are simple
 * declarations, and anything unparseable falls back to the neutral palette.
 */
function ghoti_mail_parse_tokens($css){
	$tokens = array();
	if(!is_string($css) || $css === ''){ return $tokens; }
	//Strip comments so a commented-out declaration is not read as live.
	$css = preg_replace('#/\*.*?\*/#s', '', $css);
	if($css === null){ return $tokens; }
	//The selector charset excludes braces, so each match starts where the
	//previous block ended - no leading "}" to anchor on, which would make
	//preg_match_all() skip every other block by consuming it.
	if(!preg_match_all('/([^{}@]+)\{([^{}]*)\}/s', $css, $blocks, PREG_SET_ORDER)){ return $tokens; }

	$rootBlocks = array();
	$hostBlocks = array();
	foreach($blocks as $block){
		$selector = trim($block[1]);
		if(strpos($selector, '--') === 0){ continue; } //a value, not a selector
		if(strpos($block[2], '--') === false){ continue; }
		if(strpos($selector, ':root') !== false){ $rootBlocks[] = $block[2]; continue; }
		//A theme root applied as an element class, e.g. "body.cyber-theme".
		if(preg_match('/^(?:html|body)[A-Za-z0-9_.#-]*$/', $selector)){ $hostBlocks[] = $block[2]; }
	}
	foreach(array_merge($rootBlocks, $hostBlocks) as $declarations){
		if(!preg_match_all('/--([A-Za-z0-9_-]+)\s*:\s*([^;]+);/', $declarations, $pairs, PREG_SET_ORDER)){ continue; }
		foreach($pairs as $pair){
			$name = $pair[1];
			if(!isset($tokens[$name])){ $tokens[$name] = trim($pair[2]); }
		}
	}
	return $tokens;
}

/* Parse one CSS colour into array(r,g,b,alpha), or null when it is not a
 * colour this needs to understand (gradients, none, keywords beyond the few
 * below). var() references are followed against $tokens. */
function ghoti_mail_parse_color($value, array $tokens = array(), $depth = 0){
	if(!is_string($value) || $depth > 4){ return null; }
	$value = trim($value);
	if($value === ''){ return null; }

	if(preg_match('/^var\(\s*--([A-Za-z0-9_-]+)\s*(?:,(.*))?\)$/s', $value, $m)){
		if(isset($tokens[$m[1]])){
			$resolved = ghoti_mail_parse_color($tokens[$m[1]], $tokens, $depth + 1);
			if($resolved !== null){ return $resolved; }
		}
		return isset($m[2]) ? ghoti_mail_parse_color($m[2], $tokens, $depth + 1) : null;
	}

	$named = array('white'=>array(255,255,255,1.0), 'black'=>array(0,0,0,1.0), 'transparent'=>array(0,0,0,0.0));
	$lower = strtolower($value);
	if(isset($named[$lower])){ return $named[$lower]; }

	if(preg_match('/^#([0-9A-Fa-f]{3,8})$/', $value, $m)){
		$hex = $m[1];
		if(strlen($hex) === 3 || strlen($hex) === 4){
			$expanded = '';
			foreach(str_split($hex) as $char){ $expanded .= $char.$char; }
			$hex = $expanded;
		}
		if(strlen($hex) !== 6 && strlen($hex) !== 8){ return null; }
		return array(
			hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)),
			strlen($hex) === 8 ? hexdec(substr($hex, 6, 2)) / 255 : 1.0,
		);
	}

	//rgb()/rgba(), both the legacy comma form and the modern space form.
	if(preg_match('/^rgba?\(([^)]*)\)$/i', $value, $m)){
		$parts = preg_split('#[\s,/]+#', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
		if(count($parts) < 3){ return null; }
		$channel = function($part){
			$part = trim($part);
			if(substr($part, -1) === '%'){ return (float)$part * 255 / 100; }
			return (float)$part;
		};
		$alpha = 1.0;
		if(isset($parts[3])){
			$alpha = substr(trim($parts[3]), -1) === '%' ? (float)$parts[3] / 100 : (float)$parts[3];
		}
		return array(
			(int)round(max(0, min(255, $channel($parts[0])))),
			(int)round(max(0, min(255, $channel($parts[1])))),
			(int)round(max(0, min(255, $channel($parts[2])))),
			max(0.0, min(1.0, $alpha)),
		);
	}
	return null;
}

/* Flatten a possibly-translucent colour onto an opaque backdrop. Mail clients
 * do not composite layers the way a browser does, so this is done here. */
function ghoti_mail_flatten($color, $backdrop){
	if($color === null){ return null; }
	$alpha = $color[3];
	if($alpha >= 1.0){ return array($color[0], $color[1], $color[2], 1.0); }
	$base = $backdrop === null ? array(255,255,255,1.0) : $backdrop;
	return array(
		(int)round($color[0] * $alpha + $base[0] * (1 - $alpha)),
		(int)round($color[1] * $alpha + $base[1] * (1 - $alpha)),
		(int)round($color[2] * $alpha + $base[2] * (1 - $alpha)),
		1.0,
	);
}

function ghoti_mail_hex($color){
	return sprintf('#%02x%02x%02x', (int)$color[0], (int)$color[1], (int)$color[2]);
}

//Relative luminance (WCAG), used to decide dark-on-light vs light-on-dark.
function ghoti_mail_luminance($color){
	$channel = function($value){
		$value = $value / 255;
		return $value <= 0.03928 ? $value / 12.92 : pow(($value + 0.055) / 1.055, 2.4);
	};
	return 0.2126 * $channel($color[0]) + 0.7152 * $channel($color[1]) + 0.0722 * $channel($color[2]);
}

//WCAG contrast ratio between two opaque colours, 1.0 (identical) to 21.0.
function ghoti_mail_contrast($a, $b){
	$la = ghoti_mail_luminance($a);
	$lb = ghoti_mail_luminance($b);
	return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/* The font stack. Themes name display/mono families that the reader almost
 * certainly does not have installed, so the theme's first family is kept as a
 * hint and a portable stack is appended behind it. */
function ghoti_mail_font_stack(array $tokens){
	$fallback = 'Helvetica, Arial, sans-serif';
	foreach(array('font-display','font','font-mono') as $name){
		if(!isset($tokens[$name])){ continue; }
		$families = explode(',', $tokens[$name]);
		$first = trim($families[0], " \t\"'");
		if($first === '' || stripos($first, 'var(') === 0){ continue; }
		if(!preg_match('/^[A-Za-z0-9 ._-]{1,40}$/', $first)){ continue; }
		$quoted = strpos($first, ' ') !== false ? '"'.$first.'"' : $first;
		return $quoted.', '.$fallback;
	}
	return $fallback;
}

/*
 * The palette the message is painted with. $theme defaults to the site's
 * configured default theme: async endpoints are dispatched before index.php
 * applies the per-visitor theme override, so ghoti::$defaultTheme here is the
 * saved site setting rather than whatever the admin is personally viewing.
 *
 * Always returns a complete, opaque palette - an unreadable theme file or a
 * token this cannot parse degrades to the neutral defaults, never to a
 * missing colour.
 */
function ghoti_mail_palette($theme = null){
	static $cache = array();
	if($theme === null){ $theme = class_exists('ghoti') ? ghoti::$defaultTheme : ''; }
	$key = (string)$theme;
	if(isset($cache[$key])){ return $cache[$key]; }

	$tokens = array();
	$path = ghoti_mail_theme_stylesheet($theme);
	if($path !== ''){
		$css = @file_get_contents($path);
		$tokens = ghoti_mail_parse_tokens($css);
	}

	$roles = ghoti_mail_token_roles();
	$pick = function($role) use ($roles, $tokens){
		foreach($roles[$role] as $name){
			if(!isset($tokens[$name])){ continue; }
			$color = ghoti_mail_parse_color($tokens[$name], $tokens);
			if($color !== null && $color[3] > 0){ return $color; }
		}
		return null;
	};

	$bg = ghoti_mail_flatten($pick('bg'), array(255,255,255,1.0));
	if($bg === null){ $bg = array(243,240,232,1.0); }
	$dark = ghoti_mail_luminance($bg) < 0.4;

	$surface = ghoti_mail_flatten($pick('surface'), $bg);
	if($surface === null){ $surface = $dark ? array(20,24,36,1.0) : array(255,255,255,1.0); }
	//Fallbacks for everything inside the panel key off the PANEL, not the page:
	//a theme can put a light card on a dark ground (mahogany does), and text
	//defaulted from the page colour would then be invisible on the card.
	$panelDark = ghoti_mail_luminance($surface) < 0.4;
	$inset = ghoti_mail_flatten($pick('inset'), $surface);
	if($inset === null){ $inset = $panelDark ? array(28,32,46,1.0) : array(240,238,232,1.0); }
	$text = ghoti_mail_flatten($pick('text'), $surface);
	if($text === null){ $text = $panelDark ? array(238,240,246,1.0) : array(32,33,30,1.0); }
	$muted = ghoti_mail_flatten($pick('muted'), $surface);
	if($muted === null){ $muted = $panelDark ? array(148,155,172,1.0) : array(101,105,94,1.0); }
	$border = ghoti_mail_flatten($pick('border'), $surface);
	if($border === null){ $border = $panelDark ? array(58,64,80,1.0) : array(214,210,200,1.0); }
	$accent = ghoti_mail_flatten($pick('accent'), $surface);
	if($accent === null){ $accent = array(244,91,53,1.0); }

	//Header text sits on the accent block. The theme's own --on-accent is used
	//when it is legible there (3:1, the WCAG AA threshold for the large bold
	//type this is), and replaced with black or white when it is not - a theme
	//that never pairs those two colours must not produce an unreadable header.
	$onAccent = ghoti_mail_flatten($pick('onAccent'), $accent);
	if($onAccent === null || ghoti_mail_contrast($onAccent, $accent) < 3.0){
		$dark_on = array(12,13,16,1.0);
		$light_on = array(255,255,255,1.0);
		$onAccent = ghoti_mail_contrast($dark_on, $accent) >= ghoti_mail_contrast($light_on, $accent) ? $dark_on : $light_on;
	}

	$palette = array(
		'theme'    => $key,
		'dark'     => $panelDark,
		'bg'       => ghoti_mail_hex($bg),
		'surface'  => ghoti_mail_hex($surface),
		'inset'    => ghoti_mail_hex($inset),
		'text'     => ghoti_mail_hex($text),
		'muted'    => ghoti_mail_hex($muted),
		'border'   => ghoti_mail_hex($border),
		'accent'   => ghoti_mail_hex($accent),
		'onAccent' => ghoti_mail_hex($onAccent),
		'font'     => ghoti_mail_font_stack($tokens),
	);
	$cache[$key] = $palette;
	return $palette;
}

/* The site's public base URL, or '' when the operator has not configured one.
 * Reuses the password-reset rules (HTTPS, no credentials, no query) because a
 * link mailed to every user deserves the same scrutiny as a reset link. */
function ghoti_mail_site_url(){
	try{
		$url = ghoti_password_reset_url();
	}catch(Throwable $e){
		return '';
	}
	return substr($url, 0, -strlen('/password-reset.php'));
}

/* ================================================================== *
 *  Message rendering
 * ================================================================== */

/* Admin-written plain text -> escaped HTML paragraphs. The compose field is
 * plain text on purpose: accepting markup would add an injection surface for
 * nothing a newsletter of this kind needs. */
function ghoti_mail_body_html($message, array $palette){
	$paragraphs = preg_split('/\n\s*\n/', str_replace("\r\n", "\n", (string)$message));
	$out = '';
	foreach($paragraphs as $paragraph){
		$paragraph = trim($paragraph, "\n");
		if(trim($paragraph) === ''){ continue; }
		$escaped = htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8');
		//XHTML-style break, matching the self-closed tags the rest of the
		//message uses - some older mail clients are stricter than browsers.
		$escaped = nl2br($escaped);
		$out .= '<p style="margin:0 0 16px 0;color:'.$palette['text'].';font-size:15px;line-height:1.65;">'.$escaped."</p>\n";
	}
	if($out === ''){
		$out = '<p style="margin:0;color:'.$palette['text'].';font-size:15px;line-height:1.65;">&nbsp;</p>'."\n";
	}
	return $out;
}

/*
 * The themed HTML part. Table layout with every colour inlined and repeated
 * as a bgcolor attribute: mail clients strip <style> blocks, ignore flexbox,
 * and some force a light background behind the message - so each text node
 * carries its own explicit colour and each block its own background.
 */
function ghoti_mail_render_html($siteTitle, $subject, $message, array $palette = null, $siteUrl = null){
	if($palette === null){ $palette = ghoti_mail_palette(); }
	if($siteUrl === null){ $siteUrl = ghoti_mail_site_url(); }
	$esc = function($value){ return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
	$title = $esc($siteTitle);
	$heading = $esc($subject);
	$font = $esc($palette['font']);
	$body = ghoti_mail_body_html($message, $palette);

	$footerLink = '';
	if($siteUrl !== ''){
		$footerLink = ' <a href="'.$esc($siteUrl).'" style="color:'.$palette['accent'].';text-decoration:underline;">'.$esc($siteUrl).'</a>';
	}

	$html  = '<!DOCTYPE html>'."\n".'<html><head><meta charset="utf-8" />'
		.'<meta name="viewport" content="width=device-width, initial-scale=1" />'
		.'<title>'.$heading.'</title></head>'."\n";
	$html .= '<body style="margin:0;padding:0;background-color:'.$palette['bg'].';">'."\n";
	$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="'.$palette['bg'].'" style="background-color:'.$palette['bg'].';margin:0;padding:0;">'."\n";
	$html .= '<tr><td align="center" style="padding:24px 12px;">'."\n";
	$html .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="'.$palette['surface'].'" style="width:100%;max-width:600px;background-color:'.$palette['surface'].';border:1px solid '.$palette['border'].';border-radius:8px;">'."\n";

	//Header bar: the site's accent, the site's name.
	$html .= '<tr><td bgcolor="'.$palette['accent'].'" style="background-color:'.$palette['accent'].';padding:20px 24px;border-radius:8px 8px 0 0;font-family:'.$font.';color:'.$palette['onAccent'].';font-size:20px;font-weight:bold;">'.$title.'</td></tr>'."\n";

	$html .= '<tr><td style="padding:24px;font-family:'.$font.';color:'.$palette['text'].';">'."\n";
	$html .= '<h1 style="margin:0 0 16px 0;font-size:19px;line-height:1.35;color:'.$palette['text'].';">'.$heading.'</h1>'."\n";
	$html .= $body;
	$html .= '</td></tr>'."\n";

	$html .= '<tr><td bgcolor="'.$palette['inset'].'" style="background-color:'.$palette['inset'].';padding:16px 24px;border-top:1px solid '.$palette['border'].';border-radius:0 0 8px 8px;font-family:'.$font.';color:'.$palette['muted'].';font-size:12px;line-height:1.5;">'
		.'You received this message because you have an account on '.$title.'.'.$footerLink.'</td></tr>'."\n";

	$html .= '</table>'."\n".'</td></tr>'."\n".'</table>'."\n".'</body></html>';
	return $html;
}

/* The text/plain part. Not a stripped copy of the HTML: it is the admin's
 * message as typed, which is what a plain-text reader should get. */
function ghoti_mail_render_text($siteTitle, $subject, $message, $siteUrl = null){
	if($siteUrl === null){ $siteUrl = ghoti_mail_site_url(); }
	$clean = function($value){ return str_replace("\r\n", "\n", (string)$value); };
	$text  = $clean($subject)."\n".str_repeat('=', max(3, min(60, strlen($clean($subject)))))."\n\n";
	$text .= trim($clean($message))."\n\n";
	$text .= "--\n";
	$text .= 'You received this message because you have an account on '.$clean($siteTitle).".\n";
	if($siteUrl !== ''){ $text .= $siteUrl."\n"; }
	return $text;
}

/* ================================================================== *
 *  Recipients and delivery
 * ================================================================== */

/*
 * Resolve the audience. $mode is 'all', 'admins' or 'selected'; 'selected'
 * takes user ids. Returns array('recipients'=>[[userId,userName,email],...],
 * 'skipped'=>int) - users without a usable address are reported rather than
 * silently dropped, because "sent to 40 of 47" is the thing an admin needs
 * to know afterwards.
 */
function ghoti_mail_resolve_recipients($mode, array $userIds, array $directory){
	$wanted = array();
	foreach($userIds as $id){ $wanted[(int)$id] = true; }
	$recipients = array();
	$seen = array();
	$skipped = 0;
	foreach($directory as $user){
		$id = (int)($user['userId'] ?? 0);
		if($mode === 'admins' && empty($user['admin'])){ continue; }
		if($mode === 'selected' && !isset($wanted[$id])){ continue; }
		$email = trim((string)($user['email'] ?? ''));
		if(!filter_var($email, FILTER_VALIDATE_EMAIL)){ $skipped++; continue; }
		$key = strtolower($email);
		if(isset($seen[$key])){ continue; }
		$seen[$key] = true;
		$recipients[] = array(
			'userId'   => $id,
			'userName' => (string)($user['userName'] ?? ''),
			'email'    => $email,
		);
	}
	return array('recipients'=>$recipients, 'skipped'=>$skipped);
}

/*
 * Deliver one message per recipient. Separate messages, not one message with
 * many recipients: users never see each other's addresses, and one rejection
 * cannot cancel the rest of the run. $mailer is injected so this is testable
 * without SMTP; it needs the mail module's send() shape.
 */
function ghoti_mail_send_bulk($mailer, array $recipients, $subject, $textBody, $htmlBody){
	$sent = 0;
	$failed = 0;
	$failedAddresses = array();
	foreach($recipients as $recipient){
		try{
			$result = $mailer->send($recipient['email'], $recipient['userName'], $subject, $textBody, array(), $htmlBody);
		}catch(Throwable $e){
			$result = false;
			ghoti::logException('ghoti.mail.php:send', $e, 'delivery raised for a recipient');
		}
		if($result === true){
			$sent++;
			continue;
		}
		$failed++;
		if(count($failedAddresses) < 10){ $failedAddresses[] = $recipient['email']; }
		ghoti::logWarn('ghoti.mail.php:send', 'Delivery failed for '.$recipient['email'].(is_string($result) ? ': '.$result : ''));
	}
	return array('sent'=>$sent, 'failed'=>$failed, 'failedAddresses'=>$failedAddresses);
}

/* The configured mailer, or null when mail is off/unavailable. Resolved the
 * same way the alert path does: the request's module instance if it is still
 * around, otherwise a fresh one. */
function ghoti_mail_mailer(){
	if(isset($_SESSION['mailObj']) && is_object($_SESSION['mailObj'])){ return $_SESSION['mailObj']; }
	if(!in_array('mail', ghoti::enabledModules(), true) || !is_file(__DIR__.'/mod/mail/mail.php')){ return null; }
	require_once __DIR__.'/mod/mail/mail.php';
	return new mail();
}

/* True when Mail Settings is configured well enough to send anything. */
function ghoti_mail_is_enabled($mailer){
	if(!is_object($mailer) || !isset($mailer->maildb)){ return false; }
	try{
		$settings = $mailer->maildb->getSettings();
	}catch(Throwable $e){
		return false;
	}
	return !empty($settings['enabled']) && trim((string)($settings['fromAddress'] ?? '')) !== '';
}

/* ================================================================== *
 *  Endpoints
 * ================================================================== */

function printComposeMail(){
	if(!ghoti_require_admin()){ return '<h1>Send Email</h1><p>Admin access required.</p>'; }
	$mailer = ghoti_mail_mailer();
	$directory = array();
	$directoryError = '';
	try{
		$users = new GhotiUserDirectory();
		$directory = $users->recipients();
	}catch(Throwable $e){
		ghoti::logException('ghoti.mail.php:printComposeMail', $e);
		$directoryError = 'The user list could not be read. Check the database and the log.';
	}
	return ghoti_mail_render_compose($directory, ghoti_mail_is_enabled($mailer), $directoryError);
}

/*
 * Send the composed message. $payload is the decoded object the browser
 * sends: mode, userIds, subject, message. Returns the same result shape the
 * other admin endpoints use - true-ish summary string on success, error
 * string on refusal - so the JS layer stays trivial.
 */
function sendComposedMail($payload){
	if(!ghoti_require_admin()){ return 'Admin access required.'; }
	if(!is_array($payload)){ return 'Invalid request.'; }

	$mailer = ghoti_mail_mailer();
	if(!ghoti_mail_is_enabled($mailer)){
		return 'Mail sending is off. Configure and enable it under Admin Menu -> Mail Settings first.';
	}

	$mode = strtolower(trim((string)($payload['mode'] ?? '')));
	if(!in_array($mode, array('all','admins','selected'), true)){ return 'Choose who the message goes to.'; }

	$v = ghoti_validate();
	try{
		$subject = $v->text($payload['subject'] ?? '', GHOTI_MAIL_MAX_SUBJECT, true, 'Subject');
		$message = $v->multilineText($payload['message'] ?? '', GHOTI_MAIL_MAX_MESSAGE, true, 'Message');
	}catch(Exception $e){
		return $e->getMessage();
	}

	$userIds = array();
	if($mode === 'selected'){
		$raw = $payload['userIds'] ?? array();
		if(!is_array($raw) || !$raw){ return 'Select at least one user.'; }
		foreach($raw as $id){
			$id = (int)$id;
			if($id > 0){ $userIds[$id] = $id; }
		}
		if(!$userIds){ return 'Select at least one user.'; }
		$userIds = array_values($userIds);
	}

	try{
		$users = new GhotiUserDirectory();
		$directory = $users->recipients();
	}catch(Throwable $e){
		ghoti::logException('ghoti.mail.php:sendComposedMail', $e);
		return 'The user list could not be read. Check the database and the log.';
	}

	$resolved = ghoti_mail_resolve_recipients($mode, $userIds, $directory);
	$recipients = $resolved['recipients'];
	if(!$recipients){
		return $resolved['skipped'] > 0
			? 'None of the selected users has a valid e-mail address. Fix their addresses in Manage Users.'
			: 'No users matched that selection.';
	}
	if(count($recipients) > GHOTI_MAIL_MAX_RECIPIENTS){
		return 'That is '.count($recipients).' recipients; this screen sends at most '.GHOTI_MAIL_MAX_RECIPIENTS
			.' per message so the request cannot time out part-way. Send to a selected group instead.';
	}

	$siteTitle = ghoti::$siteTitle;
	$siteUrl = ghoti_mail_site_url();
	$htmlBody = ghoti_mail_render_html($siteTitle, $subject, $message, ghoti_mail_palette(), $siteUrl);
	$textBody = ghoti_mail_render_text($siteTitle, $subject, $message, $siteUrl);

	//A run of up to GHOTI_MAIL_MAX_RECIPIENTS SMTP conversations can outlast the
	//default execution limit, and a half-finished run is the one outcome with no
	//clean recovery - so ask for more time and keep going if the admin's browser
	//goes away. Both are best effort; the recipient cap is what actually bounds
	//the work.
	@set_time_limit(300);
	@ignore_user_abort(true);

	$result = ghoti_mail_send_bulk($mailer, $recipients, $subject, $textBody, $htmlBody);
	ghoti::logInfo('ghoti.mail.php:sendComposedMail', 'UID:'.ghoti_current_user_id().' mailed '.$result['sent']
		.' recipient(s), '.$result['failed'].' failed, mode='.$mode);

	$summary = 'Sent to '.$result['sent'].' of '.count($recipients).' recipient'.(count($recipients) === 1 ? '' : 's').'.';
	if($resolved['skipped'] > 0){
		$summary .= ' '.$resolved['skipped'].' user'.($resolved['skipped'] === 1 ? ' was' : 's were').' skipped for a missing or invalid address.';
	}
	if($result['failed'] > 0){
		$summary .= ' Delivery failed for: '.implode(', ', $result['failedAddresses'])
			.($result['failed'] > count($result['failedAddresses']) ? ' and others' : '').'. Check Mail Settings and the log.';
	}
	return $summary;
}

ghoti_async_register('printComposeMail', 'sendComposedMail');

/* ================================================================== *
 *  UI
 * ================================================================== */

function ghoti_mail_render_compose(array $directory, $mailEnabled, $directoryError = ''){
	$esc = function($value){ return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
	$palette = ghoti_mail_palette();

	//Counted through the same resolver the send uses, so the number on the
	//button is the number of messages that would actually go out - two
	//accounts sharing an address are one recipient, not two.
	$withAddress = count(ghoti_mail_resolve_recipients('all', array(), $directory)['recipients']);

	$o  = '<section id="ghotiComposeMail" class="ghotiAdminPanel">';
	$o .= '<div class="ghotiCrudHeader"><div><h1>Send Email</h1>';
	$o .= '<p class="ghotiHelpText">Write one message and send it to a single user, a selection, or everyone with an address. Each person receives their own copy, so recipients never see each other.</p></div></div>';

	if(!$mailEnabled){
		$o .= '<p class="ghotiHelpText"><b>Mail sending is off.</b> Configure the SMTP server under <b>Admin Menu &rarr; Mail Settings</b>, tick <b>Enabled</b>, and send a test message. Composing is disabled until then.</p>';
	}
	if($directoryError !== ''){
		$o .= '<p class="ghotiHelpText">'.$esc($directoryError).'</p>';
	}

	$disabled = ($mailEnabled && $withAddress > 0) ? '' : ' disabled="disabled"';

	$o .= '<form id="composeMailForm" class="ghotiForm" action="#" onsubmit="sendComposedMail(); return false;">';
	$o .= '<fieldset class="siteSettingsSection"><legend>Recipients</legend>';
	$o .= '<div class="siteSettingsChoices">';
	$o .= '<label class="ghotiInlineChoice"><input type="radio" name="composeMailMode" value="all" checked="checked" onchange="composeMailModeChanged();"'.$disabled.' /> All users <i>('.$withAddress.' with an address)</i></label>';
	$o .= '<label class="ghotiInlineChoice"><input type="radio" name="composeMailMode" value="admins" onchange="composeMailModeChanged();"'.$disabled.' /> Administrators only</label>';
	$o .= '<label class="ghotiInlineChoice"><input type="radio" name="composeMailMode" value="selected" onchange="composeMailModeChanged();"'.$disabled.' /> Selected users</label>';
	$o .= '</div>';

	$o .= '<div id="composeMailUserList" class="composeMailUserList" hidden="hidden">';
	if(!$directory){
		$o .= '<p class="ghotiEmptyState">No user accounts were found.</p>';
	}else{
		$o .= '<ul>';
		foreach($directory as $user){
			$email = trim((string)($user['email'] ?? ''));
			$usable = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
			$label = $esc($user['userName'] ?? '').' <small>'.($email === '' ? 'no address' : $esc($email)).'</small>';
			if(!empty($user['admin'])){ $label .= ' <small>admin</small>'; }
			$o .= '<li><label class="ghotiInlineChoice"><input type="checkbox" class="composeMailUser" value="'.(int)($user['userId'] ?? 0).'"'
				.($usable ? '' : ' disabled="disabled"').' /> '.$label.'</label></li>';
		}
		$o .= '</ul>';
	}
	$o .= '</div></fieldset>';

	$o .= '<fieldset class="siteSettingsSection"><legend>Message</legend>';
	$o .= '<label class="ghotiField"><span>Subject</span><input type="text" id="composeMail-subject" maxlength="'.GHOTI_MAIL_MAX_SUBJECT.'" size="60"'.$disabled.' /></label>';
	$o .= '<label class="ghotiField"><span>Message</span><textarea id="composeMail-message" rows="12" maxlength="'.GHOTI_MAIL_MAX_MESSAGE.'" spellcheck="true"'.$disabled.'></textarea></label>';
	$o .= '<p class="ghotiHelpText">Plain text. A blank line starts a new paragraph. The message is styled with the site&rsquo;s <b>'.$esc($palette['theme']).'</b> theme colours and also sent as plain text for readers that do not show HTML.</p>';
	$o .= '</fieldset>';

	$o .= '<div class="ghotiFormActions"><button type="button" class="ghotiButton" onclick="sendComposedMail();"'.$disabled.'>Send Message</button></div>';
	$o .= '<span id="composeMailFeedback" role="status" aria-live="polite"></span>';
	$o .= '</form>';

	//A live sample of the themed message, painted with the same palette the
	//real mail uses - an admin should see the thing before sending it to
	//everyone, and the preview cannot drift from the template.
	$o .= '<h2>Preview</h2>';
	$o .= '<div class="composeMailPreview" style="background-color:'.$palette['bg'].';padding:12px;border:1px solid '.$palette['border'].';border-radius:8px;overflow:auto;">';
	$o .= ghoti_mail_preview_markup(ghoti::$siteTitle, $palette);
	$o .= '</div>';

	$o .= ghoti_docs_panel('How sending email works', 'recipients, delivery, limits', array(
		array('heading'=>'Who gets it', 'list'=>array(
			'<b>All users</b> is every account with a valid address; <b>Administrators only</b> is the same list Mail Settings and critical alerts use; <b>Selected users</b> is one or more accounts you tick - that is how you mail a single person.',
			'Accounts without a valid address are skipped, and the count is reported back to you. Fix addresses in <b>Manage Users</b>.')),
		array('heading'=>'Delivery', 'list'=>array(
			'One message per recipient, sent through <b>Mail Settings</b>. No one sees anyone else&rsquo;s address, and a rejection for one person does not stop the rest.',
			'At most '.GHOTI_MAIL_MAX_RECIPIENTS.' recipients per message, so the request finishes before PHP&rsquo;s execution limit. Send to selected groups for a larger audience.',
			'The result line reports how many were sent, skipped and failed. Failures are also written to the log.')),
		array('heading'=>'Appearance', 'list'=>array(
			'Colours and fonts come from the site&rsquo;s default theme, read from its stylesheet when the message is built - change the theme in <b>Site Settings</b> and the next message follows it.',
			'The body is plain text: blank lines become paragraphs. HTML typed into the box is sent as literal text, never as markup.'))
	));
	$o .= '</section>';
	return $o;
}

/* Miniature of the real template, used for the on-screen preview. Kept next
 * to the renderer so both move together. */
function ghoti_mail_preview_markup($siteTitle, array $palette){
	$esc = function($value){ return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
	$o  = '<div style="max-width:600px;margin:0 auto;background-color:'.$palette['surface'].';border:1px solid '.$palette['border'].';border-radius:8px;font-family:'.$esc($palette['font']).';">';
	$o .= '<div style="background-color:'.$palette['accent'].';color:'.$palette['onAccent'].';padding:20px 24px;border-radius:8px 8px 0 0;font-size:20px;font-weight:bold;">'.$esc($siteTitle).'</div>';
	$o .= '<div style="padding:24px;color:'.$palette['text'].';">';
	$o .= '<h3 id="composeMailPreviewSubject" style="margin:0 0 16px 0;font-size:19px;color:'.$palette['text'].';">Your subject appears here</h3>';
	$o .= '<div id="composeMailPreviewBody" style="color:'.$palette['text'].';font-size:15px;line-height:1.65;">Your message appears here.</div>';
	$o .= '</div>';
	$o .= '<div style="background-color:'.$palette['inset'].';color:'.$palette['muted'].';padding:16px 24px;border-top:1px solid '.$palette['border'].';border-radius:0 0 8px 8px;font-size:12px;">You received this message because you have an account on '.$esc($siteTitle).'.</div>';
	$o .= '</div>';
	return $o;
}
?>
