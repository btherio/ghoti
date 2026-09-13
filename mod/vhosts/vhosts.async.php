<?php
/*
 * vhosts.async.php - vhosts module async layer.
 *
 * Endpoints (admin-only) + the UI renderer (class vhostsui), in the same
 * one-file pattern as mail.async.php / banners.async.php.
 *
 * Authorization: every endpoint calls vhostsRequireAdmin() server-side. The
 * admin menu only shows this panel to admins, but that is presentation, not
 * enforcement - see the Authorization helpers note in ghoti.async.php.
 *
 * Two tiers of gate, on purpose:
 *   vhostsRequireAdmin() - reading (list/inspect/configtest/certs)
 *   vhostsRequireWrite() - anything that changes Apache's config, which also
 *                          needs the helper installed AND the module's master
 *                          "enabled" switch on.
 */

/* ---------------------------------------------------------------- *
 *  Endpoints
 * ---------------------------------------------------------------- */

function vhostsRemoteAddr(){
	return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

function vhostsRequireAdmin(){
	if(!ghoti::$enableVhosts){ return false; }
	if(!ghoti_require_admin()){
		ghoti::logWarn("vhosts.async.php", "Unauthorized vhosts access attempt from ".vhostsRemoteAddr());
		return false;
	}
	return true;
}

//Admin + helper installed + writes enabled. Returns true, or an error string
//the caller should return straight to the client.
function vhostsRequireWrite(){
	if(!vhostsRequireAdmin()){ return "Admin access required."; }
	$mod = $_SESSION["vhostsObj"];
	if(empty($mod->vhostsdb->getSettings()['enabled'])){
		return "Changes are disabled. Turn on \"Allow changes\" in the vhosts settings first.";
	}
	if(!$mod->helper->isAvailable()){
		return "The privileged helper is not installed, so this panel is read-only. See \"How vhost management works\" for the install steps.";
	}
	return true;
}

//Short audit line for every config-changing action. The log is the module's
//audit trail on purpose - mirroring vhost state into the database would drift
//the moment certbot or an admin edited a .conf directly.
function vhostsAudit($action, $detail){
	ghoti::logInfo("vhosts.async.php:".$action,
		$detail." by UID:".($_SESSION['userId'] ?? '?')." from ".vhostsRemoteAddr());
}

//Renders the admin "Apache Vhosts" panel (Admin Menu -> Apache Vhosts).
function printVhostsPanel(){
	if(!vhostsRequireAdmin()){ return "<h1>Apache Vhosts</h1><p>Admin access required.</p>"; }
	$mod = $_SESSION["vhostsObj"];
	$settings = $mod->vhostsdb->getSettings();
	return $mod->vhostsui->printVhostsPanel(
		$mod->parser->listVhosts(),
		$settings,
		$mod->helper->isAvailable()
	);
}

/*
 * The detail/edit view for one vhost. Managed vhosts get an editable form;
 * external ones get the same fields read-only plus their raw block.
 *
 * $key addresses a vhost the same way vhostsui::vhostKey() writes it: a
 * managed vhost by its file stem, an external one as "file.conf:startLine",
 * because several external blocks share one file and may share a ServerName.
 * An empty key means "new vhost".
 */
function printVhostForm($key){
	if(!vhostsRequireAdmin()){ return "<h1>Vhost</h1><p>Admin access required.</p>"; }
	$mod = $_SESSION["vhostsObj"];
	$settings = $mod->vhostsdb->getSettings();

	$key = (string)$key;
	if($key === ''){
		//New vhost: hand the form sensible defaults derived from the settings.
		return $mod->vhostsui->printVhostForm(null, $settings, $mod->helper->canWrite());
	}
	if(!vhostsIsValidKey($key)){ return "<p>Invalid vhost reference.</p>"; }

	$match = null;
	foreach($mod->parser->listVhosts() as $vhost){
		if(vhostsui::vhostKey($vhost) === $key){
			$match = $vhost;
			break;
		}
	}
	if($match === null){ return "<p>That vhost no longer exists - reload the list.</p>"; }
	//Adopted files are deliberately NOT editable here - see VhostsParser::MARKER.
	return $mod->vhostsui->printVhostForm($match, $settings, $mod->helper->canWrite() && $match['editable']);
}

/*
 * Create or replace a managed vhost drop-in file.
 *
 * $vhost is the decoded object the client sends. Everything is validated here
 * before it is rendered into config text; the helper then configtests the
 * result and rolls back if Apache rejects it, so a bad write can never survive
 * into the next httpd restart.
 */
function saveVhost($vhost){
	$gate = vhostsRequireWrite();
	if($gate !== true){ return $gate; }
	if(!is_array($vhost)){ return "Invalid vhost."; }

	$mod = $_SESSION["vhostsObj"];
	$settings = $mod->vhostsdb->getSettings();

	$name = strtolower(trim((string)($vhost['name'] ?? '')));
	if(!VhostsParser::isSafeName($name)){
		return "The vhost name must be a short lowercase name (letters, digits, dot, dash, underscore) - it becomes the filename.";
	}

	$serverName = vhostsCleanHostname($vhost['serverName'] ?? '');
	if($serverName === ''){
		return "A ServerName is required, and must be a hostname (not a URL or an IP address).";
	}

	$aliases = array();
	foreach(preg_split('/[\s,]+/', (string)($vhost['aliases'] ?? '')) as $alias){
		if(trim($alias) === ''){ continue; }
		$clean = vhostsCleanHostname($alias);
		if($clean === ''){ return "\"".htmlspecialchars($alias, ENT_QUOTES)."\" is not a valid ServerAlias hostname."; }
		if(!in_array($clean, $aliases, true) && $clean !== $serverName){ $aliases[] = $clean; }
	}
	if(count($aliases) > 32){ return "That is more aliases than this panel supports (32)."; }

	//DocumentRoot must be an existing directory beneath the configured base, so
	//a typo can't point a live vhost at /etc or someone's home directory.
	$docRoot = vhostsCleanPath($vhost['documentRoot'] ?? '');
	if($docRoot === ''){ return "The DocumentRoot must be an absolute path (letters, digits, . _ - / only)."; }
	$base = rtrim((string)$settings['docRootBase'], '/');
	if($base !== '' && strpos($docRoot, $base.'/') !== 0 && $docRoot !== $base){
		return "The DocumentRoot must be inside ".htmlspecialchars($base, ENT_QUOTES).".";
	}
	if(!is_dir($docRoot)){ return "The DocumentRoot \"".htmlspecialchars($docRoot, ENT_QUOTES)."\" does not exist."; }

	$serverAdmin = '';
	if(trim((string)($vhost['serverAdmin'] ?? '')) !== ''){
		try{ $serverAdmin = ghoti_validate()->email($vhost['serverAdmin']); }
		catch(Exception $e){ return "Server admin: ".$e->getMessage(); }
	}

	$useSsl = !empty($vhost['useSsl']);
	$redirectToSsl = !empty($vhost['redirectToSsl']);
	$certFile = ''; $certKeyFile = '';
	if($useSsl){
		$certFile = vhostsCleanPath($vhost['certFile'] ?? '');
		$certKeyFile = vhostsCleanPath($vhost['certKeyFile'] ?? '');
		if($certFile === '' || $certKeyFile === ''){
			return "An HTTPS vhost needs both a certificate file and a private key path. Issue a certificate first, or fill both in.";
		}
	}elseif($redirectToSsl){
		return "Redirect HTTP to HTTPS only makes sense with the HTTPS vhost enabled.";
	}

	$logDir = rtrim((string)$settings['logDir'], '/');
	$config = $_SESSION["vhostsObj"]->vhostsui->renderVhostConfig(array(
		'name' => $name, 'serverName' => $serverName, 'aliases' => $aliases,
		'serverAdmin' => $serverAdmin, 'documentRoot' => $docRoot,
		'useSsl' => $useSsl, 'redirectToSsl' => $redirectToSsl,
		'certFile' => $certFile, 'certKeyFile' => $certKeyFile,
		'logDir' => $logDir,
	));

	$notifier = vhostsNotifier($settings);
	$result = $mod->helper->run('write', array($name), $config);
	if(!$result['ok']){
		$notifier->configRolledBack($name, $result['output']);
		return "Apache rejected the change, so nothing was applied:\n".$result['output'];
	}
	vhostsAudit("saveVhost", "vhost '".$name."' (".$serverName.") written");

	//Written and configtested; now make it live.
	$reload = $mod->helper->run('reload');
	if(!$reload['ok']){
		//The file is on disk but the running server is still on the old config -
		//that gap is exactly the case a person has to go close.
		$notifier->reloadFailed($reload['output']);
		return "Saved, but reloading Apache failed:\n".$reload['output'];
	}
	vhostsAudit("saveVhost", "apache reloaded for '".$name."'");
	$notifier->vhostChanged($serverName, "was saved and Apache reloaded");
	return true;
}

//Removes a managed drop-in file and reloads. Only ever touches the drop-in
//directory - external vhosts have no delete path at all.
function deleteVhost($name){
	$gate = vhostsRequireWrite();
	if($gate !== true){ return $gate; }
	if(!VhostsParser::isSafeName($name)){ return "Invalid vhost name."; }

	$mod = $_SESSION["vhostsObj"];
	$notifier = vhostsNotifier($mod->vhostsdb->getSettings());
	$result = $mod->helper->run('delete', array($name));
	if(!$result['ok']){
		$notifier->configRolledBack($name, $result['output']);
		return "Could not remove that vhost:\n".$result['output'];
	}
	vhostsAudit("deleteVhost", "vhost '".$name."' removed");
	$reload = $mod->helper->run('reload');
	if(!$reload['ok']){
		$notifier->reloadFailed($reload['output']);
		return "Removed, but reloading Apache failed:\n".$reload['output'];
	}
	$notifier->vhostChanged($name, "was deleted and Apache reloaded");
	return true;
}

/*
 * Preview the import: what files would be created, in what order, from what.
 * Read-only and safe for any admin to run - it computes the plan and renders
 * it, and touches nothing.
 */
function printVhostImport(){
	if(!vhostsRequireAdmin()){ return "<h1>Import</h1><p>Admin access required.</p>"; }
	$mod = $_SESSION["vhostsObj"];
	$settings = $mod->vhostsdb->getSettings();
	$plan = VhostsImporter::plan($mod->parser->listVhosts(), (string)$settings['readOnlyConf']);
	return $mod->vhostsui->printVhostImport($plan, $settings, $mod->helper->canWrite());
}

/*
 * Run the import. One helper call moves every vhost, neutralises the source
 * file and configtests, rolling the whole thing back on any failure - so this
 * either fully happens or does not happen at all.
 */
function importVhosts(){
	$gate = vhostsRequireWrite();
	if($gate !== true){ return $gate; }

	$mod = $_SESSION["vhostsObj"];
	$settings = $mod->vhostsdb->getSettings();
	$source = (string)$settings['readOnlyConf'];
	$plan = VhostsImporter::plan($mod->parser->listVhosts(), $source);
	if(!$plan['ok']){ return $plan['error']; }

	$notifier = vhostsNotifier($settings);
	$result = $mod->helper->run('import', array($source), VhostsImporter::encode($plan['files']));
	if(!$result['ok']){
		ghoti::logError("vhosts.async.php:importVhosts", "import failed and was rolled back");
		$notifier->needsIntervention("importing vhosts failed and was rolled back", $result['output']);
		return "The import failed and everything was rolled back:\n".$result['output'];
	}
	vhostsAudit("importVhosts", count($plan['files'])." vhost file(s) imported from ".$source);

	$reload = $mod->helper->run('reload');
	if(!$reload['ok']){
		//Files are in place and configtest passed, but the running server has
		//not picked them up - squarely a go-look-at-it situation.
		$notifier->reloadFailed($reload['output']);
		return "Imported, but reloading Apache failed:\n".$reload['output'];
	}
	$notifier->notify(VhostsNotifier::EVENT_OK,
		count($plan['files'])." vhost(s) imported into ghoti management", $result['output']);
	return "Imported ".count($plan['files'])." vhost file(s) and reloaded Apache.\n".$result['output'];
}

//`apachectl configtest` - safe for any admin to run, changes nothing.
function vhostsConfigTest(){
	if(!vhostsRequireAdmin()){ return "Admin access required."; }
	$mod = $_SESSION["vhostsObj"];
	if(!$mod->helper->isAvailable()){ return "The privileged helper is not installed, so Apache's config cannot be tested from here."; }
	$result = $mod->helper->run('configtest');
	return ($result['ok'] ? "Syntax OK\n" : "").$result['output'];
}

//`apachectl -S`: which vhost Apache actually resolves for each name/port,
//after every include. Useful when a vhost "isn't taking effect".
function vhostsVhostMap(){
	if(!vhostsRequireAdmin()){ return "Admin access required."; }
	$mod = $_SESSION["vhostsObj"];
	if(!$mod->helper->isAvailable()){ return "The privileged helper is not installed, so the live vhost map is unavailable."; }
	return $mod->helper->run('vhost-map')['output'];
}

function reloadApache(){
	$gate = vhostsRequireWrite();
	if($gate !== true){ return $gate; }
	$mod = $_SESSION["vhostsObj"];
	$result = $mod->helper->run('reload');
	if(!$result['ok']){
		vhostsNotifier($mod->vhostsdb->getSettings())->reloadFailed($result['output']);
		return "Reload failed:\n".$result['output'];
	}
	vhostsAudit("reloadApache", "apache reloaded");
	return true;
}

//Renders the certificates pane. /etc/letsencrypt/live is root-only (0700), so
//without the helper this pane has nothing to show - say so rather than
//rendering a misleading empty table.
function printCertificates(){
	if(!vhostsRequireAdmin()){ return "<h1>Certificates</h1><p>Admin access required.</p>"; }
	$mod = $_SESSION["vhostsObj"];
	$settings = $mod->vhostsdb->getSettings();
	if(!$mod->helper->isAvailable()){
		return $mod->vhostsui->printCertificates(array('ok' => false, 'error' => 'helper-missing', 'certs' => array()), $settings, false);
	}
	return $mod->vhostsui->printCertificates($mod->helper->listCertificates(), $settings, $mod->helper->canWrite());
}

//Obtains a Let's Encrypt certificate for a managed vhost. The domains and
//webroot are read from the vhost file by the helper (as root), so this only
//passes the vhost name and the contact address.
function issueCertificate($name){
	$gate = vhostsRequireWrite();
	if($gate !== true){ return $gate; }
	if(!VhostsParser::isSafeName($name)){ return "Invalid vhost name."; }

	$mod = $_SESSION["vhostsObj"];
	$settings = $mod->vhostsdb->getSettings();
	$email = (string)$settings['certbotEmail'];
	if($email === ''){ return "Set a certificate contact e-mail in the vhosts settings first - Let's Encrypt requires one."; }

	$notifier = vhostsNotifier($settings);
	$result = $mod->helper->run('issue', array($name, $email));
	if(!$result['ok']){
		$notifier->certFailed($name, "issue", $result['output']);
		return "Certificate issuance failed:\n".$result['output'];
	}
	vhostsAudit("issueCertificate", "certificate issued for '".$name."'");
	$notifier->certIssued($name, $result['output']);
	return "Certificate issued.\n".$result['output'];
}

function renewCertificate($name){
	$gate = vhostsRequireWrite();
	if($gate !== true){ return $gate; }
	if(!VhostsParser::isSafeName($name)){ return "Invalid certificate name."; }

	$mod = $_SESSION["vhostsObj"];
	$notifier = vhostsNotifier($mod->vhostsdb->getSettings());
	$result = $mod->helper->run('renew', array($name));
	if(!$result['ok']){
		$notifier->certFailed($name, "renew", $result['output']);
		return "Renewal failed:\n".$result['output'];
	}
	vhostsAudit("renewCertificate", "certificate '".$name."' renewed");
	$notifier->certRenewed($name, $result['output']);
	return "Certificate renewed.\n".$result['output'];
}

//Sends a test alert using the CURRENTLY SAVED settings, so admins should press
//Save first - same contract as the mail module's own test button.
function sendVhostsTestAlert(){
	if(!vhostsRequireAdmin()){ return "Admin access required."; }
	$settings = $_SESSION["vhostsObj"]->vhostsdb->getSettings();
	$notifier = vhostsNotifier($settings);
	if(!$notifier->isEnabled()){
		if(empty($settings['notifyEnabled'])){ return "E-mail alerts are switched off. Tick the box, save, then try again."; }
		if($notifier->recipient() === ''){ return "No notification address is set."; }
		return "The mail module is not available, so alerts cannot be sent.";
	}
	if($notifier->notify(VhostsNotifier::EVENT_OK, "test alert",
		"Nothing is wrong - this message confirms that vhost and certificate alerts can reach you.")){
		return "Test alert sent to ".$notifier->recipient()." - check the inbox.";
	}
	return "The test alert could not be sent. Check Mail Settings and the log.";
}

function printVhostsSettingsForm(){
	if(!vhostsRequireAdmin()){ return "<h1>Vhost Settings</h1><p>Admin access required.</p>"; }
	$mod = $_SESSION["vhostsObj"];
	return $mod->vhostsui->printVhostsSettingsForm($mod->vhostsdb->getSettings(), $mod->helper->isAvailable());
}

//Persists the module's own settings. These are filesystem paths, so each one is
//range-checked the same way mail's tlsCaFile is - text() would mangle a path.
function saveVhostsSettings($settings){
	if(!vhostsRequireAdmin()){ return "Admin access required."; }
	if(!is_array($settings)){ return "Invalid settings."; }

	$clean = array();
	$paths = array(
		'helperPath'   => 'Helper path',
		'dropInDir'    => 'Drop-in directory',
		'readOnlyConf' => 'Existing vhosts file',
		'docRootBase'  => 'Document root base',
		'logDir'       => 'Log directory',
	);
	foreach($paths as $key => $label){
		$value = vhostsCleanPath($settings[$key] ?? '');
		if($value === ''){ return $label." must be an absolute path (letters, digits, . _ - / only)."; }
		$clean[$key] = $value;
	}
	if(trim((string)($settings['certbotEmail'] ?? '')) !== ''){
		try{ $clean['certbotEmail'] = ghoti_validate()->email($settings['certbotEmail']); }
		catch(Exception $e){ return "Certificate contact: ".$e->getMessage(); }
	}else{
		$clean['certbotEmail'] = '';
	}
	if(trim((string)($settings['notifyEmail'] ?? '')) !== ''){
		try{ $clean['notifyEmail'] = ghoti_validate()->email($settings['notifyEmail']); }
		catch(Exception $e){ return "Notification address: ".$e->getMessage(); }
	}else{
		$clean['notifyEmail'] = '';
	}
	$clean['notifyEnabled'] = (bool)ghoti_validate()->boolInt($settings['notifyEnabled'] ?? 0);
	$clean['enabled'] = (bool)ghoti_validate()->boolInt($settings['enabled'] ?? 0);

	//Alerts with nowhere to go are a silent no-op later; say so now instead.
	if($clean['notifyEnabled'] && $clean['notifyEmail'] === '' && $clean['certbotEmail'] === ''){
		return "Set a notification address (or a certificate contact) before turning notifications on.";
	}

	$mod = $_SESSION["vhostsObj"];
	if(!$mod->vhostsdb->saveSettings($clean)){
		return "Could not save vhost settings. Check the log.";
	}
	$mod->refresh(); //so the rest of this request uses the new paths
	vhostsAudit("saveVhostsSettings", "vhost module settings updated".($clean['enabled'] ? " (changes ENABLED)" : ""));
	return true;
}

/* ---------------------------------------------------------------- *
 *  Shared validators
 * ---------------------------------------------------------------- */

/*
 * A vhost reference as printVhostForm() accepts it: either a managed vhost's
 * file stem, or an external block's "file.conf:startLine".
 *
 * Kept separate from isSafeName() on purpose - isSafeName() guards values that
 * become FILENAMES and must stay strict, while this only guards a lookup key
 * that is compared against parsed vhosts and never touches the filesystem.
 */
function vhostsIsValidKey($key){
	if(!is_string($key) || $key === '' || strlen($key) > 320){ return false; }
	$colon = strrpos($key, ':');
	if($colon === false){
		return VhostsParser::isSafeName($key);
	}
	$file = substr($key, 0, $colon);
	$line = substr($key, $colon + 1);
	return $line !== '' && ctype_digit($line)
		&& $file !== '' && strlen($file) <= 255
		&& strpos($file, '/') === false && strpos($file, '..') === false;
}

//A hostname for ServerName/ServerAlias. Returns '' if it isn't one. A leading
//"*." is allowed because Apache accepts wildcard ServerAlias.
function vhostsCleanHostname($value){
	$host = strtolower(trim((string)$value));
	if($host === '' || strlen($host) > 253){ return ''; }
	$probe = strpos($host, '*.') === 0 ? substr($host, 2) : $host;
	if(!preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $probe)){ return ''; }
	if(strpos($probe, '..') !== false){ return ''; }
	return $host;
}

