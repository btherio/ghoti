<?php
declare(strict_types=1);
/*
 * password-reset.php - mail-based password recovery.
 *
 * Replaces the previous "type your email + a brand new password" flow, which
 * let anyone who knew (or guessed) an account's email address reset that
 * account's password outright - no proof the requester actually controls the
 * mailbox. This version is the standard two-step flow instead:
 *
 *   1. Visitor submits their email. If it matches exactly one account, a
 *      single-use, time-limited token is emailed to that address (via the
 *      `mail` module / your local Arch Linux mail server - see Admin Menu ->
 *      Mail Settings). The page shows the SAME message either way, so it
 *      cannot be used to test which addresses have accounts.
 *   2. Visitor follows the emailed link (this same script, with ?token=...)
 *      and sets a new password. The token is checked server-side
 *      (login.db.php: validatePasswordResetToken/resetPasswordWithToken),
 *      is single-use, and expires after 30 minutes.
 *
 * Runs standalone (outside index.php's full module bootstrap) using its own
 * hardened session, exactly like the file it replaces, but now pulls in
 * ghoti.php + the login and mail modules' DB classes directly so it can
 * reuse the same password hashing, rate limiting, and mail sending logic the
 * logged-in app uses - instead of hand-rolling a parallel implementation.
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
session_name('ghoti_password_reset');
session_set_cookie_params(array('lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict'));
session_start();
if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

require_once __DIR__.'/ghoti.php';
require_once __DIR__.'/mod/login/login.db.php';
require_once __DIR__.'/mod/mail/mail.db.php';
require_once __DIR__.'/mod/mail/mail.smtp.php';

$mode = isset($_GET['token']) || isset($_POST['token']) ? 'set' : 'request';
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$message = '';
$success = false;   // request accepted / password actually changed
$complete = false;  // hide the form after a terminal outcome

function passwordResetBaseUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.:\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return $scheme.'://'.$host.strtok((string)($_SERVER['REQUEST_URI'] ?? '/password-reset.php'), '?');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Your form expired. Refresh the page and try again.');
        }

        if ($mode === 'request') {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Enter the email address saved on your account.');
            }
            $db = new logindb();
            $userId = $db->findUserIdByEmail($email);
            if ($userId !== null) {
                $rawToken = $db->createPasswordResetToken($userId, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
                if ($rawToken !== null) {
                    $link = passwordResetBaseUrl().'?token='.$rawToken;
                    $body = "A password reset was requested for your account on ".ghoti::$siteTitle.".\n\n"
                          . "To choose a new password, open this link within 30 minutes:\n".$link."\n\n"
                          . "If you did not request this, you can ignore this email - your password will not change.\n";
                    $mailDb = new maildb();
                    $settings = $mailDb->getSettings();
                    if ($settings['enabled']) {
                        $client = new MailSmtpClient($settings);
                        if (!$client->send($email, '', "Reset your ".ghoti::$siteTitle." password", $body)) {
                            ghoti::logError("password-reset.php", "send failed for userId $userId: ".$client->lastError);
                        }
                    } else {
                        ghoti::logError("password-reset.php", "mail sending is disabled - could not deliver reset link for userId $userId");
                    }
                }
                // If rate-limited, createPasswordResetToken() already logged it
                // and returned null - deliberately still reported as success below.
            }
            // Identical response whether or not the address matched an account,
            // or whether sending actually succeeded - the alternative leaks
            // which emails have accounts, or lets an attacker fingerprint a
            // broken mail server via response differences.
            $message = 'If that email address matches an account, a reset link has been sent to it. The link expires in 30 minutes.';
            $success = true;
            $complete = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        } else {
            $password = (string)($_POST['password'] ?? '');
            $confirm = (string)($_POST['confirm'] ?? '');
            if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
                throw new RuntimeException('This password reset link is invalid or has expired. Request a new one.');
            }
            if (strlen($password) < 8 || strlen($password) > 4096) {
                throw new RuntimeException('Use a password of at least 8 characters.');
            }
            if (!hash_equals($password, $confirm)) {
                throw new RuntimeException('The passwords do not match.');
            }
            $db = new logindb();
            $result = $db->resetPasswordWithToken($token, $password);
            if ($result !== true) {
                throw new RuntimeException(is_string($result) ? $result : 'The password could not be reset. Try again.');
            }
            $message = 'Your password has been reset. You can now sign in with your new password.';
            $success = true;
            $complete = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }
} elseif ($mode === 'set') {
    // GET with a token: validate up front so an already-used/expired link
    // shows a clear message instead of a form that will just fail on submit.
    try {
        $db = new logindb();
        if ($db->validatePasswordResetToken($token) === null) {
            throw new RuntimeException('This password reset link is invalid or has expired. Request a new one.');
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $complete = true; // hide the (pointless) set-password form
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset password</title><style>
:root{color-scheme:light dark}body{font:16px/1.5 system-ui,sans-serif;background:#eef2f6;color:#18202a;margin:0;padding:2rem}main{max-width:30rem;margin:7vh auto;background:#fff;padding:clamp(1.4rem,5vw,2.25rem);border-radius:.8rem;box-shadow:0 8px 30px #0002}label{display:block;margin:1rem 0;font-weight:600}.field{display:block;width:100%;box-sizing:border-box;padding:.75rem;margin-top:.35rem;font:inherit}button{padding:.75rem 1rem;font:inherit;cursor:pointer}.message{padding:.8rem;background:#f7e8e8;border-radius:.4rem}.success{background:#e4f4e7}.help{color:#536273;font-size:.94rem}.actions{display:flex;gap:1rem;align-items:center;flex-wrap:wrap;margin-top:1.25rem}@media(prefers-color-scheme:dark){body{background:#121820;color:#e9eef4}main{background:#202a35}.help{color:#b9c5d2}.message{background:#512d32}.success{background:#204a2a}}
</style></head><body><main>
<?php if ($mode === 'request'): ?>
<h1>Reset password</h1>
<p class="help">Enter the email address saved on your account. If it matches an account, we'll send a link to choose a new password.</p>
<?php if ($message !== ''): ?><p role="alert" class="message<?= $success ? ' success' : '' ?>"><?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></p><?php endif; ?>
<?php if (!$complete): ?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=htmlspecialchars((string)$_SESSION['csrf'],ENT_QUOTES,'UTF-8')?>">
<label>Email address<input class="field" type="email" name="email" maxlength="254" required autocomplete="email"></label>
<div class="actions"><button type="submit">Send reset link</button><a href="/">Cancel</a></div>
</form>
<?php else: ?><p><a href="/">Return to the site</a></p><?php endif; ?>

<?php else: /* mode === 'set' */ ?>
<h1>Choose a new password</h1>
<?php if ($message !== ''): ?><p role="alert" class="message<?= $success ? ' success' : '' ?>"><?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></p><?php endif; ?>
<?php if (!$complete): ?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=htmlspecialchars((string)$_SESSION['csrf'],ENT_QUOTES,'UTF-8')?>">
<input type="hidden" name="token" value="<?=htmlspecialchars($token,ENT_QUOTES,'UTF-8')?>">
<label>New password<input class="field" type="password" name="password" minlength="8" maxlength="4096" required autocomplete="new-password"></label>
<label>Confirm new password<input class="field" type="password" name="confirm" minlength="8" maxlength="4096" required autocomplete="new-password"></label>
<div class="actions"><button type="submit">Reset password</button><a href="/">Cancel</a></div>
</form>
<?php else: ?><p><a href="/">Return to the site</a></p><?php endif; ?>
<?php endif; ?>
</main></body></html>
