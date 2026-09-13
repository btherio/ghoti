<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php print ghoti::$siteTitle;?></title>
  
  <link href="lib/fonts/modrnlt.css" rel="stylesheet">
  <link rel="stylesheet" href="css/modrnlt/modrnlt.css">
  <?php include_once "ghoti.header.php"; ?>
</head>
<body class="legacy-light">
  <a class="ghotiSkip" href="#ghotiContent">Skip to content</a>
  <header>
    <img width="25%" height="25%"   src="gfx/SmarTEND-ng-light.png" alt="<?php print ghoti::$siteTitle;?>" />
    <nav>
        <div><?php print $_SESSION['ghotiObj']?->printPageMenu();?></div>
        <div style="float: left;" id="ghotiPrivateMenu"></div>
    </nav>
    
    <nav>
        <div style="float: right;" ><?php print $_SESSION['loginObj']->loginui->printPopupLogin();?></div>
        <div style="float: right;" id="ghotiAdminMenu"></div>  
    </nav>
  </header>
    
  
  
  <?php include "ghoti.body.php";?>
  
  
  
  <footer>        
    <?php print $_SESSION['ghotiObj']->ghotiui->printFooter();?>
  </footer>
</body>
</html>