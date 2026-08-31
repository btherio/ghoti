//<!--//
//jquery stuff
$(document).ready(function(){
	//this runs these functions when the page is finished loading
	
	//getDefaultPage(); //gets the def
	
	x_getDefaultPage(printPage);
	x_getLinks(getLinks_cb); //loads the links pane once; it now refreshes on change instead of polling every 3s.
	bindGhotiMenuLinks();

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
* the ghoti async layer.
*/
function ghotiString(value) {
	if(value === null || typeof value === 'undefined'){
		return "";
	}
	return String(value);
}
function ghotiEscapeHtml(value) {
	return ghotiString(value)
		.replace(/&/g,'&amp;')
		.replace(/</g,'&lt;')
		.replace(/>/g,'&gt;')
		.replace(/"/g,'&quot;')
		.replace(/'/g,'&#039;');
}
function ghotiEscapeHtmlAttr(value) {
	return ghotiEscapeHtml(value);
}
function addslashes(str) {
	str = ghotiString(str);
	str=str.replace(/\\/g,'\\\\');
	str=str.replace(/\'/g,'\\\'');
	str=str.replace(/\"/g,'\\"');
	str=str.replace(/\0/g,'\\0');
	return str;
}
function stripslashes(str) {
	str = ghotiString(str);
	str=str.replace(/\\'/g,'\'');
	str=str.replace(/\\"/g,'"');
	str=str.replace(/\\0/g,'\0');
	str=str.replace(/\\\\/g,'\\');
	return str;
}

function bindGhotiMenuLinks(){
	$(document)
		.off('click.ghotiMenu', '.ghotiMenu')
		.on('click.ghotiMenu', '.ghotiMenu', function(e){
			e.preventDefault();
		});
}

/*
 * Admin-menu actions that live in module scripts (banners.js, links.js,
 * login.js, analytics.js) are called through this guard. Those scripts load
 * right after ghoti.js, but a partial deployment or a blocked request can
 * leave one of them unloaded - in which case the old inline onclick produced
 * a bare "showAnalytics is not defined" console error and a dead menu item.
 * Now the click shows a clear message and can be retried after a reload.
 * When the module script has loaded (the normal case) behaviour is identical
 * to calling the function directly.
 */
function ghotiModuleAction(name){
	if(typeof window[name] === 'function'){
		return window[name].apply(null, Array.prototype.slice.call(arguments, 1));
	}
	pageFeedBack("The '" + name + "' script did not load - reload the page to retry.");
	return false;
}

/*
 * Client-side twin of ghoti_docs_panel() (ghoti.async.php): builds the same
 * collapsible "how to" <details> markup for admin panels that are rendered in
 * JS (e.g. the links manager). headings/hints are escaped; list items are
 * trusted static markup.
 */
function ghotiDocsHtml(title, hint, sections){
	var html = '<details class="ghotiDocs">';
	html += '<summary><span class="ghotiDocsTitle">' + ghotiEscapeHtml(title) + '</span><span class="ghotiDocsHint">' + ghotiEscapeHtml(hint) + '</span></summary>';
	html += '<div class="ghotiDocsBody">';
	for(var i = 0; i < sections.length; i++){
		var s = sections[i];
		if(s && s.heading){ html += '<h3>' + ghotiEscapeHtml(s.heading) + '</h3>'; }
		if(s && s.list){
			html += '<ul>';
			for(var j = 0; j < s.list.length; j++){ html += '<li>' + s.list[j] + '</li>'; }
			html += '</ul>';
		}else if(s && s.html){ html += s.html; }
	}
	html += '</div></details>';
	return html;
}

/* ================================================================== *
 *  Button feedback
 *
 *  Every press of a button that fires an async RPC shows a spinner on that
 *  button (ghotiAsync applies/removes the .is-busy state - see the wrapper
 *  in ghoti.async.php). We capture the most recent button click / form
 *  submit here, and ghotiAsync consumes it if it is fresh enough. Buttons
 *  with an immediate visual result (popups, navigation) need no spinner -
 *  the :active press + the new UI state are the feedback.
 * ================================================================== */

var GHOTI_LAST_TRIGGER = null;
var GHOTI_LAST_TRIGGER_AT = 0;

function ghotiCaptureTrigger(el){
	GHOTI_LAST_TRIGGER = el;
	GHOTI_LAST_TRIGGER_AT = Date.now();
}

//Capture phase, so this runs before any inline onclick handler that fires
//the async call - and we record the button, not whatever was inside it.
//Only real buttons are captured: <button>, submit/button/image/reset inputs,
//and links styled as buttons - never plain text inputs or checkboxes.
document.addEventListener('click', function(e){
	var el = e.target;
	while(el && el.nodeType === 1){
		var tag = el.tagName;
		var isInputButton = tag === 'INPUT' && (el.type === 'submit' || el.type === 'button' || el.type === 'image' || el.type === 'reset');
		if(tag === 'BUTTON' || isInputButton
			|| (el.classList && (el.classList.contains('ghotiButton') || el.classList.contains('ghotiIconButton') || el.classList.contains('btn')))){
			ghotiCaptureTrigger(el);
			return;
		}
		el = el.parentNode;
	}
}, true);

//Enter-key submissions: attribute the spinner to the form's submit button.
document.addEventListener('submit', function(e){
	var form = e.target;
	if(!form || !form.elements){ return; }
	for(var i = 0; i < form.elements.length; i++){
		var el = form.elements[i];
		if(el.tagName === 'BUTTON' && (el.type === 'submit' || el.type === '')){
			ghotiCaptureTrigger(el);
			return;
		}
	}
}, true);

//Add/remove the spinner state on a captured button (see .is-busy in ghoti.css).
//Buttons are disabled while busy to prevent double submits; anchors get the
//pointer-events:none from .is-busy instead. A button that was already disabled
//before the click stays disabled afterwards.
function ghotiButtonBusy(el, busy){
	if(!el || !el.classList){ return; }
	if(busy){
		if(el.getAttribute('data-ghoti-busy') !== '1'){
			el.setAttribute('data-ghoti-busy', '1');
			el.setAttribute('data-ghoti-was-disabled', el.disabled ? '1' : '0');
		}
		el.classList.add('is-busy');
		el.setAttribute('aria-busy', 'true');
		if(el.tagName !== 'A'){ el.disabled = true; }
	}else{
		el.classList.remove('is-busy');
		el.removeAttribute('aria-busy');
		if(el.tagName !== 'A' && el.getAttribute('data-ghoti-was-disabled') !== '1'){
			el.disabled = false;
		}
		el.removeAttribute('data-ghoti-busy');
		el.removeAttribute('data-ghoti-was-disabled');
	}
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
	var themeSelect = form && form.theme;
	if(!themeSelect || themeSelect.selectedIndex < 0){
		return;
	}
	var selectedItem = themeSelect.selectedIndex;
	var url = themeSelect.options[selectedItem].value;
	if(url && url !== '#'){
		location.href=url;
	}
}
// Page editing uses a plain textarea (#pageContentEdit) - no rich-text editor.
function printPageEditor(){
	$("#ghotiPageDisplay").stop(true, true).slideUp("slow");
	$("#managePageForm").stop(true, true).css("visibility", "visible").slideDown("slow");
	$("#pageEditButton").css("visibility", "hidden");
	$("#pageContentEdit").focus();
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
function showPageManager(){
	x_printPageManagementPanel(function(content){
		printPage(content);
		initPageManager();
	});
}
function initPageManager(){
	var $rows = $("#ghotiPageManagerRows .ghotiPageManagerRow");
	var draggedRow = null;
	$("#ghotiPageManagerRows .ghotiPagePermission select")
		.off("change.ghotiPageManager")
		.on("change.ghotiPageManager", updatePageDefaultChoices);
	$rows.off(".ghotiPageManager")
		.on("dragstart.ghotiPageManager", function(event){
			draggedRow = this;
			$(this).addClass("is-dragging");
			if(event.originalEvent && event.originalEvent.dataTransfer){
				event.originalEvent.dataTransfer.effectAllowed = "move";
				event.originalEvent.dataTransfer.setData("text/plain", $(this).attr("data-page-id"));
			}
		})
		.on("dragover.ghotiPageManager", function(event){
			event.preventDefault();
			if(!draggedRow || draggedRow === this){ return; }
			var rect = this.getBoundingClientRect();
			if(event.originalEvent.clientY < rect.top + rect.height / 2){
				this.parentNode.insertBefore(draggedRow,this);
			}else{
				this.parentNode.insertBefore(draggedRow,this.nextSibling);
			}
		})
		.on("dragend.ghotiPageManager", function(){
			$rows.removeClass("is-dragging");
			draggedRow = null;
			updatePageOrderControls();
		});
	updatePageOrderControls();
	updatePageDefaultChoices();
}
function updatePageDefaultChoices(){
	var $firstPublicChoice = $();
	var checkedChoiceIsPublic = false;
	$("#ghotiPageManagerRows .ghotiPageManagerRow").each(function(){
		var isPublic = $(this).find(".ghotiPagePermission select").val() === "public";
		var $choice = $(this).find(".ghotiDefaultChoice");
		$choice.prop("hidden", !isPublic);
		if(isPublic && !$firstPublicChoice.length){ $firstPublicChoice = $choice; }
		if(isPublic && $choice.find("input").is(":checked")){ checkedChoiceIsPublic = true; }
	});
	if(!checkedChoiceIsPublic && $firstPublicChoice.length){
		$firstPublicChoice.find("input").prop("checked",true);
	}
}
function updatePageOrderControls(){
	var $rows = $("#ghotiPageManagerRows .ghotiPageManagerRow");
	$rows.each(function(index){
		var $buttons = $(this).find(".ghotiPageOrderControls button");
		$buttons.eq(0).prop("disabled",index === 0);
		$buttons.eq(1).prop("disabled",index === $rows.length - 1);
	});
}
function moveManagedPage(button,direction){
	var $row = $(button).closest(".ghotiPageManagerRow");
	if(direction < 0){
		var $previous = $row.prev(".ghotiPageManagerRow");
		if($previous.length){ $row.insertBefore($previous); }
	}else{
		var $next = $row.next(".ghotiPageManagerRow");
		if($next.length){ $row.insertAfter($next); }
	}
	updatePageOrderControls();
}
function addManagedPage(){
	var title = ghotiString($("#ghotiNewPageTitle").val()).trim();
	if(!title){ pageFeedBack("Enter a page title."); return; }
	x_addPage(title,function(result){
		if(result === true){
			showPageManager();
			x_refreshPageMenu(refreshPageMenu_cb);
		}else{
			pageFeedBack(result || "Could not add the page.");
		}
	});
}
function editManagedPage(id){
	x_getPageById(id,function(content){
		printPage(content);
		printPageEditor();
	});
}
function deleteManagedPage(id){
	if(!confirm("Delete this page permanently?")){ return; }
	x_deletePage(id,function(result){
		if(result === true){
			showPageManager();
			x_refreshPageMenu(refreshPageMenu_cb);
			x_refreshPrivateMenu(refreshPrivateMenu_cb);
		}else{
			pageFeedBack(result || "Could not delete the page.");
		}
	});
}
function savePageManagement(){
	var pages = [];
	$("#ghotiPageManagerRows .ghotiPageManagerRow").each(function(){
		pages.push({
			id: $(this).attr("data-page-id"),
			groupName: $(this).find(".ghotiPagePermission select").val()
		});
	});
	var defaultPageId = $("input[name=ghotiDefaultPage]:checked").val();
	if(!defaultPageId){ pageFeedBack("Choose a default page."); return; }
	var defaultPage = pages.filter(function(page){ return String(page.id) === String(defaultPageId); })[0];
	if(defaultPage && defaultPage.groupName !== "public"){
		pageFeedBack("The default page must be visible to everyone.");
		return;
	}
	x_savePageManagement(pages,defaultPageId,function(result){
		if(result === true){
			pageFeedBack("Page settings saved.");
			x_refreshPageMenu(refreshPageMenu_cb);
			x_refreshPrivateMenu(refreshPrivateMenu_cb);
			showPageManager();
		}else{
			pageFeedBack(result || "Could not save page settings.");
		}
	});
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
	var content = $("#pageContentEdit").val();

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
		window.setTimeout(function(){ x_showGhotiLog(printPage); },1000);
	}
}
function showSiteSettings(){
	x_printSiteSettingsForm(printPage);
}
function saveSiteSettings(){
	var settings = {
		siteTitle: $("#set-siteTitle").val(),
		defaultPageTitle: $("#set-defaultPageTitle").val(),
		defaultTheme: $("#set-defaultTheme").val(),
		headerImg: $("#set-headerImg").val(),
		allowRegister: $("#set-allowRegister").is(":checked") ? 1 : 0,
		enableThemeChanger: $("#set-enableThemeChanger").is(":checked") ? 1 : 0,
		enableDebug: $("#set-enableDebug").is(":checked") ? 1 : 0
	};
	x_saveSiteSettings(settings, saveSiteSettings_cb);
}
function saveSiteSettings_cb(result){
	if(result === true){
		pageFeedBack("Settings saved.");
		showSiteSettings(); //re-render with the saved values
	}else{
		pageFeedBack(result);
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
	if(/^\d+$/.test(String(id))){
		getPage(id);
		x_refreshPageMenu(refreshPageMenu_cb);
		x_refreshPrivateMenu(refreshPrivateMenu_cb);
	}else{
		pageFeedBack(id || "Could not change page permission.");
	}
}
function printPage(content) {
	var $target = $("#ghotiContent");
	$target.html(content);
	// add fade-in animation to updated content.
	// Build the set explicitly instead of relying on traversal helper behavior.
	$target.addClass('fade-in').find('*').addClass('fade-in');
	// remove animation class after it completes
	setTimeout(function(){ $target.removeClass('fade-in').find('.fade-in').removeClass('fade-in'); }, 400);

	$("#managePageForm").slideUp(0);//workaround to hide ugly space at the bottom.
}
function popup_cb(contents){
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
	if(result === true){
		x_getDefaultPage(printPage);	
		x_refreshPageMenu(refreshPageMenu_cb);
		x_refreshPrivateMenu(refreshPrivateMenu_cb);
	}else{
		pageFeedBack(result || "Could not delete the page.");
	}
}
function refreshPageMenu_cb(content){
	$("#ghotiPageMenu").html(content);
}
function refreshPrivateMenu_cb(content){
	$("#ghotiPrivateMenu").html(content);
}

// -->
