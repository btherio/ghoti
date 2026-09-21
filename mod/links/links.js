/*
 * links.js - sidebar links, the Add Link popup and the Manage Links screen.
 *
 * Every links endpoint answers { success, data, error:{code,message} } (see
 * links.async.php); linksError() turns any reply, including a missing one, into
 * text an admin can read.
 */
function linksOk(result){
	return !!(result && result.success === true);
}
function linksError(result, fallback){
	return (result && result.error && result.error.message) || fallback || "Something went wrong. Try again.";
}

/* ---------------------------------------------------------------- *
 *  Sidebar
 * ---------------------------------------------------------------- */

// Validate legacy/imported stored URLs at rendering as well as on save.
function ghotiSafeLinkUrl(url){
	var probe = String(url).replace(/[\x00-\x20]/g, '');
	var scheme = /^([A-Za-z][A-Za-z0-9+.-]*):/.exec(probe);
	return !scheme || /^(https?|mailto)$/i.test(scheme[1]);
}

// Build the <li> markup in one pass so it goes in with a single DOM write.
function buildLinksHtml(links){
	var html = "";
	links.forEach(function(link){
		if(!link.url || !ghotiSafeLinkUrl(link.url)){ return; }
		html += "<li><a href=\""+ghotiEscapeHtmlAttr(link.url)+"\">"+ghotiEscapeHtml(link.name || link.url)+"</a></li>";
	});
	return html;
}

// The sidebar shows the default group. It loads once and is refreshed by
// refreshLinks() after every change - it used to poll the server every 3s.
// Other groups appear inside pages with the [links:group] shortcode.
function getLinks_cb(result){
	if(!linksOk(result)){ return; }
	$("#ghotiLinks").html("<ul id=\"ghotiLinksList\">"+buildLinksHtml(result.data.links)+"</ul>");
}
function refreshLinks(){
	x_getLinks("default", getLinks_cb);
}

/* ---------------------------------------------------------------- *
 *  Add a link
 * ---------------------------------------------------------------- */

function addLinkForm(){
	$("#popup-content").html(
		"<form id=\"addLinkForm\" class=\"ghotiForm\" action=\"#\" novalidate>"+
			"<label class=\"ghotiField\"><span>Link name</span><input type=\"text\" id=\"linkName\" maxlength=\"32\" autocomplete=\"off\" required /></label>"+
			"<label class=\"ghotiField\"><span>URL</span><input type=\"text\" id=\"linkURL\" inputmode=\"url\" autocapitalize=\"off\" autocomplete=\"off\" placeholder=\"example.com or https://example.com\" required /></label>"+
			"<p class=\"ghotiHelpText\">https:// is added for you. Email addresses become mailto: links.</p>"+
			"<label class=\"ghotiField\"><span>Group</span><input type=\"text\" id=\"linkGroup\" list=\"linkGroupOptions\" maxlength=\"32\" value=\"default\" autocomplete=\"off\" /><datalist id=\"linkGroupOptions\"></datalist></label>"+
			"<p class=\"ghotiHelpText\">Choose a group or type a new one. Show a group in any page with <b>[links:group]</b>.</p>"+
			"<p id=\"linkFormError\" class=\"ghotiFormError\" role=\"alert\" hidden></p>"+
			"<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Add Link</button></div>"+
		"</form>"
	);
	var form = document.getElementById("addLinkForm");
	form.addEventListener("submit", function(event){
		event.preventDefault();
		addLink();
	});
	form.addEventListener("input", function(){ setLinkFormError(""); });

	x_getLinkGroups(getLinkGroups_cb); // fills the group suggestions
	$("#popupTitle").html("Add a link");
	showPopup();
}
function getLinkGroups_cb(result){
	var list = document.getElementById("linkGroupOptions");
	if(!list || !linksOk(result)){ return; }
	list.innerHTML = result.data.groups.map(function(group){
		return "<option value=\""+ghotiEscapeHtmlAttr(group)+"\"></option>";
	}).join("");
}
function setLinkFormError(message, field){
	var box = document.getElementById("linkFormError");
	if(!box){ return; }
	box.textContent = message;
	box.hidden = !message;
	if(message && field){ field.focus(); }
}
function addLink(){
	var nameField = document.getElementById("linkName");
	var urlField = document.getElementById("linkURL");
	var name = nameField.value.trim();
	var url = urlField.value.trim();
	if(!name){ return setLinkFormError("Enter a name for the link.", nameField); }
	if(!url){ return setLinkFormError("Enter the link's URL.", urlField); }
	var group = document.getElementById("linkGroup").value.trim();
	x_addLink(name, url, group, addLink_cb);
}
function addLink_cb(result){
	if(!document.getElementById("addLinkForm")){ return; } // popup was closed meanwhile
	if(!linksOk(result)){
		var field = result && result.error && result.error.code === "duplicate" ? document.getElementById("linkURL") : null;
		setLinkFormError(linksError(result, "The link could not be added."), field);
		return;
	}
	var link = result.data.link;
	popupFeedBack("Added \""+link.name+"\" to "+link.grp+".");
	// Stay open for the next link; the group is usually the same.
	document.getElementById("linkName").value = "";
	document.getElementById("linkURL").value = "";
	document.getElementById("linkName").focus();
	x_getLinkGroups(getLinkGroups_cb);
	refreshLinks();
	if(document.getElementById("ghotiManageLinks")){ editLinkForm(); }
}