//An absolute filesystem path. Returns '' if it isn't one. Rejects "..' segments
//outright rather than trying to normalise them.
function vhostsCleanPath($value){
	$path = rtrim(trim((string)$value), '/');
	if($path === '' || strlen($path) > 255){ return ''; }
	if(!preg_match('#^/[A-Za-z0-9._/-]+$#', $path)){ return ''; }
	if(strpos($path, '..') !== false){ return ''; }
	return $path;
}

ghoti_async_register(
	"printVhostsPanel",
	"printVhostForm",
	"saveVhost",
	"deleteVhost",
	"vhostsConfigTest",
	"vhostsVhostMap",
	"reloadApache",
	"printCertificates",
	"issueCertificate",
	"renewCertificate",
	"printVhostImport",
	"importVhosts",
	"printVhostsSettingsForm",
	"saveVhostsSettings",
	"sendVhostsTestAlert"
);

/* ---------------------------------------------------------------- *
 *  UI renderer (class vhostsui)
 * ---------------------------------------------------------------- */

class vhostsui{
	public $output;

	private static function esc($value){
		return htmlspecialchars((string)$value, ENT_QUOTES);
	}

	private static function checked($flag){
		return $flag ? " checked=\"checked\"" : "";
	}

	/*
	 * How a vhost is addressed in a client callback. A managed vhost is its own
	 * file stem; an external one is "file.conf:startLine", since one file holds
	 * many blocks and two of them can share a ServerName (a :80 and a :443 pair
	 * always do). Mirrored by vhostsIsValidKey() on the way back in.
	 */
	public static function vhostKey($vhost){
		return $vhost['managed'] ? $vhost['name'] : $vhost['fileName'].':'.$vhost['startLine'];
	}

