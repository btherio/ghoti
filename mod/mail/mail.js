/*
 * mail.js - admin "Mail Settings" panel wiring.
 *
 * Follows the same shape as banners.js / analytics.js: showX() fetches +
 * renders the panel, saveX() reads the form fields and posts them back.
 */
function showMailSettings(){
	x_printMailSettingsForm(popup_cb);
	$("#popupTitle").text("Mail Settings");
}
function saveMailSettings(){
	var settings = {
		smtpHost: $("#mail-smtpHost").val(),
		smtpPort: $("#mail-smtpPort").val(),
		encryption: $("#mail-encryption").val(),
		smtpUsername: $("#mail-smtpUsername").val(),
		smtpPassword: $("#mail-smtpPassword").val(),
		fromAddress: $("#mail-fromAddress").val(),
		fromName: $("#mail-fromName").val(),
		enabled: $("#mail-enabled").is(":checked") ? 1 : 0
	};
	x_saveMailSettings(settings, saveMailSettings_cb);
}
function saveMailSettings_cb(result){
	if(result === true){
		pageFeedBack("Mail settings saved.");
		showMailSettings(); //re-render with the saved values
	}else{
		pageFeedBack(result);
	}
}
function sendTestMail(){
	var toAddress = $("#mail-testAddress").val();
	if(!toAddress){
		$("#mailSettingsFeedback").text("Enter an address to send the test message to.");
		return;
	}
	x_sendTestMail(toAddress, sendTestMail_cb);
}
function sendTestMail_cb(result){
	if(result === true){
		$("#mailSettingsFeedback").text("Test message sent - check the inbox.");
	}else{
		$("#mailSettingsFeedback").text(result);
	}
}
