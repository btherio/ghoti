function addBannerForm(){
	x_addBannerForm(popup_cb);
	document.getElementById('popupTitle').innerHTML = "Add A Banner";
}
function addBanner(){
	var desc = document.getElementById('bannerDesc').value;
	var imgUrl = document.getElementById('bannerImgUrl').value;
	var linkUrl = document.getElementById('bannerLinkUrl').value;
	var smallBanner = document.getElementById('bannerSmallBanner').checked;

	if(!desc || !imgUrl || !linkUrl){
		popupFeedBack("Required field missing.");
	}else{
		if(smallBanner){
			x_addBanner(desc,imgUrl,linkUrl,1,addBanner_cb);
		}else{
			x_addBanner(desc,imgUrl,linkUrl,0,addBanner_cb);
		}	
	}

}
function addBanner_cb(result){
	if(result){
		pageFeedBack("Banner added!");
	}else{
		pageFeedBack(result);
	}
}
function manageBanners(){
	x_manageBanners(printPage);
}
function toggleSmallBanner(id){
	if($("#smallBanner-"+id).val() == 0){
		$("#smallBanner-"+id).val('1');
		$("#smallBannerIcon-"+id).attr('src','gfx/green-check.gif');
	}else{
		$("#smallBanner-"+id).val('0');
		$("#smallBannerIcon-"+id).attr('src','gfx/red-x.gif');		
	}
	
}
function saveBanner(id){
	var alt = document.getElementById('alt-'+id).value;
	var linkUrl = document.getElementById('linkUrl-'+id).value;
	var imgUrl = document.getElementById('imgUrl-'+id).value;
	var smallBanner = document.getElementById('smallBanner-'+id).value;
	
	if(!alt || !imgUrl || !linkUrl){
		popupFeedBack("Required field missing.");
	}else{
		x_editBanner(id,alt,imgUrl,linkUrl,smallBanner,saveBanner_cb);
	}
}
function saveBanner_cb(result){
	x_manageBanners(printPage);
	pageFeedBack(result);
}
function deleteBanner(id){
	var confirmation = confirm ('Delete is permanent! \nAre you sure?');
	if (confirmation){
		x_deleteBanner(id,doNothing_cb);
		x_manageBanners(printPage);	
	}
}
