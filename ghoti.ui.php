<?php
/*
 * Created on Mar 1, 2009
 *
 */

class ghotiui{
    public $output;
    
	function showGhotiLog(){
		$showGhotiLog = "<h1>Log</h1>\n";
		$showGhotiLog .= "<textarea rows=\"30\" cols=\"80\">".`cat ghoti.log`."</textarea><br />\n";
		$showGhotiLog .= "<a href=\"#\"  onclick=\"clearGhotiLog();\" >Clear log</a>\n";
		return $showGhotiLog;
	}
	function printPageList($pageList){
		$this->output = "<ul>\n";
		foreach($pageList as $records => $row){
			$this->output .= "<li><a href=\"#\" class=\"ghotiMenu\" onclick=\"getPage(".$row[0].")\">".stripslashes($row[1])."</a></li>\n";
		}
		$this->output .= "</ul>\n";
		return $this->output;
	}
	
	function printFooter(){
		return "GhotiCMS ". `cat VERSION`;
	}
		
	function printCloseButton($popupName){
		$this->output = html::divStart(null,"popup-close")."<a class=\"ghotiMenu\" href=\"#\" onclick=\"cancelPopup('$popupName');\">";
		$this->output .="<img src=\"gfx/popup-close.png\" alt=\"close\" /></a>".html::divEnd();
		return $this->output;
	}
	
	function printEditPageForm($id,$title,$content,$group){
		$this->output .= html::divStart("managePagePanel")."<form action=\"#\">";
		$this->output .= html::divStart("managePageForm")."Page Title:<input id=\"pageTitleEdit\" maxlength=\"20\" type=\"text\" value=\"$title\" />";
		$this->output .= "<textarea id=\"pageContentEdit\">".stripslashes($content)."</textarea>";
		$this->output .= "<br /><input type=\"button\" id=\"pageSaveButton\" onclick=\"savePage();\" value=\"Save\" />\n";
		$this->output .= "<span>Public:</span>\n";
		
		if($group === "public")
			$this->output .= "<span id=\"publicPrivateButton\"><img class=\"linkIcon\" src=\"gfx/green-check.gif\" alt=\"yes\" onclick=\"setPagePrivate($id);\" /></span>";
		elseif($group === "private")
			$this->output .= "<span id=\"publicPrivateButton\"><img class=\"linkIcon\" src=\"gfx/red-x.gif\" alt=\"no\" onclick=\"setPagePublic($id)\" /></span>";
		
		$this->output .= html::divEnd().html::divStart()."<input type=\"button\" onclick=\"deletePage($id);\" value=\"Delete Page\" />";
		$this->output .= "<input type=\"button\" id=\"pageEditButton\" onclick=\"printPageEditor();\" value=\"Edit Page\" />";
		$this->output .= "<input type=\"hidden\" id=\"pageIdEdit\" value=\"$id\" /></div>";
		$this->output .= "</form>".html::divEnd();
		return $this->output;
	}

	function printPageMenu($pageList,$newDiv){
		if($newDiv){
			return "<div id=\"ghotiPageMenu\">".$this->printPageList($pageList)."</div>\n";
		}else{
			return $this->printPageList($pageList);	
		}		
	}
	function printThemeChanger($themes){
		$this->output = "<form id=\"changeTheme\" action=\"#\">\n";
		$this->output .="<p><select id=\"theme\" onchange=\"changeTheme(this.form)\">\n";
		$this->output .="<option value=\"#\">Change Theme</option>\n";
		foreach($themes as $theme){
		if($theme['level'] == 2) //check for level two of the xml file <theme>
			$this->output .= "<option value=\"?theme=".$theme['value']."\">".$theme['value']."</option>\n";
		}
		$this->output .="</select></p></form>\n";
		return $this->output;
	}
}
?>
