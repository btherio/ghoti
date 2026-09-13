<?php
/*
 * Created on Apr 3, 2009
 */
?>
<span id="pageFeedback" role="status" aria-live="polite"></span>
<?php $ghotiPublicView = ghoti_public_view(); ?>
<div id="ghotiContent" tabindex="-1"<?php if($ghotiPublicView !== null){ echo ' data-server-rendered="true"'; } ?>><?php echo $ghotiPublicView ?? ''; ?></div>
<div id="popup-bg">
	<div id="popup" class="popup" role="dialog" aria-modal="true" aria-labelledby="popupTitle" tabindex="-1">
		<div id="popup-title"><h2 id="popupTitle">Ghoti CMS</h2></div>
		<?php print $_SESSION['ghotiObj']->ghotiui->printCloseButton('popup-bg')?>
		<div id="popup-content" class="popup-content"></div>
		<span id="popupFeedback" role="status" aria-live="polite"></span>
	</div>
</div>