/* ---------------------------------------------------------------- *
 *  Manage links
 * ---------------------------------------------------------------- */

function editLinkForm(){
	x_getLinks("all", editLinkForm_cb);
}

function linksDocsHtml(){
	return ghotiDocsHtml("How to use links", "sidebar links & groups", [
		{ heading: "Add a link",
		  list: ["Press <b>Add Link</b>, then enter a name and a URL. Web addresses get <b>https://</b> added automatically.",
		         "Only <b>http://</b>, <b>https://</b>, <b>mailto:</b> and site-relative URLs are accepted."] },
		{ heading: "Groups",
		  list: ["Every link belongs to a group. The sidebar shows the <b>default</b> group.",
		         "Type a new group name when adding or saving a link to create it.",
		         "Show any group inside a page by typing its shortcode, such as <b>[links:resources]</b>. Use <b>Copy</b> beside a group to grab it."] },
		{ heading: "Edit or remove",
		  list: ["Change any field and press <b>Save</b> (or <b>Enter</b>), or <b>Delete</b> to remove the link."] }
	]);
}

function linkRowHtml(link){
	var id = parseInt(link.id, 10);
	return "<article class=\"ghotiCrudRow ghotiLinkRow\" data-link-id=\""+id+"\" data-link-group=\""+ghotiEscapeHtmlAttr(link.grp)+"\" data-link-search=\""+ghotiEscapeHtmlAttr((link.name+" "+link.url+" "+link.grp).toLowerCase())+"\">"+
		"<div class=\"ghotiFormGrid\">"+
			"<label class=\"ghotiField\"><span>Name</span><input type=\"text\" name=\"name\" maxlength=\"32\" value=\""+ghotiEscapeHtmlAttr(link.name)+"\" /></label>"+
			"<label class=\"ghotiField ghotiFieldWide\"><span>URL</span><input type=\"text\" name=\"url\" inputmode=\"url\" autocapitalize=\"off\" value=\""+ghotiEscapeHtmlAttr(link.url)+"\" /></label>"+
			"<label class=\"ghotiField\"><span>Group</span><input type=\"text\" name=\"grp\" maxlength=\"32\" list=\"manageLinkGroups\" value=\""+ghotiEscapeHtmlAttr(link.grp)+"\" /></label>"+
		"</div>"+
		"<div class=\"ghotiFormActions ghotiCrudActions\">"+
			"<span class=\"ghotiCrudMeta\">"+(link.userName ? "Added by <b>"+ghotiEscapeHtml(link.userName)+"</b> · " : "")+"<span class=\"ghotiLinkStatus\" role=\"status\"></span></span>"+
			"<button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" data-links-action=\"save\"><img src=\"gfx/save.png\" alt=\"\" />Save</button>"+
			"<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonDanger\" data-links-action=\"delete\"><img src=\"gfx/delete.png\" alt=\"\" />Delete</button>"+
		"</div>"+
	"</article>";
}

// Links arrive ordered by group; "default" goes first, the rest keep that order.
function groupLinks(links){
	var groups = [], byName = {};
	links.forEach(function(link){
		if(!byName.hasOwnProperty(link.grp)){
			byName[link.grp] = { name: link.grp, slug: link.slug, links: [] };
			groups.push(byName[link.grp]);
		}
		byName[link.grp].links.push(link);
	});
	groups.sort(function(a, b){
		return (b.name === "default") - (a.name === "default");
	});
	return groups;
}

