<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#241a17">
  <title><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?></title>
  <?php include_once "ghoti.header.php"; ?>
  <link rel="stylesheet" href="<?php echo $ghotiAsset('css/mahogany/mahogany.css'); ?>">
  <?php if (ghoti::$enableStore) { ?>
  <link rel="stylesheet" href="<?php echo $ghotiAsset('css/mahogany/store.css'); ?>">
  <?php } ?>
</head>
<body class="mahogany-theme" id="top">
  <a class="ghotiSkip" href="#ghotiContent">Skip to content</a>
  <header class="site-header mahogany-header">
    <div class="header-layout">
      <a href="<?php echo htmlspecialchars($ghotiAsset(''), ENT_QUOTES, 'UTF-8'); ?>" class="header-brand" aria-label="<?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?> — home">
        <span class="brand-logo"><img src="<?php echo htmlspecialchars(ghoti::$headerImg, ENT_QUOTES, 'UTF-8'); ?>" alt="" width="96" height="96"></span>
        <span class="brand-copy"><strong><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?></strong><small>MAHOGANY</small></span>
      </a>

      <nav class="menu-cluster menu-cluster--primary" aria-label="Public navigation">
        <?php echo $_SESSION['ghotiObj']->printPageMenu(); ?>
      </nav>

      <div class="header-actions">
        <button type="button" class="account-signin" id="account-signin" data-login-visible="<?php echo ghoti::showLoginButton() ? 'true' : 'false'; ?>"<?php if (ghoti_require_login() || !ghoti::showLoginButton()) { echo ' hidden'; } ?> onclick="popupLogin();">Sign in <span aria-hidden="true">↗</span></button>
        <details class="workspace-menu" id="workspace-menu"<?php if (!ghoti_require_login() && !ghoti::$enableThemeChanger) { echo ' hidden'; } ?>>
          <summary class="workspace-trigger" aria-controls="workspace-panel">
            <span class="account-avatar" aria-hidden="true">M</span>
            <span id="workspace-trigger-label">Account</span>
            <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path d="m6 8 4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
          </summary>
          <div class="workspace-panel" id="workspace-panel">
            <div class="workspace-heading">
              <div><span class="workspace-eyebrow">YOUR WORKSPACE</span><h2>Workspace</h2></div>
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
      </div>
    </div>
  </header>

  <main class="mahogany-main">
    <section class="mahogany-masthead" aria-labelledby="mahogany-title">
      <p class="mahogany-eyebrow">A considered perspective</p>
      <h1 id="mahogany-title"><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?><span aria-hidden="true">.</span></h1>
      <div class="mahogany-intro"><p>Ideas of substance. Room to reflect.</p><span aria-hidden="true">MAHOGANY &nbsp; / &nbsp; GHOTI</span></div>
    </section>
    <div class="mahogany-layout">
      <section class="mahogany-content" aria-label="Page content">
        <div class="mahogany-section-label">The journal <span aria-hidden="true">01</span></div>
        <?php include "ghoti.body.php"; ?>
      </section>
      <aside class="mahogany-sidebar" aria-label="Around the site">
        <section class="mahogany-links">
          <p class="mahogany-eyebrow">The address book</p>
          <h2>In good company.</h2>
          <div id="ghotiLinks">Loading links…</div>
        </section>
        <div class="mahogany-banner"><?php echo $_SESSION['bannersObj']->displayBanner(true); ?></div>
      </aside>
    </div>
  </main>
  <footer class="mahogany-footer" id="footer">
    <span class="mahogany-footer-mark">Mahogany<span>Quietly distinguished.</span></span>
    <div><?php echo $_SESSION['bannersObj']->displayBanner(false); ?><?php echo $_SESSION['ghotiObj']->ghotiui->printFooter(); ?></div>
    <a href="#top">Back to top ↑</a>
  </footer>
  <script src="<?php echo $ghotiAsset('css/mahogany/workspace.js'); ?>"></script>
</body>
</html>
