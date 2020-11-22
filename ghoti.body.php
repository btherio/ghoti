<?php
/*
 * Created on Apr 3, 2009
 */
?>
<span id="pageFeedback"></span>
<div id="ghotiContent"></div>
<div id="popup-bg">
	<div id="popup" class="popup">
		<div id="popup-title"><h2 id="popupTitle">Ghoti CMS</h2></div>
		<?php print $_SESSION['ghotiObj']->ghotiui->printCloseButton('popup-bg')?>
		<div id="popup-content" class="popup-content"></div>
		<span id="popupFeedback"></span>
	</div>
</div>