function editLinkForm_cb(result){
	var body;
	if(!linksOk(result)){
		body = "<p class=\"ghotiEmptyState\" role=\"alert\">"+ghotiEscapeHtml(linksError(result, "Could not load links."))+"</p>";
	}else if(!result.data.links.length){
		body = "<p class=\"ghotiEmptyState\">No links yet. Press <b>Add Link</b> to create one.</p>";
	}else{
		var groups = groupLinks(result.data.links);
		var search = result.data.links.length > 8
			? "<label class=\"ghotiField\"><span>Filter links</span><input type=\"search\" id=\"linkFilter\" placeholder=\"Name, URL or group\" autocomplete=\"off\" /></label>"
			: "";
		var options = groups.map(function(group){ return "<option value=\""+ghotiEscapeHtmlAttr(group.name)+"\"></option>"; }).join("");
		body = search+"<datalist id=\"manageLinkGroups\">"+options+"</datalist>"+
			"<form id=\"editLinkForm\" class=\"ghotiForm ghotiCrudList\" action=\"#\">"+
			groups.map(function(group){
				var shortcode = "[links:"+group.slug+"]";
				return "<section class=\"ghotiLinkGroup\" data-group=\""+ghotiEscapeHtmlAttr(group.name)+"\">"+
					"<header class=\"ghotiLinkGroupHeader\"><h2>"+ghotiEscapeHtml(group.name)+"</h2>"+
					"<span class=\"ghotiCrudMeta\">"+group.links.length+(group.links.length === 1 ? " link" : " links")+" · <code>"+ghotiEscapeHtml(shortcode)+"</code></span>"+
					"<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary ghotiButtonCompact\" data-links-action=\"copy\" data-shortcode=\""+ghotiEscapeHtmlAttr(shortcode)+"\">Copy</button>"+
					"<span class=\"ghotiLinkStatus\" role=\"status\"></span></header>"+
					group.links.map(linkRowHtml).join("")+
				"</section>";
			}).join("")+
			"</form>";
	}
	$("#ghotiContent").html(
		"<section id=\"ghotiManageLinks\" class=\"ghotiAdminPanel\">"+
			"<div class=\"ghotiCrudHeader\"><h1>Manage Links</h1><button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"addLinkForm();\">Add Link</button></div>"+
			body+linksDocsHtml()+
		"</section>"
	);
	bindLinksManager();
}

function bindLinksManager(){
	var form = document.getElementById("editLinkForm");
	if(form){
		form.addEventListener("submit", function(event){
			event.preventDefault(); // saving is per row, never a whole-form submit
		});
		form.addEventListener("keydown", function(event){
			if(event.key !== "Enter" || event.target.tagName !== "INPUT"){ return; }
			event.preventDefault(); // Enter in a field saves that row
			saveLink(event.target.closest(".ghotiLinkRow"));
		});
		form.addEventListener("click", function(event){
			var button = event.target.closest("[data-links-action]");
			if(!button){ return; }
			var action = button.getAttribute("data-links-action");
			if(action === "save"){ saveLink(button.closest(".ghotiLinkRow")); }
			if(action === "delete"){ deleteLink(button.closest(".ghotiLinkRow")); }
			if(action === "copy"){ copyLinkShortcode(button); }
		});
	}
	var filter = document.getElementById("linkFilter");
	if(filter){
		filter.addEventListener("input", function(){
			var needle = filter.value.trim().toLowerCase();
			document.querySelectorAll("#ghotiManageLinks .ghotiLinkRow").forEach(function(row){
				row.hidden = needle !== "" && row.getAttribute("data-link-search").indexOf(needle) === -1;
			});
		});
	}
}

// Status text sits beside the buttons instead of in a popup that covers the row.
function setLinkStatus(container, message, isError){
	var status = container.querySelector(".ghotiLinkStatus");
	if(!status){ return; }
	status.textContent = message;
	status.classList.toggle("is-error", !!isError);
}

function saveLink(row){
	var id = row.getAttribute("data-link-id");
	var name = row.querySelector("[name=name]").value.trim();
	var url = row.querySelector("[name=url]").value.trim();
	var grp = row.querySelector("[name=grp]").value.trim();
	if(!name || !url){
		setLinkStatus(row, "Name and URL are required.", true);
		return;
	}
	setLinkStatus(row, "Saving…", false);
	x_saveLink(id, name, url, grp, function(result){
		saveLink_cb(row, result);
	});
}
function saveLink_cb(row, result){
	if(!linksOk(result)){
		setLinkStatus(row, linksError(result, "The link could not be saved."), true);
		return;
	}
	var link = result.data.link;
	refreshLinks();
	if(link.grp !== row.getAttribute("data-link-group")){
		editLinkForm(); // it moved to another group, so redraw the sections
		return;
	}
	// Show what was actually stored (e.g. https:// added to a bare domain).
	row.querySelector("[name=name]").value = link.name;
	row.querySelector("[name=url]").value = link.url;
	setLinkStatus(row, "Saved", false);
}

function deleteLink(row){
	var name = row.querySelector("[name=name]").value;
	if(!confirm("Delete \""+name+"\"?\nThis is permanent.")){ return; }
	x_deleteLink(row.getAttribute("data-link-id"), function(result){
		deleteLink_cb(row, result);
	});
}
function deleteLink_cb(row, result){
	// "not_found" means someone already removed it; the list just needs a refresh.
	if(!linksOk(result) && !(result && result.error && result.error.code === "not_found")){
		setLinkStatus(row, linksError(result, "The link could not be deleted."), true);
		return;
	}
	refreshLinks();
	editLinkForm();
}

function copyLinkShortcode(button){
	var header = button.closest(".ghotiLinkGroupHeader");
	var text = button.getAttribute("data-shortcode");
	var done = function(ok){
		setLinkStatus(header, ok ? "Copied" : "Copy failed - select the shortcode and copy it manually.", !ok);
	};
	if(!navigator.clipboard || !navigator.clipboard.writeText){ return done(false); }
	navigator.clipboard.writeText(text).then(function(){ done(true); }, function(){ done(false); });
}
