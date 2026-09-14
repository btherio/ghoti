<?php
/*
 * Administrator documentation. Kept separate from ghoti.async.php so the
 * complete guide can grow without burying the request and page-management
 * code. The guide is static trusted markup and is only returned to admins.
 */

function printDocumentation(){
	if(!ghoti_require_admin()){ return "<p>Admin access required.</p>"; }
	return ghoti_documentation_html();
}

function ghoti_documentation_html(){
	$version = htmlspecialchars(trim((string)@file_get_contents(__DIR__.'/VERSION')), ENT_QUOTES, 'UTF-8');
	if($version === ''){ $version = 'current'; }
	$vhostsState = ghoti::$enableVhosts ? 'Enabled on this site' : 'Optional module, currently disabled';
	$storeState = ghoti::$enableStore ? 'Enabled on this site' : 'Optional module, currently disabled';
	$bpongState = ghoti::$enableBpong ? 'Enabled on this site' : 'Optional module, currently disabled';

	return <<<HTML
<section id="ghotiDocumentation" class="ghotiAdminPanel">
  <header class="ghotiDocsHero">
    <div>
      <span class="ghotiDocsKicker">GhotiCMS {$version}</span>
      <h1>Documentation</h1>
      <p>Install the CMS securely, publish your first page, and run every built-in tool with confidence.</p>
    </div>
    <div class="ghotiDocsHeroMark" aria-hidden="true">G</div>
  </header>

  <div class="ghotiDocsLayout">
    <nav class="ghotiDocsNav" aria-label="Documentation sections">
      <strong>On this page</strong>
      <a href="#docs-quick-start">Quick start</a>
      <a href="#docs-install">Installation</a>
      <a href="#docs-first-run">First-run setup</a>
      <a href="#docs-pages">Pages &amp; content</a>
      <a href="#docs-features">Site tools</a>
      <a href="#docs-accounts">Users &amp; access</a>
      <a href="#docs-operations">Operations &amp; security</a>
      <a href="#docs-troubleshooting">Troubleshooting</a>
    </nav>

    <div class="ghotiDocsGuide">
      <section id="docs-quick-start" class="ghotiGuideSection ghotiGuideLead">
        <span class="ghotiGuideNumber">01</span>
        <div>
          <h2>Quick start</h2>
          <p>For an existing installation, the normal publishing loop is simple:</p>
          <ol class="ghotiGuideSteps">
            <li><span>1</span><div><strong>Open the workspace</strong><p>Sign in as an administrator and open the Workspace menu.</p></div></li>
            <li><span>2</span><div><strong>Set the site identity</strong><p>Choose the title, theme, header image, and visitor options in <b>Site Settings</b>. The published privacy contact is the administrator account&rsquo;s own e-mail address.</p></div></li>
            <li><span>3</span><div><strong>Create and arrange pages</strong><p>Use <b>Pages</b> to add pages, choose the home page, set menu order, and control the audience.</p></div></li>
            <li><span>4</span><div><strong>Publish content</strong><p>Open a page, select Edit, compose in Visual mode, check Preview, then select <b>Save &amp; publish</b>.</p></div></li>
          </ol>
          <div class="ghotiGuideCallout"><strong>Contextual tips are optional.</strong> Turn the expandable “How to” panels on or off under Site Settings → Admin experience. This full guide is always available.</div>
        </div>
      </section>

      <section id="docs-install" class="ghotiGuideSection">
        <span class="ghotiGuideNumber">02</span>
        <div>
          <h2>Installation</h2>
          <h3>Requirements</h3>
          <ul class="ghotiGuideChecklist">
            <li>Apache or another PHP-capable web server, with HTTPS for production</li>
            <li>PHP 8 or newer with PDO MySQL, DOM, fileinfo, mbstring, and XML extensions</li>
            <li>MySQL or MariaDB and a dedicated database account</li>
            <li>Write access for the PHP service account to Ghoti’s runtime files and upload directory</li>
            <li>Optional: ImageMagick for HEIC, TIFF, and BMP gallery conversion; a local or remote SMTP relay for mail</li>
          </ul>

          <h3>1. Place the application</h3>
          <p>Copy or clone the repository into the intended web directory. Point the virtual host at this directory, allow the bundled <code>.htaccess</code> rules when using Apache, and deny direct access to hidden files and local configuration at the server or proxy layer. Keep the Git checkout, local database credentials, and logs out of public directory listings.</p>

          <h3>2. Create the database</h3>
          <p>Create an empty UTF-8 database and a dedicated account. Ghoti provisions its module tables on first use and may add columns during upgrades, so the account needs normal data permissions plus <code>CREATE</code> and <code>ALTER</code> on this database. Do not grant global or administrative database privileges.</p>
          <pre><code>CREATE DATABASE ghoti CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ghoti'@'localhost' IDENTIFIED BY 'use-a-unique-secret';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX
  ON ghoti.* TO 'ghoti'@'localhost';</code></pre>

          <h3>3. Configure the connection</h3>
          <p>Use environment variables for managed deployments: <code>GHOTI_DB_HOST</code>, <code>GHOTI_DB_PORT</code>, <code>GHOTI_DB_NAME</code>, <code>GHOTI_DB_USER</code>, <code>GHOTI_DB_PASSWORD</code>, and optionally <code>GHOTI_DB_CHARSET</code>. Alternatively, create the ignored <code>db.config.local.php</code> file beside <code>db.config.php</code>. Never commit a database password.</p>
          <p>If you prefer the web setup screen, set a strong temporary <code>GHOTI_SETUP_KEY</code>, open <code>/?k=YOUR_SETUP_KEY</code>, test and save the connection, then remove the setup key from the web-server environment. Web setup is unavailable without that key.</p>

          <h3>4. Prepare writable paths</h3>
          <p>The PHP service account must be able to maintain <code>ghoti.settings.json</code>, <code>ghoti.log</code>, <code>db.provisioned.json</code>, <code>critical-alerts.json</code>, <code>login.throttle.json</code>, and <code>files/gallery/</code>. If web setup is used, it must also be able to create <code>db.config.local.php</code>. Grant access only to these paths; avoid making the entire application tree world-writable.</p>

          <h3>5. Serve securely</h3>
          <p>Enable HTTPS, keep PHP error display off in production, set a unique session name if multiple Ghoti sites share one host, and ensure the web server honors the root access-control rules. For password-reset links, set <code>GHOTI_PUBLIC_URL</code> to the public HTTPS base URL, such as <code>https://example.com/ghoti</code>.</p>
        </div>
      </section>

      <section id="docs-first-run" class="ghotiGuideSection">
        <span class="ghotiGuideNumber">03</span>
        <div>
          <h2>First-run setup</h2>
          <p>After the database connects, provision the first administrator from the server. Public registration cannot create the initial administrator.</p>
          <pre><code>read -r -s -p 'Initial admin password: ' ghoti_admin_password
printf '%s\\n' "\$ghoti_admin_password" | php bin/create-admin.php admin admin@example.com
unset ghoti_admin_password</code></pre>
          <p>The command only succeeds while the users table is empty. Sign in, open Site Settings, and review these items before publishing:</p>
          <div class="ghotiGuideCards">
            <article><strong>Identity</strong><p>Site title, theme, header image, and home page.</p></article>
            <article><strong>Access</strong><p>Registration, login visibility, page audiences, and admin roles.</p></article>
            <article><strong>Privacy</strong><p>Operator name, public contact, region, and analytics consent behavior.</p></article>
            <article><strong>Recovery</strong><p>SMTP settings and the public URL used in password-reset links.</p></article>
          </div>
          <div class="ghotiGuideCallout ghotiGuideCalloutWarn"><strong>Keep registration off until you need it.</strong> New accounts are ordinary users. Promote trusted administrators from Users and keep at least one working admin account.</div>
        </div>
      </section>

      <section id="docs-pages" class="ghotiGuideSection">
        <span class="ghotiGuideNumber">04</span>
        <div>
          <h2>Pages &amp; content</h2>
          <h3>Manage the site structure</h3>
          <p>Open <b>Pages</b> to create or remove pages, drag them into navigation order, choose one public page as Home, and set each audience to Everyone or Signed-in users. The home page must remain public. Private pages appear only after login.</p>
          <h3>Use the page studio</h3>
          <p>Select a page title, then use Visual mode for everyday writing, HTML mode for precise markup, and Preview to inspect the result. The toolbar supports headings, paragraphs, emphasis, lists, quotes, code blocks, and links. <kbd>Ctrl</kbd>/<kbd>Cmd</kbd> + <kbd>S</kbd> saves. Ghoti warns before discarding unsaved work.</p>
          <p>Page HTML is filtered during preview, save, and public rendering. Safe structural and text elements, links, images, tables, classes, and accessibility labels are preserved. Scripts, event handlers, forms, embedded frames, executable URLs, and inline styles are removed.</p>
          <h3>Embed galleries</h3>
          <p>Place <code>[gallery:NAME]</code> in a page body, using the gallery’s exact slug. The gallery renders in that position and updates everywhere when its photos change.</p>
          <h3>Comments</h3>
          <p>Signed-in users can comment on the current page. Authors can remove their own comments; administrators can moderate all comments. Comments are stored as plain text.</p>
        </div>
      </section>

      <section id="docs-features" class="ghotiGuideSection">
        <span class="ghotiGuideNumber">05</span>
        <div>
          <h2>Site tools</h2>
          <div class="ghotiGuideFeatureList">
            <article><h3>Banners</h3><p>Add an image URL, destination URL, accessible description, and size. Banners are selected randomly by themes that display them. Use HTTPS image sources you control.</p></article>
            <article><h3>Links</h3><p>Create sidebar links and organize them by group. Web and email links accept safe HTTP, HTTPS, mailto, or site-relative addresses.</p></article>
            <article><h3>Galleries</h3><p>Create a gallery, upload or link images, edit captions, reorder photos, and use View for its standalone page. Browser-safe images are stored directly; supported camera formats require ImageMagick conversion.</p></article>
            <article><h3>Files</h3><p>Browse and manage files available to the site. File management is a powerful administrator tool: limit admin access and keep secrets, backups, hidden files, and runtime state outside its editable scope.</p></article>
            <article><h3>Mail Settings</h3><p>Configure a local relay or authenticated SMTP. Use STARTTLS on port 587 or implicit TLS on 465 where required, keep certificate verification enabled, save, then send a test message.</p></article>
            <article><h3>Analytics</h3><p>Review pageviews, sessions, pages, browsers, devices, referrers, errors, and logs. Visitors are tracked only after consent. Use Exclude admin views for visitor-focused reporting and CSV export for offline analysis. The <b>Apache logs</b> card at the bottom analyses the web server’s own access and error logs — see the <a href="docs/apache-log-analyzer.md" target="_blank" rel="noopener noreferrer">Apache log analyzer guide</a>.</p></article>
            <article><h3>Apache Vhosts</h3><p>{$vhostsState}. Enable it in Site Settings for read-only inspection. Writes require the separately installed root-owned helper, reviewed paths, a passing config test, and an explicit Allow changes setting. See the <a href="docs/vhosts-enablement.md" target="_blank" rel="noopener noreferrer">vhost enablement guide</a>.</p></article>
            <article><h3>Store</h3><p>{$storeState}. Sells physical and digital goods, taking payment through PayPal. Enable it in Site Settings, add the PayPal REST credentials under <b>Store</b>, and put a shop on any page with <code>[store:all]</code>. Prices are always computed on the server and an order is only marked paid once PayPal confirms the captured amount matches. See the <a href="docs/store.md" target="_blank" rel="noopener noreferrer">store guide</a>.</p></article>
            <article><h3>Pong</h3><p>{$bpongState}. Puts a playable Bitcoin Pong board on any page with <code>[bpong:game]</code>, ported from the terminal game in <code>lib/bitcoin-pong</code>. The match runs in the visitor&rsquo;s browser: nothing is submitted, no score is kept, and no sign-in is required. See the <a href="docs/bpong.md" target="_blank" rel="noopener noreferrer">pong guide</a>.</p></article>
            <article><h3>Site Settings</h3><p>Controls site identity, visitor access, privacy details, operational alerts, contextual tips, themes, and optional modules. Most presentation changes appear after reload.</p></article>
          </div>
        </div>
      </section>

      <section id="docs-accounts" class="ghotiGuideSection">
        <span class="ghotiGuideNumber">06</span>
        <div>
          <h2>Users &amp; access</h2>
          <p>Users may view private pages, comment, and change their own password. Administrators additionally receive the Workspace management tools. In <b>Users</b>, edit account details, grant or revoke admin access, and remove accounts. The application protects the last administrator from accidental demotion or deletion.</p>
          <p>Password recovery depends on enabled, working mail settings and <code>GHOTI_PUBLIC_URL</code>. Reset links are single-use and time-limited. A password change or account deletion invalidates existing sessions. Normal sessions expire after 30 minutes of inactivity and are bound to the browser and network address observed at login.</p>
          <div class="ghotiGuideCallout"><strong>Principle of least privilege:</strong> give admin access only to people who manage the site. Administrators can publish HTML, upload files, manage accounts, inspect analytics, and—when explicitly enabled—change web-server configuration.</div>
        </div>
      </section>

      <section id="docs-operations" class="ghotiGuideSection">
        <span class="ghotiGuideNumber">07</span>
        <div>
          <h2>Operations &amp; security</h2>
          <h3>Backups</h3>
          <p>Back up the database, <code>files/</code>, <code>ghoti.settings.json</code>, and the untracked database/mail configuration needed to rebuild the installation. Encrypt backups that contain credentials or user data, store a copy away from the web server, and test restoration periodically.</p>
          <h3>Updates</h3>
          <p>Take a backup, deploy reviewed Git commits, preserve untracked per-site configuration, and let Ghoti run its guarded table provisioning. Validate login, page publishing, uploads, mail, and any enabled optional module after deployment.</p>
          <h3>Monitoring</h3>
          <p>The Analytics screen includes recent application errors, the raw rotating log, and an analyzer for the web server’s own Apache access and error logs (read-only; it can also follow a log live). Enable critical alerts to notify every administrator account after repeated authentication/security events or application errors. Alerts require working Mail Settings. Debug logging is useful during diagnosis and should be disabled during normal operation.</p>
          <h3>Production checklist</h3>
          <ul class="ghotiGuideChecklist">
            <li>HTTPS is enforced and PHP does not display errors to visitors</li>
            <li>Database and SMTP credentials are untracked and minimally privileged</li>
            <li>Runtime JSON/log files and hidden paths cannot be downloaded over HTTP</li>
            <li>Public registration and optional modules are enabled only when needed</li>
            <li>Mail recovery, backups, and restore procedures have been tested</li>
            <li>Server, PHP, database, and image-processing packages receive security updates</li>
          </ul>
        </div>
      </section>

      <section id="docs-troubleshooting" class="ghotiGuideSection">
        <span class="ghotiGuideNumber">08</span>
        <div>
          <h2>Troubleshooting</h2>
          <dl class="ghotiGuideTroubleshooting">
            <dt>The site says the database is unavailable</dt>
            <dd>Confirm the database service, host, port, database name, account permissions, and PDO MySQL extension. Web setup requires <code>GHOTI_SETUP_KEY</code>; otherwise correct the environment or <code>db.config.local.php</code> directly.</dd>
            <dt>Settings save fails</dt>
            <dd>Ensure the PHP service account can write <code>ghoti.settings.json</code> and the application directory is not read-only. Check the application log for the exact server-side failure.</dd>
            <dt>Images will not upload or convert</dt>
            <dd>Check PHP upload size limits, <code>files/gallery/</code> ownership and free space. HEIC, TIFF, and BMP conversion also needs ImageMagick available to the PHP service account.</dd>
            <dt>Password-reset mail does not arrive</dt>
            <dd>Save Mail Settings, send a test message, verify the From address and TLS name, then confirm <code>GHOTI_PUBLIC_URL</code>. Review SMTP and application logs without exposing credentials.</dd>
            <dt>HTML disappears from a page</dt>
            <dd>Use HTML mode and Preview to see the stored-safe result. Ghoti keeps common content markup but removes scripts, inline styles, forms, frames, event handlers, and unsafe URL schemes.</dd>
            <dt>An admin tool is missing</dt>
            <dd>Confirm the account still has admin access. Apache Vhosts additionally must be enabled under Site Settings and the page reloaded.</dd>
          </dl>
          <p class="ghotiGuideFinish">Still investigating? Reproduce the problem once, note the time and action, then compare it with the newest entries in Analytics → Log. Avoid enabling debug logging longer than needed.</p>
        </div>
      </section>
    </div>
  </div>
</section>
HTML;
}

ghoti_async_register('printDocumentation');
?>
