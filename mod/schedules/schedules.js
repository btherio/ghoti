function getSchedules(){
    x_getSchedules(printSchedulesForm);
} 
function getRelaysDD2_cb(result){
    relaysArray = result[0];
    for (x in relaysArray){
        $("#SchedulePin").append("<option value=\""+stripslashes(relaysArray[x]['pin'].toString())+"\">"+stripslashes(relaysArray[x]['name'].toString())+"</option>\n");
    }
}

function addSchedule(){
var ScheduleString  = $("#ScheduleString").val();
var SchedulePin     = $("#SchedulePin").val();
var ScheduleState   = $("#ScheduleState :selected").val();
    
	if(ScheduleString.length < 1 || SchedulePin.length < 1 ){
		popupFeedBack("Required field missing.");
	}else{
		x_addSchedule(ScheduleString,SchedulePin,ScheduleState,getSchedules);
	}
}

function deleteSchedule(lineNum){
    var CronString  = $("#cron"+lineNum).val();
	var confirmation = confirm ('Delete is permanent! \nAre you sure?');
	if (confirmation){
        alert(CronString); //for testing
		x_deleteSchedule(CronString,getSchedules);
	}
}
 

function addScheduleForm(){
    $("#popup-content").html("");
    //$("#popup-content").append("<label>Schedule</label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<label>Pin</label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<label>State</label><br />");
    $("#popup-content").append("<input type=\"text\" id=\"ScheduleString\" size=\"10\" value=\"30 6 * * *\" />\n");
    $("#popup-content").append("<select id=\"SchedulePin\"></select>\n");
    $("#popup-content").append("<select id=\"ScheduleState\"></select>\n");
    $("#ScheduleState").append("<option value=\"On\">On</option>");
    $("#ScheduleState").append("<option value=\"Off\">Off</option>");
    //$("#popup-content").append("<input type=\"button\" value=\"Add\" onclick=\"addSchedule();\" /><br />\n");
    $("#popup-content").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"addSchedule();\" >Add</a><br />\n");
    $("#popup-content").append("<i>Schedule format: Minute, Hour, Day of Month, Month, Day of Week</i><br />");
    $("#popup-content").append("<i> eg: <b>\"30 6 * * *\"</b> for 6:30am every day</i><br />");
    $("#popup-content").append("<i> eg: <b>\"0 17 * * sun\"</b> for 5:00pm every Sunday</i><br />");
    $("#popup-content").append("<i> eg: <b>\"*/10 * * * *\"</b> for every 10 minutes</i><br />");
    $("#popup-content").append("<i> eg: <b>\"0 */2 * * *\"</b> for every 2 hours</i><br />");
    $("#popupTitle").html("Add a Schedule");
    showPopup();
    x_getRelays(getRelaysDD2_cb);
}

function printSchedulesForm(result){
    schedulesArray = result;
    $("#popupTitle").html("Schedules");
	$("#popup-content").html("<form id=\"schedulesForm\" action=\"#\"></form>");
    $("#popup-content").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"addScheduleForm();\">Add Schedule</a>");
    showPopup();
    //alert(result);
    if(schedulesArray.len == 0){
        $("#schedulesForm").append("<b>Empty</b>");
    } else {
        $("#schedulesForm").append("<i>Schedule format: Minute, Hour, Day of Month, Month, Day of Week</i><br />");
        for (x in schedulesArray){
            $("#schedulesForm").append("<b>"+schedulesArray[x]+"</b>");
            $("#schedulesForm").append("<input type=\"hidden\" id=\"cron"+x+"\" value=\""+schedulesArray[x]+"\">");
            //$("#schedulesForm").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"deleteSchedule(++)\" >Delete</a>");
            $("#schedulesForm").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"deleteSchedule("+x+")\" >Delete</a>");
            $("#schedulesForm").append("<br />");
            }
    }
}