	//The tab strip shared by all three panes. $active is the pane showing now.
	private static function tabs($active){
		$tabs = array(
			'vhosts' => array('Virtual Hosts', 'showVhosts()'),
			'certs'  => array('Certificates', 'showVhostCertificates()'),
			'import' => array('Import', 'showVhostImport()'),
			'config' => array('Settings', 'showVhostsSettings()'),
		);
		$o = "<div class=\"vhTabs\" role=\"tablist\">\n";
		foreach($tabs as $key => $tab){
			$class = "vhTab".($key === $active ? " vhTabActive" : "");
			$o .= "<button type=\"button\" class=\"".$class."\" role=\"tab\" aria-selected=\"".($key === $active ? "true" : "false")."\" onclick=\"".$tab[1].";\">".self::esc($tab[0])."</button>\n";
		}
		$o .= "</div>\n";
		return $o;
	}

	//Banner shown across every pane when the privileged helper is missing:
	//the module is fully usable read-only, and this is the one thing standing
	//between the admin and the rest of it, so it gets the install commands.
	private static function helperBanner($settings){
		$path = self::esc($settings['helperPath']);
		$o  = "<div class=\"vhNotice vhNoticeWarn\">\n";
		$o .= "<b>Read-only.</b> The privileged helper is not installed (or sudo is refusing it), so vhosts can be inspected but not changed. ";
		$o .= "PHP runs as the unprivileged <code>http</code> user and cannot write Apache's config, reload the server, or read <code>/etc/letsencrypt/live</code> on its own.\n";
		$o .= "<p>Install it on this server as root, from the module directory:</p>\n";
		$o .= "<pre class=\"vhCode\">install -o root -g root -m 0755 mod/vhosts/ghoti-vhosts-helper ".$path."\n";
		$o .= "install -o root -g root -m 0440 mod/vhosts/sudoers.ghoti-vhosts /etc/sudoers.d/ghoti-vhosts\nvisudo -c</pre>\n";
		$o .= "<p>Then turn on <b>Allow changes</b> under <b>Settings</b>.</p>\n";
		$o .= "</div>\n";
		return $o;
	}

