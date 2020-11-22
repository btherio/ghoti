<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">

<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en-AU">
  <head>
    <meta http-equiv="content-type" content="application/xhtml+xml; charset=UTF-8" />
    <meta name="author" content="haran" />
    <meta name="generator" content="haran" />

    <link rel="stylesheet" type="text/css" href="./css/tierraverde/tierraverde-screen.css" media="screen" title="Tierra Verde (screen)" />
    <link rel="stylesheet" type="text/css" href="./css/tierraverde/tierraverde-print.css" media="print" />
	<?php include_once "ghoti.header.php"; ?>
    <title><?php print ghoti::$siteTitle;?></title>
  </head>

  <body>
    <!-- For non-visual user agents: -->
      <div id="top"><a href="#main-copy" class="doNotDisplay doNotPrint">Skip to main content.</a></div>

    <!-- ##### Header ##### -->

    <div id="header">
      <img src="<?php print ghoti::$headerImg;?>" alt="<?php print ghoti::$siteTitle;?>" />

      <div class="headerLinks">
        <span class="doNotDisplay">Menu: </span> 
        <?php print $_SESSION['ghotiObj']->printPageMenu(); ?>
      </div>
    </div>

    <!-- ##### Side Bar ##### -->

    <div id="side-bar">

	<p class="sideBarTitle" id="ghotiLoginTitle">Login</p>
	<?php print $_SESSION['loginObj']->loginui->printLoginForm();?>
	<p class="sideBarTitle" id="ghotiPrivateMenuTitle"></p>
	<div id="ghotiPrivateMenu"></div>
	<p class="sideBarTitle" id="ghotiAdminMenuTitle"></p>
	<div id="ghotiAdminMenu"></div>
	<p class="sideBarTitle">Links</p>
	<div id="ghotiLinks">Loading...</div>
	<p class="sideBarTitle">Themes</p>
	<?php print $_SESSION['ghotiObj']->themeChanger(); ?>
    </div>

    <!-- ##### Main Copy ##### -->

    <div id="main-copy">
     <?php include "ghoti.body.php";?>
    </div>

    <!-- ##### Footer ##### -->

    <div id="footer">
      <div class="left doNotPrint">
        <a href="http://validator.w3.org/check/referer">Valid XHTML 1.0 Strict</a> |
        <a href="http://jigsaw.w3.org/css-validator/check/referer">Valid CSS</a>
      </div>

      <div class="right">
<span class="doNotPrint"> <?php print $_SESSION['bannersObj']->displayBanner(false);?>  </span>

      </div>

      <br class="doNotPrint" />
    </div>

    <div class="subFooter">   
    	    <? print $_SESSION['ghotiObj']->ghotiui->printFooter();?>
    </div>
  </body>
</html>
