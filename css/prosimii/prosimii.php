<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">

<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
  <head>
    <meta http-equiv="content-type" content="application/xhtml+xml; charset=UTF-8" />
    <meta name="author" content="haran" />
    <meta name="generator" content="haran" />

    <link rel="stylesheet" type="text/css" href="./css/prosimii/prosimii-screen.css" media="screen, tv, projection" title="Default" />
    <link rel="stylesheet alternative" type="text/css" href="./css/prosimii/prosimii-print.css" media="screen" title="Print Preview" />
    <link rel="stylesheet" type="text/css" href="./css/prosimii/prosimii-print.css" media="print" />
<?php include_once "ghoti.header.php"; ?>
    <title><?php print ghoti::$siteTitle;?></title>
  </head>

  <body>
    <!-- For non-visual user agents: -->
      <div id="top"><a href="#main-copy" class="doNotDisplay doNotPrint">Skip to main content.</a></div>

    <!-- ##### Header ##### -->

    <div id="header">
      <div class="superHeader">
      <span class="doNotDisplay" id="ghotiLoginTitle">Login</span>
					<?php print $_SESSION['loginObj']->loginui->printPopupLogin();?>
      </div>

      <div class="midHeader">
        <img src="<?php print ghoti::$headerImg;?>" alt="<?php print ghoti::$siteTitle;?>" />
        <div class="headerSubTitle">
        </div>

        <br class="doNotDisplay doNotPrint" />

        <div class="headerLinks">
        
        </div>
      </div>

      <div class="subHeader">
        <span class="doNotDisplay">Navigation:</span>
<?php print $_SESSION['ghotiObj']->printPageMenu();	?>
      </div>
    </div>

    <!-- ##### Main Copy ##### -->

    <div id="main-copy">
      <div class="rowOfBoxes">
        <div class="twoThirds noBorderOnLeft">
<?php include "ghoti.body.php";?>
        </div>

        <div class="oneThird">
          
					
					<h1 id="ghotiPrivateMenuTitle"></h1>
					<div id="ghotiPrivateMenu"></div>
					<h1 id="ghotiAdminMenuTitle"></h1>
					<div id="ghotiAdminMenu"></div>

					<?php print $_SESSION['ghotiObj']->themeChanger(); ?>
        </div>
      </div>

      <div class="rowOfBoxes dividingBorderAbove">
        <div class="quarter noBorderOnLeft"></div>

        <div class="quarter">

          <div id="liveRelays">
          <h1>Relays</h1>
          <p><img alt="Loading..." height="24" src="mod/sensors/loading.gif" width="24" /></p>
          </div>
       </div>

        <div class="quarter">
        	<h1>Standards Compliant</h1><br />
        	<div class="alignRight">
        	Valid <a href="http://validator.w3.org/check/referer">XHTML</a> and <a href="http://jigsaw.w3.org/css-validator/check/referer">CSS</a>.
        	</div>
        </div>

        <div class="quarter">
					<h1>Links</h1>
					<div id="ghotiLinks">Loading...</div>
        </div>
      </div>
    </div>

    <!-- ##### Footer ##### -->

    <div id="footer">
    <?php print $_SESSION['bannersObj']->displayBanner(false); ?><br />
    <?php print $_SESSION['ghotiObj']->ghotiui->printFooter();?>
    </div>
  </body>
</html>
