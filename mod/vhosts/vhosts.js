/*
 * vhosts.js - admin "Apache Vhosts" panel wiring.
 *
 * Same shape as mail.js / filemanager.js: showX() fetches + renders a pane into
 * the popup, saveX() reads the form fields and posts them back.
 *
 * Results from privileged actions are multi-line command output (configtest
 * errors, certbot logs), which pageFeedBack()'s 3-second toast would throw away
 * before it could be read. Those go to the #vhostsOutput pane instead, and the
 * toast is reserved for one-line outcomes.
 */

function showVhosts(){
	x_printVhostsPanel(popup_cb);
	$("#popupTitle").text("Apache Vhosts");
}
function showVhostCertificates(){
	x_printCertificates(popup_cb);
	$("#popupTitle").text("Certificates");
}
function showVhostImport(){
	x_printVhostImport(popup_cb);
	$("#popupTitle").text("Import Vhosts");
}
/* Two-step, like deleteVhost: this rewrites every vhost file on the server, so
 * a stray click should not start it. */
function importVhosts(){
	if(importVhosts.confirmed !== true){
		importVhosts.confirmed = true;
		setTimeout(function(){ importVhosts.confirmed = false; }, 8000);
		vhostsOutput("This moves every vhost out of the shared config file into its own file, and reloads Apache.\nClick Import again within 8 seconds to go ahead.");
		return;
	}
	importVhosts.confirmed = false;
	vhostsOutput("Importing - this runs configtest and reloads Apache, give it a moment...");
	x_importVhosts(importVhosts_cb);
}
function importVhosts_cb(result){
	vhostsOutput(result);
}

function showVhostsSettings(){
	x_printVhostsSettingsForm(popup_cb);
	$("#popupTitle").text("Vhost Settings");
}
function newVhost(){
	x_printVhostForm("", popup_cb);
	$("#popupTitle").text("New vhost");
}
/* key is whatever the card rendered: a managed vhost's file stem, or an
 * external block's "file.conf:startLine". */
function editVhost(key){
	x_printVhostForm(key, popup_cb);
	$("#popupTitle").text("Vhost");
}

/* Show command output in the panel's own <pre>, scrolled into view. Falls back
 * to the toast when the current pane has no output area (e.g. the edit form). */
function vhostsOutput(text){
	var pane = $("#vhostsOutput");
	if(!pane.length){
		pageFeedBack(String(text).replace(/\n/g, "<br />"));
		return;
	}
	pane.text(text).prop("hidden", false);
	if(pane[0].scrollIntoView){ pane[0].scrollIntoView({block: "nearest"}); }
}

function saveVhost(){
	var vhost = {
		name: $("#vh-name").val(),
		serverName: $("#vh-serverName").val(),
		aliases: $("#vh-aliases").val(),
		documentRoot: $("#vh-documentRoot").val(),
		serverAdmin: $("#vh-serverAdmin").val(),
		certFile: $("#vh-certFile").val(),
		certKeyFile: $("#vh-certKeyFile").val(),
		useSsl: $("#vh-useSsl").is(":checked") ? 1 : 0,
		redirectToSsl: $("#vh-redirectToSsl").is(":checked") ? 1 : 0
	};
	x_saveVhost(vhost, saveVhost_cb);
}
function saveVhost_cb(result){
	if(result === true){
		pageFeedBack("Vhost saved and Apache reloaded.");
		showVhosts();
	}else{
		vhostsOutput(result);
	}
}

function deleteVhost(name){
	/* No confirm() here on purpose: a browser modal blocks the whole page and
	 * the app renders its own popups. Re-click within 5s to confirm instead. */
	if(deleteVhost.pending !== name){
		deleteVhost.pending = name;
		setTimeout(function(){
			if(deleteVhost.pending === name){ deleteVhost.pending = null; }
		}, 5000);
		vhostsOutput("Delete " + name + "? Click Delete again within 5 seconds to confirm.");
		return;
	}
	deleteVhost.pending = null;
	x_deleteVhost(name, deleteVhost_cb);
}
function deleteVhost_cb(result){
	if(result === true){
		pageFeedBack("Vhost removed and Apache reloaded.");
		showVhosts();
	}else{
		vhostsOutput(result);
	}
}

function vhostsConfigTest(){ x_vhostsConfigTest(vhostsOutput); }
function vhostsVhostMap(){ x_vhostsVhostMap(vhostsOutput); }

function reloadApache(){ x_reloadApache(reloadApache_cb); }
function reloadApache_cb(result){
	if(result === true){
		pageFeedBack("Apache reloaded.");
	}else{
		vhostsOutput(result);
	}
}

function issueCertificate(name){ x_issueCertificate(name, vhostsOutput); }
function renewCertificate(name){ x_renewCertificate(name, vhostsOutput); }

function saveVhostsSettings(){
	var settings = {
		helperPath: $("#vh-helperPath").val(),
		dropInDir: $("#vh-dropInDir").val(),
		readOnlyConf: $("#vh-readOnlyConf").val(),
		docRootBase: $("#vh-docRootBase").val(),
		logDir: $("#vh-logDir").val(),
		certbotEmail: $("#vh-certbotEmail").val(),
		notifyEmail: $("#vh-notifyEmail").val(),
		notifyEnabled: $("#vh-notifyEnabled").is(":checked") ? 1 : 0,
		enabled: $("#vh-enabled").is(":checked") ? 1 : 0
	};
	x_saveVhostsSettings(settings, saveVhostsSettings_cb);
}
function sendVhostsTestAlert(){ x_sendVhostsTestAlert(vhostsOutput); }

function saveVhostsSettings_cb(result){
	if(result === true){
		pageFeedBack("Vhost settings saved.");
		showVhostsSettings(); //re-render so the helper banner reflects the new path
	}else{
		pageFeedBack(result);
	}
}
