<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#202923">
  <title><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?></title>
  <?php include_once "ghoti.header.php"; ?>
  <link rel="stylesheet" href="<?php echo $ghotiAsset('css/veil/veil.css'); ?>">
</head>
<body class="veil-theme" id="top">
  <a class="ghotiSkip" href="#ghotiContent">Skip to content</a>
  <header class="veil-masthead site-header">
    <a class="veil-mark" href="<?php echo htmlspecialchars($ghotiAsset(''), ENT_QUOTES, 'UTF-8'); ?>"><svg viewBox="0 0 80 80" aria-hidden="true"><path d="M40 5 76 69H4Z M40 15 66 63H14Z"/><path d="M18 45q22-24 44 0-22 24-44 0Z"/><circle cx="40" cy="45" r="8"/></svg><span>VEIL<small>THE UNCOMMON ARCHIVE</small></span></a>
    <p class="veil-masthead-note">Between the known<br><em>and the not yet understood.</em></p>
    <span class="veil-catalog-number">COLLECTION / ∞<br>OPEN FOR EXAMINATION</span>
  </header>
  <main class="veil-desk">
    <section class="veil-title-block" aria-labelledby="veil-title"><div><p class="veil-eyebrow">AN ATLAS OF UNEXPECTED CONNECTIONS</p><h1 id="veil-title"><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?></h1></div><a class="veil-open-file" href="#veil-document">Open the dossier <span aria-hidden="true">↓</span></a></section>
    <nav class="veil-file-tabs" aria-label="Public navigation"><span class="veil-index-label">INDEX</span><?php echo $_SESSION['ghotiObj']->printPageMenu(); ?></nav>
    <div class="veil-evidence-desk">
      <section class="veil-document" id="veil-document" aria-label="Page content" tabindex="-1"><div class="veil-document-meta"><span>FIELD RECORD / 001</span><span>READER’S COPY</span></div><?php include "ghoti.body.php"; ?><div class="veil-document-end" aria-hidden="true">◇ &nbsp; THE RECORD REMAINS OPEN &nbsp; ◇</div></section>
      <aside class="veil-evidence" aria-label="Archive exhibits">
        <figure class="veil-plate"><a href="<?php echo $ghotiAsset('css/veil/images/atlas.png'); ?>" target="_blank" rel="noopener" aria-label="Examine the full archive illustration (opens in a new tab)"><img src="<?php echo $ghotiAsset('css/veil/images/atlas.png'); ?>" alt="An imagined vellum atlas combining a grey alien portrait, celestial instruments, an eye in a triangle, Celtic knots and ancient standing stones." width="1536" height="1024" fetchpriority="high"></a><figcaption><span>PLATE I — THE OBSERVER</span><span>ILLUSTRATED CONJECTURE</span></figcaption></figure>
        <section class="veil-instrument" aria-labelledby="veil-instrument-title"><div class="veil-instrument-top"><span class="veil-eyebrow">THE CORRESPONDENCE ENGINE</span><span aria-hidden="true">⌖</span></div><div class="veil-instrument-body"><svg id="veil-astrolabe" viewBox="0 0 200 200" aria-hidden="true"><circle cx="100" cy="100" r="90"/><circle cx="100" cy="100" r="77" stroke-dasharray="1 7"/><circle cx="100" cy="100" r="62"/><g class="veil-orbits"><ellipse cx="100" cy="100" rx="75" ry="30"/><ellipse cx="100" cy="100" rx="75" ry="30" transform="rotate(60 100 100)"/><ellipse cx="100" cy="100" rx="75" ry="30" transform="rotate(120 100 100)"/><path d="M100 22 168 140H32Z"/><circle cx="100" cy="25" r="4"/></g><path d="M10 100h180M100 10v180"/><circle cx="100" cy="100" r="12"/></svg><div><h2 id="veil-instrument-title">As above.<br>So below.</h2><p id="veil-lens-description" aria-live="polite">Geometry turns a mystery into a question worth asking.</p></div></div><div class="veil-lenses" role="group" aria-label="Explore a symbolic lens"><button type="button" data-lens="geometry" aria-pressed="true">I. Geometry</button><button type="button" data-lens="cosmos" aria-pressed="false">II. Cosmos</button><button type="button" data-lens="grove" aria-pressed="false">III. Grove</button></div></section>
        <details class="veil-sealed-note"><summary>A note in the margin <span aria-hidden="true">+</span></summary><p>“The most interesting door is often the one drawn in the margin.”</p><small>The archivist left no forwarding dimension.</small></details>
      </aside>
    </div>
    <section class="veil-crossrefs" aria-labelledby="veil-crossrefs-title"><div><p class="veil-eyebrow">CONTINUE THE INQUIRY</p><h2 id="veil-crossrefs-title">Cross-references.</h2></div><div id="ghotiLinks">Consulting the index…</div></section>
    <div class="veil-banners"><?php echo $_SESSION['bannersObj']->displayBanner(true); ?><?php echo $_SESSION['bannersObj']->displayBanner(false); ?></div>
    <footer class="veil-footer" id="footer"><span>VEIL / A CABINET OF POSSIBILITIES</span><div><?php echo $_SESSION['ghotiObj']->ghotiui->printFooter(); ?></div><a href="#top">Return to the index ↑</a></footer>
  </main>
  <aside class="veil-dock" aria-label="Archive controls"><span class="veil-dock-label">ARCHIVIST’S DESK</span><button type="button" id="veil-focus" aria-pressed="false" hidden>Reading lamp</button><button type="button" class="account-signin" id="account-signin" data-login-visible="<?php echo ghoti::showLoginButton() ? 'true' : 'false'; ?>"<?php if (ghoti_require_login() || !ghoti::showLoginButton()) { echo ' hidden'; } ?> onclick="popupLogin();">Sign in</button>
        <details class="workspace-menu" id="workspace-menu"<?php if (!ghoti_require_login() && !ghoti::$enableThemeChanger) { echo ' hidden'; } ?>>
          <summary class="workspace-trigger" aria-controls="workspace-panel">
            <span class="account-avatar" aria-hidden="true">◇</span>
            <span id="workspace-trigger-label">Account</span>
            <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path d="m6 8 4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
          </summary>
          <div class="workspace-panel" id="workspace-panel">
            <div class="workspace-heading">
              <div><span class="workspace-eyebrow">ARCHIVIST’S DESK</span><h2>Workspace</h2></div>
              <button type="button" class="workspace-close" aria-label="Close account menu">×</button>
            </div>
            <section class="workspace-section" id="workspace-admin"<?php if (!ghoti_require_login() || !isAdmin(ghoti_current_user_id())) { echo ' hidden'; } ?>>
              <div class="workspace-section-heading"><h3>Administration</h3><span class="workspace-badge">ADMIN</span></div>
              <label class="workspace-search">
                <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="m13 13 4 4" stroke="currentColor" stroke-width="1.5"/></svg>
                <input type="search" id="workspace-search" placeholder="Find a tool…" aria-label="Find an administration tool" autocomplete="off">
              </label>
              <nav id="ghotiAdminMenu" aria-label="Site administration"><?php if (ghoti_require_login() && isAdmin(ghoti_current_user_id())) { echo printAdminMenu(); } ?></nav>
              <p class="workspace-empty" id="workspace-empty" hidden>No matching tools.</p>
            </section>
            <section class="workspace-section" id="workspace-private"<?php if (!ghoti_require_login()) { echo ' hidden'; } ?>>
              <div class="workspace-section-heading"><h3>Private pages</h3><span class="workspace-section-note">MEMBERS</span></div>
              <nav id="ghotiPrivateMenu" aria-label="Private pages"><?php if (ghoti_require_login()) { echo refreshPrivateMenu(); } ?></nav>
            </section>
            <?php if (ghoti::$enableThemeChanger) { ?>
            <section class="workspace-section workspace-appearance" id="workspace-appearance">
              <div class="workspace-section-heading"><h3>Appearance</h3></div>
              <?php echo $_SESSION['ghotiObj']->themeChanger(); ?>
            </section>
            <?php } ?>
            <section class="workspace-section workspace-account">
              <div class="workspace-section-heading"><h3>Account</h3></div>
              <div id="ghotiLogin"><?php if (ghoti_require_login()) { echo $_SESSION['loginObj']->loginui->printSystemMenu(); } ?></div>
            </section>
            <div class="workspace-footnote"><span class="workspace-status-dot" aria-hidden="true"></span>GHOTI CMS <kbd>⌘ / Ctrl K</kbd></div>
          </div>
        </details>
  </aside>
  <script src="<?php echo $ghotiAsset('css/veil/workspace.js'); ?>"></script>
  <script src="<?php echo $ghotiAsset('css/veil/veil.js'); ?>"></script>
</body>
</html>
