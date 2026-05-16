//<!--//
//jquery stuff
$(document).ready(function(){
	//this runs these functions when the page is finished loading
	
	//getDefaultPage(); //gets the def
	
	x_getDefaultPage(printPage);
	x_getLinks(getLinks_cb); //gets the links and starts the timed links updating cycle that I'm not fond of.
	
	$(".ghotiMenu").click(function(e){
		e.preventDefault();// stop normal link click on ghotiMenu links
	});

	// inject lightweight SVG icons for any .linkIcon elements missing an image
	function injectIcons(){
		$(".linkIcon").each(function(){
			var $this = $(this);
			if($this.find('img,svg').length === 0){
				var svg = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="ghoti-icon" xmlns="http://www.w3.org/2000/svg"><path d="M3 12a9 9 0 0115.9-5.1l-1.4 1.4A7 7 0 104 12H2l3 3 3-3H6a5 5 0 118.6-3.6l1.4 1.4A7 7 0 006 18l-3 3-1-1V12z" fill="currentColor"/></svg>';
				$this.prepend(svg);
			}
		});
	}

	injectIcons();
});

//regular javascript

/*These are some nice strip/add slashes functions google found for me @ about.com
* an alternative to this could have been shooting the slashed data back to php ala
* sajax.
*/
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
	var $bg = $("#popup-bg");
	var $popup = $("#popup");
	$bg.css('display','flex');
	// ensure popup has 'show' class to trigger CSS transition
	setTimeout(function(){
		$popup.addClass('show');
	}, 10);
}

function popupFeedBack(text){
	$("#popupFeedback").html(text);
	window.setTimeout(function(){ $("#popupFeedback").html(""); },3000);
}
function cancelPopup(name) {
	var $popup = $("#popup");
	$popup.removeClass('show');
	// delay hiding overlay until transition finishes
	setTimeout(function(){
		$("#popup-bg").hide();
		$("#popup-content").html("");
	}, 300);
}
function hideMenu() {
	var $side = $("#side-bar");
	var $main = $("#main-copy");
	if(menuHide == false){
		$side.animate({ width: 0 }, 250, function(){ $side.css('visibility','hidden'); });
		$main.animate({ marginRight: 0 }, 250);
		$("#sideBarText, #sideBarTitle, #ghotiPrivateMenu, #ghotiAdminMenu, #ghotiPrivateMenuTitle, #ghotiAdminMenuTitle").css('visibility','hidden');
		menuHide = true;
	}else{
		$side.css('visibility','visible').animate({ width: '15em' }, 250);
		$main.animate({ marginRight: '15em' }, 250);
		$("#sideBarText, #sideBarTitle, #ghotiPrivateMenu, #ghotiAdminMenu, #ghotiPrivateMenuTitle, #ghotiAdminMenuTitle").css('visibility','visible');
		menuHide = false;
	}
}

function pageFeedBack(text){
	$("#popupTitle").html("Ghoti CMS");
	$("#popup-content").html(text);
	showPopup();
	setTimeout(function(){
		$("#popup-content").html("");
		cancelPopup('popup-bg');
	}, 3000);
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
	
	var $target = $("#ghotiContent");
	$target.html(content);
	// add fade-in animation to updated content
	$target.find('*').addBack().addClass('fade-in');
	// remove animation class after it completes
	setTimeout(function(){ $target.find('.fade-in').removeClass('fade-in'); }, 400);

	$("#managePageForm").slideUp(0);//workaround to hide ugly space at the bottom.
}
function popup_cb(contents){
	if(CKEDITOR.instances){
		if(CKEDITOR.instances.pageContentEdit){ //we want to destroy it if it already exists before we make a new one
			CKEDITOR.instances.pageContentEdit.destroy();
		}
	}
	$("#popup-content").html(contents);
	// show overlay and animated popup
	$("#popup-bg").css('display','flex');
	setTimeout(function(){ $("#popup").addClass('show'); }, 10);
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
