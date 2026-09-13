//<!--//
//jquery stuff
$(document).ready(function(){
	//this runs these functions when the page is finished loading
	
	//getDefaultPage(); //gets the def
	
	if(!document.querySelector('#ghotiContent[data-server-rendered]')){
		var initialPage = new URLSearchParams(window.location.search).get('page');
		if(initialPage && /^[1-9][0-9]*$/.test(initialPage)){ x_getPageById(initialPage, printPage); }
		else { x_getDefaultPage(printPage); }
	}
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
 * login.js, charts.js) are called through this guard. Those scripts load
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

function ghotiTogglePassword(button){
	var input = button.parentNode.querySelector('input');
	if(!input){ return; }
	var show = input.type === 'password';
	input.type = show ? 'text' : 'password';
	button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
	button.setAttribute('title', show ? 'Hide password' : 'Show password');
	button.setAttribute('aria-pressed', show ? 'true' : 'false');
}

/*
 * Client-side twin of ghoti_docs_panel() (ghoti.async.php): builds the same
 * collapsible "how to" <details> markup for admin panels that are rendered in
 * JS (e.g. the links manager). headings/hints are escaped; list items are
 * trusted static markup.
 */
function ghotiDocsHtml(title, hint, sections){
	if(typeof GHOTI_SHOW_HELP_TIPS !== 'undefined' && !GHOTI_SHOW_HELP_TIPS){ return ''; }
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

var ghotiPopupReturnFocus = null;
var ghotiLastPageFocus = null;
document.addEventListener('focusin', function(event){
 if(event.target !== document.body && !event.target.closest('#popup-bg')) ghotiLastPageFocus = event.target;
});
var ghotiPopupInert = [];
function showPopup() {
	var $bg = $("#popup-bg");
	var $popup = $("#popup");
	if(!$bg.is(':visible')){
		ghotiPopupReturnFocus = document.activeElement === document.body ? ghotiLastPageFocus : document.activeElement;
		var branch = $bg[0];
		while(branch && branch.parentElement){
			Array.from(branch.parentElement.children).forEach(function(sibling){
				if(sibling !== branch && !sibling.inert){ sibling.inert = true; ghotiPopupInert.push(sibling); }
			});
			branch = branch.parentElement;
			if(branch === document.body) break;
		}
	}
	$bg.css('display','flex');
	setTimeout(function(){
		var first = document.querySelector('#popup-content input:not([type=hidden]), #popup-content button, #popup-content a[href]');
		(first || $popup[0]).focus();
	}, 20);
	// ensure popup has 'show' class to trigger CSS transition
	setTimeout(function(){
		$popup.addClass('show');
	}, 10);
}

function popupFeedBack(text){
	$("#popupFeedback").text(text);
	window.setTimeout(function(){ $("#popupFeedback").html(""); },3000);
}
function cancelPopup(name) {
	var $popup = $("#popup");
	$popup.removeClass('show');
	// delay hiding overlay until transition finishes
	setTimeout(function(){
		$("#popup-bg").hide();
		$("#popup-content").html("");
		ghotiPopupInert.forEach(function(node){ node.inert = false; });
		ghotiPopupInert = [];
		if(ghotiPopupReturnFocus && ghotiPopupReturnFocus.isConnected){ ghotiPopupReturnFocus.focus(); }
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
	$("#popup-content").text(text);
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
var ghotiPageEditorState = { mode: 'visual', original: '', dirty: false };

function ghotiSanitizeEditorUrl(value){
	value = ghotiString(value).trim();
	if(!value || /[\u0000-\u0020]/.test(value.replace(/^\s+|\s+$/g, ''))){ return ''; }
	var match = value.match(/^([A-Za-z][A-Za-z0-9+.\-]*):/);
	if(match && ['http','https','mailto'].indexOf(match[1].toLowerCase()) === -1){ return ''; }
	return value;
}

//A client-side mirror of the authoritative PHP allowlist. It makes visual and
//preview modes safe immediately; the server repeats this work before saving
//and on every public render.
function ghotiSanitizeEditorHtml(html){
	var allowedTags = ['p','br','h1','h2','h3','h4','h5','h6','ul','ol','li','blockquote','pre','code','strong','b','em','i','u','s','sub','sup','a','img','figure','figcaption','hr','div','span','section','article','table','thead','tbody','tfoot','tr','th','td'];
	var discardTags = ['script','style','iframe','object','embed','svg','math','template','form','input','button','textarea','select','option','link','meta','base','noscript','noembed','xmp','plaintext','title','frame','frameset'];
	var parser = new DOMParser();
	var documentCopy = parser.parseFromString('<div id="ghoti-editor-root">' + ghotiString(html) + '</div>', 'text/html');
	var root = documentCopy.getElementById('ghoti-editor-root');
	if(!root){ return ''; }
	discardTags.forEach(function(tag){
		Array.from(root.querySelectorAll(tag)).forEach(function(node){ node.remove(); });
	});
	Array.from(root.querySelectorAll('*')).reverse().forEach(function(element){
		var tag = element.tagName.toLowerCase();
		if(allowedTags.indexOf(tag) === -1){
			element.replaceWith.apply(element, Array.from(element.childNodes));
			return;
		}
		var perTag = {
			a: ['href','title','target','rel'],
			img: ['src','alt','title','width','height','loading'],
			th: ['colspan','rowspan','scope'],
			td: ['colspan','rowspan']
		};
		var allowedAttributes = ['class','id','aria-label'].concat(perTag[tag] || []);
		Array.from(element.attributes).forEach(function(attribute){
			var name = attribute.name.toLowerCase();
			var value = attribute.value.trim();
			if(allowedAttributes.indexOf(name) === -1){ element.removeAttribute(name); return; }
			if(name === 'href' || name === 'src'){
				value = ghotiSanitizeEditorUrl(value);
			}else if(name === 'class'){
				value = value.split(/\s+/).filter(function(token){ return /^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(token); }).slice(0,12).join(' ');
			}else if(name === 'id'){
				value = /^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(value) ? value : '';
			}else if(['width','height','colspan','rowspan'].indexOf(name) !== -1){
				value = /^\d+$/.test(value) ? String(Math.max(1, Math.min(4096, Number(value)))) : '';
			}else if(name === 'target'){
				value = ['_blank','_self'].indexOf(value) !== -1 ? value : '';
			}else if(name === 'loading'){
				value = ['lazy','eager'].indexOf(value.toLowerCase()) !== -1 ? value.toLowerCase() : '';
			}else if(name === 'scope'){
				value = ['row','col','rowgroup','colgroup'].indexOf(value.toLowerCase()) !== -1 ? value.toLowerCase() : '';
			}else if(name === 'rel'){
				value = value.toLowerCase().split(/\s+/).filter(function(token){ return ['noopener','noreferrer','nofollow'].indexOf(token) !== -1; }).join(' ');
			}else{
				value = value.replace(/[\u0000-\u001F\u007F]/g, ' ').slice(0,500);
			}
			if(value){ element.setAttribute(name, value); }else{ element.removeAttribute(name); }
		});
		if(tag === 'a' && element.getAttribute('target') === '_blank'){
			var rel = (element.getAttribute('rel') || '').split(/\s+/).filter(Boolean);
			['noopener','noreferrer'].forEach(function(token){ if(rel.indexOf(token) === -1){ rel.push(token); } });
			element.setAttribute('rel', rel.join(' '));
		}
		if(tag === 'img' && !element.hasAttribute('alt')){ element.setAttribute('alt',''); }
	});
	return root.innerHTML.trim();
}

function ghotiEditorCurrentHtml(){
	var source = document.getElementById('pageContentEdit');
	var visual = document.getElementById('pageContentVisual');
	var html = ghotiPageEditorState.mode === 'source' ? source.value : visual.innerHTML;
	return ghotiSanitizeEditorHtml(html);
}

function ghotiEditorUpdateStatus(){
	var html = ghotiEditorCurrentHtml();
	var sourceHasPendingMarkup = ghotiPageEditorState.mode === 'source' && document.getElementById('pageContentEdit').value.trim() !== html;
	var holder = document.createElement('div');
	holder.innerHTML = html;
	var text = (holder.textContent || '').replace(/\s+/g,' ').trim();
	var words = text ? text.split(' ').length : 0;
	$('#ghotiEditorCount').text(words + (words === 1 ? ' word' : ' words') + ' · ' + text.length + ' characters');
	var signature = $('#pageTitleEdit').val() + '\n' + $('#pageVisibilityEdit').val() + '\n' + html;
	ghotiPageEditorState.dirty = signature !== ghotiPageEditorState.original || sourceHasPendingMarkup;
	$('#managePageForm').toggleClass('is-dirty', ghotiPageEditorState.dirty);
}

function ghotiEditorSetMode(mode){
	if(['visual','source','preview'].indexOf(mode) === -1){ return; }
	var source = document.getElementById('pageContentEdit');
	var visual = document.getElementById('pageContentVisual');
	var current = ghotiEditorCurrentHtml();
	if(ghotiPageEditorState.mode === 'source' && source.value.trim() !== current){
		$('#ghotiEditorMessage').text('Unsupported or unsafe markup was removed.');
	}
	source.value = current;
	visual.innerHTML = current;
	if(mode === 'preview'){
		document.getElementById('pageContentPreview').innerHTML = current || '<p class="ghotiEditorEmptyPreview">Nothing to preview yet.</p>';
	}
	ghotiPageEditorState.mode = mode;
	$('#managePageForm').attr('data-editor-mode', mode);
	$('[data-editor-mode]').each(function(){
		var active = $(this).attr('data-editor-mode') === mode;
		$(this).toggleClass('is-active', active).attr('aria-pressed', active ? 'true' : 'false');
	});
	ghotiEditorUpdateStatus();
	if(mode === 'visual'){ visual.focus(); }
	if(mode === 'source'){ source.focus(); }
}

function initPageEditor(){
	var source = document.getElementById('pageContentEdit');
	var visual = document.getElementById('pageContentVisual');
	if(!source || !visual){ return; }
	var safeHtml = ghotiSanitizeEditorHtml(source.value);
	source.value = safeHtml;
	visual.innerHTML = safeHtml;
	ghotiPageEditorState.mode = 'visual';
	ghotiPageEditorState.original = $('#pageTitleEdit').val() + '\n' + $('#pageVisibilityEdit').val() + '\n' + safeHtml;
	ghotiPageEditorState.dirty = false;
	$('#managePageForm').attr('data-editor-mode','visual');

	$('#pageContentVisual').off('.ghotiEditor').on('input.ghotiEditor', ghotiEditorUpdateStatus).on('paste.ghotiEditor', function(event){
		event.preventDefault();
		var clipboard = event.originalEvent.clipboardData;
		var pastedHtml = clipboard && clipboard.getData('text/html');
		var safePaste = pastedHtml ? ghotiSanitizeEditorHtml(pastedHtml) : ghotiEscapeHtml(clipboard ? clipboard.getData('text/plain') : '').replace(/\n/g,'<br>');
		document.execCommand('insertHTML', false, safePaste);
		ghotiEditorUpdateStatus();
	});
	$('#pageContentEdit, #pageTitleEdit, #pageVisibilityEdit').off('.ghotiEditor').on('input.ghotiEditor change.ghotiEditor', ghotiEditorUpdateStatus);
	$('[data-editor-command]').off('.ghotiEditor').on('mousedown.ghotiEditor', function(event){
		event.preventDefault();
		document.execCommand($(this).attr('data-editor-command'), false, null);
		visual.focus();
		ghotiEditorUpdateStatus();
	});
	$('#ghotiEditorBlock').off('.ghotiEditor').on('change.ghotiEditor', function(){
		document.execCommand('formatBlock', false, '<' + this.value + '>');
		visual.focus();
		ghotiEditorUpdateStatus();
	});
	$('[data-editor-action="link"]').off('.ghotiEditor').on('mousedown.ghotiEditor', function(event){
		event.preventDefault();
		var url = window.prompt('Paste a web or email address:');
		if(url === null){ return; }
		url = ghotiSanitizeEditorUrl(url);
		if(!url){ $('#ghotiEditorMessage').text('That link address is not allowed.'); return; }
		document.execCommand('createLink', false, url);
		visual.focus();
		ghotiEditorUpdateStatus();
	});
	$('[data-editor-mode]').off('.ghotiEditor').on('click.ghotiEditor', function(){ ghotiEditorSetMode($(this).attr('data-editor-mode')); });
	$('.ghotiPageEditorForm').off('.ghotiEditor').on('keydown.ghotiEditor', function(event){
		if((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's'){
			event.preventDefault();
			savePage();
		}
	});
	ghotiEditorUpdateStatus();
}

function printPageEditor(){
	$("#ghotiPageDisplay").stop(true, true).slideUp("slow");
	$("#managePageForm").stop(true, true).css("visibility", "visible").slideDown("slow");
	$("#pageEditButton").css("visibility", "hidden");
	initPageEditor();
	$("#pageTitleEdit").focus().select();
}

function cancelPageEditor(){
	if(ghotiPageEditorState.dirty && !window.confirm('Discard your unsaved page changes?')){ return; }
	ghotiPageEditorState.dirty = false;
	$("#managePageForm").css("visibility", "hidden").slideUp("slow");
	$("#ghotiPageDisplay").stop(true, true).slideDown("slow");
	$("#pageEditButton").css("visibility", "visible").focus();
}

window.addEventListener('beforeunload', function(event){
	if(!ghotiPageEditorState.dirty){ return; }
	event.preventDefault();
	event.returnValue = '';
});

//The CMS swaps page/admin panels without a browser navigation. Protect those
//clicks too, so the same unsaved-change promise holds inside this single-page
//flow as it does when closing the tab.
document.addEventListener('click', function(event){
	if(!ghotiPageEditorState.dirty || !document.getElementById('managePageForm')){ return; }
	if(event.target.closest && event.target.closest('#managePageForm')){ return; }
	if(window.confirm('Discard your unsaved page changes?')){
		ghotiPageEditorState.dirty = false;
		return;
	}
	event.preventDefault();
	event.stopPropagation();
}, true);

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
	var title = $("#pageTitleEdit").val().trim();
	var content = ghotiEditorCurrentHtml();
	var group = $("#pageVisibilityEdit").val();
	if(!title){
		$("#ghotiEditorMessage").text("Give this page a title before saving.");
		$("#pageTitleEdit").focus();
		return;
	}
	$("#pageContentEdit").val(content);
	$("#ghotiEditorMessage").text("Saving…");
	x_savePage(id,title,content,group,function(result){ savePage_cb(result,id); });
}
function logToFile(line){
	x_logToFile(line,doNothing_cb);
}
function clearGhotiLog(){
	var confirmation = confirm ('Clearing is permanent! \nAre you sure?');
	if(confirmation){
		x_clearGhotiLog();
		window.setTimeout(function(){ showAnalytics(); },1000);
	}
}
function showSiteSettings(){
	x_printSiteSettingsForm(function(content){
		printPage(content);
		initSiteSettings();
	});
}
function showDocumentation(){
	x_printDocumentation(printPage);
}
/* Dim the alert-recipient field while its checkbox is off, so the dependency is
 * visible as you toggle and not only on the next render. The field stays
 * enabled and readable on purpose - hiding it would lose sight of a saved
 * address, and disabling it would drop the value from the save payload. */
function initSiteSettings(){
	var box = document.getElementById('set-enableCriticalAlerts');
	if(!box){ return; }
	var row = box.closest('fieldset').querySelector('.settingsDependent');
	if(!row){ return; }
	box.addEventListener('change', function(){
		if(box.checked){ row.removeAttribute('data-inactive'); }
		else { row.setAttribute('data-inactive', 'true'); }
	});
}
function saveSiteSettings(){
	var settings = {
		siteTitle: $("#set-siteTitle").val(),
		enableVhosts: $("#set-enableVhosts").is(":checked") ? 1 : 0,
		enableCriticalAlerts: $("#set-enableCriticalAlerts").is(":checked") ? 1 : 0,
		criticalAlertEmail: $("#set-criticalAlertEmail").val(),
		privacyOperator: $("#set-privacyOperator").val(),
		privacyEmail: $("#set-privacyEmail").val(),
		privacyRegion: $("#set-privacyRegion").val(),
		defaultTheme: $("#set-defaultTheme").val(),
		headerImg: $("#set-headerImg").val(),
		allowRegister: $("#set-allowRegister").is(":checked") ? 1 : 0,
		enableThemeChanger: $("#set-enableThemeChanger").is(":checked") ? 1 : 0,
		hideLoginButton: $("#set-hideLoginButton").is(":checked") ? 1 : 0,
		showHelpTips: $("#set-showHelpTips").is(":checked") ? 1 : 0,
		enableDebug: $("#set-enableDebug").is(":checked") ? 1 : 0
	};
	x_saveSiteSettings(settings, saveSiteSettings_cb);
}
function saveSiteSettings_cb(result){
	if(result === true){
		GHOTI_SHOW_HELP_TIPS = $("#set-showHelpTips").is(":checked");
		pageFeedBack("Settings saved.");
		showSiteSettings(); //re-render with the saved values
	}else{
		pageFeedBack(result);
	}
}
function setPagePublic(id){
	$("#pageVisibilityEdit").val("public");
	savePage();
}
function setPagePrivate(id){
	$("#pageVisibilityEdit").val("private");
	savePage();
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
	ghotiPageEditorState.dirty = false;
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
	showPopup();
}
function savePage_cb(result,id){
	if(result === true){
		ghotiPageEditorState.dirty = false;
		$("#ghotiEditorMessage").text("Saved. Publishing your update…");
		x_refreshPageMenu(refreshPageMenu_cb);
		x_refreshPrivateMenu(refreshPrivateMenu_cb);
		x_getPageById(id,printPage);
	}else{
		$("#ghotiEditorMessage").text(result || "Could not save the page.");
		logToFile("Error saving page:"+(result || "unknown error"));
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

function ghotiSetPrivacyChoice(allow){
 x_setPrivacyChoice(allow, function(message){
  var status = document.getElementById('ghotiPrivacyStatus');
  if(status) status.textContent = message;
 });
}

document.addEventListener('keydown', function(event){
 var popup = document.getElementById('popup');
 if(!popup || !popup.classList.contains('show')) return;
 if(event.key === 'Escape'){ event.preventDefault(); cancelPopup('popup-bg'); }
 if(event.key !== 'Tab') return;
 var items = Array.from(popup.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex="0"]')).filter(function(el){return el.getClientRects().length > 0;});
 var first = items[0], last = items[items.length-1];
 if(!first){ event.preventDefault(); popup.focus(); }
 else if(event.shiftKey && (document.activeElement === first || document.activeElement === popup)){event.preventDefault();last.focus();}
 else if(!event.shiftKey && document.activeElement === last){event.preventDefault();first.focus();}
});
