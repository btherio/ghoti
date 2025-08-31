<?php
/*
 * Created on May 1, 2010
 */

class bannersui{
	public function addBannerForm(){
		$addBannerForm = "<p>Banner Description: <input type=\"text\" id=\"bannerDesc\" name=\"bannerDesc\" size=\"20\" \><br />\n";
		$addBannerForm .= "Image URL: <input type=\"text\" id=\"bannerImgUrl\" name=\"bannerImgUrl\" size=\"15\" /><br />\n";	
		$addBannerForm .= "Link URL: <input type=\"text\" id=\"bannerLinkUrl\" name=\"bannerLinkUrl\" size=\"15\" /><br />\n";	
		$addBannerForm .= "Small Banner: <input type=\"checkbox\" id=\"bannerSmallBanner\" name=\"bannerSmallBanner\" value=\"true\" /><br />\n";	
		$addBannerForm .= "<input type=\"button\" value=\"Add Banner\" onclick=\"addBanner();\"/><br />\n";
		$addBannerForm .= "<span id=\"addBannerMessages\"></span></p>\n";
		return $addBannerForm;
	}

	public function displayBanner($dbresult){
		$this->banner = "";
		foreach($dbresult as $x => $y){
				$this->banner .= "<a href=\"".$y[3]."\"><img src=\"".$y[2]."\" alt=\"".$y[1]."\" class=\"ghotiBanner\" /></a>\n";		
		}
		return $this->banner;
	}
	public function manageBanners($dbresult){
		$manageBanners = "<table border=\"0\">\n";
		foreach($dbresult as $x => $y){
			$manageBanners .= "<tr><td colspan=\"3\"><a href=\"".$y[3]."\"><img src=\"".$y[2]."\" alt=\"".$y[1]."\" border=\"0\" /></a></td></tr>";
			$manageBanners .= "<tr><td><a href=\"#\" onclick=\"saveBanner(".$y[0].");\" value=\"Save\" >Save</a></td>\n";
			$manageBanners .= "<td>Description:<input type=\"text\" id=\"alt-".$y[0]."\" size=\"15\" value =\"".$y[1]."\" /></td>";
			$manageBanners .= "<td>Image URL:<input type=\"text\" id=\"imgUrl-".$y[0]."\" size=\"20\" value =\"".$y[2]."\" /></td></tr>\n";
			$manageBanners .= "<td><a href=\"#\" onclick=\"deleteBanner(".$y[0].");\">Delete</a></td>";
			if($y[4] == 1)	
				$manageBanners .= "<td>Small:<img id=\"smallBannerIcon-".$y[0]."\" class=\"adminIcon\" src=\"gfx/green-check.gif\" alt=\"yes\" onclick=\"toggleSmallBanner(".$y[0].");\" />\n";
			else
				$manageBanners .= "<td>Small:<img id=\"smallBannerIcon-".$y[0]."\" class=\"adminIcon\" src=\"gfx/red-x.gif\" alt=\"no\" onclick=\"toggleSmallBanner(".$y[0].");\" />\n";	
			$manageBanners .= "<input type=\"hidden\" id=\"smallBanner-".$y[0]."\" value =\"".$y[4]."\" /></td>";
			$manageBanners .= "<td>Link URL:<input type=\"text\" id=\"linkUrl-".$y[0]."\" size=\"20\" value =\"".$y[3]."\" /></td></tr>\n";
			$manageBanners .= "<tr><td colspan=\"3\"><hr width=\"90%\"</td></tr>";		}
		$manageBanners .="</table>\n";
		$manageBanners .="<a href=\"#\" class=\"ghotiMenu\" onclick=\"addBannerForm();\">Add Banners</a>";
		return $manageBanners;
	}
}
?>
