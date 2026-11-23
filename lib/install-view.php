<?php
if(!isset($view)){ http_response_code(404); return; }
$escape = static function($value): string {
    return htmlspecialchars(is_scalar($value) ? (string)$value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$step = $view['step'] ?? '';
$recovery = ($view['mode'] ?? '') === 'recovery';
$title = $view['title'] ?? ($recovery ? 'Recover the database connection' : 'Set up Ghoti');
$section = array('database' => 'Database', 'site' => 'Site settings', 'checks' => 'Installation checks');
$site = $view['site'] ?? array();
$fields = $view['fields'] ?? array();
$token = $step !== '' ? $escape(ghoti_csrf_token()) : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $escape($title) ?></title>
<style>
:root{color-scheme:dark;--bg:#11151b;--panel:#1b212a;--field:#131920;--text:#f1f4f8;--muted:#b0bdcc;--line:#394758;--accent:#9de8d5;--error:#ffb6b1}
*{box-sizing:border-box}
body{margin:0;padding:clamp(16px,5vw,60px) 16px;background:var(--bg);color:var(--text);font:1rem/1.5 system-ui,sans-serif}
main{width:min(100%,640px);margin:auto;padding:clamp(18px,4vw,34px);background:var(--panel);border:1px solid var(--line);border-radius:16px}
h1,h2{line-height:1.2;margin:0 0 .7rem}
h1{font-size:clamp(1.65rem,5vw,2.25rem)}
h2{font-size:1.25rem}
p{margin:.55rem 0 1rem;color:var(--muted)}
a{color:var(--accent)}
ol{display:flex;gap:6px 20px;flex-wrap:wrap;list-style:none;margin:18px 0 22px;padding:0 0 12px;border-bottom:1px solid var(--line);color:var(--muted);font-size:.9rem}
[aria-current]{color:var(--accent);font-weight:700}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
label{display:grid;gap:5px;font-size:.92rem;font-weight:600}
form{margin:16px 0 0}
input,select{width:100%;min-width:0;padding:.6rem .75rem;background:var(--field);color:var(--text);border:1px solid var(--line);border-radius:7px;font:inherit}
input[type=checkbox]{width:auto}
.checkbox{display:flex;align-items:center;gap:8px;margin-top:12px}
.actions{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin-top:18px}
button{border:1px solid var(--line);border-radius:7px;padding:.65rem .9rem;background:var(--field);color:var(--text);font:inherit;font-weight:650;cursor:pointer}
.primary{background:var(--accent);border-color:var(--accent);color:#102b25}
:focus-visible{outline:2px solid var(--accent);outline-offset:3px}
.error,.notice{padding:10px 14px;border:1px solid currentColor;border-radius:8px}
.error,.failed{color:var(--error)}
.notice,.passed{color:var(--accent)}
.checks{list-style:none;padding:0;margin:16px 0}
.checks li{padding:8px 0;border-bottom:1px solid var(--line);overflow-wrap:anywhere}
.checks strong{display:inline-block;margin-right:8px}
.checks small{display:block;color:var(--muted);font-size:.9rem}
fieldset{padding:0;margin:20px 0 0;min-width:0;border:0}
legend{padding:0 0 10px;font-size:1.1rem;font-weight:700}
code{overflow-wrap:anywhere}
@media(max-width:480px){.grid{grid-template-columns:minmax(0,1fr)}}
</style>
</head>
<body>
<main>
<h1><?= $escape($title) ?></h1>
<?php if($step === ''): ?>
    <p><?= $escape($view['message']) ?></p>
    <?php if(http_response_code() === 409): ?><a href="index.php">Open site</a><?php endif; ?>
<?php else: ?>
    <?php if(!$recovery): ?>
    <nav aria-label="Installation steps"><ol>
    <?php foreach($section as $name => $label): ?>
        <li<?= $step === $name ? ' aria-current="step"' : '' ?>><?= $escape($label) ?></li>
    <?php endforeach; ?>
    </ol></nav>
    <h2><?= $escape($section[$step]) ?></h2>
    <?php endif; ?>
    <?php if($view['error'] !== ''): ?><p class="error" role="alert"><?= $escape($view['error']) ?></p><?php endif; ?>
    <?php if($view['notice'] !== ''): ?><p class="notice" role="status"><?= $escape($view['notice']) ?></p><?php endif; ?>
    <?php if($view['checks'] !== null): ?>
    <ul class="checks" aria-label="Installation check results">
        <?php foreach($view['checks'] as $label => $failure): ?>
        <li><strong class="<?= $failure === null ? 'passed' : 'failed' ?>"><?= $failure === null ? 'Passed' : 'Failed' ?></strong>
            <?= $escape($label) ?>
            <?php if($failure !== null): ?><small><?= $escape($failure) ?></small><?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if($step === 'database'): ?>
        <?php if($view['connected'] && !$recovery): ?>
        <form method="post" action="index.php">
            <input type="hidden" name="token" value="<?= $token ?>">
            <p>Ghoti can connect using the current settings.</p>
            <button class="primary" name="ghoti_setup_action" value="use-database">Use current connection</button>
        </form>
        <?php endif; ?>
        <form method="post" action="index.php">
            <input type="hidden" name="token" value="<?= $token ?>">
            <button name="ghoti_setup_action" value="run-checks">Run installation checks</button>
        </form>
        <?php if($view['managed']): ?>
            <p>Update the connection in the file selected by <code>GHOTI_DB_CONFIG</code>, then reload this page.</p>
        <?php else: ?>
            <p>Enter the settings for your MySQL or MariaDB database. Ghoti saves them in <code>db.config.local.php</code>.</p>
            <form method="post" action="index.php">
                <input type="hidden" name="token" value="<?= $token ?>">
                <div class="grid">
                    <label>Host <input name="host" value="<?= $escape($fields['host']) ?>" maxlength="255" required spellcheck="false"></label>
                    <label>Port <input name="port" value="<?= $escape($fields['port']) ?>" maxlength="5" inputmode="numeric" required></label>
                    <label>Database name <input name="database" value="<?= $escape($fields['database']) ?>" maxlength="64" required spellcheck="false"></label>
                    <label>Character set <input name="charset" value="<?= $escape($fields['charset']) ?>" maxlength="32" spellcheck="false"></label>
                    <label>Database username <input name="username" value="<?= $escape($fields['username']) ?>" maxlength="128" required autocomplete="off"></label>
                    <label>Database password <input type="password" name="password" maxlength="512" autocomplete="off"></label>
                </div>
                <div class="actions">
                    <button name="ghoti_setup_action" value="test-database">Test connection</button>
                    <button class="primary" name="ghoti_setup_action" value="save-database"><?= $recovery ? 'Reconnect' : 'Save and continue' ?></button>
                </div>
            </form>
        <?php endif; ?>

    <?php elseif($step === 'site'): ?>
        <form method="post" action="index.php?step=site">
            <input type="hidden" name="token" value="<?= $token ?>">
            <div class="grid">
                <label>Site title <input name="siteTitle" value="<?= $escape($site['siteTitle']) ?>" maxlength="120" required></label>
                <label>Default theme
                    <select name="defaultTheme">
                        <?php foreach(glob(__DIR__.'/../css/*', GLOB_ONLYDIR) ?: array() as $path):
                            $theme = basename($path);
                            if(!ghoti::isValidTheme($theme)){ continue; } ?>
                        <option value="<?= $escape($theme) ?>"<?= $site['defaultTheme'] === $theme ? ' selected' : '' ?>><?= $escape($theme) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Public site URL (optional) <input type="url" name="seoSiteUrl" value="<?= $escape($site['seoSiteUrl']) ?>" placeholder="https://example.com"></label>
            </div>
            <label class="checkbox"><input type="checkbox" name="seoAllowIndexing" value="1"<?= $site['seoAllowIndexing'] ? ' checked' : '' ?>> Allow search engines to index this site</label>
            <div class="actions">
                <button class="primary" name="ghoti_setup_action" value="save-site">Continue to checks</button>
                <a href="index.php">Back to database</a>
            </div>
        </form>

    <?php else: ?>
        <p>Check that PHP can reach the database and write to the directories Ghoti uses.</p>
        <form method="post" action="index.php?step=checks">
            <input type="hidden" name="token" value="<?= $token ?>">
            <button name="ghoti_setup_action" value="run-checks">Run checks</button>
        </form>
        <form method="post" action="index.php?step=checks">
            <input type="hidden" name="token" value="<?= $token ?>">
            <?php if(!$view['hasAdmin']): ?>
            <fieldset>
                <legend>First administrator</legend>
                <div class="grid">
                    <label>Username <input name="adminName" required autocomplete="username"></label>
                    <label>Email <input type="email" name="adminEmail" required autocomplete="email"></label>
                    <label>Password <input type="password" name="adminPassword" required autocomplete="new-password"></label>
                </div>
            </fieldset>
            <?php endif; ?>
            <div class="actions">
                <button class="primary" name="ghoti_setup_action" value="finish">Finish installation</button>
                <a href="index.php?step=site">Back to site settings</a>
            </div>
        </form>
    <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
