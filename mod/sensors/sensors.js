function getSensors(){
    x_getSensors(printSensorsForm);
}   
function deleteSensor(id){
    //deletes sensor by id
    var confirmation = confirm ('Delete is permanent! \nAre you sure?');
	if (confirmation){
		x_deleteSensor(id, getSensors);
        pageFeedBack("Deleting Sensor.");
		getSensors();
	}
}
function clearSensorData(id){
    //deletes sensor by id
    var confirmation = confirm ('Clearing is permanent! \nAre you sure?');
	if (confirmation){
		x_clearSensorData(id, getSensors);
		getSensors();
        pageFeedBack("Clearing Sensor Data.");
	}
}
function clearSetpoints(id){
    //clears sensor setpoints for a given sensor
	var confirmation = confirm ('This is permanent! \nAre you sure?');
	if (confirmation){
		x_clearSetpoints(id, doNothing_cb);
		manageSetpoints(id);
        pageFeedBack("Clearing Sensor Setpoints.");
	}
}

function saveSensor(){
    //saves a sensor from the modifySensorForm
    var id = $("#sensorID").val();
    var name = $("#sensorName").val();
    var address = $("#sensorAddress").val();
    //var type = $("#sensorType :selected").text();
    var type = $("#sensorType :selected").val();
	if(!name || !address || !type ){ //|| name.length < 1 || address.length < 1 || type.length < 1){
		pageFeedBack("Required field missing, failed javascript check");
	}else{
		x_saveSensor(id,name,address,type,getSensors);	//switched callback to sensorsForm...  trying something new
        pageFeedBack("Saving Sensor.")
    }
}

function addSensorForm(name="",address="192.168.12.*"){

	$("#ghotiContent").html("<H1>Add a Sensor</H1>");
    $("#ghotiContent").append("<br />Sensor Name:<input type=\"text\" id=\"sensorName\" size=\"10\" value=\""+name+"\" /><br />\n");
	$("#ghotiContent").append("Sensor IP address:<input type=\"text\" id=\"sensorAddress\" size=\"15\" value=\""+address+"\" /><br />\n");
	$("#ghotiContent").append("Sensor Type: <select id=\"sensorType\"></select><br /><br />\n");
	$("#ghotiContent").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"addSensor();\" >Add</a>&nbsp;\n");
    $("#ghotiContent").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"getSensors();\">Cancel</a>&nbsp;");
    //i hardcoded these becasue... i did
    $("#sensorType").append("<option value=\"dht\">DHT</option>");
    $("#sensorType").append("<option value=\"ds18b20\">DS18B20</option>");
    $("#sensorType").append("<option value=\"thermistor\">Thermistor</option>");
    $("#sensorType").append("<option value=\"tds\">TDS</option>");
    $("#sensorType").append("<option value=\"ph\">PH</option>");
    $("#sensorType").append("<option value=\"moisture\">Moisture</option>");
    $("#sensorType").append("<option value=\"relay\">Relay</option>");
    $("#sensorType").append("<option value=\"refrig\">Refrigeration</option>");
    //showPopup();
}

function modifySensorForm(id=0,name="",address="192.168.12.*"){
    $("#ghotiContent").html("<H1>Modify Sensor</H1>");
    $("#ghotiContent").append("<br />Sensor Name:<input type=\"text\" id=\"sensorName\" size=\"10\" value=\""+name+"\" /><br />\n");
    $("#ghotiContent").append("<input type=\"hidden\" id=\"sensorID\" value=\""+id+"\" />\n");
    $("#ghotiContent").append("Sensor IP address:<input type=\"text\" id=\"sensorAddress\" size=\"15\" value=\""+address+"\" /><br />\n");
    $("#ghotiContent").append("Sensor Type: <select id=\"sensorType\"></select><br /><br />\n");
    $("#ghotiContent").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"saveSensor();\" >Save</a>&nbsp;\n");
    $("#ghotiContent").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"getSensors();\">Cancel</a>&nbsp;");
    //i hardcoded these becasue... i did
    $("#sensorType").append("<option value=\"dht\">DHT</option>");
    $("#sensorType").append("<option value=\"ds18b20\">DS18B20</option>");
    $("#sensorType").append("<option value=\"thermistor\">Thermistor</option>");
    $("#sensorType").append("<option value=\"tds\">TDS</option>");
    $("#sensorType").append("<option value=\"ph\">PH</option>");
    $("#sensorType").append("<option value=\"moisture\">Moisture</option>");
    $("#sensorType").append("<option value=\"relay\">Relay</option>");
    $("#sensorType").append("<option value=\"refrig\">Refrigeration</option>");
    //showPopup();
}
function manageSetpoints(id){
    $("#ghotiContent").html("<h1>Sensor Setpoints</h1>");
    $("#ghotiContent").append("<br />");
    $("#ghotiContent").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"getSensors();\">Cancel</a>&nbsp;");
    $("#ghotiContent").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"clearSetpoints("+id+");\">Clear Setpoints</a>&nbsp;");
    $("#ghotiContent").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"addSetpointForm("+id+")\">Add Setpoint</a><br />");
    x_getSetpoints(id,printSetpointsForm);
}

