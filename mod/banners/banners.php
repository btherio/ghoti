<?php
/*
 * banners.php - banners module entry point.
 *
 * Created on May 1, 2010. What a "banner" is has since become a choice:
 *
 *   local   - a random row from this site's own banner list (the original
 *             behaviour, and still the default)
 *   adsense - a Google AdSense unit, filled in the visitor's browser
 *   both    - one winner drawn from the local banners and the ad unit together
 *
 *   banners.db.php   - the banner list and the ad settings
 *   banners.ads.php  - AdSense markup, validation and policy origins
 *   banners.async.php- endpoints + class bannersui
 *
 * Themes call displayBanner() directly, often more than once per page, so
 * everything it touches is either memoized (settings) or guarded against being
 * emitted twice (the AdSense loader).
 */
include_once('banners.db.php');
include_once('banners.ads.php');
include_once('banners.async.php'); //endpoints + class bannersui
class banners{
	public $bannersdb,$bannersui;
	public function __construct(){
		$this->bannersdb = new bannersdb();
		$this->bannersui = new bannersui();
	}

	/*
	 * Render one banner position. $smallBanner picks the size the theme has room
	 * for; it selects both which local banners are eligible and which AdSense
	 * unit is used.
	 *
	 * Returns '' when there is nothing to show - no local banners, or ads on but
	 * not yet configured. Every theme drops this straight into its markup, so a
	 * missing banner has to be an empty string and never an error.
	 */
	public function displayBanner($smallBanner=true){
		$settings = $this->bannersdb->getSettings();
		$source = $settings['source'];
		$adsReady = ($source === 'adsense' || $source === 'both') && BannerAds::configured($settings, $smallBanner);

		if($source === 'adsense'){
			return $adsReady ? BannerAds::unit($settings, $smallBanner) : '';
		}

		if($adsReady){
			//"Both" is one pool, not two positions: the ad unit is entered
			//alongside this size's local banners and one winner is drawn. A slot
			//therefore holds one thing, the layout does not change shape, and a
			//site with ten banners does not turn into a site that is 50% ads.
			$local = $this->bannersdb->countBanners($smallBanner);
			if($local < 1 || random_int(0, $local) === 0){
				return BannerAds::unit($settings, $smallBanner);
			}
		}

		$rows = $this->bannersdb->getRandomBanner($smallBanner);
		return $rows ? $this->bannersui->displayBanner($rows) : '';
	}

	//What index.php needs before it writes the content-security policy header:
	//true only when this site is actually going to emit Google's tag.
	public function adsActive(){
		$settings = $this->bannersdb->getSettings();
		return ($settings['source'] === 'adsense' || $settings['source'] === 'both')
			&& BannerAds::configured($settings);
	}
}
?>
