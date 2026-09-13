<?php
/*
 * vhosts.php - Apache virtual host + certificate management module.
 *
 * Runs ON the Apache server it manages (this CMS is served out of
 * /etc/httpd/docs on that host), so everything here is local filesystem and
 * local process work - there is no SSH client and no stored server password.
 *
 * Privilege model
 * ---------------
 * PHP runs as the unprivileged `http` user. That user can READ Apache's
 * world-readable config, but it cannot write /etc/httpd/conf/**, reload httpd,
 * or read /etc/letsencrypt/live (mode 0700, root-only). Rather than loosen any
 * of that, every privileged action goes through one root helper script with a
 * fixed set of verbs, invoked via a narrow sudoers rule:
 *
 *   ghoti-vhosts-helper   - the root helper (repo copy: mod/vhosts/)
 *   sudoers.ghoti-vhosts  - the matching sudoers snippet
 *
 * The module is opt-in via Site Settings (enableVhosts defaults to false).
 * Once enabled, when the helper is not installed it works read-only: it lists
 * and inspects vhosts parsed from the config files, and tells the admin exactly
 * how to install the helper to unlock writes. See vhosts.helper.php.
 *
 *   vhosts.db.php     - `vhosts` settings table (single row, admin-edited)
 *   vhosts.helper.php - class VhostsHelper: the sudo bridge to the root helper
 *   vhosts.notify.php - class VhostsNotifier: admin e-mail alerts via mod/mail
 *   vhosts.import.php - class VhostsImporter: adopting hand-written vhosts
 *   vhosts.parser.php - class VhostsParser: reads <VirtualHost> blocks from disk
 *   vhosts.async.php  - admin endpoints/UI + class vhostsui
 */
include_once('vhosts.db.php');
include_once('vhosts.parser.php');
include_once('vhosts.helper.php');
include_once('vhosts.notify.php');
include_once('vhosts.import.php');
include_once('vhosts.async.php'); //endpoints + class vhostsui

class vhosts{
	public $vhostsdb,$vhostsui,$helper,$parser;

	public function __construct(){
		$this->vhostsdb = new vhostsdb();
		$this->vhostsui = new vhostsui();
		$settings = $this->vhostsdb->getSettings();
		$this->helper = new VhostsHelper($settings);
		$this->parser = new VhostsParser($settings);
	}

	//Re-reads settings and rebuilds the helper/parser with them. Called after a
	//settings save so the rest of the request sees the new paths immediately.
	public function refresh(){
		$settings = $this->vhostsdb->getSettings();
		$this->helper = new VhostsHelper($settings);
		$this->parser = new VhostsParser($settings);
		return $settings;
	}
}
?>
