function getSchedules(){
    x_getSchedules(printSchedulesForm);
}
function printRelayName_cb(result){
    for(x in result){
            $("#relayName"+result[0][0]).html(""+result[1][0]['name']+"");
    }
}
function getRelaysDD2_cb(result){
    relaysArray = result[0];
    for (x in relaysArray){
        $("#SchedulePin").append("<option value=\""+stripslashes(relaysArray[x]['pin'].toString())+"\" data-address=\""+stripslashes(relaysArray[x]['address'].toString())+"\">"+stripslashes(relaysArray[x]['name'].toString())+"</option>\n");
    }


    //populate hours/minutes fields
    for (i = 24; i >= 0; i--) {
        if(i == 24){
            $("#hours").append("<option value=\"H\" selected>H</option>\n");
        }else{
            $("#hours").append("<option value=\""+i+"\">"+i+"</option>\n");
        }
    }
    for (i = 60; i >= 0; i--) {
        if(i == 60){
            $("#minutes").append("<option value=\"M\" selected>M</option>\n");
        }else{
            $("#minutes").append("<option value=\""+i+"\">"+i+"</option>\n");
        }
    }
    for (i = 0; i <= 100; i++) {
        if(i==0){
            $("#recursiveMinutes").append("<option value=\""+i+"\">"+i+"</option>\n");
            $("#recursiveHours").append("<option value=\""+i+"\">"+i+"</option>\n");
        }else{
            $("#recursiveMinutes").append("<option value=\"*\/"+i+"\">"+i+"</option>\n");
            $("#recursiveHours").append("<option value=\"*\/"+i+"\">"+i+"</option>\n");
        }
    }
    for(i=0; i<60;i++){
        if(i == 0){
            $("#pulseLength").append("<option value=\"0\" selected>Sec</option>\n");
        } else {
            $("#pulseLength").append("<option value=\""+i+"\">"+i+"</option>");
        }
    }
}
function deleteSchedule(lineNum){
	var confirmation = confirm ('Delete is permanent! \nAre you sure?');
	if (confirmation){
        var CronString  = $("#cron"+lineNum).val();
        x_deleteSchedule(CronString,getSchedules);
        popupFeedBack("Deleting schedule.");
	}
}

function addSchedule(){
    //scheduleString is cron format line "* * * * * command"
    var SchedulePin     = $("#SchedulePin :selected").val();
    //var ScheduleString  = $("#ScheduleString").val();
    var ScheduleState = $("#ScheduleState :selected").val();
    var ScheduleString = "";

    var sel = document.getElementById('SchedulePin');
    var selected = sel.options[sel.selectedIndex];
    var ipAddress = selected.getAttribute('data-address');

    //var ipAddress = $("#SchedulePin :selected").data('address');
    var pulseLength = "0";
    if ($("#minutes").val() != "M"){
        //if #minutes is not M then it's selected and we should do absolute time code  44 12 * * *
        //we can set only minutes but we cant set only hours, or command will run every minute of that hour.
        if($("#hours").val() != "H"){
            //hours are set too
            ScheduleString = $("#minutes").val() + " " + $("#hours").val() + " * * *";
            popupFeedBack("Setting daily schedule.");
        }else{
            //hours arent set, only do minutes
            ScheduleString = $("#minutes").val() + " * * * *";
            popupFeedBack("Setting hourly schedule.");
        }
    }else if($("#recursiveMinutes").val() != "0"){
        //if recursiveMinutes has been set, we should do code for */10 * * * *
        ScheduleString = $("#recursiveMinutes").val() + " * * * *";
        popupFeedBack("Setting minutes schedule.");
    }else if($("#recursiveHours").val() != "0"){
        //if recursiveHours has been selected we should do code for * */10 * * *
        ScheduleString = "* " + $("#recursiveHours").val() + " * * *";
        popupFeedBack("Setting hours schedule.");
    }else if($("#hours").val() != "H"){
        //this means only h was set
        popupFeedBack("Required field missing.");
    }
    if(ScheduleState == "Pulse"){
        pulseLength = $("#pulseLength").val();
    }

    if(ScheduleString.length < 1 || SchedulePin.length < 1 ){
        popupFeedBack("Required field missing.");
    } else if ($("#ScheduleState :selected").val() == "Pulse" && $("#pulseLength :selected").val() == "0"){
        popupFeedBack("Required field missing.");
    } else {
        x_addSchedule(ScheduleString,SchedulePin,ScheduleState,pulseLength,ipAddress,getSchedules);
        popupFeedBack("Adding "+ScheduleState+" schedule.");
    }

    //alert(ScheduleString);


    //if(ScheduleState == "Pulse"){
    //    var splitString = ScheduleString.split(" ");
    //    pulseLength = splitString[5]; //grab the 5th character group (separated by spaces)
    //    ScheduleString = ScheduleString.slice(0, -3); //remove the last 3 characters
    //}
}

