function addLinkForm(){
	$("#popup-content").append("Link Name:<input type=\"text\" id=\"linkName\" size=\"10\" /><br />\n");
	$("#popup-content").append("URL:<input type=\"text\" id=\"linkURL\" size=\"20\" value=\"http://\" /><br />\n");
	$("#popup-content").append("<i>Make sure to include <b>http://</b></i><br />\n");
	$("#popup-content").append("Group: <select id=\"linkGroup\"></select>\n");	
	$("#popup-content").append("<input type=\"button\" value=\"Add\" onclick=\"addLink();\" />\n");

	x_getLinkGroups(getLinkGroups_cb); //this populates the linkGroup ddl
	
	$("#popupTitle").html("Add a link");
	showPopup();
}
function getLinkGroups_cb(result){
	for(x in result){
		$("#linkGroup").append("<option value=\""+stripslashes(result[x]['grp'].toString())+"\">"+stripslashes(result[x]['grp'].toString())+"</option>\n");
	}
}
function editLinkForm(){
	x_getLinks("all",editLinkForm_cb);
}
function editLinkForm_cb(result){
	linksArray = result;
	$("#ghotiContent").html("<form id=\"editLinkForm\" action=\"#\"></form>");
	for(x in linksArray){
		$("#editLinkForm").append("<input type=\"hidden\" id=\""+linksArray[x]['id']+"-id\" value=\""+linksArray[x]['id']+"\" />");
		$("#editLinkForm").append("<label>Name</label><input type=\"text\" size=\"12\" id=\""+linksArray[x]['id']+"-name\" value=\""+stripslashes(linksArray[x]['name'])+"\" />");
		$("#editLinkForm").append("<label>URL</label><input type=\"text\" size=\"30\" id=\""+linksArray[x]['id']+"-url\" value=\""+stripslashes(linksArray[x]['url'])+"\" />");
		$("#editLinkForm").append("<label>Group</label><input type=\"text\" size=\"7\" id=\""+linksArray[x]['id']+"-grp\" value=\""+stripslashes(linksArray[x]['grp'])+"\"><br />");

		$("#editLinkForm").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"saveLink("+linksArray[x]['id']+")\" >Save</a>");
        $("#editLinkForm").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"deleteLink("+linksArray[x]['id']+")\" >Delete</a>");
		$("#editLinkForm").append("<span>Added by: <b>"+linksArray[x]['userName']+"</b></span><br />");
		$("#editLinkForm").append("<hr width=\"100%\" />");
	}
	$("#ghotiContent").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"addLinkForm();\">Add Links</a>");
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
	}else{
		pageFeedBack(result);
	}
}
function addLink_cb(result){
	if(result == true){
		popupFeedBack("Link Added!");
		//x_getLinks(getLinks_cb);
	}else{
		//popupFeedBack("Error adding link. Probably duplicate.");
		popupFeedBack(result);
	}
}

function getLinks_cb(links){
	//document.getElementById('ghotiLinks').innerHTML = links;
	//clear the links pane first
	var linksArray = links[1];
	if(links[0] == 'default'){
		$("#ghotiLinks").html("<ul id=\"ghotiLinksList\"></ul>"); //use .html() to clear the div
		for(x in linksArray){
				$("#ghotiLinksList").append("<li><a href=\""+stripslashes(linksArray[x]['url'].toString())+"\">"+stripslashes(linksArray[x]['name'].toString())+"</a></li>");
		}	
		window.setTimeout('x_getLinks(getLinks_cb)',3000); //wait, then repeat the whole thing.
	}else{
		//do it all over again, with a twist.
		$("#ghotiLinks"+links[0]).html("<ul id=\"ghotiLinks"+links[0]+"List\"></ul>");
		for(x in linksArray){
				$("#ghotiLinks"+links[0]+"List").append("<li><a href=\""+stripslashes(linksArray[x]['url'].toString())+"\">"+stripslashes(linksArray[x]['name'].toString())+"</a></li>");
		}	
		window.setTimeout('x_getLinks("'+links[0]+'",getLinks_cb)',3000);
	}
}