	/* ---------------- Virtual hosts pane ---------------- */

	public function printVhostsPanel($vhosts, $settings, $helperAvailable){
		$canWrite = $helperAvailable && !empty($settings['enabled']);

		$o  = "<section id=\"ghotiVhosts\" class=\"ghotiAdminPanel\">\n";
		$o .= "<div class=\"ghotiCrudHeader\"><h1>Apache Vhosts</h1>\n";
		$o .= "<div class=\"vhHeaderActions\">\n";
		if($canWrite){
			$o .= "<button type=\"button\" class=\"ghotiButton\" onclick=\"newVhost();\">New vhost</button>\n";
			$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"reloadApache();\">Reload Apache</button>\n";
		}
		$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"vhostsConfigTest();\">Test config</button>\n";
		$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"vhostsVhostMap();\">Live map</button>\n";
		$o .= "</div></div>\n";
		$o .= self::tabs('vhosts');
		if(!$helperAvailable){
			$o .= self::helperBanner($settings);
		}elseif(empty($settings['enabled'])){
			$o .= "<div class=\"vhNotice\">Changes are switched off. Turn on <b>Allow changes</b> under <b>Settings</b> to edit vhosts from here.</div>\n";
		}

		$managed = array();
		$external = array();
		foreach($vhosts as $vhost){
			if($vhost['managed']){ $managed[] = $vhost; }else{ $external[] = $vhost; }
		}

		$o .= "<h2 class=\"vhSectionTitle\">Managed by ghoti <span class=\"vhCount\">".count($managed)."</span></h2>\n";
		$o .= "<p class=\"ghotiHelpText\">One file per vhost in <code>".self::esc($settings['dropInDir'])."</code>. Apache includes this directory last, so nothing here can take over the default vhost for a port.</p>\n";
		if(!$managed){
			$o .= "<div class=\"vhEmpty\">No ghoti-managed vhosts yet.".($canWrite ? " Use <b>New vhost</b> to add one." : "")."</div>\n";
		}else{
			$o .= $this->vhostCards($managed, $canWrite);
		}

		$o .= "<h2 class=\"vhSectionTitle\">Defined elsewhere <span class=\"vhCount\">".count($external)."</span></h2>\n";
		$o .= "<p class=\"ghotiHelpText\">From <code>".self::esc($settings['readOnlyConf'])."</code>. Shown for context and never written by this panel &mdash; that file owns the vhost ordering, and the first vhost on each port is the fallback for unmatched requests.</p>\n";
		if(!$external){
			$o .= "<div class=\"vhEmpty\">Nothing found. Check the path under <b>Settings</b> if you expected vhosts here.</div>\n";
		}else{
			$o .= $this->vhostCards($external, false);
		}

		$o .= $this->vhostsDocs();
		$o .= "<pre id=\"vhostsOutput\" class=\"vhOutput\" hidden=\"hidden\"></pre>\n";
		$o .= "<span id=\"vhostsFeedback\"></span>\n";
		$o .= "</section>\n";
		return $o;
	}

