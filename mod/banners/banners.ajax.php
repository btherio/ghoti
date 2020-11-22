<?php
/*
 * Created on May 1, 2010
 */

function addBannerForm(){
	return $_SESSION["bannersObj"]->bannersui->addBannerForm();
}	
sajax_export("addBannerForm");

function getRandomBanner(){
	return $_SESSION["bannersObj"]->bannersdb->getRandomBanner();
}
sajax_export("getRandomBanner");
function manageBanners(){
	return $_SESSION["bannersObj"]->bannersui->manageBanners(getAllBanners());
}
sajax_export("manageBanners");
function getAllBanners(){
	return $_SESSION["bannersObj"]->bannersdb->getAllBanners();
}
sajax_export("getAllBanners");

function addBanner($desc,$imgUrl,$linkUrl,$smallBanner){
	try{
		$_SESSION['ghotiObj']->validate->checkExists($desc);
		$_SESSION['ghotiObj']->validate->checkExists($imgUrl);
		$_SESSION['ghotiObj']->validate->checkExists($linkUrl);
		$_SESSION['ghotiObj']->validate->checkNumber($smallBanner);
	}catch (Exception $e) {
    return $e->getMessage();
  }
	return $_SESSION["bannersObj"]->bannersdb->addBanner($desc,$imgUrl,$linkUrl,$smallBanner);
}
sajax_export("addBanner");

function editBanner($id,$desc,$imgUrl,$linkUrl,$smallBanner){
	if($_SESSION["bannersObj"]->bannersdb->editBanner($id,$desc,$imgUrl,$linkUrl,$smallBanner))
		return "Banner Saved!";
	else
		return "Saving Banner Failed!";
}
sajax_export("editBanner");

function deleteBanner($id){
	return $_SESSION["bannersObj"]->bannersdb->deleteBanner($id);
}
sajax_export("deleteBanner");

?>
