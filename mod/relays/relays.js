function getRelays(){
    x_getRelays(900,printRelaysForm);
}  
function getRelaysOverview(){
    x_getRelays(900,printRelaysOverview);
}
function addRelay(){
	var RelayName = $("#RelayName").val();
	var RelayPin = $("#RelayPin").val();
	if(RelayName.length < 1 || RelayPin.length < 1 ){
		popupFeedBack("Required field missing.");
	}else{
		x_addRelay(RelayName,RelayPin,getRelays);
	}
}
function deleteRelay(id){
	var confirmation = confirm ('Delete is permanent! \nAre you sure?');
	if (confirmation){
		x_deleteRelay(id, doNothing_cb);
		getRelays();
	}
}
function saveRelay(){
    var id = $("#RelayID").val();
    var name = $("#RelayName").val();
    var pin = $("#RelayPin").val();
	if(!name || !pin ){
		pageFeedBack("Required field missing, failed javascript check");
	}else{
		x_saveRelay(id,name,pin,getRelays);
    }
}
 

function addRelayForm(name="",pin="0"){
	$("#popup-content").html("Relay Name:<input type=\"text\" id=\"RelayName\" size=\"10\" value=\""+name+"\" /><br />\n");
	$("#popup-content").append("Relay pin (BCM):<input type=\"text\" id=\"RelayPin\" size=\"3\" value=\""+pin+"\" /><br />\n");
    $("#popup-content").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"addRelay();\" >Add</a>\n");
    $("#popupTitle").html("Add a Relay");
	showPopup();
}

function modifyRelayForm(id=0,name="",pin=0){
	$("#popup-content").html("Relay Name:<input type=\"text\" id=\"RelayName\" size=\"10\" value=\""+name+"\" /><br />\n");
    $("#popup-content").append("<input type=\"hidden\" id=\"RelayID\" value=\""+id+"\" /><br />\n");
    $("#popup-content").append("Relay pin (BCM):<input type=\"text\" id=\"RelayPin\" size=\"3\" value=\""+pin+"\" /><br />\n");
    $("#popup-content").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"saveRelay();\" >Save</a>\n");
    $("#popupTitle").html("Modify Relay");    
	showPopup();
}

function printRelaysForm(result){
	relaysArray = result[0];
    $("#popupTitle").html("Relays");
	$("#popup-content").html("<form id=\"relaysForm\" action=\"#\"></form>");
    $("#popup-content").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"addRelayForm();\">Add Relay</a>");
    showPopup();
    
    for (x in relaysArray){
        $("#relaysForm").append("<input type=\"hidden\" id=\""+relaysArray[x]['id']+"-id\" value=\""+relaysArray[x]['id']+"\" />&emsp;");
        $("#relaysForm").append("<label alt=\"name\" id=\""+relaysArray[x]['id']+"-name\"><b>"+stripslashes(relaysArray[x]['name'])+"</b></label>&emsp;&emsp;Pin:");
        $("#relaysForm").append("<label id=\""+relaysArray[x]['id']+"-pin\">"+stripslashes(relaysArray[x]['pin'])+"</label>&emsp;&emsp;");
        //$("#relaysForm").append("<label id=\""+relaysArray[x]['id']+"-state\">"+stripslashes(relaysArray[x]['state'])+"</label>&emsp;&emsp;&emsp;");
        $("#relaysForm").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"modifyRelayForm("+relaysArray[x]['id']+",'"+stripslashes(relaysArray[x]['name'])+"','"+stripslashes(relaysArray[x]['pin'])+"')\" >Edit</a>&emsp;");
        $("#relaysForm").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"deleteRelay("+relaysArray[x]['id']+")\" >Delete</a>&emsp;");
        if(stripslashes(relaysArray[x]['state']) == "off"){
            $("#relaysForm").append("<a class=\"ghotiMenu\" href=\"#\" onclick=\"x_switchRelay("+stripslashes(relaysArray[x]['id'])+","+stripslashes(relaysArray[x]['pin'])+",'on',getRelays);\">Force On</a>&emsp;");
        } else {
            $("#relaysForm").append("<a class=\"ghotiMenu\" href=\"#\" onclick=\"x_switchRelay("+stripslashes(relaysArray[x]['id'])+","+stripslashes(relaysArray[x]['pin'])+",'off',getRelays);\">Force Off</a>&emsp;");
        }
        
        $("#relaysForm").append("<br />");
        }
    
}

function printRelaysOverview(result){
    relaysArray = result[0];
    liveContent = "";
    
    liveContent += "<h1>Relay Overview</h1><br /><form id=\"relaysForm\" action=\"#\">";
    
    for (x in relaysArray){
        if((x % 2) == 0 || x == 0){ //this is how we make two columns. If the index is divisible evenly by two...
            liveContent += "<div class=\"flex-container\">";
            
        }
        liveContent += "<div class=\"box\"><input type=\"hidden\" id=\""+relaysArray[x]['id']+"-id\" value=\""+relaysArray[x]['id']+"\" />"; //id
        liveContent += "<label><b>"+stripslashes(relaysArray[x]['name'])+"</label></b>"; //name
        if(relaysArray[x]['state'] == 'on'){
            liveContent += "<p><img height=\"36\" width=\"36\" src=\"mod/relays/on.png\"></p><p>"; //state
        } else if(relaysArray[x]['state'] == 'off'){
            liveContent += "<p><img height=\"36\" width=\"36\" src=\"mod/relays/off.png\"></p><p>"; //state
        } else {
            liveContent += "<p>Unable to determine state</p><p>";
        }
        liveContent += "</p></div>";
        if((x % 2) != 0 ){
            liveContent += "</div>";
        }
    }
    liveContent += "</div></form>";
    $("#liveRelays").html(liveContent); //write the content to the page
    $(".ghotiMenu").click(function(e){ //we do this again, because we just made more links.
			e.preventDefault();// stop normal link click on ghotiMenu links
		});
}