	//The card grid. Each card is a vhost: identity, where it serves from, and
	//the certificate it presents (if any).
	private function vhostCards($vhosts, $canWrite){
		$o = "<div class=\"vhGrid\">\n";
		foreach($vhosts as $vhost){
			$key = self::vhostKey($vhost);
			$o .= "<div class=\"vhCard".($vhost['sslEngine'] ? " vhCardSsl" : "")."\">\n";
			$o .= "<div class=\"vhCardHead\">\n";
			$o .= "<span class=\"vhCardName\">".self::esc($vhost['serverName'])."</span>\n";
			$o .= "<span class=\"vhBadges\">";
			foreach($vhost['ports'] as $port){
				$o .= "<span class=\"vhBadge vhBadgePort\">:".self::esc($port)."</span>";
			}
			$o .= $vhost['sslEngine'] ? "<span class=\"vhBadge vhBadgeSsl\">TLS</span>" : "";
			if($vhost['origin'] === VhostsParser::ORIGIN_GENERATED){
				$o .= "<span class=\"vhBadge vhBadgeManaged\">managed</span>";
			}elseif($vhost['origin'] === VhostsParser::ORIGIN_ADOPTED){
				$o .= "<span class=\"vhBadge vhBadgeAdopted\" title=\"Imported verbatim - edited on the server, not in this form\">adopted</span>";
			}else{
				$o .= "<span class=\"vhBadge vhBadgeExternal\">external</span>";
			}
			$o .= "</span>\n</div>\n";

			if($vhost['aliases']){
				$o .= "<div class=\"vhAliases\">also ".self::esc(implode(', ', $vhost['aliases']))."</div>\n";
			}
			$o .= "<dl class=\"vhFacts\">\n";
			$o .= "<dt>Root</dt><dd><code>".self::esc($vhost['documentRoot'] !== '' ? $vhost['documentRoot'] : '-')."</code></dd>\n";
			$o .= "<dt>File</dt><dd><code>".self::esc($vhost['fileName'])."</code> line ".self::esc($vhost['startLine'])."</dd>\n";
			if($vhost['certFile'] !== ''){
				$o .= "<dt>Cert</dt><dd><code>".self::esc($vhost['certFile'])."</code></dd>\n";
			}
			$o .= "</dl>\n";

			$o .= "<div class=\"vhCardActions\">\n";
			$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"editVhost('".self::esc($key)."');\">".($vhost['editable'] && $canWrite ? "Edit" : "Inspect")."</button>\n";
			if($vhost['managed'] && $canWrite){
				$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"issueCertificate('".self::esc($vhost['name'])."');\">Issue cert</button>\n";
				$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonDanger\" onclick=\"deleteVhost('".self::esc($vhost['name'])."');\">Delete</button>\n";
			}
			$o .= "</div>\n</div>\n";
		}
		$o .= "</div>\n";
		return $o;
	}

	/* ---------------- One vhost: create / edit / inspect ---------------- */

	public function printVhostForm($vhost, $settings, $canWrite){
		$isNew = ($vhost === null);
		$readOnly = !$canWrite;
		$disabled = $readOnly ? " disabled=\"disabled\"" : "";

		$name = $isNew ? '' : (string)$vhost['name'];
		$serverName = $isNew ? '' : ($vhost['serverName'] === '(no ServerName)' ? '' : $vhost['serverName']);
		$aliases = $isNew ? '' : implode(' ', $vhost['aliases']);
		$docRoot = $isNew ? rtrim((string)$settings['docRootBase'], '/').'/' : $vhost['documentRoot'];
		$admin = $isNew ? '' : $vhost['serverAdmin'];
		$useSsl = $isNew ? false : (bool)$vhost['sslEngine'];
		$certFile = $isNew ? '' : $vhost['certFile'];
		$certKey = $isNew ? '' : $vhost['certKeyFile'];
		//Read from the file, not inferred from SSL being on: a managed vhost
		//that was hand-edited to drop its redirect should show an unchecked box
		//rather than silently re-adding the rule on the next save.
		$redirect = $isNew ? false : (bool)$vhost['redirectsToSsl'];

		$o  = "<section id=\"ghotiVhostForm\" class=\"ghotiAdminPanel\">\n";
		$o .= "<h1>".($isNew ? "New vhost" : self::esc($serverName !== '' ? $serverName : $vhost['fileName']))."</h1>\n";
		if($readOnly && !$isNew){
			if($vhost['origin'] === VhostsParser::ORIGIN_ADOPTED){
				$reason = "This vhost was <b>imported verbatim</b> and is shown read-only on purpose. It may use directives this form has no fields for &mdash; <code>&lt;Directory&gt;</code>, <code>&lt;FilesMatch&gt;</code>, <code>Alias</code>, <code>SSLOptions</code>, <code>Include</code> &mdash; and rebuilding it from the fields below would delete them. Edit <code>".self::esc($vhost['fileName'])."</code> on the server. Certificates and deletion still work from here.";
			}elseif($vhost['managed']){
				$reason = "Read-only: changes are disabled or the helper is not installed.";
			}else{
				$reason = "This vhost lives in <code>".self::esc($vhost['fileName'])."</code>, which this panel never writes. Edit it on the server, or import it to bring it under ghoti management.";
			}
			$o .= "<div class=\"vhNotice\">".$reason."</div>\n";
		}

		$o .= "<form id=\"vhostForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"saveVhost(); return false;\">\n";
		$o .= "<div class=\"ghotiFormGrid\">\n";
		$o .= "<label class=\"ghotiField\"><span>Name <i>(filename)</i></span><input type=\"text\" id=\"vh-name\" size=\"30\" maxlength=\"64\" placeholder=\"example.com\" value=\"".self::esc($name)."\"".($isNew ? "" : " readonly=\"readonly\"").$disabled." /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>ServerName</span><input type=\"text\" id=\"vh-serverName\" size=\"30\" maxlength=\"253\" placeholder=\"example.com\" value=\"".self::esc($serverName)."\"".$disabled." /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>ServerAlias <i>(space separated)</i></span><input type=\"text\" id=\"vh-aliases\" size=\"30\" maxlength=\"1000\" placeholder=\"www.example.com\" value=\"".self::esc($aliases)."\"".$disabled." /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>DocumentRoot</span><input type=\"text\" id=\"vh-documentRoot\" size=\"30\" maxlength=\"255\" value=\"".self::esc($docRoot)."\"".$disabled." /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>ServerAdmin <i>(optional)</i></span><input type=\"email\" id=\"vh-serverAdmin\" size=\"30\" maxlength=\"190\" placeholder=\"webmaster@example.com\" value=\"".self::esc($admin)."\"".$disabled." /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Certificate file</span><input type=\"text\" id=\"vh-certFile\" size=\"30\" maxlength=\"255\" placeholder=\"/etc/letsencrypt/live/example.com/fullchain.pem\" value=\"".self::esc($certFile)."\"".$disabled." /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Private key file</span><input type=\"text\" id=\"vh-certKeyFile\" size=\"30\" maxlength=\"255\" placeholder=\"/etc/letsencrypt/live/example.com/privkey.pem\" value=\"".self::esc($certKey)."\"".$disabled." /></label>\n";
		$o .= "</div>\n";
		$o .= "<label class=\"ghotiInlineChoice\"><input type=\"checkbox\" id=\"vh-useSsl\"".self::checked($useSsl).$disabled." /> Serve HTTPS &mdash; adds a :443 vhost using the certificate above</label>\n";
		$o .= "<label class=\"ghotiInlineChoice\"><input type=\"checkbox\" id=\"vh-redirectToSsl\"".self::checked($redirect).$disabled." /> Redirect HTTP to HTTPS &mdash; ACME challenge requests are always exempt so renewals keep working</label>\n";
		if(!$readOnly){
			$o .= "<div class=\"ghotiFormActions\"><button type=\"button\" class=\"ghotiButton\" onclick=\"saveVhost();\">Save &amp; reload Apache</button>\n";
			$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"showVhosts();\">Cancel</button></div>\n";
		}else{
			$o .= "<div class=\"ghotiFormActions\"><button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"showVhosts();\">Back</button></div>\n";
		}
		$o .= "</form>\n";

		if(!$isNew){
			//A merged managed vhost already carries its own <VirtualHost> wrappers
			//(one file, several blocks); a single external block does not.
			$raw = $vhost['addresses'] === ''
				? $vhost['body']
				: "<VirtualHost ".$vhost['addresses'].">\n".$vhost['body']."\n</VirtualHost>";
			$o .= "<details class=\"ghotiDocs\"><summary><span class=\"ghotiDocsTitle\">Raw configuration</span><span class=\"ghotiDocsHint\">".self::esc($vhost['fileName'])."</span></summary>\n";
			$o .= "<div class=\"ghotiDocsBody\"><pre class=\"vhCode\">".self::esc($raw)."</pre></div></details>\n";
		}
		$o .= "<span id=\"vhostsFeedback\"></span>\n";
		$o .= "</section>\n";
		return $o;
	}

