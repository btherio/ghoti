<?php
if(!isset($ready, $error, $name, $email)){ http_response_code(404); exit; }
$escape = static fn($value) => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set up Ghoti</title>
<style>
:root{color-scheme:light dark;font:16px/1.5 system-ui,sans-serif;background:Canvas;color:CanvasText}
body{margin:0;padding:clamp(1rem,6vw,4rem)}
main{max-width:27rem;margin:0 auto;padding:clamp(1rem,5vw,2rem);border:1px solid GrayText;border-radius:1rem}
h1{margin:0 0 .5rem;line-height:1.15}
p{margin:0 0 1rem}
label{display:block;margin:0 0 .875rem;font-weight:600}
input,button{font:inherit;padding:.55rem .7rem;border-radius:.4rem;box-sizing:border-box}
input{display:block;width:100%;margin:.25rem 0 0;border:1px solid GrayText}
button{width:100%;border:1px solid transparent;background:#185cb1;color:white;font-weight:600;cursor:pointer}
:focus-visible{outline:2px solid Highlight;outline-offset:2px}
[role=alert]{border-inline-start:3px solid #b65028;padding-inline-start:.65rem}
</style>
</head>
<body>
<main>
    <h1>Set up Ghoti</h1>
    <?php if($ready): ?>
        <p>Create your administrator account. Ghoti will set up a local database for this site.</p>
    <?php endif; ?>
    <?php if($error !== ''): ?>
        <p role="alert"><?= $escape($error) ?></p>
    <?php endif; ?>
    <?php if($ready): ?>
        <form method="post" action="index.php">
            <input type="hidden" name="ghoti_install" value="1">
            <input type="hidden" name="token" value="<?= $escape(ghoti_csrf_token()) ?>">
            <label>Username
                <input name="adminName" value="<?= $escape($name) ?>" required maxlength="<?= validate::MAX_USERNAME ?>" autocomplete="username">
            </label>
            <label>Email
                <input name="adminEmail" type="email" value="<?= $escape($email) ?>" required maxlength="254" autocomplete="email">
            </label>
            <label>Password
                <input name="adminPassword" type="password" required minlength="<?= validate::MIN_PASSWORD ?>" maxlength="<?= validate::MAX_PASSWORD ?>" autocomplete="new-password">
            </label>
            <button type="submit">Create site</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
