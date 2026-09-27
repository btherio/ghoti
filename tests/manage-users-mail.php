<?php
// Rendering integration uses saved user rows and a mail-settings fixture; no database or mail.
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
require __DIR__.'/../ghoti.php';
require __DIR__.'/../mod/login/login.async.php';
function usersMailCheck($ok, $message){ if(!$ok){ throw new RuntimeException($message); } }
$_SESSION['mailObj'] = (object)array('maildb'=>new class{
    public function getSettings(){ return array('enabled'=>true, 'fromAddress'=>'site@example.test'); }
});
$ui = new loginui();
$menu = $ui->printAdminMenu();
usersMailCheck(!str_contains($menu, 'Send Email') && str_contains($menu, 'printManageUserForm'), 'Email should be accessed through Users');
$html = $ui->printManageUserForm(array(array(7, 'Alice <test>', 'alice@example.test', 1), array(9, 'Bob', '', 0)));
usersMailCheck(substr_count($html, 'class="ghotiUserCard"') === 2 && !str_contains($html, '<table'), 'Accounts should use full-width cards');
usersMailCheck(substr_count($html, '<span>Username</span>') === 2 && substr_count($html, '<span>Email</span>') === 2, 'Account inputs need visible labels');
usersMailCheck(substr_count($html, '>email</button>') === 2, 'Every user needs an email button');
usersMailCheck(str_contains($html, 'composeMailToUser(7);') && str_contains($html, 'composeMailToUser(9);'), 'Email buttons must use saved user IDs');
usersMailCheck(strpos($html, 'id="ghotiComposeMail"') > strrpos($html, '</article>'), 'Composer must follow the user list');
usersMailCheck(str_contains($html, 'Group email options') && str_contains($html, 'Administrators only') && str_contains($html, 'Selected users'), 'Group choices missing');
usersMailCheck(str_contains($html, 'value="7"') && str_contains($html, 'value="9" disabled="disabled"'), 'Composer must reflect saved addresses');
usersMailCheck(!str_contains($html, 'Alice <test>') && str_contains($html, 'Alice &lt;test&gt;'), 'User names must be escaped');
$empty = $ui->printManageUserForm(array());
usersMailCheck(str_contains($empty, 'No user accounts were found.'), 'Empty directory must render');
echo "PASS: 10 user management email assertions; no database or real mail\n";