function printSetpointsForm(result){
    setpointsArray = result[0];
    //showPopup();
    for (x in setpointsArray){
        $("#ghotiContent").append("<label>Setpoint: <label> " + setpointsArray[x]['setpoint'] + "&nbsp;");
        $("#ghotiContent").append("<label>Type: </label> " + setpointsArray[x]['type'] + "&nbsp;");
        $("#ghotiContent").append("<label>Action:</label> " + setpointsArray[x]['action'] + "&nbsp;");
        $("#ghotiContent").append("<br />");
    }
}

function getRelaysDD_cb(result){
    relaysArray = result[0];
    for (x in relaysArray){
        $("#setpointAction").append("<option value=\""+stripslashes(relaysArray[x]['id'].toString())+"\">"+stripslashes(relaysArray[x]['name'].toString())+"</option>\n");
        //$("#setpointAction").append("<option value=\"911\">Alarm</option>\n");
    }
}
function addSetpointForm(id){
    $("#ghotiContent").html("<h1>Add Setpoint</h1>");
    $("#ghotiContent").append("<b>HIGH setpoints trigger when sensor value is \> setpoint.</b> Logically, the relay being activated should decrease the sensor value.<br />");
    $("#ghotiContent").append("<b>LOW setpoints trigger when sensor value is \< setpoint.</b> Logically, the relay being activated should increase the sensor valve.<br />");
    $("#ghotiContent").append("<br /><form id=\"setpointForm\" action=\"#\"></form>");
    $("#setpointForm").append("<input type=\"hidden\" id=\"sensorID\" value=\""+id+"\" />");
    $("#setpointForm").append(" <select id=\"setpoint\" value=\"\" ></select>&nbsp;Setpoint<br /><br />");
    //$("#setpoint").slider();
    $("#setpointForm").append("<select id=\"setpointType\"></select>&nbsp;Type<br /><br />");
    $("#setpointType").append("<option value=\"HIGH\">HIGH</option>");
    $("#setpointType").append("<option value=\"LOW\">LOW</option>");
    $("#setpointForm").append("<select id=\"setpointAction\"></select>&nbsp;Action<br /><br />");
    $("#setpointForm").append("<a href=\"#\" onclick=\"addSetpoint();\">Add Setpoint</a>&nbsp;&nbsp;");
    $("#setpointForm").append("<a href=\"#\" onclick=\"getSensors();\">Cancel</a>&nbsp;");
    //populate setpoints dropdown
    for (i = -1000; i < 1000; i++) {
        if(i == 0){
            $("#setpoint").append("<option value=\""+i+"\" selected>"+i+"</option>");
        } else {
            $("#setpoint").append("<option value=\""+i+"\">"+i+"</option>");
        }
    } 
    x_getRelays(915,getRelaysDD_cb);
}

function addSetpoint(){
	var sensorID = $("#sensorID").val();
	var setpoint = $("#setpoint :selected").val();
    var setpointType = $("#setpointType :selected").text();
    var setpointAction = $("#setpointAction :selected").val();

	if(setpoint.length < 1 ){
		pageFeedBack("Required field missing.");
	}else{
		x_addSetpoint(sensorID,setpoint,setpointType,setpointAction,getSensors);
	}
}

