<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php print ghoti::$siteTitle;?></title>
  <meta name="description" content="Portfolio of a designer operating at the intersection of craft, systems, and code.">
  <link href="lib/fonts/ghoticms.css" rel="stylesheet">
  <link rel="stylesheet" href="./css/ghoticms/style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>">
  <?php include_once "ghoti.header.php"; ?>
</head>
<body>
  <a class="ghotiSkip" href="#ghotiContent">Skip to content</a>
  <div class="bg-layer" aria-hidden="true">
    <img src="./css/ghoticms/background.png" alt="" class="bg-layer__image" width="1920" height="1080">
    <div class="bg-layer__veil"></div>
  </div>
  <div class="grid-overlay" aria-hidden="true"></div>
  <div class="scanlines" aria-hidden="true"></div>

  <header class="site-header" data-animate="header">
    <div class="header-layout">
      <a href="/" class="header-brand" aria-label="ghoti — home">
        <span class="brand-logo">
          <img src="<?php print htmlspecialchars(ghoti::$headerImg, ENT_QUOTES, 'UTF-8');?>" alt="" class="brand-logo__img" width="256" height="256">
        </span>
        <span class="brand-name">ghoti <small>cms</small></span>
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
              <div><span class="workspace-eyebrow">ghoti / workspace</span><h2>Your space.</h2></div>
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
<main class="site-main">
    <section class="hero" data-animate="hero">
      <?php include "ghoti.body.php";?>
      <div class="hero-coords">
        <span>x: <em data-coord="x">0.000</em></span>
        <span>y: <em data-coord="y">0.000</em></span>
        <span>scroll: <em data-coord="scroll">0</em>%</span>
      </div>

      
    </section>
          
  </main>
  
  <section class="capabilities content-panel" data-animate="section">
      <header class="section-header">
        <h2>stats</h2>
      </header>
      <ul class="hero-specs">
        <li><strong>focus</strong> programming · electronics · refrigeration</li>
        <li><strong>status</strong> <span class="status-pulse">available for select projects</span></li>

      </ul>

  </section>

  <footer class="site-footer content-panel">
    <?php print $_SESSION['bannersObj']->displayBanner(false); ?>
    <?php print $_SESSION['ghotiObj']->ghotiui->printFooter();?>
  </footer>

  
  <script src="<?php echo $ghotiAsset('lib/vendor/gsap.min.js'); ?>"></script>
  <script src="<?php echo $ghotiAsset('lib/vendor/ScrollTrigger.min.js'); ?>"></script>
  <script src="<?php echo $ghotiAsset('lib/vendor/lenis.min.js'); ?>"></script>
  <script src="<?php echo $ghotiAsset('css/ghoticms/workspace.js'); ?>"></script>
  <script src="<?php echo $ghotiAsset('css/ghoticms/script.js'); ?>"></script>
</body>
</html>