	/*
	 * Render a managed drop-in file from validated values.
	 *
	 * Conventions deliberately match the server's existing httpd-vhosts.conf so
	 * a managed vhost and a hand-written one behave the same:
	 *   - every vhost includes conf/extra/httpd-errorpages.conf, because Alias
	 *     is NOT inherited by vhosts and /errors/*.html would otherwise 404;
	 *   - every :80 -> :443 redirect skips /.well-known/acme-challenge/, so
	 *     Let's Encrypt issuance and renewal are never bounced to https.
	 *
	 * Every value interpolated here has already been through the validators in
	 * this file (hostname / absolute-path shaped), so none of it can introduce
	 * a newline and forge an extra directive.
	 */
	public function renderVhostConfig($spec){
		$logDir = $spec['logDir'] !== '' ? $spec['logDir'] : '/var/log/httpd';
		$errorLog = $logDir.'/'.$spec['name'].'-error_log';
		$accessLog = $logDir.'/'.$spec['name'].'-access_log';
		$aliasLine = $spec['aliases'] ? "    ServerAlias  ".implode(' ', $spec['aliases'])."\n" : "";
		$adminLine = $spec['serverAdmin'] !== '' ? "    ServerAdmin  ".$spec['serverAdmin']."\n" : "";

		$o  = "#############################################################################\n";
		$o .= VhostsParser::MARKER." ".VhostsParser::ORIGIN_GENERATED."\n";
		$o .= "#  ".$spec['serverName']."\n";
		$o .= "#\n";
		$o .= "#  Generated by the ghoti vhosts module. Edits made here by hand will be\n";
		$o .= "#  overwritten the next time this vhost is saved from the admin panel.\n";
		$o .= "#  Apache includes this directory (conf.d) after the consolidated vhost\n";
		$o .= "#  file, so nothing in here can become the default vhost for a port.\n";
		$o .= "#############################################################################\n\n";

		$o .= "<VirtualHost *:80>\n";
		$o .= "    ServerName   ".$spec['serverName']."\n".$aliasLine.$adminLine;
		$o .= "    DocumentRoot \"".$spec['documentRoot']."\"\n";
		$o .= "    ErrorLog     \"".$errorLog."\"\n";
		$o .= "    CustomLog    \"".$accessLog."\" common\n";
		if($spec['redirectToSsl']){
			$o .= "\n    RewriteEngine On\n";
			$o .= "    RewriteCond %{REQUEST_URI} !^/\\.well-known/acme-challenge/\n";
			$o .= "    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]\n";
		}
		$o .= "\n    Include conf/extra/httpd-errorpages.conf\n";
		$o .= "</VirtualHost>\n";

		if($spec['useSsl']){
			$o .= "\n<VirtualHost *:443>\n";
			$o .= "    ServerName   ".$spec['serverName']."\n".$aliasLine.$adminLine;
			$o .= "    DocumentRoot \"".$spec['documentRoot']."\"\n";
			$o .= "    ErrorLog     \"".$errorLog."\"\n";
			$o .= "    CustomLog    \"".$accessLog."\" common\n\n";
			$o .= "    SSLEngine on\n";
			$o .= "    SSLCertificateFile    ".$spec['certFile']."\n";
			$o .= "    SSLCertificateKeyFile ".$spec['certKeyFile']."\n";
			$o .= "\n    Include conf/extra/httpd-errorpages.conf\n";
			$o .= "</VirtualHost>\n";
		}
		return $o;
	}

	/* ---------------- Certificates pane ---------------- */

	public function printCertificates($result, $settings, $canWrite){
		$o  = "<section id=\"ghotiVhostCerts\" class=\"ghotiAdminPanel\">\n";
		$o .= "<div class=\"ghotiCrudHeader\"><h1>Certificates</h1></div>\n";
		$o .= self::tabs('certs');

		if(!$result['ok']){
			if($result['error'] === 'helper-missing'){
				$o .= self::helperBanner($settings);
				$o .= "<p class=\"ghotiHelpText\"><code>/etc/letsencrypt/live</code> is mode 0700 and owned by root, so without the helper there is genuinely nothing here to read &mdash; not even the certificate Apache is already serving.</p>\n";
			}else{
				$o .= "<div class=\"vhNotice vhNoticeWarn\"><b>certbot could not be queried.</b><pre class=\"vhCode\">".self::esc($result['error'])."</pre></div>\n";
			}
			$o .= "<span id=\"vhostsFeedback\"></span>\n</section>\n";
			return $o;
		}

		if(!$result['certs']){
			$o .= "<div class=\"vhEmpty\">certbot is installed but manages no certificates yet. Create a vhost, then use <b>Issue cert</b> on its card.</div>\n";
		}else{
			$o .= "<div class=\"vhGrid\">\n";
			foreach($result['certs'] as $cert){
				//Let's Encrypt certificates last 90 days and renew at 30 left,
				//so the meter is scaled to 90 and the thresholds match that.
				$days = $cert['daysLeft'];
				$state = 'ok';
				if($days === null){ $state = 'unknown'; }
				elseif($days <= 0){ $state = 'expired'; }
				elseif($days <= 10){ $state = 'urgent'; }
				elseif($days <= 30){ $state = 'due'; }
				$pct = $days === null ? 0 : max(0, min(100, (int)round($days / 90 * 100)));

				$o .= "<div class=\"vhCard vhCert vhCert-".$state."\">\n";
				$o .= "<div class=\"vhCardHead\"><span class=\"vhCardName\">".self::esc($cert['name'])."</span>\n";
				$o .= "<span class=\"vhBadges\"><span class=\"vhBadge vhBadge-".$state."\">".self::esc(
					$days === null ? 'unknown' : ($days <= 0 ? 'expired' : $days.' days left')
				)."</span></span></div>\n";
				$o .= "<div class=\"vhMeter\" role=\"img\" aria-label=\"".self::esc($days === null ? 'expiry unknown' : $days.' days remaining of 90')."\"><span style=\"width:".$pct."%\"></span></div>\n";
				$o .= "<dl class=\"vhFacts\">\n";
				$o .= "<dt>Domains</dt><dd>".self::esc(implode(', ', $cert['domains']))."</dd>\n";
				$o .= "<dt>Expires</dt><dd>".self::esc($cert['expiry'])."</dd>\n";
				$o .= "<dt>Cert</dt><dd><code>".self::esc($cert['certPath'])."</code></dd>\n";
				$o .= "</dl>\n";
				if($canWrite){
					$o .= "<div class=\"vhCardActions\"><button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"renewCertificate('".self::esc($cert['name'])."');\">Renew now</button></div>\n";
				}
				$o .= "</div>\n";
			}
			$o .= "</div>\n";
		}

		$o .= ghoti_docs_panel("About these certificates", "issuance, renewal, and what this panel does not do", array(
			array('heading' => 'Issuance',
				'list' => array('<b>Issue cert</b> on a managed vhost runs <code>certbot certonly --webroot</code> against that vhost\'s own DocumentRoot, for its ServerName and every ServerAlias.',
					'The domain must already resolve to this server and be reachable on port 80 &mdash; Let\'s Encrypt validates by fetching a file under <code>/.well-known/acme-challenge/</code>.',
					'Set a contact e-mail under <b>Settings</b> first; Let\'s Encrypt requires one and uses it for expiry warnings.')),
			array('heading' => 'Renewal',
				'list' => array('certbot renews automatically via its own systemd timer &mdash; check it with <code>systemctl status certbot-renew.timer</code>. <b>Renew now</b> here is for forcing one early.',
					'A certificate with 30 days or fewer left is flagged; that is also when certbot would normally renew it on its own.')),
			array('heading' => 'What this panel will not do',
				'list' => array('It never reads or displays private keys.',
					'It cannot manage certificates that were not issued by certbot &mdash; those show up on the vhost card by path only.'))
		));
		$o .= "<pre id=\"vhostsOutput\" class=\"vhOutput\" hidden=\"hidden\"></pre>\n";
		$o .= "<span id=\"vhostsFeedback\"></span>\n</section>\n";
		return $o;
	}

