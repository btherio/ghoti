<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#050507">
  <title><?php echo ghoti_seo_title_html(); ?></title>
  <?php if (ghoti::$seoDescription === '') { ?><meta name="description" content="<?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?> — independent apparel and objects for restless minds"><?php } ?>
  <?php include_once "ghoti.header.php"; ?>
  <link rel="stylesheet" href="<?php echo $ghotiAsset('css/hianxiety/hianxiety.css'); ?>">
  <?php if (ghoti::$enableStore) { ?>
  <link rel="stylesheet" href="<?php echo $ghotiAsset('css/hianxiety/store.css'); ?>">
  <?php } ?>
</head>
<body class="hianxiety-theme" id="top">
  <a class="ghotiSkip" href="#ghotiContent">Skip to content</a>
  <div class="hianxiety-sky" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div>

  <header class="site-header hianxiety-header">
    <div class="header-layout">
      <a href="<?php echo htmlspecialchars($ghotiAsset(''), ENT_QUOTES, 'UTF-8'); ?>" class="header-brand" aria-label="<?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?> — home">
        <img src="<?php echo $ghotiAsset('gfx/hatco-logo3.png'); ?>" alt="" width="1983" height="793">
        <span class="brand-copy"><strong><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?></strong><small>INDEPENDENT GOODS / HIAX-001</small></span>
      </a>

      <nav class="menu-cluster menu-cluster--primary" aria-label="Public navigation">
        <?php echo $_SESSION['ghotiObj']->printPageMenu(); ?>
      </nav>

      <div class="header-actions">
        <a class="hianxiety-cart-jump" href="#hianxiety-content">Shop <span aria-hidden="true">↘</span></a>
        <button type="button" class="account-signin" id="account-signin" data-login-visible="<?php echo ghoti::showLoginButton() ? 'true' : 'false'; ?>"<?php if (ghoti_require_login() || !ghoti::showLoginButton()) { echo ' hidden'; } ?> onclick="popupLogin();">Sign in</button>
        <details class="workspace-menu" id="workspace-menu"<?php if (!ghoti_require_login() && !ghoti::$enableThemeChanger) { echo ' hidden'; } ?>>
          <summary class="workspace-trigger" aria-controls="workspace-panel">
            <span class="account-avatar" aria-hidden="true">H</span>
            <span id="workspace-trigger-label">Account</span>
            <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path d="m6 8 4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
          </summary>
          <div class="workspace-panel" id="workspace-panel">
            <div class="workspace-heading">
              <div><span class="workspace-eyebrow">BACKSTAGE ACCESS</span><h2>Workspace</h2></div>
              <button type="button" class="workspace-close" aria-label="Close account menu">×</button>
            </div>
            <section class="workspace-section" id="workspace-admin"<?php if (!ghoti_require_login() || !isAdmin(ghoti_current_user_id())) { echo ' hidden'; } ?>>
              <div class="workspace-section-heading"><h3>Store controls</h3><span class="workspace-badge">ADMIN</span></div>
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
            <div class="workspace-footnote"><span><i aria-hidden="true"></i> SIGNAL LIVE</span><kbd>⌘ / Ctrl K</kbd></div>
          </div>
        </details>
      </div>
    </div>
  </header>

  <main class="hianxiety-main">
    <section class="hianxiety-hero" aria-labelledby="hianxiety-title">
      <div class="hianxiety-orbit" aria-hidden="true"><i></i><i></i><i></i></div>
      <p class="hianxiety-eyebrow">Independent streetwear / worldwide signal</p>
      <h1 id="hianxiety-title">
        <span class="sr-only"><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?></span>
        <img src="<?php echo $ghotiAsset('gfx/hatco-logo3.png'); ?>" alt="" width="1983" height="793" fetchpriority="high">
      </h1>
      <div class="hianxiety-hero-bottom">
        <p>Wear the noise.<br><span>Keep the signal.</span></p>
        <a class="hianxiety-enter" href="#hianxiety-content">Shop the current drop <span aria-hidden="true">↘</span></a>
        <dl>
          <div><dt>DROP</dt><dd>001</dd></div>
          <div><dt>FORMAT</dt><dd>SMALL RUN</dd></div>
          <div><dt>STATUS</dt><dd><i aria-hidden="true"></i> LIVE</dd></div>
        </dl>
      </div>
    </section>

    <div class="hianxiety-ticker" aria-hidden="true"><span>HI ANXIETY</span><b>✦</b><span>WEAR THE NOISE</span><b>✦</b><span>MADE FOR RESTLESS MINDS</span><b>✦</b><span>HIANXIETY.CA</span></div>

    <section class="hianxiety-content" id="hianxiety-content" aria-label="Page content">
      <div class="hianxiety-section-label"><span>CURRENT DROP / ONLINE STORE</span><span aria-hidden="true">001 — <?php echo date('Y'); ?></span></div>
      <?php include "ghoti.body.php"; ?>
    </section>

    <section class="hianxiety-after" aria-label="More from Hi Anxiety">
      <div class="hianxiety-manifesto">
        <p class="hianxiety-eyebrow">The feeling is real</p>
        <h2>Clothes for loud minds<br>and quiet rooms.</h2>
        <p>Independent pieces for wherever your head is at. No perfect posture required.</p>
      </div>
      <div class="hianxiety-links">
        <div><span>KEEP ORBITING</span><span aria-hidden="true">↗</span></div>
        <div id="ghotiLinks">Tuning the signal…</div>
      </div>
    </section>
    <div class="hianxiety-banners"><?php echo $_SESSION['bannersObj']->displayBanner(true); ?><?php echo $_SESSION['bannersObj']->displayBanner(false); ?></div>
  </main>

  <footer class="hianxiety-footer" id="footer">
    <img src="<?php echo $ghotiAsset('gfx/hatco-logo3.png'); ?>" alt="" width="1983" height="793">
    <div><?php echo $_SESSION['ghotiObj']->ghotiui->printFooter(); ?></div>
    <a href="#top">Back to orbit ↑</a>
  </footer>
  <script src="<?php echo $ghotiAsset('css/hianxiety/workspace.js'); ?>"></script>
  <script src="<?php echo $ghotiAsset('css/hianxiety/hianxiety.js'); ?>"></script>
</body>
</html>
