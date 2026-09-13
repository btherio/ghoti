<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?></title>
  <link href="lib/fonts/prosimii.css" rel="stylesheet">
  <?php include_once "ghoti.header.php"; ?>
  <link rel="stylesheet" href="<?php echo $ghotiAsset('css/prosimii/prosimii-modern.css'); ?>">
  <link rel="stylesheet" href="./css/prosimii/prosimii-print.css" media="print">
</head>
<body class="prosimii-modern" id="top">
  <a class="prosimii-skip" href="#ghotiContent">Skip to content</a>
  <header class="site-header" data-animate="header">
    <div class="header-layout">
      <a href="/" class="header-brand" aria-label="<?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?> — home">
        <span class="brand-logo">
          <img src="<?php print htmlspecialchars(ghoti::$headerImg, ENT_QUOTES, 'UTF-8');?>" alt="" class="brand-logo__img" width="256" height="256">
        </span>
        <span class="brand-name"><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?><small> / prosimii</small></span>
      </a>

      <nav class="menu-cluster menu-cluster--primary" aria-label="Public navigation">
        <?php print $_SESSION['ghotiObj']->printPageMenu(); ?>
      </nav>
      <div class="header-actions">
        <button type="button" class="account-signin" id="account-signin" data-login-visible="<?php echo ghoti::showLoginButton() ? 'true' : 'false'; ?>"<?php if (ghoti_require_login() || !ghoti::showLoginButton()) { echo ' hidden'; } ?> onclick="popupLogin();">Sign in <span aria-hidden="true">↗</span></button>
        <details class="workspace-menu" id="workspace-menu"<?php if (!ghoti_require_login() && !ghoti::$enableThemeChanger) { echo ' hidden'; } ?>>
          <summary class="workspace-trigger" aria-controls="workspace-panel">
            <span class="account-avatar" aria-hidden="true">g</span>
            <span id="workspace-trigger-label">Account</span>
            <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path d="m6 8 4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
          </summary>
          <div class="workspace-panel" id="workspace-panel" data-lenis-prevent>
            <div class="workspace-heading">
              <div><span class="workspace-eyebrow">prosimii / workspace</span><h2>Your space.</h2></div>
              <button type="button" class="workspace-close" aria-label="Close account menu">×</button>
            </div>
            <section class="workspace-section" id="workspace-admin"<?php if (!ghoti_require_login() || !isAdmin(ghoti_current_user_id())) { echo ' hidden'; } ?>>
              <div class="workspace-section-heading"><h3>Manage your site</h3><span class="workspace-badge">Admin</span></div>
              <label class="workspace-search"><svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="m13 13 4 4" stroke="currentColor" stroke-width="1.5"/></svg><input type="search" id="workspace-search" placeholder="Find a tool…" aria-label="Find an administration tool" autocomplete="off"></label>
              <nav id="ghotiAdminMenu" aria-label="Site administration"><?php if (ghoti_require_login() && isAdmin(ghoti_current_user_id())) { echo printAdminMenu(); } ?></nav>
              <p class="workspace-empty" id="workspace-empty" hidden>No matching tools. Try another name.</p>
            </section>
            <section class="workspace-section" id="workspace-private"<?php if (!ghoti_require_login()) { echo ' hidden'; } ?>>
              <div class="workspace-section-heading"><h3>Private pages</h3><span class="workspace-section-note">Members only</span></div>
              <nav id="ghotiPrivateMenu" aria-label="Private pages"><?php if (ghoti_require_login()) { echo refreshPrivateMenu(); } ?></nav>
            </section>
            <?php if (ghoti::$enableThemeChanger) { ?>
            <section class="workspace-section workspace-appearance" id="workspace-appearance">
              <div class="workspace-section-heading"><h3>Appearance</h3></div>
              <?php echo $_SESSION['ghotiObj']->themeChanger(); ?>
            </section>
            <?php } ?>
            <section class="workspace-section workspace-account">
              <div class="workspace-section-heading"><h3>Your account</h3></div>
              <div id="ghotiLogin"><?php if (ghoti_require_login()) { echo $_SESSION['loginObj']->loginui->printSystemMenu(); } ?></div>
            </section>
            <div class="workspace-footnote"><span class="workspace-status-dot" aria-hidden="true"></span>Connected to ghoti <kbd>⌘ / Ctrl K</kbd></div>
          </div>
        </details>
      </div>
    </div>
    <div class="scroll-progress" aria-hidden="true"><span class="scroll-progress__bar"></span></div>
  </header>
  <main class="prosimii-main" id="main-copy">
    <section class="prosimii-masthead" aria-labelledby="prosimii-title">
      <div class="prosimii-kicker"><span class="prosimii-dot" aria-hidden="true"></span> Your corner of the web <span>Prosimii / reimagined</span></div>
      <div class="prosimii-title-row">
        <h1 id="prosimii-title"><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?><span class="prosimii-period">.</span></h1>
        <svg class="prosimii-star" viewBox="0 0 100 100" aria-hidden="true"><path d="M50 8v84M8 50h84M20 20l60 60M20 80l60-60" fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round"/></svg>
      </div>
      <div class="prosimii-masthead-foot"><p>A place for ideas.<br>And whatever comes next.</p><a href="#prosimii-content" class="prosimii-explore">Take a look <span aria-hidden="true">↘</span></a></div>
    </section>
    <section class="prosimii-content" id="prosimii-content" aria-label="Page content">
      <div class="prosimii-section-label"><span>Explore the site</span><span aria-hidden="true">↗</span></div>
      <?php include "ghoti.body.php"; ?>
    </section>
    <aside class="prosimii-extras" aria-label="Around the site">
      <section class="prosimii-card prosimii-links"><span class="prosimii-card-index">01 / DISCOVER</span><h2>Good connections<span>↗</span></h2><div id="ghotiLinks">Loading links…</div></section>
      <section class="prosimii-card prosimii-open"><span class="prosimii-card-index">02 / OPEN SOURCE</span><h2>Built to be shared<svg class="prosimii-card-star" viewBox="0 0 100 100" aria-hidden="true"><path d="M50 8v84M8 50h84M20 20l60 60M20 80l60-60" fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round"/></svg></h2><div class="prosimii-banners"><?php echo $_SESSION['bannersObj']->displayBanner(true); ?></div><p>Powered by curiosity.<br>Made with ghoti.</p></section>
    </aside>
    <div class="prosimii-signoff" aria-hidden="true">Stay curious. <span>Make things.</span></div>
  </main>
  <footer class="prosimii-footer" id="footer">
    <?php echo $_SESSION['bannersObj']->displayBanner(false); ?>
    <?php echo $_SESSION['ghotiObj']->ghotiui->printFooter(); ?>
    <a href="#top">Back to top ↑</a>
  </footer>
  <script src="<?php echo $ghotiAsset('css/prosimii/workspace.js'); ?>"></script>
</body>
</html>