	/* ---------------- Import pane ---------------- */

	public function printVhostImport($plan, $settings, $canWrite){
		$o  = "<section id=\"ghotiVhostImport\" class=\"ghotiAdminPanel\">\n";
		$o .= "<div class=\"ghotiCrudHeader\"><h1>Import Vhosts</h1></div>\n";
		$o .= self::tabs('import');

		$o .= "<p class=\"ghotiHelpText\">Moves each vhost out of <code>".self::esc($settings['readOnlyConf'])."</code> into its own file in <code>".self::esc($settings['dropInDir'])."</code>, so this panel can manage its certificates and removal. Blocks are copied <b>exactly</b> as written &mdash; nothing is regenerated, so <code>&lt;Directory&gt;</code>, <code>&lt;FilesMatch&gt;</code>, <code>Alias</code>, <code>SSLOptions</code> and <code>Include</code> lines survive untouched.</p>\n";

		if(!$plan['ok']){
			$o .= "<div class=\"vhNotice\">".self::esc($plan['error'])."</div>\n";
			$o .= $this->importDocs();
			$o .= "<span id=\"vhostsFeedback\"></span>\n</section>\n";
			return $o;
		}

		$first = $plan['files'][0];
		$o .= "<div class=\"vhNotice vhNoticeWarn\"><b>Read this before importing.</b>";
		$o .= "<p>Apache serves the <i>first</i> vhost defined on a port to any request whose Host header matches nothing else. Files are numbered so the current order is preserved exactly, which keeps <b>".self::esc($first['serverName'])."</b> the default. Renaming these files afterwards can silently change which site answers unmatched requests.</p>";
		$o .= "<p>The original file is backed up and replaced with a note pointing at the new location. If Apache rejects the result, every file is put back and nothing is reloaded.</p></div>\n";

		$o .= "<h2 class=\"vhSectionTitle\">Planned files <span class=\"vhCount\">".count($plan['files'])."</span></h2>\n";
		$o .= "<div class=\"vhTableWrap\"><table class=\"ghotiManageTable vhImportTable\">\n";
		$o .= "<thead><tr><th>Order</th><th>New file</th><th>ServerName</th><th>Ports</th><th>Blocks</th></tr></thead>\n<tbody>\n";
		$position = 0;
		foreach($plan['files'] as $file){
			$position++;
			$o .= "<tr><td>".$position."</td>";
			$o .= "<td><code>".self::esc($file['fileName'])."</code></td>";
			$o .= "<td>".self::esc($file['serverName'])."</td>";
			$o .= "<td>".self::esc(implode(', ', $file['ports']))."</td>";
			$o .= "<td>".self::esc($file['blocks'])."</td></tr>\n";
		}
		$o .= "</tbody></table></div>\n";

		$o .= "<div class=\"ghotiFormActions\">";
		if($canWrite){
			$o .= "<button type=\"button\" class=\"ghotiButton\" onclick=\"importVhosts();\">Import ".count($plan['files'])." vhost(s)</button>";
		}else{
			$o .= "<button type=\"button\" class=\"ghotiButton\" disabled=\"disabled\">Import unavailable</button>";
		}
		$o .= "</div>\n";
		if(!$canWrite){
			$o .= "<div class=\"vhNotice\">The import needs the privileged helper installed and <b>Allow changes</b> switched on under <b>Settings</b>.</div>\n";
		}

		$o .= "<details class=\"ghotiDocs\"><summary><span class=\"ghotiDocsTitle\">Preview the generated files</span><span class=\"ghotiDocsHint\">exactly what would be written</span></summary>\n<div class=\"ghotiDocsBody\">\n";
		foreach($plan['files'] as $file){
			$o .= "<h3><code>".self::esc($file['fileName'])."</code></h3>\n";
			$o .= "<pre class=\"vhCode\">".self::esc($file['content'])."</pre>\n";
		}
		$o .= "</div></details>\n";

		$o .= $this->importDocs();
		$o .= "<pre id=\"vhostsOutput\" class=\"vhOutput\" hidden=\"hidden\"></pre>\n";
		$o .= "<span id=\"vhostsFeedback\"></span>\n</section>\n";
		return $o;
	}

	private function importDocs(){
		return ghoti_docs_panel("About importing", "what changes, what does not, and how to undo it", array(
			array('heading' => 'What the import does',
				'list' => array('Copies each <code>&lt;VirtualHost&gt;</code> block verbatim into its own file in the drop-in directory.',
					'Numbers the files to preserve the original order, because the first vhost on a port is the fallback for unmatched requests.',
					'Backs up the original file, then replaces it with a note saying where its vhosts went.',
					'Runs <code>apachectl configtest</code> once over the result and reloads only if it passes.')),
			array('heading' => 'What it deliberately does not do',
				'list' => array('It does not rewrite or normalise your configuration. Imported vhosts are marked <b>adopted</b> and stay read-only in the edit form, because that form only knows about the fields it shows &mdash; regenerating an adopted vhost from them would delete anything else the block contains.',
					'Certificates, deletion and reloads still work normally for adopted vhosts.',
					'It will not overwrite a drop-in file that already exists.')),
			array('heading' => 'Undoing it',
				'list' => array('Restore the backup over the original file and delete the numbered files from the drop-in directory, then reload Apache.',
					'The backup path is printed when the import finishes, and the note left in the original file repeats it.'))
		));
	}

