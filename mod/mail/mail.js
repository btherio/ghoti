/*
 * mail.js - admin "Mail Settings" panel wiring.
 *
 * Follows the same shape as banners.js / charts.js: showX() fetches +
 * renders the panel, saveX() reads the form fields and posts them back.
 */
function showMailSettings(){
	x_printMailSettingsForm(printPage);
}
function saveMailSettings(){
	var settings = {
		smtpHost: $("#mail-smtpHost").val(),
		smtpPort: $("#mail-smtpPort").val(),
		encryption: $("#mail-encryption").val(),
		smtpUsername: $("#mail-smtpUsername").val(),
		smtpPassword: $("#mail-smtpPassword").val(),
		tlsVerify: $("#mail-tlsVerify").is(":checked") ? 1 : 0,
		tlsCaFile: $("#mail-tlsCaFile").val(),
		tlsPeerName: $("#mail-tlsPeerName").val(),
		fromAddress: $("#mail-fromAddress").val(),
		fromName: $("#mail-fromName").val(),
		enabled: $("#mail-enabled").is(":checked") ? 1 : 0
	};
	x_saveMailSettings(settings, saveMailSettings_cb);
}
function saveMailSettings_cb(result){
	if(result === true){
		$("#mailSettingsFeedback").text("Mail settings saved.");
	}else{
		$("#mailSettingsFeedback").text(result);
	}
}
/* The test goes to every administrator account - there is no address to
 * collect, so this just fires and reports what the server says. */
function sendTestMail(){
	$("#mailSettingsFeedback").text("Sending test message to all administrators\u2026");
	x_sendTestMail(sendTestMail_cb);
}
function sendTestMail_cb(result){
	if(result === true){
		$("#mailSettingsFeedback").text("Test message sent to every administrator - check the inboxes.");
	}else{
		$("#mailSettingsFeedback").text(result);
	}
}
