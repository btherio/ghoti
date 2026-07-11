<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php print ghoti::$siteTitle;?></title>
  <meta name="description" content="Portfolio of a designer operating at the intersection of craft, systems, and code.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="./css/smurfius/style.css">
  <?php include_once "ghoti.header.php"; ?>
</head>
<body>
  <div class="bg-layer" aria-hidden="true">
    <img src="./css/smurfius/background.png" alt="" class="bg-layer__image" width="1920" height="1080">
    <div class="bg-layer__veil"></div>
  </div>
  <div class="grid-overlay" aria-hidden="true"></div>
  <div class="scanlines" aria-hidden="true"></div>

  <header class="site-header" data-animate="header">
    <div class="header-layout">
      <a href="/" class="header-brand" aria-label="Smurfius — home">
        <span class="brand-logo">
          <img src="<?php print ghoti::$headerImg;?>" alt="Smurfius" class="brand-logo__img" width="200" height="200">
        </span>
        <span class="brand-name">cms</span>
      </a>

      <nav class="menu-cluster menu-cluster--primary" aria-label="Public navigation">
        <?php print $_SESSION['ghotiObj']->printPageMenu(); ?>
        
      </nav>

      <div class="header-actions">
        <div class="telemetry-readout" aria-hidden="true">
          <span class="telemetry-item" data-telemetry="fps">fps —</span>
          <span class="telemetry-item" data-telemetry="time">00:00:00</span>
        </div>
        
      </div>

      <nav class="menu-cluster menu-cluster--secondary" aria-label="Authenticated navigation">
        
        
      </nav>
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
    <section class="capabilities content-panel" data-animate="section">
      <div id="ghotiPrivateMenu"></div>
      <div id="ghotiAdminMenu">Admin</div>
    </section>
  </main>
  
  <section class="capabilities content-panel" data-animate="section">
      <header class="section-header">
        <h2>stats</h2>
      </header>
      <ul class="hero-specs">
        <li><strong>focus</strong> programming · electronics · refrigeration</li>
        <li><strong>status</strong> <span class="status-pulse">available for select projects</span></li>
        <li><strong>theme</strong><?php print $_SESSION['ghotiObj']->themeChanger(); ?> </li>
        <li><strong>user</strong><span class="cursor-blink"><b>:</b></span>
        <?php print $_SESSION['loginObj']->loginui->printPopupLogin();?></span></li>
      </ul>

  </section>

  <footer class="site-footer content-panel">
    <?php print $_SESSION['bannersObj']->displayBanner(false); ?>
    <?php print $_SESSION['ghotiObj']->ghotiui->printFooter();?>
  </footer>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@studio-freight/lenis@1.0.42/dist/lenis.min.js"></script>
  <script src="./css/smurfius/script.js"></script>
</body>
</html>