function addSensor(){
	var sensorName = $("#sensorName").val();
	var sensorAddress = $("#sensorAddress").val();
	var sensorType = $("#sensorType :selected").val();
    
	if(sensorName.length < 1 || sensorAddress.length < 1 ){
		pageFeedBack("Required field missing.");
	}else{
		x_addSensor(sensorName,sensorAddress,sensorType,getSensors);
	}
}

function printSensorsForm(result){
	sensorsArray = result[0];
    $("#ghotiContent").html("<h1>Sensors</h1><br />");
	$("#ghotiContent").append("<form id=\"sensorsForm\" action=\"#\"></form>");
    $("#ghotiContent").append("<br /><a alt=\"Sensors\" class=\"ghotiMenu\" href=\"#\" onclick=\"searchSensors();\">Add Sensor</a>");
    $("#setpointForm").append("<a href=\"#\" onclick=\"getSensors();\">Cancel</a>&nbsp;");
    //showPopup();
    
    for (x in sensorsArray){
        $("#sensorsForm").append("<input type=\"hidden\" id=\""+sensorsArray[x]['id']+"-id\" value=\""+sensorsArray[x]['id']+"\" />");
        $("#sensorsForm").append("<label alt=\"name\" id=\""+sensorsArray[x]['id']+"-name\"><b>"+stripslashes(sensorsArray[x]['name'])+"</b></label>&nbsp;&nbsp;&nbsp;");
        $("#sensorsForm").append("<label id=\""+sensorsArray[x]['id']+"-address\">"+stripslashes(sensorsArray[x]['address'])+"</label>&nbsp;&nbsp;&nbsp;");
        $("#sensorsForm").append("<label id=\""+sensorsArray[x]['id']+"-type\">"+stripslashes(sensorsArray[x]['type'])+"</label>&nbsp;&nbsp;&nbsp;<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;");
        $("#sensorsForm").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"manageSetpoints("+sensorsArray[x]['id']+")\" >Setpoints</a>&nbsp;&nbsp;&nbsp;&nbsp;");
        $("#sensorsForm").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"clearSensorData("+sensorsArray[x]['id']+")\" >Clear-Data</a>&nbsp;&nbsp;&nbsp;&nbsp;");
        $("#sensorsForm").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"modifySensorForm("+sensorsArray[x]['id']+",'"+stripslashes(sensorsArray[x]['name'])+"','"+stripslashes(sensorsArray[x]['address'])+"')\" >Edit</a>&nbsp;&nbsp;&nbsp;&nbsp;");
        $("#sensorsForm").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"deleteSensor("+sensorsArray[x]['id']+")\" >Delete</a>");
        $("#sensorsForm").append("<br /><br /><br />");
        }
    
}

