/*
 * mail.js - the Mail tab of Site Settings.
 *
 * The tab has no Save button of its own: the Site Settings Save runs
 * mailSaveIfChanged() first and only then saves the rest. Mail is saved only
 * when something on the tab changed, so a CA file that has since become
 * unreadable does not block an unrelated save on another tab.
 */
var mailSettingsSnapshot = null;

//Kept for anything that still opens mail directly (older bookmarks, docs).
function showMailSettings(){
	ghotiSettingsTab = 'mail';
	showSiteSettings();
}
function collectMailSettings(){
	if(!document.getElementById("mail-smtpHost")){ return null; }
	return {
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
}
//Called by initSiteSettings() each time the panel is rendered.
function initMailSettings(){
	var settings = collectMailSettings();
	mailSettingsSnapshot = settings ? JSON.stringify(settings) : null;
}
function mailSettingsChanged(){
	var settings = collectMailSettings();
	return settings !== null && JSON.stringify(settings) !== mailSettingsSnapshot;
}
/* Save the Mail tab if it changed, then call next(). On a refusal next() is
 * not called: the Mail tab is brought forward with the reason, so the rest of
 * the form is not saved over a problem the admin has not seen. */
function mailSaveIfChanged(next){
	if(!mailSettingsChanged()){ next(); return; }
	var settings = collectMailSettings();
	x_saveMailSettings(settings, function(result){
		if(result === true){
			mailSettingsSnapshot = JSON.stringify(settings);
			next();
			return;
		}
		if(typeof selectSiteSettingsTab === 'function'){
			selectSiteSettingsTab(document.getElementById('settings-tab-mail'));
		}
		$("#mailSettingsFeedback").text(result);
		pageFeedBack(result);
	});
}
/* The test goes to every administrator account - there is no address to
 * collect, so this just fires and reports what the server says. It tests the
 * SAVED settings, so unsaved changes on this tab are saved first. */
function sendTestMail(){
	mailSaveIfChanged(function(){
		$("#mailSettingsFeedback").text("Sending test message to all administrators…");
		x_sendTestMail(sendTestMail_cb);
	});
}
function sendTestMail_cb(result){
	if(result === true){
		$("#mailSettingsFeedback").text("Test message sent to every administrator - check the inboxes.");
		//Two-factor was waiting on exactly this. The server re-checks on save;
		//this only saves a reload before the checkbox can be ticked.
		$("#set-enableTwoFactor, #set-twoFactorAllUsers").prop("disabled", false);
		$("#set-enableTwoFactor-help").text("Outbound mail has now been tested, so this can be switched on.");
	}else{
		$("#mailSettingsFeedback").text(result);
	}
}
