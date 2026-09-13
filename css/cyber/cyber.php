<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?> — powered by GhotiCMS">
  <link href="lib/fonts/ghoticms.css" rel="stylesheet">
  <?php include_once "ghoti.header.php"; ?>
  <link rel="stylesheet" href="<?php echo $ghotiAsset('css/cyber/cyber.css'); ?>">
</head>
<body class="cyber-theme" id="top">
  <a class="ghotiSkip" href="#ghotiContent">Skip to content</a>

  <div class="cyber-atmosphere" aria-hidden="true">
    <div class="cyber-stars"></div>
    <div class="cyber-grid"></div>
    <div class="cyber-scanlines"></div>
    <span class="cyber-particle"></span>
    <span class="cyber-particle"></span>
    <span class="cyber-particle"></span>
    <span class="cyber-particle"></span>
    <span class="cyber-particle"></span>
  </div>

  <header class="site-header cyber-header">
    <div class="header-layout">
      <a href="<?php echo htmlspecialchars($ghotiAsset(''), ENT_QUOTES, 'UTF-8'); ?>" class="header-brand" aria-label="<?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?> — home">
        <span class="brand-logo"><img src="<?php echo htmlspecialchars(ghoti::$headerImg, ENT_QUOTES, 'UTF-8'); ?>" alt="" width="96" height="96"></span>
        <span class="brand-copy"><strong><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?></strong><small>CYBER // GHOTI</small></span>
      </a>

      <nav class="menu-cluster menu-cluster--primary" aria-label="Public navigation">
        <?php echo $_SESSION['ghotiObj']->printPageMenu(); ?>
      </nav>

      <div class="header-actions">
        <button type="button" class="account-signin" id="account-signin" data-login-visible="<?php echo ghoti::showLoginButton() ? 'true' : 'false'; ?>"<?php if (ghoti_require_login() || !ghoti::showLoginButton()) { echo ' hidden'; } ?> onclick="popupLogin();">Sign in <span aria-hidden="true">↗</span></button>
        <details class="workspace-menu" id="workspace-menu"<?php if (!ghoti_require_login() && !ghoti::$enableThemeChanger) { echo ' hidden'; } ?>>
          <summary class="workspace-trigger" aria-controls="workspace-panel">
            <span class="account-avatar" aria-hidden="true">C</span>
            <span id="workspace-trigger-label">Account</span>
            <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path d="m6 8 4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
          </summary>
          <div class="workspace-panel" id="workspace-panel">
            <div class="workspace-heading">
              <div><span class="workspace-eyebrow">SECURE CONSOLE</span><h2>Workspace</h2></div>
              <button type="button" class="workspace-close" aria-label="Close account menu">×</button>
            </div>
            <section class="workspace-section" id="workspace-admin"<?php if (!ghoti_require_login() || !isAdmin(ghoti_current_user_id())) { echo ' hidden'; } ?>>
              <div class="workspace-section-heading"><h3>Site controls</h3><span class="workspace-badge">ADMIN</span></div>
              <label class="workspace-search">
                <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="m13 13 4 4" stroke="currentColor" stroke-width="1.5"/></svg>
                <input type="search" id="workspace-search" placeholder="Search commands…" aria-label="Find an administration tool" autocomplete="off">
              </label>
              <nav id="ghotiAdminMenu" aria-label="Site administration"><?php if (ghoti_require_login() && isAdmin(ghoti_current_user_id())) { echo printAdminMenu(); } ?></nav>
              <p class="workspace-empty" id="workspace-empty" hidden>No matching command.</p>
            </section>
            <section class="workspace-section" id="workspace-private"<?php if (!ghoti_require_login()) { echo ' hidden'; } ?>>
              <div class="workspace-section-heading"><h3>Private channels</h3><span class="workspace-section-note">MEMBERS</span></div>
              <nav id="ghotiPrivateMenu" aria-label="Private pages"><?php if (ghoti_require_login()) { echo refreshPrivateMenu(); } ?></nav>
            </section>
            <?php if (ghoti::$enableThemeChanger) { ?>
            <section class="workspace-section workspace-appearance" id="workspace-appearance">
              <div class="workspace-section-heading"><h3>Interface skin</h3></div>
              <?php echo $_SESSION['ghotiObj']->themeChanger(); ?>
            </section>
            <?php } ?>
            <section class="workspace-section workspace-account">
              <div class="workspace-section-heading"><h3>Session</h3></div>
              <div id="ghotiLogin"><?php if (ghoti_require_login()) { echo $_SESSION['loginObj']->loginui->printSystemMenu(); } ?></div>
            </section>
            <div class="workspace-footnote"><span class="workspace-status-dot" aria-hidden="true"></span>CONNECTION STABLE <kbd>⌘ / Ctrl K</kbd></div>
          </div>
        </details>
      </div>
    </div>
    <div class="scroll-progress" aria-hidden="true"><span class="scroll-progress__bar"></span></div>
  </header>

  <main class="cyber-main">
    <section class="cyber-hero" aria-labelledby="cyber-title">
      <div>
        <span class="cyber-kicker"><i aria-hidden="true"></i> Transmission online</span>
        <h1 id="cyber-title" data-text="<?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p>A signal from the edge of the network.</p>
      </div>
      <dl class="cyber-telemetry" aria-label="System status">
        <div><dt>CHANNEL</dt><dd>WEB / PUBLIC</dd></div>
        <div><dt>STATUS</dt><dd><span></span>STABLE</dd></div>
        <div><dt>LOCAL TIME</dt><dd id="cyber-clock">--:--:--</dd></div>
      </dl>
    </section>

    <div class="cyber-layout">
      <section class="cyber-content" aria-label="Page content">
        <div class="cyber-panel-label"><span>01</span> PRIMARY TRANSMISSION</div>
        <?php include "ghoti.body.php"; ?>
      </section>

      <aside class="cyber-sidebar" aria-label="Around the site">
        <section class="cyber-side-panel">
          <header><span>02</span><h2>Link nodes</h2></header>
          <div id="ghotiLinks">Scanning network…</div>
        </section>
        <section class="cyber-side-panel cyber-signal-panel">
          <header><span>03</span><h2>Signal</h2></header>
          <div class="cyber-wave" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
          <p>Ghoti node active.<br>Content stream synchronized.</p>
        </section>
        <div class="cyber-sidebar-banner"><?php echo $_SESSION['bannersObj']->displayBanner(true); ?></div>
      </aside>
    </div>
  </main>

  <footer class="cyber-footer" id="footer">
    <div class="cyber-footer-mark"><span aria-hidden="true">◇</span> END OF TRANSMISSION</div>
    <div><?php echo $_SESSION['bannersObj']->displayBanner(false); ?></div>
    <div><?php echo $_SESSION['ghotiObj']->ghotiui->printFooter(); ?></div>
    <a href="#top">Return to origin ↑</a>
  </footer>

  <script src="<?php echo $ghotiAsset('css/cyber/cyber.js'); ?>"></script>
</body>
</html>
