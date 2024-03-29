<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">

<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en-AU">
  <head>
    <meta http-equiv="content-type" content="application/xhtml+xml; charset=UTF-8" />
    <meta name="author" content="haran" />
    <meta name="generator" content="author" />

    <link rel="stylesheet" type="text/css" href="./css/sinorca/sinorca-screen.css" media="screen" title="Sinorca (screen)" />
    <link rel="stylesheet alternative" type="text/css" href="./css/sinorca/sinorca-screen-alt.css" media="screen" title="Sinorca (alternative)" />
    <link rel="stylesheet" type="text/css" href="./css/sinorca/sinorca-print.css" media="print" />
<?php include_once "ghoti.header.php"; ?>
    <title><?php print ghoti::$siteTitle;?></title>
  </head>

  <body>
    <!-- For non-visual user agents: -->
      <div id="top"><a href="#main-copy" class="doNotDisplay doNotPrint">Skip to main content.</a></div>

    <!-- ##### Header ##### -->

    <div id="header">
      <div class="superHeader">
        <div class="left">
          <span class="doNotDisplay" id="ghotiLoginTitle">Top Bar:</span>
					<?php print $_SESSION['loginObj']->loginui->printPopupLogin();?>
        </div>
        <div class="right">
					<?php print $_SESSION['bannersObj']->displayBanner(true);?>
        </div>
      </div>

      <div class="midHeader">
       <img src="<?php print ghoti::$headerImg;?>" alt="<?php print ghoti::$siteTitle;?>" />
      </div>

      <div class="subHeader">
        <span class="doNotDisplay">Navigation:</span>
	<?php print $_SESSION['ghotiObj']?->printPageMenu();?>
      </div>
    </div>

    <!-- ##### Side Bar ##### -->

    <div id="side-bar">
      <div>
        
				
				<p class="sideBarTitle" id="ghotiPrivateMenuTitle"></p>
				<div id="ghotiPrivateMenu"></div>
				
				<p class="sideBarTitle" id="ghotiAdminMenuTitle"></p>
				<div id="ghotiAdminMenu"></div>
			
				<p class="sideBarTitle">Links</p>
				<div id="ghotiLinks">Loading...</div>
      </div>

      <div>
        <p class="sideBarTitle">Themes</p>
				<?php print $_SESSION['ghotiObj']->themeChanger(); ?>
      </div>

      <div class="lighterBackground">
        <p class="sideBarTitle">Alternative stylesheet</p>
        <span class="sideBarText">
          Sinorca contains an alternative stylesheet that renders this side bar differently.
        </span>
        <span class="sideBarText">
          To view the alternative stylesheet in (certain)
          <span class="titleTip" title="eg: Mozilla, Camino, Netscape 6+">Gecko-based browsers</span>,
          select <em>Sinorca (alternative)</em> from the <em>View</em> &rsaquo; <em>Use Style</em>
          submenu.
        </span>
      </div>
    
      <div>
        <p class="sideBarTitle">Validation</p>
        <span class="sideBarText">
          Validate the <a href="http://validator.w3.org/check/referer">XHTML</a> and
          <a href="http://jigsaw.w3.org/css-validator/check/referer">CSS</a> of this page.
        </span>
      </div>

      <div>
        <p class="sideBarTitle">Powered By</p>
				<?php print $_SESSION['bannersObj']->displayBanner(true);?>
      </div>
      
    </div>

    <!-- ##### Main Copy ##### -->

    <div id="main-copy">
      <?php include "ghoti.body.php";?>
    </div>
    
    <!-- ##### Footer ##### -->

    <div id="footer">
      <div class="left">
				<?php print $_SESSION['ghotiObj']->ghotiui->printFooter();?>
      </div>

      <br class="doNotDisplay doNotPrint" />
		
      <div class="right">
        <?php print $_SESSION['bannersObj']->displayBanner(false); ?> 
      </div>
    </div>
  </body>
</html>
