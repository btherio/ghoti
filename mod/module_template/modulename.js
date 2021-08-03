function getModulename(){
    x_getModulename(printModulenameForm);
}  
function getModulenameOverview(){
    x_getModulename(printModulenameOverview);
}
function addModulename(){
	var ModulenameName = $("#ModulenameName").val();
	var ModulenamePin = $("#ModulenamePin").val();
	if(ModulenameName.length < 1 || ModulenamePin.length < 1 ){
		popupFeedBack("Required field missing.");
	}else{
		x_addModulename(ModulenameName,ModulenamePin,getModulename);
	}
}
function deleteModulename(id){
	var confirmation = confirm ('Delete is permanent! \nAre you sure?');
	if (confirmation){
		x_deleteModulename(id, doNothing_cb);
		getModulename();
	}
}
function saveModulename(){
    var id = $("#ModulenameID").val();
    var name = $("#ModulenameName").val();
    var pin = $("#ModulenamePin").val();
	if(!name || !pin ){
		pageFeedBack("Required field missing, failed javascript check");
	}else{
		x_saveModulename(id,name,pin,getModulename);
    }
}
 

function addModulenameForm(name="",pin="0"){
	$("#popup-content").html("Modulename Name:<input type=\"text\" id=\"ModulenameName\" size=\"10\" value=\""+name+"\" /><br />\n");
	$("#popup-content").append("Modulename pin (BCM):<input type=\"text\" id=\"ModulenamePin\" size=\"3\" value=\""+pin+"\" /><br />\n");
	$("#popup-content").append("<input type=\"button\" value=\"Add\" onclick=\"addModulename();\" />\n");
    $("#popupTitle").html("Add a Modulename");
	showPopup();
}

function modifyModulenameForm(id=0,name="",pin=0){
	$("#popup-content").html("Modulename Name:<input type=\"text\" id=\"ModulenameName\" size=\"10\" value=\""+name+"\" /><br />\n");
    $("#popup-content").append("<input type=\"hidden\" id=\"ModulenameID\" value=\""+id+"\" /><br />\n");
    $("#popup-content").append("Modulename pin (BCM):<input type=\"text\" id=\"ModulenamePin\" size=\"3\" value=\""+pin+"\" /><br />\n");
	$("#popup-content").append("<input type=\"button\" value=\"Save\" onclick=\"saveModulename();\" />\n");
    $("#popupTitle").html("Modify Modulename");    
	showPopup();
}

function printModulenameForm(result){
	ModulenameArray = result[0];
    $("#popupTitle").html("Modulename");
	$("#popup-content").html("<form id=\"ModulenameForm\" action=\"#\"></form>");
    $("#popup-content").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"addModulenameForm();\">Add Modulename</a>");
    showPopup();
    
    for (x in ModulenameArray){
        $("#ModulenameForm").append("<input type=\"hidden\" id=\""+ModulenameArray[x]['id']+"-id\" value=\""+ModulenameArray[x]['id']+"\" />");
        $("#ModulenameForm").append("<label alt=\"name\" id=\""+ModulenameArray[x]['id']+"-name\"><b>"+stripslashes(ModulenameArray[x]['name'])+"</b></label>&nbsp;&nbsp;&nbsp;");
        $("#ModulenameForm").append("<label id=\""+ModulenameArray[x]['id']+"-pin\">"+stripslashes(ModulenameArray[x]['pin'])+"</label>&nbsp;&nbsp;&nbsp;");
        $("#ModulenameForm").append("<label id=\""+ModulenameArray[x]['id']+"-state\">"+stripslashes(ModulenameArray[x]['state'])+"</label>&nbsp;&nbsp;&nbsp;");
        $("#ModulenameForm").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"modifyModulenameForm("+ModulenameArray[x]['id']+",'"+stripslashes(ModulenameArray[x]['name'])+"','"+stripslashes(ModulenameArray[x]['pin'])+"')\" >Edit</a>&nbsp;");
        $("#ModulenameForm").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"deleteModulename("+ModulenameArray[x]['id']+")\" >Delete</a>");
        $("#ModulenameForm").append("<br />");
        }
    
}

function printModulenameOverview(result){
    ModulenameArray = result[0];
    liveContent = "";
    
    liveContent += "<h1>Modulename Overview</h1><br /><form id=\"ModulenameForm\" action=\"#\">";
    
    for (x in ModulenameArray){
        if((x % 2) == 0 || x == 0){ //this is how we make two columns. If the index is divisible evenly by two...
            liveContent += "<div class=\"flex-container\">";
            
        }
        liveContent += "<div class=\"box\"><input type=\"hidden\" id=\""+ModulenameArray[x]['id']+"-id\" value=\""+ModulenameArray[x]['id']+"\" />"; //id
        liveContent += "<label><b>"+stripslashes(ModulenameArray[x]['name'])+"</label></b>"; //name
        liveContent += "<p>"+ModulenameArray[x]['state']+"</p><p>"; //state
        if(stripslashes(ModulenameArray[x]['state']) == "off"){
            liveContent += "<a class=\"ghotiMenu\" href=\"#\" onclick=\"x_switchModulename("+stripslashes(ModulenameArray[x]['id'])+","+stripslashes(ModulenameArray[x]['pin'])+",'on',getModulenameOverview);\">Switch On</a>";
        } else {
            liveContent += "<a class=\"ghotiMenu\" href=\"#\" onclick=\"x_switchModulename("+stripslashes(ModulenameArray[x]['id'])+","+stripslashes(ModulenameArray[x]['pin'])+",'off',getModulenameOverview);\">Switch Off</a>";
        }

        liveContent += "</p></div>";
        if((x % 2) != 0 ){
            liveContent += "</div>";
        }
    }
    liveContent += "</div></form>";
    $("#liveModulename").html(liveContent); //write the content to the page
    $(".ghotiMenu").click(function(e){ //we do this again, because we just made more links.
			e.preventDefault();// stop normal link click on ghotiMenu links
		});
}