	/* ---------------- Settings pane ---------------- */

	public function printVhostsSettingsForm($settings, $helperAvailable){
		$o  = "<section id=\"ghotiVhostsSettings\" class=\"ghotiAdminPanel\">\n";
		$o .= "<div class=\"ghotiCrudHeader\"><h1>Vhost Settings</h1></div>\n";
		$o .= self::tabs('config');
		$o .= $helperAvailable
			? "<div class=\"vhNotice vhNoticeOk\">The privileged helper is installed and responding.</div>\n"
			: self::helperBanner($settings);

		$o .= "<form id=\"vhostsSettingsForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"saveVhostsSettings(); return false;\">\n";
		$o .= "<div class=\"ghotiFormGrid\">\n";
		$fields = array(
			'helperPath'   => array('Helper path', 'Where ghoti-vhosts-helper is installed'),
			'dropInDir'    => array('Drop-in directory', 'Included by httpd.conf as conf.d/*.conf'),
			'readOnlyConf' => array('Existing vhosts file', 'Displayed but never written'),
			'docRootBase'  => array('Document root base', 'A DocumentRoot must live under this'),
			'logDir'       => array('Log directory', 'Where generated vhosts write their logs'),
		);
		foreach($fields as $key => $field){
			$o .= "<label class=\"ghotiField\"><span>".self::esc($field[0])." <i>(".self::esc($field[1]).")</i></span>";
			$o .= "<input type=\"text\" id=\"vh-".self::esc($key)."\" size=\"30\" maxlength=\"255\" value=\"".self::esc($settings[$key])."\" /></label>\n";
		}
		$o .= "<label class=\"ghotiField\"><span>Certificate contact e-mail <i>(Let's Encrypt account)</i></span><input type=\"email\" id=\"vh-certbotEmail\" size=\"30\" maxlength=\"190\" placeholder=\"webmaster@example.com\" value=\"".self::esc($settings['certbotEmail'])."\" /></label>\n";
		$o .= "<label class=\"ghotiField\"><span>Notification address <i>(blank = use the contact above)</i></span><input type=\"email\" id=\"vh-notifyEmail\" size=\"30\" maxlength=\"190\" placeholder=\"admin@example.com\" value=\"".self::esc($settings['notifyEmail'])."\" /></label>\n";
		$o .= "</div>\n";
		$o .= "<label class=\"ghotiInlineChoice\"><input type=\"checkbox\" id=\"vh-notifyEnabled\"".self::checked($settings['notifyEnabled'])." /> E-mail alerts &mdash; on certificate issue/renewal, failures, and anything needing manual intervention</label>\n";
		$o .= "<label class=\"ghotiInlineChoice\"><input type=\"checkbox\" id=\"vh-enabled\"".self::checked($settings['enabled'])." /> Allow changes &mdash; without this the panel can look but never write, even with the helper installed</label>\n";
		$o .= "<div class=\"ghotiFormActions\"><button type=\"button\" class=\"ghotiButton\" onclick=\"saveVhostsSettings();\">Save Settings</button>\n";
		$o .= "<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"sendVhostsTestAlert();\">Send test alert</button></div>\n";
		$o .= "</form>\n";
		$o .= $this->notifyDocs();
		$o .= "<pre id=\"vhostsOutput\" class=\"vhOutput\" hidden=\"hidden\"></pre>\n";
		$o .= "<span id=\"vhostsFeedback\"></span>\n</section>\n";
		return $o;
	}

	private function notifyDocs(){
		return ghoti_docs_panel("About e-mail alerts", "what triggers one, and what catches certbot's own renewals", array(
			array('heading' => 'Where they come from',
				'list' => array('Alerts are sent through the <b>mail module</b> (Admin Menu &rarr; Mail Settings). If mail sending is off or misconfigured, alerts are logged and dropped &mdash; a certificate renewal is never failed just because the mail server is unreachable.',
					'Use <b>Send test alert</b> to confirm the whole path end to end.')),
			array('heading' => 'What triggers one',
				'list' => array('A certificate is issued or renewed from this panel.',
					'A certificate operation fails &mdash; subject is prefixed <code>[FAILED]</code>.',
					'Apache rejects a configuration and the change is rolled back.',
					'Configuration is saved but Apache will not reload &mdash; prefixed <code>[ACTION NEEDED]</code>, because the file on disk and the running server now disagree.',
					'A certificate is close to expiry and has not renewed itself.')),
			array('heading' => 'Renewals certbot does on its own',
				'list' => array('certbot renews from its own systemd timer, without going through this panel, so nothing here would notice.',
					'<code>mod/vhosts/vhosts.certwatch.php</code> is a small CLI script that compares certbot\'s current state against the last state it saw, and mails when a serial number changes or a certificate is running out.',
					'Run it as the web server user, once a day is plenty. From root\'s crontab: <code>0 7 * * * sudo -u http /usr/bin/php '.self::esc(__DIR__).'/vhosts.certwatch.php --quiet</code>',
					'Running it as <i>root</i> works but risks leaving a root-owned <code>ghoti.log</code> after a rotation, which the web server could then no longer write.',
					'It writes nothing to Apache and needs no arguments; with <code>--dry-run</code> it prints what it would send and sends nothing.'))
		));
	}

	private function vhostsDocs(){
		return ghoti_docs_panel("How vhost management works", "drop-in files, safety rails, the privileged helper", array(
			array('heading' => 'Where new vhosts go',
				'list' => array('Each vhost this panel creates is its own file in the drop-in directory, named after the vhost.',
					'<code>httpd.conf</code> includes that directory <i>after</i> the consolidated vhost file. Since the first <code>&lt;VirtualHost&gt;</code> for a port is the fallback for unmatched requests, a vhost added here can never silently steal that role.',
					'Vhosts defined in the consolidated file are listed as <b>external</b> and are never rewritten from here.')),
			array('heading' => 'Safety rails on every change',
				'list' => array('The new file is written, then <code>apachectl configtest</code> runs. If Apache rejects it, the previous version is restored automatically and nothing is reloaded.',
					'The replaced version is kept under <code>/var/lib/ghoti-vhosts/backups</code>.',
					'Apache is reloaded, never restarted, so in-flight requests are not dropped.')),
			array('heading' => 'Why a helper script',
				'list' => array('PHP runs as <code>http</code>, which cannot write <code>/etc/httpd/conf</code>, reload the server, or read <code>/etc/letsencrypt/live</code>.',
					'Rather than loosening those permissions, one root script exposes a fixed set of verbs through a single sudoers rule. It accepts a vhost name, never a path or a command, and new config reaches it on stdin.',
					'Keep the helper root-owned and not group-writable, or the sudoers rule becomes a root shell for the web server user.')),
			array('heading' => 'If a change goes wrong',
				'list' => array('<b>Test config</b> runs configtest without changing anything.',
					'<b>Live map</b> runs <code>apachectl -S</code> to show which vhost Apache actually resolves for each name and port &mdash; the first thing to check when a vhost "is not taking effect".',
					'Deleting a vhost also backs up its file first, so it can be restored by hand.'))
		));
	}
}
?>