function addScheduleForm(){
    $("#popupTitle").html("Add a Schedule");
    $("#popup-content").html("<form id=\"addScheduleForm\" action=\"#\"></form>");
    $("#addScheduleForm").html("<input type=\"hidden\" id=\"ScheduleString\" size=\"10\" value=\"30 6 * * *\" />\n");
    $("#addScheduleForm").append("<b>Every day at:</b><select id=\"hours\"></select><b>:</b>\n");
    $("#addScheduleForm").append("<select id=\"minutes\"></select><br />\n");
    $("#addScheduleForm").append("<b>Reoccur every </b><select id=\"recursiveMinutes\"></select>Minutes<br />\n");
    $("#addScheduleForm").append("<b>Reoccur every </b><select id=\"recursiveHours\"></select>Hours<br />\n");
    $("#addScheduleForm").append("<b>Action:</b><select id=\"SchedulePin\"></select><br />\n");
    $("#addScheduleForm").append("<b>State:</b><select id=\"ScheduleState\"></select>\n");
    $("#ScheduleState").append("<option value=\"On\">On</option>");
    $("#ScheduleState").append("<option value=\"Off\">Off</option>");
    $("#ScheduleState").append("<option value=\"Pulse\">Pulse</option>");
    $("#addScheduleForm").append("<select id=\"pulseLength\"></select>\n");
    $("#pulseLength").hide();

    $("#ScheduleState").change(function(){
        if ($("#ScheduleState").val() == "Pulse"){
            $("#pulseLength").show();
        }else{
                $("#pulseLength").hide();
        }
    });


    $("#popup-content").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"addSchedule();\" >Add</a>&nbsp;\n");
    $("#popup-content").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"getSchedules();\" >Back</a><br />\n");
    //$("#popup-content").append("<i>Schedule format: Minute, Hour, Day of Month, Month, Day of Week, <b>Pulse Length (0-99)</b></i><br />");
    //$("#popup-content").append("<i> eg: <b>\"30 6 * * *\"</b> for 6:30am every day</i><br />");
    //$("#popup-content").append("<i> eg: <b>\"0 17 * * sun\"</b> for 5:00pm every Sunday</i><br />");
    //$("#popup-content").append("<i> eg: <b>\"*/10 * * * *\"</b> for every 10 minutes</i><br />");
    //$("#popup-content").append("<i> eg: <b>\"0 */2 * * *\"</b> for every 2 hours, on the hour</i><br />");

    x_getRelays(900,getRelaysDD2_cb);
    showPopup();
}

function printSchedulesForm(result){
    schedulesArray = result;
    $("#popupTitle").html("Schedules");
    $("#popup-content").html("<form id=\"schedulesForm\" action=\"#\"></form>");
    $("#popup-content").append("<br /><a href=\"#\" class=\"ghotiMenu\" onclick=\"addScheduleForm();\">Add Schedule</a>");
    showPopup();
    //alert(result);
    if(schedulesArray.len == 0){
        $("#schedulesForm").append("<b>Empty</b>");
    } else {
        $("#schedulesForm").append("<i>Schedules in 24 Hour Format.<br /><b>*/#</b> indicates recurring event.</i><br /><br />");
        $("#schedulesForm").append("<b>");
        y = 1; //start at 1
        for (x in schedulesArray){
            if(schedulesArray[x].length > 0){
                $("#schedulesForm").append("<span id=\"hrs-"+y+"\"></span>:<span id=\"mins-"+y+"\"></span>");
                var explode = schedulesArray[x].split(" "); //explode string and just displaying good bits.
                for(var i = 0; i < explode.length; i++){
                    if (i < 14 && (i < 5 || i == 7 )){ //basically we want character groups <14 but only <5 and == 7. strip out the rest, not important.
                        if(i == 0){
                            if(explode[i] < 10){
                                $("#mins-"+y+"").append("0"+explode[i]+" "); //add a zero for single digits
                            }else{
                                $("#mins-"+y+"").append(explode[i]+" "); //first field is minutes
                            }
                        } else if (i == 1){
                            $("#hrs-"+y+"").append(explode[i]); //second field is hours
                        } else if (i == 6 || i == 7){
                            $("#schedulesForm").append(explode[i] + " ");
                        }

                    }
                    if (i == 6){ //character group 6 is the relay pin, we are going to pull the name from the database.
                        x_getRelayNameByPin(explode[i],y,printRelayName_cb);
                        $("#schedulesForm").append("  <label id=\"relayName"+y+"\"></label>  ");
                    }
                }
                //$("#schedulesForm").append("<b>"+schedulesArray[x]+"</b>");
                $("#schedulesForm").append("</b>");
                $("#schedulesForm").append("<input type=\"hidden\" id=\"cron"+y+"\" value=\""+schedulesArray[x]+"\">");
                $("#schedulesForm").append("<a href=\"#\" class=\"ghotiMenu\" onclick=\"deleteSchedule("+y+")\" >Delete</a>");
                $("#schedulesForm").append("<br />");
                }
            y++; //increment
        }
    }
}
