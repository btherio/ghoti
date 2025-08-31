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
		pageFeedBack("Required field missing.");
	}else{
		x_addRelay(RelayName,RelayPin,address,getRelays);
        pageFeedBack("Added Relay.");
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
        pageFeedBack("Saved Relay.");
		x_saveRelay(id,name,pin,address,getRelays);
    }
}
 

function addRelayForm(name="",pin="0"){
    $("#ghotiContent").html("<h1>Add a Relay</h1>");
    $("#ghotiContent").append("<br />Relay Name:<input type=\"text\" id=\"RelayName\" size=\"10\" value=\""+name+"\" />\n");
	$("#ghotiContent").append("<select id=\"RelayPin\" ></select><br />\n");
    $("#ghotiContent").append("<span id=\"ipAddress\">IP Address:<input type=\"text\" id=\"address\" size=\"10\" value=\"192.168.12.0\" /><br /></span>");
    $("#ghotiContent").append("<br /><a href=\"#\" class=\"ghotiMenu\" onclick=\"addRelay();\" >Add</a>&nbsp;\n");
    $("#ghotiContent").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"getRelays();\" >Cancel</a>\n");
    $("#ipAddress").hide();
    for(i = 0; i <= 27; i++){
        if(i == 0){
            $("#RelayPin").append("<option value=\""+i+"\">Relay GPIO or WIFI</option>");
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


	//showPopup();
}

function modifyRelayForm(id=0,name="",pin=0,address){
	$("#ghotiContent").html("<h1>Modify Relay</h1>");
    $("#ghotiContent").append("<br />Relay Name:<input type=\"text\" id=\"RelayName\" size=\"10\" value=\""+name+"\" /><br />\n");
    $("#ghotiContent").append("<input type=\"hidden\" id=\"RelayID\" value=\""+id+"\" /><br />\n");
    $("#ghotiContent").append("<span id=\"gpioInput\">Relay pin (BCM):<input type=\"text\" id=\"RelayPin\" size=\"3\" value=\""+pin+"\" /><br /></span>\n");
    $("#ghotiContent").append("<span id=\"ipAddress\">IP Address:<input type=\"text\" id=\"address\" size=\"10\" value=\""+address+"\" /><br /></span>\n");
    $("#ghotiContent").append("<br /><a href=\"#\" class=\"ghotiMenu\" onclick=\"saveRelay();\" >Save</a>&nbsp;\n");
    $("#ghotiContent").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"getRelays();\" >Cancel</a>\n");
    if(pin == 1){ //pin1 indicates wifi relay, show and hide fields appropriately
        $("#ipAddress").show();
        $("#gpioInput").hide();
    }else{
        $("#ipAddress").hide();
        $("#gpioInput").show();
    }
	//showPopup();
}

function printRelaysForm(result){
	relaysArray = result[0];
    $("#ghotiContent").html("<h1>Relays</h1>");
	$("#ghotiContent").append("<form class=\"dashboard\" id=\"relaysForm\" action=\"#\"></form>");
    $("#ghotiContent").append("<br /><button class=\"status ok\" onclick=\"addRelayForm();\">Add Relay</button>");
    //showPopup();

formContent = ""; //zero out the variable
    for (x in relaysArray){

        formContent += "<div class=\"card\">";
        formContent += `<input type=\"hidden\" id=\"${relaysArray[x]['id']}-id\" value=\"${relaysArray[x]['id']}\" />`;

        if(stripslashes(relaysArray[x]['state']) == "off"){
            formContent += "<button class=\"status warn\" style=\"float: left;\" onclick=\"x_switchRelay("+stripslashes(relaysArray[x]['id'])+","+stripslashes(relaysArray[x]['pin'])+",'on','"+stripslashes(relaysArray[x]['address'])+"',getRelays);\"><img height=\"17\" width=\"30\" src=\"mod/relays/off.png\"></button>";
        } else {
            formContent += "<button class=\"status ok\"style=\"float: left;\" onclick=\"x_switchRelay("+stripslashes(relaysArray[x]['id'])+","+stripslashes(relaysArray[x]['pin'])+",'off','"+stripslashes(relaysArray[x]['address'])+"',getRelays);\"><img height=\"17\" width=\"30\" src=\"mod/relays/on.png\"></button>";
        }
        
        formContent += "<h2 alt=\"name\" id=\""+relaysArray[x]['id']+"-name\">"+stripslashes(relaysArray[x]['name'])+"</h2>";


        formContent += "<br /><p align=\"center\">";

        if(relaysArray[x]['pin'] > 1){ //we dont have a wifi relay
            formContent += "<br /><h2 align=\"center\" id=\""+relaysArray[x]['id']+"-pin\">Pin:"+stripslashes(relaysArray[x]['pin'])+"</h2>&emsp;&emsp;";
        }else{ //we have a wifi relay
            formContent += "<br /><h2 align=\"center\" id=\""+relaysArray[x]['id']+"-address\">IP Address: "+stripslashes(relaysArray[x]['address'])+"</h2>&emsp;&emsp;";
        }

        formContent += "</p><br /><br /><button  class=\"status ok\" onclick=\"modifyRelayForm("+relaysArray[x]['id']+",'"+stripslashes(relaysArray[x]['name'])+"','"+stripslashes(relaysArray[x]['pin'])+"','"+stripslashes(relaysArray[x]['address'])+"')\" >Edit</button>&emsp;";
        formContent += "<button class=\"status error\" style=\"float: right;\" onclick=\"deleteRelay("+relaysArray[x]['id']+")\" >Delete</button>&emsp;";
        formContent += "</div>";

    }
    $("#relaysForm").append(formContent);
    
}

function printRelaysOverview(result){
    relaysArray = result[0];
    liveContent = "";
    
    liveContent += "<h1>Relays</h1>";
    liveContent += "<main class=\"dashboard\">";
    for (x in relaysArray){
  
        liveContent += "<div class=\"card\">";
        liveContent += "<input type=\"hidden\" id=\""+relaysArray[x]['id']+"-id\" value=\""+relaysArray[x]['id']+"\" />"; //id
        liveContent += "<h2>"+stripslashes(relaysArray[x]['name'])+"</h2>"; //name

        if(relaysArray[x]['state'] == 'on'){
            liveContent += "<p align=\"center\"><button class=\"status ok\"><img height=\"35\" width=\"61\" src=\"mod/relays/on.png\"></button></p>"; //state
        } else if(relaysArray[x]['state'] == 'off'){
            liveContent += "<p align=\"center\"><button><img height=\"35\" width=\"61\" src=\"mod/relays/off.png\"></button></p>"; //state
        } else {
            liveContent += "<p>Unable to determine state</p>";
        }



        liveContent += "</div>";

    }
    liveContent += "</main>";
    $("#liveRelays").html(liveContent); //write the content to the page
    $(".ghotiMenu").click(function(e){ //we do this again, because we just made more links.
			e.preventDefault();// stop normal link click on ghotiMenu links
		});
}
