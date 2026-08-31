function addLinkForm(){
	$("#popup-content").html(
		"<form id=\"addLinkForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"addLink(); return false;\">"+
			"<label class=\"ghotiField\"><span>Link name</span><input type=\"text\" id=\"linkName\" size=\"20\" autocomplete=\"off\" /></label>"+
			"<label class=\"ghotiField\"><span>URL</span><input type=\"text\" id=\"linkURL\" size=\"30\" value=\"http://\" /></label>"+
			"<p class=\"ghotiHelpText\">Include the protocol, such as <b>http://</b> or <b>https://</b>.</p>"+
			"<label class=\"ghotiField\"><span>Group</span><select id=\"linkGroup\"></select></label>"+
			"<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Add Link</button></div>"+
		"</form>"
	);

	x_getLinkGroups(getLinkGroups_cb); //this populates the linkGroup ddl
	
	$("#popupTitle").html("Add a link");
	showPopup();
}
function getLinkGroups_cb(result){
	if(!result || typeof result !== 'object') return;
	for(var x in result){
		if(!result.hasOwnProperty(x)) continue;
		var group = stripslashes(result[x] && result[x]['grp']);
		$("#linkGroup").append("<option value=\""+ghotiEscapeHtmlAttr(group)+"\">"+ghotiEscapeHtml(group)+"</option>\n");
	}
}
function editLinkForm(){
	x_getLinks("all",editLinkForm_cb);
}
var LINK_FIELD_INDEX = {
	name: 0,
	url: 1,
	id: 2,
	grp: 3,
	userName: 4
};
function isNumericLinkRow(row){
	return row && typeof row === 'object' && typeof row.length === 'number'
		&& row.length >= 5 && typeof row[0] !== 'object';
}
function isAssociativeLinkRow(row){
	return row && typeof row === 'object'
		&& (typeof row['name'] !== 'undefined' || typeof row['url'] !== 'undefined' || typeof row['id'] !== 'undefined');
}
function normalizeLinksResult(result){
	if(!result){
		return [];
	}
	var linksArray = result;
	if(result.length > 1 && typeof result[0] === 'string'){
		linksArray = result[1];
	}
	if(!linksArray || typeof linksArray !== 'object'){
		return [];
	}
	if(isNumericLinkRow(linksArray) || isAssociativeLinkRow(linksArray)){
		return [linksArray];
	}
	return linksArray;
}
function linkField(row, fieldName){
	if(!row || typeof row !== 'object'){
		return "";
	}
	if(typeof row[fieldName] !== 'undefined'){
		return stripslashes(row[fieldName]);
	}
	var index = LINK_FIELD_INDEX[fieldName];
	return stripslashes(typeof index !== 'undefined' ? row[index] : "");
}
function editLinkForm_cb(result){
	var linksArray = normalizeLinksResult(result);
	// Assemble the whole edit form as a single string, then write once. The old
	// version did ~8 jQuery .append() calls per link (a fresh #editLinkForm
	// lookup and layout reflow each), which got slow with more than a few links.
	var rows = "";
	for(var x in linksArray){
		if(!linksArray.hasOwnProperty(x)) continue;
		var link = linksArray[x];
		var id = parseInt(linkField(link,'id'), 10);
		if(isNaN(id)) continue;
		rows += "<article class=\"ghotiCrudRow ghotiLinkRow\">";
		rows += "<input type=\"hidden\" id=\""+id+"-id\" value=\""+id+"\" />";
		rows += "<div class=\"ghotiFormGrid\">";
		rows += "<label class=\"ghotiField\"><span>Name</span><input type=\"text\" size=\"12\" id=\""+id+"-name\" value=\""+ghotiEscapeHtmlAttr(linkField(link,'name'))+"\" /></label>";
		rows += "<label class=\"ghotiField ghotiFieldWide\"><span>URL</span><input type=\"text\" size=\"30\" id=\""+id+"-url\" value=\""+ghotiEscapeHtmlAttr(linkField(link,'url'))+"\" /></label>";
		rows += "<label class=\"ghotiField\"><span>Group</span><input type=\"text\" size=\"10\" id=\""+id+"-grp\" value=\""+ghotiEscapeHtmlAttr(linkField(link,'grp'))+"\" /></label>";
		rows += "</div>";
		rows += "<div class=\"ghotiFormActions ghotiCrudActions\">";
		rows += "<span class=\"ghotiCrudMeta\">Added by <b>"+ghotiEscapeHtml(linkField(link,'userName'))+"</b></span>";
		rows += "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" onclick=\"saveLink("+id+");\"><img src=\"gfx/save.png\" alt=\"\" />Save</button>";
		rows += "<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonDanger\" onclick=\"deleteLink("+id+");\"><img src=\"gfx/delete.png\" alt=\"\" />Delete</button>";
		rows += "</div>";
		rows += "</article>";
	}
	if(!rows){
		rows = "<p class=\"ghotiEmptyState\">No links found.</p>";
	}
	$("#ghotiContent").html(
		"<section id=\"ghotiManageLinks\" class=\"ghotiAdminPanel\">"+
			"<div class=\"ghotiCrudHeader\"><h1>Manage Links</h1><button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"addLinkForm();\">Add Link</button></div>"+
			"<form id=\"editLinkForm\" class=\"ghotiForm ghotiCrudList\" action=\"#\">"+rows+"</form>"+
			ghotiDocsHtml("How to use links", "sidebar links & groups", [
				{ heading: "Add a link",
				  list: ["Press <b>Add Link</b> and enter a name and a full URL.", "Only <b>http://</b>, <b>https://</b> and <b>mailto:</b> URLs are accepted."] },
				{ heading: "Groups",
				  list: ["The sidebar shows one list per group (the <b>default</b> group first).", "A new group is created simply by typing its name when adding or saving a link."] },
				{ heading: "Edit or remove",
				  list: ["Change any field and press <b>Save</b>, or <b>Delete</b> to remove the link."] }
			])+
		"</section>"
	);
}
function addLink(){
	var linkName = $("#linkName").val();
	var url = $("#linkURL").val();
	var linkGroup = $("#linkGroup :selected").text();

	if(!linkGroup){ //if there is no link group, it's probably our first link, add to default group.
		linkGroup = "default";
	}
	if(linkName.length < 1 || url.length < 1 ){
		popupFeedBack("Required field missing.");
	}else{
		x_addLink(linkName,url,linkGroup,addLink_cb);
	}
}

