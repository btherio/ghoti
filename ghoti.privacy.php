<?php
/* Public legal pages and permission-aware navigation. No database writes. */
function ghoti_can_view_page($group){
    return $group === 'public' || ($group === 'private' && ghoti_require_login());
}
function ghoti_privacy_escape($value){
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function ghoti_privacy_signal(){
    return ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1' || ($_SERVER['HTTP_DNT'] ?? '') === '1';
}
function ghoti_analytics_allowed(){
    return !ghoti_privacy_signal() && ($_SESSION['analyticsConsent'] ?? false) === true;
}
function setPrivacyChoice($allow){
    if(!is_bool($allow)){ return 'Invalid privacy choice.'; }
    $_SESSION['analyticsConsent'] = $allow && !ghoti_privacy_signal();
    // Rotate the identifier when a choice changes. Never reuse the login token.
    unset($_SESSION['analyticsVisitorId']);
    return ghoti_analytics_allowed() ? 'Optional analytics enabled for this session.' : 'Optional analytics are off.';
}
ghoti_async_register('setPrivacyChoice');

function ghoti_sitemap_rows($rows){
    $out = '<ul class="ghotiSitemap">';
    foreach($rows as $row){
        if(!ghoti_can_view_page($row[2] ?? null) || (int)($row[0] ?? 0) < 1){ continue; }
        $id = (int)$row[0];
        $out .= '<li><a href="?page='.$id.'">'.ghoti_privacy_escape($row[1]).'</a>'.($row[2] === 'private' ? ' <small>Members</small>' : '').'</li>';
    }
    return $out.'</ul>';
}
function ghoti_sitemap(){
    // Query only authorized groups, rather than returning a management inventory.
    $rows = array();
    $groups = ghoti_require_login() ? array('public', 'private') : array('public');
    foreach($groups as $group){
        $pages = $_SESSION['ghotiObj']->ghotidb->getPageList($group);
        if($pages === false){ return '<p>The sitemap is temporarily unavailable. Please try again.</p>'; }
        foreach($pages as $page){ $rows[] = array($page[0], $page[1], $group); }
    }
    return '<article class="ghotiLegal"><h1>Sitemap</h1><p>Pages available to you right now. Sign-in is required for member pages.</p>'.ghoti_sitemap_rows($rows).'<ul><li><a href="?view=privacy">Privacy policy</a></li><li><a href="?view=accessibility">Accessibility</a></li></ul></article>';
}
function ghoti_privacy_contact(){
    $email = ghoti::$privacyEmail;
    if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
        return '<p>The operator has not yet published a privacy contact address. This notice is incomplete until those contact details are supplied.</p>';
    }
    return '<p>For privacy questions, access or correction requests, deletion requests, complaints, or accessibility assistance, email <a href="mailto:'.ghoti_privacy_escape($email).'">'.ghoti_privacy_escape($email).'</a>. Please describe your request without sending passwords or unnecessary sensitive information. Identity verification may be necessary before account information is disclosed or changed.</p>';
}
function ghoti_privacy_policy(){
    $operator = ghoti_privacy_escape(ghoti::$privacyOperator ?: ghoti::$siteTitle.' site operator');
    $region = ghoti_privacy_escape(ghoti::$privacyRegion ?: 'Canada');
    return '<article class="ghotiLegal"><h1>Privacy policy</h1><p>Last updated: September 13, 2026</p>
    <h2>Who is responsible</h2><p>This notice describes how '.$operator.' (the “operator”, “we”, “us”) handles personal information through this website. The site is hosted in Canada. The operator’s stated location is '.$region.'. This notice covers this website; independent websites linked from it have their own privacy practices.</p>'.ghoti_privacy_contact().'
    <h2>Information handled by the website</h2>
    <ul><li><strong>Accounts:</strong> username, email address, a password hash, account permissions, and information needed to verify sign-in or recover an account. The application does not store your account password in plain text.</li>
    <li><strong>Contributions and correspondence:</strong> comments, uploaded content, messages, and information you choose to submit. Content you publish on a public page may be visible to anyone; member pages are accessible to signed-in users. Do not submit information you do not intend to share with that audience.</li>
    <li><strong>Essential technical information:</strong> IP address, browser information, session identifiers, request information, and security or error events used to deliver the site, manage sessions, investigate faults, and prevent abuse. Hosting and mail systems may also maintain operational logs.</li>
    <li><strong>Optional usage analytics:</strong> only after you enable analytics, the CMS records the visited page, time, broad browser/device category, a separate random identifier for the session, whether the visit is an administrator visit, and the referring website’s hostname. New analytics events do not store your login session token, account ID, full IP address, raw browser string, or URL query parameters.</li></ul>
    <h2>Purposes and legal grounds</h2><p>We use information to provide requested account and publishing functions, respond to communications, secure and maintain the service, and, with your choice, understand site usage. Where applicable Canadian privacy law requires consent, information must be collected, used, or disclosed with meaningful consent unless a legal exception applies. Where the GDPR applies, processing necessary to provide a requested service may rely on performance of a contract; proportionate security and maintenance processing may rely on legitimate interests; legal obligations may require retention or disclosure; and optional analytics relies on consent. The applicable basis depends on the actual activity and circumstances. This policy does not waive statutory privacy rights.</p>
    <h2>Cookies and your choices</h2><p>The CMS uses an essential session cookie for sign-in, request security, theme preferences, and your analytics choice. The cookie lasts for the browser session; the application expires an inactive session after approximately 30 minutes. Optional analytics is off until you enable it. Use “Privacy choices” in the footer to enable or decline analytics, or withdraw a previous choice, without losing access to the site. The choice applies to the current session. Global Privacy Control and Do Not Track signals prevent optional analytics. Withdrawal stops future analytics collection; it does not automatically remove earlier records. Disabling essential cookies may prevent account and other interactive features from working.</p>
    <h2>Service providers and disclosure</h2><p>Hosting, system administration, and email delivery can involve service providers that process technical information or messages to perform those services. Information may also need to be disclosed to comply with applicable law, respond to valid legal process, protect the service, or investigate misuse. Bundled theme fonts and scripts are served locally. Images, embedded content, and links added by site editors may involve other providers; visiting or loading those resources can disclose technical information to them. Providers’ locations and contractual safeguards must be assessed before a new integration is introduced.</p>
    <h2>Advertising and payments</h2><p>The site displays advertising and uses third-party payment processing. Clicking an advertisement or continuing to a payment provider can disclose information to that provider under its own notice. Payment providers may collect payer, billing, transaction, device, and fraud-prevention information. The specific provider and its privacy notice should be identified at checkout. Optional analytics consent on this site does not authorize an advertising network to track you and is not consent to receive marketing email.</p>
    <h2>International processing</h2><p>Canadian hosting does not guarantee that every recipient, email system, backup, or externally embedded resource is located in Canada. Personal information processed in another jurisdiction may be subject to its laws and lawful government access. Where an applicable law restricts international transfers, the operator must establish the required legal mechanism and safeguards before making such a transfer.</p>
    <h2>Retention and security</h2><p>Accounts and published contributions remain in the CMS until removed or otherwise managed by the operator. The current application does not automatically expire historical analytics, account records, or contributions. Application logs rotate by size, rather than a fixed number of days; hosting, email, and backup retention are managed separately. Contact the operator to ask about a particular record or request deletion. The operator must determine and apply retention periods appropriate to the purpose and applicable law, including any records needed for disputes or legal obligations. Session protections, access checks, password hashing, and administrator restrictions help protect information, but no system can guarantee absolute security.</p>
    <h2>Your rights and complaints</h2><p>Depending on the applicable law, you may request information about our practices, access to your personal information, correction of inaccuracies, withdrawal of consent, and deletion where permitted. Where GDPR rights apply, you may also have rights to restriction, objection, and portability, subject to the legal conditions and exceptions. Requests must be handled within the deadlines required by the applicable law; some information may need to be retained or withheld for lawful reasons. You may complain to the <a href="https://www.priv.gc.ca/en/report-a-concern/">Office of the Privacy Commissioner of Canada</a>, <a href="https://oipc.ab.ca/">the Office of the Information and Privacy Commissioner of Alberta</a>, or, where applicable, your European supervisory authority.</p>
    <h2>Children and sensitive information</h2><p>Do not submit a child’s information or sensitive personal information unless the service specifically requests it and provides appropriate information and safeguards. If you believe a child’s information was collected without legally required authorization, contact the operator for review.</p>
    <h2>Changes to this notice</h2><p>Updates will be posted here with a revised date. A policy update does not itself supply consent for a new purpose; additional notice or consent must be obtained where required.</p></article>';
}
function ghoti_accessibility_notice(){
    return '<article class="ghotiLegal"><h1>Accessibility</h1><p>Our technical target is WCAG 2.2 Level AA. This is a work in progress, not a claim that every page or function conforms.</p><p>You can use the footer sitemap to find pages available to you, navigate controls with a keyboard, and use your operating system’s reduced-motion preference. Content supplied by editors, older themes, uploaded documents, and third-party resources may still contain barriers.</p><h2>Report a barrier or request help</h2>'.ghoti_privacy_contact().'<p>Include the page address, what you were trying to do, and the format or assistance you need. Browser and assistive-technology details can help reproduce the issue, but are optional.</p></article>';
}
function ghoti_public_view(){
    $view = $_GET['view'] ?? null;
    if($view === 'privacy'){ return ghoti_privacy_policy(); }
    if($view === 'sitemap'){ return ghoti_sitemap(); }
    if($view === 'accessibility'){ return ghoti_accessibility_notice(); }
    return null;
}
function ghoti_footer_links(){
    $state = ghoti_analytics_allowed() ? 'Optional analytics are on.' : 'Optional analytics are off.';
    return '<nav class="ghotiFooterLinks" aria-label="Footer"><a href="?view=privacy">Privacy policy</a><a href="?view=sitemap">Sitemap</a><a href="?view=accessibility">Accessibility</a><details class="ghotiPrivacyChoices"><summary>Privacy choices</summary><p>Optional analytics helps us understand site usage. It is off unless you choose to enable it. Your choice lasts for this session.</p><div class="ghotiPrivacyActions"><button type="button" onclick="ghotiSetPrivacyChoice(false)">Decline analytics</button><button type="button" onclick="ghotiSetPrivacyChoice(true)">Enable analytics</button></div><p id="ghotiPrivacyStatus" role="status">'.$state.'</p></details></nav>';
}