function readSensors(){
    //x_readSensors(printSensorsOverview);
    x_readSensorsFromDB(printSensorsOverview);
}
function printSensorsOverview(result){
    sensorsArray = result;
    liveContent = "";
    
    liveContent += "<h1>Sensors</h1><br /><form id=\"sensorsForm\" action=\"#\">";
    for (x in sensorsArray){
        if((x % 2) == 0 || x == 0){
            liveContent += "<div class=\"flex-container\">";
            
        }

        liveContent += "<div class=\"box\"><input type=\"hidden\" id=\""+sensorsArray[x][0]+"-id\" value=\""+sensorsArray[x][0]+"\" />";
        liveContent += "<h2>";
        if(sensorsArray[x][7] == 1){ //check if sensor has returned online or offline
            liveContent += "<img src=\"mod/sensors/connect.png\" height=\"24\" width=\"24\" alt=\"Connected!\" />";
        } else {
            liveContent += "<img src=\"mod/sensors/disconnect.png\" height=\"24\" width=\"24\" alt=\"Connection Error!\" />";
        }
        liveContent += "<a href=\"#\" class=\"ghotiMenu\" onclick=\"x_getSensorDataById("+sensorsArray[x][0]+",printSensorData)\">"+stripslashes(sensorsArray[x][1])+"</a></h2>";
        liveContent += "<p>"+sensorsArray[x][5]+"</p></div>";
        if((x % 2) != 0 ){
            liveContent += "</div>";
        }
        //liveContent += "<hr width=\"100%\" />";
    }
    liveContent += "</div></form>";
    
    if(("#liveContent").length){
        x_getRelays(900,printRelaysOverview);// add relays
        $("#liveSensors").html(liveContent); //write the content to the page
        window.setTimeout('readSensors()',20000); //loop it

        //window.setTimeout('x_readSensors(printSensorsOverview)',20000); //loop it

    }
    
}
function checkAP_cb(result){
    if(result){
        $("#checkAP").append("<img src=\"mod/sensors/checkAP.gn.png\" height=\"24\" width=\"24\" /><label> AP is Up</label><br />");
    }else{
        $("#checkAP").append("<img src=\"mod/sensors/checkAP.rd.png\" height=\"24\" width=\"24\" /><label> AP is Down</label><br />");
    }
}
function listAP_cb(result){
    $("#clientsForm").append("<label>List Auto-updates every 5 minutes</label><br /><br />");
    for (x in result){
            $("#clientsForm").append("<input type=\"hidden\" value=\""+result[x][1]+"\" />");//mac
            $("#clientsForm").append("<input type=\"hidden\" size=\"16\" value=\""+result[x][2]+"\" />");//ip
            $("#clientsForm").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"addSensorForm('"+stripslashes(result[x][3])+"','"+stripslashes(result[x][2])+"')\">Add</a>");
            $("#clientsForm").append("&nbsp;&nbsp;&nbsp;&nbsp;<label>"+result[x][3]+"</label>");//hostname
            $("#clientsForm").append("<br /><br />");
    }
}
function printSensorData(result){
    //clear the main div and set some variables
    $("#ghotiContent").html("");
    var x_arr = [];
    var y_arr = [];
    var id = result[0][0];
    var name = result[0][3];
    var type = result[0][4];
    
    //split the data from the array
    for (x in result){
        x_arr[x] = result[x][1];
        y_arr[x] = result[x][2];
    }
 
    //plotly code
    var trace1 = {
    x: x_arr,
    y: y_arr,
    type: 'scatter'
    };

    var data = [trace1];


    //maybe we could vary the layout depending on sensor type result[x][4]
    var layout = {
        title: name,
        showlegend: true
    };

    Plotly.newPlot('ghotiContent', data, layout, {scrollZoom: true});
    //Plotly.newPlot('ghotiContent', data, layout, {staticPlot: true});
    
    $("#ghotiContent").append("<br /><a href=\"#\" class=\"ghotiMenu\" onclick=\"x_getSensorDataById("+id+",printSensorData)\">Recent</a>");
    $("#ghotiContent").append("&nbsp;&nbsp;<a href=\"#\" class=\"ghotiMenu\" onclick=\"x_getSensorDataByIdToday("+id+",printSensorData)\">Today</a>");
    $("#ghotiContent").append("&nbsp;&nbsp;<a href=\"#\" class=\"ghotiMenu\" onclick=\"x_getSensorDataByIdThisMonth("+id+",printSensorData)\">This Month</a>");
    $("#ghotiContent").append("&nbsp;&nbsp;<a href=\"#\" class=\"ghotiMenu\" onclick=\"x_getSensorDataByIdLastMonth("+id+",printSensorData)\">Last Month</a>");
    $(".ghotiMenu").click(function(e){
        e.preventDefault();// stop normal link click on ghotiMenu links
    });

}
function searchSensors(){
    $("#ghotiContent").html("<h1>Connected Sensors</h1>");
    $("#ghotiContent").append("<p id=\"checkAP\"></p><form id=\"clientsForm\" action=\"#\"></form>");
    $("#ghotiContent").append("<br /><a href=\"#\" class=\"ghotiMenu\" onclick=\"addSensorForm();\">Add Sensor Manually</a>&nbsp;");
    $("#ghotiContent").append("<a href=\"#\" onclick=\"getSensors();\">Cancel</a>&nbsp;");
    //showPopup();
    x_checkAP(checkAP_cb);
    x_listAP(listAP_cb);
}
