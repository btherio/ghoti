<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#160c24">
  <title><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?></title>
  <?php include_once "ghoti.header.php"; ?>
  <link rel="stylesheet" href="<?php echo $ghotiAsset('css/spore/spore.css'); ?>">
</head>
<body class="spore-theme" id="top">
  <a class="ghotiSkip" href="#ghotiContent">Skip to content</a>
  <div class="spore-cosmos" aria-hidden="true"><img class="spore-wheel spore-wheel-one" src="<?php echo $ghotiAsset('css/spore/images/mandala.png'); ?>" alt=""><img class="spore-wheel spore-wheel-two" src="<?php echo $ghotiAsset('css/spore/images/mandala.png'); ?>" alt=""></div>
  <header class="site-header spore-rail">
    <a class="spore-brand" href="<?php echo htmlspecialchars($ghotiAsset(''), ENT_QUOTES, 'UTF-8'); ?>"><img src="<?php echo htmlspecialchars(ghoti::$headerImg, ENT_QUOTES, 'UTF-8'); ?>" width="44" height="44" alt=""><span>SPORE<small>A SMALL WEB / A BIG TRIP</small></span></a>
    <span class="spore-rail-label">Choose a rabbit hole</span>
    <nav aria-label="Public navigation"><?php echo $_SESSION['ghotiObj']->printPageMenu(); ?></nav>
    <div class="spore-rail-bottom"><span aria-hidden="true">✳</span><p>Stay curious.<br>Grow sideways.</p><a href="#spore-links">Follow the mycelium ↗</a></div>
  </header>
  <aside class="spore-controls" aria-label="Garden controls">
    <button type="button" id="spore-motion" aria-pressed="false" hidden>Pause the cosmos</button>
    <button type="button" class="account-signin" id="account-signin" data-login-visible="<?php echo ghoti::showLoginButton() ? 'true' : 'false'; ?>"<?php if (ghoti_require_login() || !ghoti::showLoginButton()) { echo ' hidden'; } ?> onclick="popupLogin();">Sign in ↗</button>
        <details class="workspace-menu" id="workspace-menu"<?php if (!ghoti_require_login() && !ghoti::$enableThemeChanger) { echo ' hidden'; } ?>>
          <summary class="workspace-trigger" aria-controls="workspace-panel">
            <span class="account-avatar" aria-hidden="true">✷</span>
            <span id="workspace-trigger-label">Account</span>
            <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path d="m6 8 4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
          </summary>
          <div class="workspace-panel" id="workspace-panel">
            <div class="workspace-heading">
              <div><span class="workspace-eyebrow">THE CONTROL GARDEN</span><h2>Workspace</h2></div>
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
  <main class="spore-world">
    <section class="spore-arrival" aria-labelledby="spore-title">
      <div class="spore-intro"><p class="spore-kicker">✷ ORGANIC MATTER. DIGITAL MAGIC.</p><h1 id="spore-title"><?php echo htmlspecialchars(ghoti::$siteTitle, ENT_QUOTES, 'UTF-8'); ?></h1><p class="spore-manifesto">A little strange.<br>A lot of possibility.</p><a class="spore-enter" href="#spore-reading">Down the rabbit hole <span aria-hidden="true">↙</span></a><span class="spore-sticker" aria-hidden="true">100%<br>FREE RANGE<br>WEIRDNESS</span></div>
      <div class="spore-portal">
        <img class="spore-garden" src="<?php echo $ghotiAsset('css/spore/images/garden.png'); ?>" alt="A luminous clockwork mushroom forest, with a fairy in the canopy and a gnome hiding among the roots." width="1536" height="1024" fetchpriority="high">
        <span class="spore-orbit-label" aria-hidden="true">YOU ARE HERE. PROBABLY.</span>
        <button class="spore-secret spore-fairy" type="button" data-secret="fairy" aria-label="Visit the fairy in the canopy" aria-controls="spore-discovery"><span aria-hidden="true">✧</span><span class="spore-secret-label">psst…</span></button>
        <button class="spore-secret spore-gnome" type="button" data-secret="gnome" aria-label="Visit the gnome among the roots" aria-controls="spore-discovery"><span aria-hidden="true">🍄</span><span class="spore-secret-label">who, me?</span></button>
      </div>
      <div class="spore-discovery" id="spore-discovery" role="status" aria-live="polite">Some of the locals have things to tell you. Look closely.</div>
    </section>
    <div class="spore-ticker" aria-hidden="true"><span>MAKE WEIRD THINGS</span><b>✳</b><span>BE KIND TO SMALL CREATURES</span><b>✳</b><span>FOLLOW YOUR SPORES</span></div>
    <section class="spore-reading" id="spore-reading" aria-label="Page content" tabindex="-1">
      <div class="spore-reading-heading"><span>FIELD NOTES FROM THIS DIMENSION</span><span aria-hidden="true">↓</span></div>
      <?php include "ghoti.body.php"; ?>
      <button class="spore-secret spore-reader-fairy" type="button" data-secret="reader" aria-label="Speak to the fairy tending the page" aria-controls="spore-discovery"><span aria-hidden="true">🧚</span></button>
    </section>
    <section class="spore-connections" id="spore-links" aria-labelledby="spore-links-title">
      <div><p class="spore-kicker">THE UNDERGROUND NETWORK</p><h2 id="spore-links-title">Everything<br>is connected.</h2><p>Take a winding path to somewhere good.</p></div>
      <div id="ghotiLinks">Growing connections…</div>
      <img class="spore-wheel spore-wheel-three" src="<?php echo $ghotiAsset('css/spore/images/mandala.png'); ?>" alt="" width="1254" height="1254" loading="lazy">
    </section>
    <div class="spore-banners"><?php echo $_SESSION['bannersObj']->displayBanner(true); ?><?php echo $_SESSION['bannersObj']->displayBanner(false); ?></div>
    <footer class="spore-footer" id="footer"><a href="#top">Back to this reality ↑</a><div><?php echo $_SESSION['ghotiObj']->ghotiui->printFooter(); ?></div><span>Hand-grown in cyberspace. ✿</span></footer>
  </main>
  <script src="<?php echo $ghotiAsset('css/spore/workspace.js'); ?>"></script>
  <script src="<?php echo $ghotiAsset('css/spore/spore.js'); ?>"></script>
</body>
</html>