function deleteLink(id){
	var confirmation = confirm ('Delete is permanent! \nAre you sure?');
	if (confirmation){
		x_deleteLink(id, doNothing_cb);
		editLinkForm();
		refreshLinks(); //keep the sidebar links pane in sync
	}
}
function saveLink(id){
	var name = $("#"+id+"-name").val();
	var url = $("#"+id+"-url").val();
	var grp = $("#"+id+"-grp").val();
	if(!name || !url || !grp || name.length < 1 || url.length < 1){
		pageFeedBack("Required field missing");
	}else{
		x_saveLink(id,name,url,grp,saveLink_cb);	
	}
}


//callbacks
function saveLink_cb(result){
	if(result == true){
		pageFeedBack("Link saved!")
		refreshLinks();
	}else{
		pageFeedBack(result);
	}
}
function addLink_cb(result){
	if(result == true){
		popupFeedBack("Link Added!");
		refreshLinks();
	}else{
		//popupFeedBack("Error adding link. Probably duplicate.");
		popupFeedBack(result);
	}
}

// Build the <li> markup for a link list in one pass so we can inject it with a
// single DOM write instead of one reflow per link.
function buildLinksHtml(linksArray){
	linksArray = normalizeLinksResult(linksArray);
	var html = "";
	for(var x in linksArray){
		if(!linksArray.hasOwnProperty(x)) continue;
		var link = linksArray[x];
		var url = linkField(link,'url');
		if(!url) continue;
		var name = linkField(link,'name') || url;
		html += "<li><a href=\""+ghotiEscapeHtmlAttr(url)+"\">"+ghotiEscapeHtml(name)+"</a></li>";
	}
	return html;
}

// Render the links pane. This used to re-poll the server every 3 seconds
// forever (each poll triggered a full app bootstrap + DB connect server-side
// and a full DOM rebuild) - the single biggest drag on the whole UI. Links now
// load once and are refreshed explicitly whenever they actually change (see the
// add/save/delete callbacks below).
function getLinks_cb(links){
	var group = links && links.length > 1 && typeof links[0] === 'string' ? links[0] : 'default';
	var linksArray = normalizeLinksResult(links);
	if(group == 'default'){
		$("#ghotiLinks").html("<ul id=\"ghotiLinksList\">"+buildLinksHtml(linksArray)+"</ul>");
	}else{
		$("#ghotiLinks"+group).html("<ul id=\"ghotiLinks"+group+"List\">"+buildLinksHtml(linksArray)+"</ul>");
	}
}

// Pull a fresh copy of the default links pane after a mutation.
function refreshLinks(){
	x_getLinks(getLinks_cb);
}
