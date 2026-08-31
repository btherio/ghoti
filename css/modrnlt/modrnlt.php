<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php print ghoti::$siteTitle;?></title>
  
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/modrnlt/modrnlt.css">
  <?php include_once "ghoti.header.php"; ?>
</head>
<body>
  <header>
    <img width="25%" height="25%"   src="gfx/SmarTEND-ng-light.png" alt="<?php print ghoti::$siteTitle;?>" />
    <nav>
        <ul><?php print $_SESSION['ghotiObj']?->printPageMenu();?></ul>
        <ul style="float: left;" id="ghotiPrivateMenu">Loading...</ul>
    </nav>
    
    <nav>
        <ul style="float: right;" ><?php print $_SESSION['loginObj']->loginui->printPopupLogin();?></ul>
        <ul style="float: right;" id="ghotiAdminMenu"></ul>  
    </nav>
  </header>
    
  
  
  <?php include "ghoti.body.php";?>
  
  
  
  <footer>        
    <?php // print $_SESSION['ghotiObj']->ghotiui->printFooter();?>
  </footer>
</body>
</html>