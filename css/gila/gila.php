<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">

<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
<meta http-equiv="content-type" content="application/xhtml+xml; charset=UTF-8" />
<link rel="stylesheet" type="text/css" href="./css/gila/gila-screen.css" media="screen" title="Gila (screen)" />
<link rel="stylesheet" type="text/css" href="./css/gila/gila-print.css" media="print" />
<?php
/*******************************************************************************
* Welcome to a ghoti theme.
* This is going to be a brief walkthrough.
*
* First: we're going to include_once the ghoti header information
* and set the site title.
* This line has to go into the <head></head> tags in an html file.
*******************************************************************************/
include_once "ghoti.header.php"; ?>
<title><?php print ghoti::$siteTitle; /*Second: Set the page title*/?></title>
</head>

<body>
	
<!-- For non-visual user agents: -->
<div id="top"><a href="#main-copy" class="doNotDisplay doNotPrint">Skip to main content.</a></div>

<div id="header">
<img src="<?php print ghoti::$headerImg;?>" alt="<?php print ghoti::$siteTitle;?>" />
<div class="subHeader">
	<?php
	/*Third: This is a nice spot for our page menu.
	*/
	print $_SESSION['ghotiObj']->printPageMenu();?>
	</div>
</div>

<div id="side-bar">
	<div class="leftSideBar">
		<?php
		/*Fourth: These p's and div's denote where the user and admin menus get placed.
		* You can change the class to suit your needs. Keep the id's how they are.
		*/?>
		<p class="sideBarTitle" id="ghotiPrivateMenuTitle"></p>
		<div id="ghotiPrivateMenu"></div>
		<p class="sideBarTitle" id="ghotiAdminMenuTitle"></p>
		<div id="ghotiAdminMenu"></div>
	
		<p class="sideBarTitle">Links</p>
		<?php
		/*Fifth: Links. First one calls the function for default links
		* Second one gets only the links from Linux group
		*/?>
		<div id="ghotiLinks">Loading...</div>
	</div>
</div>

<div class="rightSideBar">
	<?php
	/* Sixth: This prints the login form and eventually the system menu.
	* The element with the id "ghotiLoginTitle" can be a span (like so) or
	* you can combine it with with the <p> like this:
	* <p class="sideBarTitle" id="ghotiLoginTitle">Login</p>
	*/?>
	<p class="sideBarTitle"><span id="ghotiLoginTitle">Login</span></p>
	<div class="sideBarText">
		<?php
		/* Here we print the login form. This could also be a login button
		* print $_SESSION['loginObj']->loginui->printPopupLogin();
		*/
		print $_SESSION['loginObj']->loginui->printLoginForm();
		?>
	</div>
</div>

<div class="rightSideBar">
	<p class="sideBarTitle">Themes</p>
	<div class="sideBarText">
		<?php
		/* Seventh: Is the theme changer. Fairly straight forward here I hope.
		*/
		print $_SESSION['ghotiObj']->themeChanger(); ?>
	</div>
</div>

<div class="rightSideBar">
	<p class="sideBarTitle">Standards</p>
	<div class="sideBarText">
		<p>Valid <a href="http://validator.w3.org/check/referer">XHTML</a> &amp;
		<a href="http://jigsaw.w3.org/css-validator/check/referer">CSS</a></p>
	</div>
</div>
<div class="rightSideBar">
	<p class="sideBarTitle">Powered By</p>
	<div class="sideBarText">
	<?php
	/*Eigth: Banners. This is to display a banner.
	*/
	print $_SESSION['bannersObj']->displayBanner(); ?>
	</div>
</div>

<div id="main-copy">
	<?php
	/* Ninth: Our ghoti body code needs to be included wherever the main
	* part of the layout is. This is where the page content will be displayed.
	*/
	include "ghoti.body.php";?>
</div>



<div id="footer">
	<?php
	/* Banners again. This time we pass it the false which tells ghoti to grab us a big
	* banner. ghoti normally defaults to small banners.
	*/
	print $_SESSION['bannersObj']->displayBanner(false); ?>
	<div class="alignRight">
		<?php
		/* Tenth: Footer.
		*/
		print $_SESSION['ghotiObj']->ghotiui->printFooter();?>
	</div>
</div>

</body>
</html>
