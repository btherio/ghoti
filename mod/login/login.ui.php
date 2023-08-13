<?php
/*
 * Created on Apr 3, 2009
 */
class loginui{
	/*I wanted to keep these menu functions in php to keep them out of the client-side
	 * until they were needed there. I should make them into xml files or something.
	 * idk, this is stupid but it works
	 */
    public $output; //define output variable
	public function printSystemMenu(){
		$this->output .= "<ul>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\" class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"logout();\">&nbsp;Log Out</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\" class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"printChangePasswordForm();\">&nbsp;Change Password</a></li>\n";
		//$this->output .= "<li class=\"dropdown-item\"><a href=\"#\" class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"printDeleteUserDialog();\">&nbsp;Delete Account</a></li>\n";
		$this->output .= "</ul>\n";
		return $this->output;
	}
	public function printAdminMenu(){		
		$this->output = "<ul>\n";
		//$this->output .= "<li><a href=\"#\" class=\"ghotiMenu\" onclick=\"addBannerForm();\">Add Banners</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\" class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"addPage();\">Add Page</a></li>\n";
		//$this->output .= "<li><a href=\"#\" class=\"ghotiMenu\" onclick=\"addLinkForm();\">Add Links</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"manageBanners();\">Banners</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"editLinkForm();\">Links</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"showGhotiLog();\">Log</a></li>\n";
		$this->output .= "<li class=\"dropdown-item\"><a href=\"#\"class=\"dropdown-item\" class=\"ghotiMenu\" onclick=\"printManageUserForm();\">Users</a></li>\n";
		$this->output .= "</ul>\n";
		return $this->output;
	}
	public function printLoginForm(){
		$this->output = "<div id=\"ghotiLogin\"><form id=\"loginForm\" action=\"javascript:login();\"><p>\n";
		$this->output .= "Username:<br /><input type=\"text\" name=\"userName\" id=\"userName\" size=\"10\" /><br />\n";
		$this->output .= "Password:<br /><input type=\"password\" name=\"password\" id=\"password\" size=\"10\" /><br />\n";
		$this->output .= "<input type=\"submit\" value=\"Login\"/>\n";
		if(ghoti::$allowRegister == true){
						$this->output .= "<input type=\"button\" value=\"Register\" onclick=\"printRegisterForm();\" />\n";
		}
		$this->output .= "</p></form><span id=\"loginFeedback\"></span></div>\n";
		return $this->output;
	}
	public function printPopupLogin(){
		$this->output = "<div id=\"ghotiLogin\"><a class=\"dropdown-item\" href=\"#\" onclick=\"popupLogin();\">Login</a></div>\n";
		return $this->output;
	}
	
	function printManageUserForm($userList){
		$this->output = "<table><th>Username</th><th>Email</th><th>Admin</th>\n";
		foreach($userList as $records => $row){
			$this->output .= "<tr>";
			$this->output .= "<td><input type=\"text\" id=\"".$row[1]."\" value=\"".$row[1]."\" size=\"10\" /></td>\n";
			$this->output .= "<td><input type=\"text\" id=\"".$row[1]."email\" value=\"".$row[2]."\" size=\"15\" /></td>\n";
			if($row[3] == 1)	
				$this->output .= "<td>&nbsp;<img  width=\"25pt\" height=\"25pt\" class=\"linkIcon\" src=\"gfx/green-check.gif\" alt=\"yes\" onclick=\"toggleAdmin('".$row[0]."');\" /></td>\n<td>";
			else
				$this->output .= "<td>&nbsp;<img  width=\"25pt\" height=\"25pt\" class=\"linkIcon\" src=\"gfx/red-x.gif\" alt=\"no\" onclick=\"toggleAdmin('".$row[0]."');\" /></td>\n<td>";
			$this->output .= "<a onclick=\"deleteUser('".$row[0]."');\" ><img src=\"gfx/delete.png\" width=\"25pt\" height=\"25pt\" /></a>\n";
			$this->output .= "<a onclick=\"saveUser('".$row[1]."','".$row[1]."email','".$row[0]."');\" ><img src=\"gfx/save.png\" width=\"25pt\" height=\"25pt\" /></a>\n";

		}
		$this->output .= "</table>\n";
		return $this->output;
	}
}
?>
