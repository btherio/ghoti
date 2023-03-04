function getRelays(){
    x_getRelays(900,printRelaysForm);
}  
function getRelaysOverview(){
    x_getRelays(900,printRelaysOverview);
}
function addRelay(){
	var RelayName = $("#RelayName").val();
	var RelayPin = $("#RelayPin").val();
    var address = $("#address").val();
	if(RelayName.length < 1 || RelayPin.length < 1 ){
		popupFeedBack("Required field missing.");
	}else{
		x_addRelay(RelayName,RelayPin,address,getRelays);
        popupFeedBack("Added Relay.");
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
    var address = $("#address").val();
	if(!name || !pin ){
		pageFeedBack("Required field missing, failed javascript check");
	}else{
        popupFeedBack("Saved Relay.");
		x_saveRelay(id,name,pin,address,getRelays);
    }
}
 

function addRelayForm(name="",pin="0"){
    $("#popupTitle").html("Add a Relay");
    $("#popup-content").html("<br />Relay Name:<input type=\"text\" id=\"RelayName\" size=\"10\" value=\""+name+"\" /><br />\n");
	$("#popup-content").append("Relay GPIO pin (or WIFI):<select id=\"RelayPin\" ></select><br />\n");
    $("#popup-content").append("<span id=\"ipAddress\">IP Address:<input type=\"text\" id=\"address\" size=\"10\" value=\"192.168.12.0\" /><br /></span>");
    $("#popup-content").append("<br /><a href=\"#\" class=\"ghotiMenu\" onclick=\"addRelay();\" >Add</a>&nbsp;\n");
    $("#popup-content").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"getRelays();\" >Cancel</a>\n");
    $("#ipAddress").hide();
    for(i = 0; i <= 27; i++){
        if(i == 0){
            $("#RelayPin").append("<option value=\""+i+"\">Choose</option>");
        }else if(i == 1){
            $("#RelayPin").append("<option value=\""+i+"\">WIFI</option>");
        }else{
            $("#RelayPin").append("<option value=\""+i+"\">GPIO"+i+"</option>");
        }
     }
    $("#RelayPin").change(function(){
        if($("#RelayPin").val() == 1){
            $("#ipAddress").show();
        } else {
            $("#ipAddress").hide();
        }
    });


	showPopup();
}

function modifyRelayForm(id=0,name="",pin=0,address){
	$("#popupTitle").html("Modify Relay");
    $("#popup-content").html("<br />Relay Name:<input type=\"text\" id=\"RelayName\" size=\"10\" value=\""+name+"\" /><br />\n");
    $("#popup-content").append("<input type=\"hidden\" id=\"RelayID\" value=\""+id+"\" /><br />\n");
    $("#popup-content").append("<span id=\"gpioInput\">Relay pin (BCM):<input type=\"text\" id=\"RelayPin\" size=\"3\" value=\""+pin+"\" /><br /></span>\n");
    $("#popup-content").append("<span id=\"ipAddress\">IP Address:<input type=\"text\" id=\"address\" size=\"10\" value=\""+address+"\" /><br /></span>\n");
    $("#popup-content").append("<br /><a href=\"#\" class=\"ghotiMenu\" onclick=\"saveRelay();\" >Save</a>&nbsp;\n");
    $("#popup-content").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"getRelays();\" >Cancel</a>\n");
    if(pin == 1){ //pin1 indicates wifi relay, show and hide fields appropriately
        $("#ipAddress").show();
        $("#gpioInput").hide();
    }else{
        $("#ipAddress").hide();
        $("#gpioInput").show();
    }
	showPopup();
}

function printRelaysForm(result){
	relaysArray = result[0];
    $("#popupTitle").html("Relays");
	$("#popup-content").html("<br /><form id=\"relaysForm\" action=\"#\"></form>");
    $("#popup-content").append("<br /><a href=\"#\" class=\"ghotiMenu\" onclick=\"addRelayForm();\">Add Relay</a>");
    showPopup();
    
    for (x in relaysArray){
        $("#relaysForm").append("<input type=\"hidden\" id=\""+relaysArray[x]['id']+"-id\" value=\""+relaysArray[x]['id']+"\" />&emsp;");
        $("#relaysForm").append("<label alt=\"name\" id=\""+relaysArray[x]['id']+"-name\"><b>"+stripslashes(relaysArray[x]['name'])+"</b></label>&emsp;&emsp;");
        if(relaysArray[x]['pin'] > 1){ //we dont have a wifi relay
            $("#relaysForm").append("Pin:<label id=\""+relaysArray[x]['id']+"-pin\">"+stripslashes(relaysArray[x]['pin'])+"</label>&emsp;&emsp;");
        }else{ //we have a wifi relay
            $("#relaysForm").append("<label id=\""+relaysArray[x]['id']+"-address\">"+stripslashes(relaysArray[x]['address'])+"</label>&emsp;&emsp;");
        }

        //$("#relaysForm").append("<label id=\""+relaysArray[x]['id']+"-state\">"+stripslashes(relaysArray[x]['state'])+"</label>&emsp;&emsp;&emsp;");
        $("#relaysForm").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"modifyRelayForm("+relaysArray[x]['id']+",'"+stripslashes(relaysArray[x]['name'])+"','"+stripslashes(relaysArray[x]['pin'])+"','"+stripslashes(relaysArray[x]['address'])+"')\" >Edit</a>&emsp;");
        $("#relaysForm").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"deleteRelay("+relaysArray[x]['id']+")\" >Delete</a>&emsp;");
        if(stripslashes(relaysArray[x]['state']) == "off"){
            $("#relaysForm").append("<a class=\"ghotiMenu\" href=\"#\" onclick=\"x_switchRelay("+stripslashes(relaysArray[x]['id'])+","+stripslashes(relaysArray[x]['pin'])+",'on','"+stripslashes(relaysArray[x]['address'])+"',getRelays);\">Force On</a>&emsp;");
        } else {
            $("#relaysForm").append("<a class=\"ghotiMenu\" href=\"#\" onclick=\"x_switchRelay("+stripslashes(relaysArray[x]['id'])+","+stripslashes(relaysArray[x]['pin'])+",'off','"+stripslashes(relaysArray[x]['address'])+"',getRelays);\">Force Off</a>&emsp;");
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
