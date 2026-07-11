<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">

    <title><?php print ghoti::$siteTitle;?></title>
    <meta name="description" content="<?php print ghoti::$siteTitle;?>">
    <link rel="stylesheet" href="css/sleek/assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i&amp;display=swap">
    <link rel="stylesheet" href="css/sleek/assets/fonts/fontawesome-all.min.css">
    <script src="css/sleek/assets/bootstrap/js/bootstrap.min.js"></script>
    <script src="css/sleek/assets/js/bs-init.js"></script>
    <script src="css/sleek/assets/js/theme.js"></script>
    <?php include_once "ghoti.header.php"; ?>
</head>

<body id="page-top">
    <div id="wrapper">
        <nav id="side-bar" class="side-bar navbar navbar-dark align-items-start sidebar sidebar-dark accordion bg-gradient-primary p-0">
            <div class="container-fluid d-flex flex-column p-0"><a class="navbar-brand d-flex justify-content-center align-items-center sidebar-brand m-0" href="#">
                    <div class="sidebar-brand-icon">
                    <!--image here?-->
                    <img  width="140pt" height="62pt" src="<?php print ghoti::$headerImg;?>" alt="<?php print ghoti::$siteTitle;?>" />
                    </div>
                </a>
                <hr class="sidebar-divider my-0" />
                
                <div class="text-center d-none d-md-inline">
                <p></p>
                <p class="text-primary m-0 fw-bold"><?php print $_SESSION['ghotiObj']?->printPageMenu();?></p>
                <p class="text-primary m-0 fw-bold" id="ghotiPrivateMenu">Loading...</p>
                <p class="text-primary m-0 fw-bold" id="ghotiLinks">Loading...</p>
                </div>
                <div class="text-center d-none d-md-inline">
                <button class="btn rounded-circle border-0" id="sidebarToggle" type="button" onclick="hideMenu();"></button>
                </div>

            </div>
        </nav>

        <div class="d-flex flex-column main-copy" id="content-wrapper">
            <div id="content">
                <nav class="navbar navbar-light navbar-expand bg-white shadow mb-4 topbar static-top">
                    <div class="container-fluid">
                    <button class="btn btn-link d-md-none rounded-circle me-3" id="sidebarToggleTop" type="button" onclick="hideMenu();">
                        <i class="fas fa-bars"></i>
                    </button>

                        <ul class="navbar-nav flex-nowrap ms-auto">

                            <div class="d-none d-sm-block topbar-divider"></div>

                            <li class="nav-item dropdown no-arrow">
                                <div class="nav-item dropdown no-arrow">
                                <a class="dropdown-toggle nav-link" aria-expanded="false" data-bs-toggle="dropdown" href="#">
                                <span class="d-none d-lg-inline me-2 text-gray-600 small"></span>
                                <img class="border rounded-circle img-profile" src="gfx/gearIcon.png"></a>

                                    <div class="dropdown-menu shadow dropdown-menu-end animated--grow-in">

                                        <?php print $_SESSION['loginObj']->loginui->printPopupLogin();?>
                                        <div class="dropdown-divider"></div>
                                        <div id="ghotiAdminMenu"></div>
                                        <a class="dropdown-item">&nbsp;<?php print $_SESSION['ghotiObj']->themeChanger(); ?></a>
                                        <div class="dropdown-divider"></div>

                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                </nav>

                <div class="container-fluid">
                    <div class="card shadow">
                        <div class="card-header py-3">
                            <?php //print $_SESSION["commentsObj"]->commentsui->addCommentButton(); ?>
                        </div>
                        <div class="card-body">
                              <?php include "ghoti.body.php";?>

                        </div>

                    </div>
                </div>


            </div>
            <footer class="bg-white sticky-footer">
                <div class="container my-auto">

                    <div class="text-center my-auto copyright"><?php print $_SESSION['ghotiObj']->ghotiui->printFooter();?></div>
                </div>
            </footer>
        </div>
        <a class="border rounded d-inline scroll-to-top" href="#page-top"><i class="fas fa-angle-up"></i></a>
    </div>
</body>
</html>
