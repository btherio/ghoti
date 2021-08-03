<!--//
//jquery stuff
$(document).ready(function(){
	//this runs these functions when the page is finished loading
	
	//getDefaultPage(); //gets the def
	
	x_getDefaultPage(printPage);
	x_getLinks(getLinks_cb); //gets the links and starts the timed links updating cycle that I'm not fond of.
    x_readSensors(printSensorsOverview); //so lets do the same thing with this one? ***SENSORS MODULE CODE***
 	hideMenu();
    
	$(".ghotiMenu").click(function(e){
		e.preventDefault();// stop normal link click on ghotiMenu links
	});
});

//regular javascript

/*These are some nice strip/add slashes functions google found for me @ about.com
* an alternative to this could have been shooting the slashed data back to php ala
* sajax.
*/
var menuHide = false;
function addslashes(str) {
	str=str.replace(/\\/g,'\\\\');
	str=str.replace(/\'/g,'\\\'');
	str=str.replace(/\"/g,'\\"');
	str=str.replace(/\0/g,'\\0');
	return str;
}
function stripslashes(str) {
	str=str.replace(/\\'/g,'\'');
	str=str.replace(/\\"/g,'"');
	str=str.replace(/\\0/g,'\0');
	str=str.replace(/\\\\/g,'\\');
	return str;
}

function showPopup() {
 $("#popup-bg").slideDown("slow");
}

function popupFeedBack(text){
	$("#popupFeedback").html(text);
	window.setTimeout('$("#popupFeedback").html("")',3000);
}
function cancelPopup(name) {
	$("#popup-content").html("");
	$("#"+name).slideUp("slow");
}
function hideMenu() {
    if(menuHide == false){
        $("#main-copy").css("margin","0 0 0 0");
        $("#side-bar").css("width", "0");
        $("#side-bar").css("visibility","hidden");
        $("#sideBarText").css("visibility","hidden");
        $("#sideBarTitle").css("visibility","hidden");
        $("#ghotiPrivateMenu").css("visibility","hidden");
        $("#ghotiAdminMenu").css("visibility","hidden");
        $("#ghotiPrivateMenuTitle").css("visibility","hidden");
        $("#ghotiAdminMenuTitle").css("visibility","hidden")
        menuHide = true;
    }else{
        $("#main-copy").css("margin","0 0 0 15em");
        $("#side-bar").css("width", "15em");
        $("#side-bar").css("visibility","visible");
        $("#sideBarText").css("visibility","visible");
        $("#sideBarTitle").css("visibility","visible");
        $("#ghotiPrivateMenu").css("visibility","visible");
        $("#ghotiAdminMenu").css("visibility","visible");
        $("#ghotiPrivateMenuTitle").css("visibility","visible");
        $("#ghotiAdminMenuTitle").css("visibility","visible")
        menuHide = false;
    }
}

function pageFeedBack(text){
	$("#popupTitle").html("Ghoti CMS");
	$("#popup-content").html(text);
	showPopup();
	window.setTimeout('$("#popup-content").html("")',3000);
	window.setTimeout('cancelPopup("popup-bg")',3000);
}

function changeTheme(form){
	selectedItem = form.theme.selectedIndex;
	url = form.theme.options[ selectedItem ].value;
	if(url.length > 0){
		location.href=url;
	}
}
function printPageEditor(){
	$("#managePageForm").css("visibility", "visible").slideDown("slow");
	$("#pageEditButton").css("visibility", "hidden");
	if(CKEDITOR.instances.pageContentEdit){ //we want to destroy it if it already exists before we make a new one
		CKEDITOR.instances.pageContentEdit.destroy();
	}
 	CKEDITOR.replace(document.getElementById('pageContentEdit'));
}

//ajax functions
function getPage(id) {
	x_getPageById(id,printPage);
}
function getPageByTitle(title){
//	x_getPageByTitle(title,printPage);
}
function getDefaultPage() {
	//x_getDefaultPageTitle(getDefaultPage_cb); //old hard coded default style
	
}
function editPage(id){
	x_editPage(id,printPage);
}
function addPage(){ 
	x_addPage("New Page",addPage_cb);
}
function deletePage(id){
	var confirmation = confirm ('Delete is permanent! \nAre you sure?');
	if(confirmation){
		x_deletePage(id,deletePage_cb);
	}
}
function savePage(){
	var id = $("#pageIdEdit").val();
	var title = $("#pageTitleEdit").val();
	var content = CKEDITOR.instances.pageContentEdit.getData();
	
	if(!title || !content){
		pageFeedBack("Required field missing.");
	}else{
		x_savePage(id,title,content,savePage_cb);
		getPage(id);
	}
}
function logToFile(line){
	x_logToFile(line,doNothing_cb);
}
function showGhotiLog(){
	x_showGhotiLog(printPage);
}
function clearGhotiLog(){
	var confirmation = confirm ('Clearing is permanent! \nAre you sure?');
	if(confirmation){
		x_clearGhotiLog();
		window.setTimeout('x_showGhotiLog(printPage)',1000);
	}
}
function setPagePublic(id){
	savePage(); //save the page first, in case someone's working on it
	x_setPagePublic(id,changePageGroup_cb);
}
function setPagePrivate(id){
	savePage(); //save the page first, in case someone's working on it
	x_setPagePrivate(id,changePageGroup_cb);
}
//callbacks
function doNothing_cb(){
	//not doing anything.
}
function changePageGroup_cb(id) {
  getPage(id);
  x_refreshPageMenu(refreshPageMenu_cb);
  x_refreshPrivateMenu(refreshPrivateMenu_cb);
}
function printPage(content) {
	if(CKEDITOR.instances){
		if(CKEDITOR.instances.pageContentEdit){ //we want to destroy it if it already exists before we make a new one
			CKEDITOR.instances.pageContentEdit.destroy();
		}
	}
	
	$("#ghotiContent").html(content);
	$("#managePageForm").slideUp(0);//workaround to hide ugly space at the bottom.
}
function popup_cb(contents){
	if(CKEDITOR.instances){
		if(CKEDITOR.instances.pageContentEdit){ //we want to destroy it if it already exists before we make a new one
			CKEDITOR.instances.pageContentEdit.destroy();
		}
	}
	$("#popup-content").html(contents);
	showPopup();
}
function savePage_cb(result){
	if(result == true){
		$("#pageEditButton").css("visibility", "visible");
		$("#managePageForm").css("visibility", "hidden").slideUp("slow");

		x_refreshPageMenu(refreshPageMenu_cb);
		x_refreshPrivateMenu(refreshPrivateMenu_cb);

		if(CKEDITOR.instances.pageContentEdit){
			CKEDITOR.instances.pageContentEdit.destroy();
		}
	}else{
		pageFeedBack(result);
		logToFile("Error saving page:"+result);
	}
}
function getDefaultPage_cb(title){
//	x_getPageByTitle(title,printPage);
}
function addPage_cb(result){
	x_refreshPageMenu(refreshPageMenu_cb);
}
function deletePage_cb(result){
	x_getDefaultPage(printPage);	
	x_refreshPageMenu(refreshPageMenu_cb);
	x_refreshPrivateMenu(refreshPrivateMenu_cb);
}
function refreshPageMenu_cb(content){
	$("#ghotiPageMenu").html(content);
}
function refreshPrivateMenu_cb(content){
	$("#ghotiPrivateMenu").html(content);
}

// -->
