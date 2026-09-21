<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#191a18">
  <title><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?></title>
  <?php include_once "ghoti.header.php"; ?>
  <link rel="stylesheet" href="<?php echo $ghotiAsset('css/ironhide/ironhide.css'); ?>">
  <?php if (ghoti::$enableStore) { ?>
  <link rel="stylesheet" href="<?php echo $ghotiAsset('css/ironhide/store.css'); ?>">
  <?php } ?>
</head>
<body class="ironhide-theme" id="top">
  <a class="ghotiSkip" href="#ghotiContent">Skip to content</a>
  <header class="site-header ironhide-header">
    <div class="header-layout">
      <a href="<?php echo htmlspecialchars($ghotiAsset(''), ENT_QUOTES, 'UTF-8'); ?>" class="header-brand" aria-label="<?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?> — home">
        <span class="brand-logo"><img src="<?php echo htmlspecialchars(ghoti::$headerImg, ENT_QUOTES, 'UTF-8'); ?>" alt="" width="96" height="96"></span>
        <span class="brand-copy"><strong><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?></strong><small>IRONHIDE</small></span>
      </a>

      <nav class="menu-cluster menu-cluster--primary" aria-label="Public navigation">
        <?php echo $_SESSION['ghotiObj']->printPageMenu(); ?>
      </nav>

      <div class="header-actions">
        <button type="button" class="account-signin" id="account-signin" data-login-visible="<?php echo ghoti::showLoginButton() ? 'true' : 'false'; ?>"<?php if (ghoti_require_login() || !ghoti::showLoginButton()) { echo ' hidden'; } ?> onclick="popupLogin();">Sign in <span aria-hidden="true">↗</span></button>
        <details class="workspace-menu" id="workspace-menu"<?php if (!ghoti_require_login() && !ghoti::$enableThemeChanger) { echo ' hidden'; } ?>>
          <summary class="workspace-trigger" aria-controls="workspace-panel">
            <span class="account-avatar" aria-hidden="true">I</span>
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

  <main class="ironhide-main">
    <section class="ironhide-hero" aria-labelledby="ironhide-title">
      <img class="ironhide-hero-image" src="<?php echo $ghotiAsset('css/ironhide/images/workshop.png'); ?>" alt="Black custom motorcycle in a warmly lit workshop with a worn leather chair." width="1536" height="1024" fetchpriority="high">
      <div class="ironhide-hero-copy">
        <div class="ironhide-forge-seal" aria-hidden="true">
          <svg viewBox="0 0 120 120" fill="none"><circle cx="60" cy="60" r="52"/><circle cx="60" cy="60" r="44" stroke-dasharray="1 7"/><path d="M60 17 97 81H23Z M60 103 23 39H97Z"/><path d="M60 37 73 60 60 83 47 60Z M8 60h12m80 0h12M60 8v12m0 80v12"/><circle cx="60" cy="60" r="6"/></svg>
          <span>FORGED WITH A LITTLE MAGIC</span>
        </div>
        <p class="ironhide-eyebrow">Independent spirit. Built to last.</p>
        <h1 id="ironhide-title"><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="ironhide-hero-motto">Hard miles.<br>Good company.</p>
        <a class="ironhide-hero-link" href="#ironhide-content">Step inside <span aria-hidden="true">↘</span></a>
      </div>
      <span class="ironhide-hero-stamp" aria-hidden="true">IRONHIDE<br><small>LEATHER / STEEL / SOUL</small></span>
    </section>
    <div class="ironhide-strip" aria-hidden="true"><span>01 / THE OPEN ROAD</span><span>02 / THE WORKSHOP</span><span>03 / THE CREW</span></div>
    <div class="ironhide-layout">
      <section class="ironhide-content" id="ironhide-content" aria-label="Page content">
        <div class="ironhide-section-label">Inside the clubhouse <span aria-hidden="true">01</span></div>
        <?php include "ghoti.body.php"; ?>
      </section>
      <aside class="ironhide-sidebar" aria-label="Around the site">
        <section class="ironhide-links">
          <p class="ironhide-eyebrow">The crew</p>
          <h2>Good connections.</h2>
          <div id="ghotiLinks">Loading links…</div>
        </section>
        <figure class="ironhide-workbench">
          <img src="<?php echo $ghotiAsset('css/ironhide/images/workbench.png'); ?>" alt="Leather riding gloves, steel wrenches and an enamel coffee mug on a weathered workbench." width="1254" height="1254" loading="lazy">
          <figcaption><span>OFF THE CLOCK</span><strong>Pull up a chair.</strong><p>There’s always room for one more.</p></figcaption>
        </figure>
        <div class="ironhide-banner"><?php echo $_SESSION['bannersObj']->displayBanner(true); ?></div>
      </aside>
    </div>
  </main>
  <footer class="ironhide-footer" id="footer">
    <span class="ironhide-footer-mark">Ironhide<span>Built tough. Welcome in.</span></span>
    <div><?php echo $_SESSION['bannersObj']->displayBanner(false); ?><?php echo $_SESSION['ghotiObj']->ghotiui->printFooter(); ?></div>
    <a href="#top">Back to top ↑</a>
  </footer>
  <script src="<?php echo $ghotiAsset('css/ironhide/workspace.js'); ?>"></script>
  <script src="<?php echo $ghotiAsset('css/ironhide/forge.js'); ?>"></script>
</body>
</html>
