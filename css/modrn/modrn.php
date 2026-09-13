<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php print ghoti::$siteTitle;?></title>
  
  <link href="lib/fonts/modrn.css" rel="stylesheet">
  <link rel="stylesheet" href="css/modrn/modrn.css">
  <?php include_once "ghoti.header.php"; ?>
</head>
<body class="legacy-dark">
  <a class="ghotiSkip" href="#ghotiContent">Skip to content</a>
  <header>
    <img width="25%" height="25%"   src="gfx/smartend-ng.shrt.png" alt="<?php print ghoti::$siteTitle;?>" />
    <nav>
      <div style="float: right;" id="ghotiPrivateMenu"></div>
      <div style="float: left;">
        <?php print $_SESSION['ghotiObj']?->printPageMenu();?>
      </div>
  </nav>
  <br />
  <nav>
    <?php print $_SESSION['loginObj']->loginui->printPopupLogin();?>
<div style="float: right;" id="ghotiAdminMenu"></div>
      
    </nav>
  </header>
    <?php include "ghoti.body.php";?>

  <footer>
    <?php print $_SESSION['ghotiObj']->ghotiui->printFooter();?>
  </footer>
</body>
</html>