<?php
/*
 * banners.ads.php - Google AdSense support for the banners module.
 *
 * Everything here is markup generation and validation; nothing talks to Google
 * from the server. AdSense is a client-side tag: the browser loads Google's
 * script, Google decides what to fill the slot with, and this site never sees
 * the ad. That is why there is no API client here the way there is for PayPal -
 * there is no server-to-server call to make.
 *
 * Two rules shape the rest of this file.
 *
 * 1. The publisher id and slot ids are interpolated into a script URL and into
 *    HTML attributes that every visitor receives. They are therefore matched
 *    against strict patterns and REJECTED when they do not fit, never escaped
 *    into submission - an "almost valid" publisher id is a typo that earns
 *    nothing, not something worth rendering.
 *
 * 2. A visitor sending Global Privacy Control or Do Not Track gets
 *    non-personalized ads. The site already honours that signal for its own
 *    analytics (ghoti_privacy_signal()); handing the same visitor to an ad
 *    network for profiling would make that promise meaningless.
 */

final class BannerAds{
	//AdSense publisher ids are "ca-pub-" followed by a run of digits (16 today).
	//The range is deliberately wider than the current length and the charset is
	//not: it is the charset that keeps this out of the script URL's query string.
	const CLIENT_PATTERN = '/^ca-pub-[0-9]{10,24}$/';
	const SLOT_PATTERN   = '/^[0-9]{5,24}$/';

	//Formats AdSense accepts on data-ad-format for a display unit.
	public static function formats(){
		return array(
			'auto'       => 'Auto (responsive)',
			'rectangle'  => 'Rectangle',
			'horizontal' => 'Horizontal',
			'vertical'   => 'Vertical',
			'fluid'      => 'Fluid (in-article/in-feed unit)',
		);
	}

	public static function validClient($client){
		return is_string($client) && preg_match(self::CLIENT_PATTERN, $client) === 1;
	}

	public static function validSlot($slot){
		return is_string($slot) && preg_match(self::SLOT_PATTERN, $slot) === 1;
	}

	/*
	 * True when ads can actually be rendered for this size. A publisher id on
	 * its own is not enough: a slot id belongs to one ad unit, and the unit for
	 * the small position is not the unit for the wide one. Asking for a size
	 * that has no slot configured renders nothing rather than reusing the other
	 * slot, which would report both positions under one name in AdSense.
	 */
	public static function configured($settings, $smallBanner = null){
		if(!self::validClient($settings['adClient'] ?? '')){ return false; }
		if($smallBanner === null){
			return self::validSlot($settings['adSlotSmall'] ?? '') || self::validSlot($settings['adSlotLarge'] ?? '');
		}
		return self::validSlot(self::slotFor($settings, $smallBanner));
	}

	public static function slotFor($settings, $smallBanner){
		return (string)($smallBanner ? ($settings['adSlotSmall'] ?? '') : ($settings['adSlotLarge'] ?? ''));
	}

	/*
	 * The loader, emitted at most once per page.
	 *
	 * Themes call displayBanner() two or three times (see css/cyber/cyber.php),
	 * and adsbygoogle.js is meant to be loaded once per document; the per-slot
	 * push() calls below queue against that one instance. The static flag is
	 * what keeps a three-banner theme from pulling the script three times.
	 *
	 * requestNonPersonalizedAds has to be set before any push(), which is the
	 * other reason this block goes first rather than being folded into unit().
	 */
	private static $loaderEmitted = false;

	public static function resetLoader(){ self::$loaderEmitted = false; }

	public static function loader($settings){
		if(self::$loaderEmitted){ return ''; }
		if(!self::validClient($settings['adClient'] ?? '')){ return ''; }
		self::$loaderEmitted = true;
		$client = htmlspecialchars((string)$settings['adClient'], ENT_QUOTES, 'UTF-8');
		$out  = "<script async src=\"https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=".$client."\" crossorigin=\"anonymous\"></script>\n";
		if(function_exists('ghoti_privacy_signal') && ghoti_privacy_signal()){
			//&npa=1 on the ad request. Google's documented switch for serving a
			//visitor without using their profile; it must precede every push().
			$out .= "<script>(adsbygoogle=window.adsbygoogle||[]).requestNonPersonalizedAds=1;</script>\n";
		}
		return $out;
	}

	/*
	 * One ad unit. Returns '' - never a broken tag - when this size has no
	 * usable configuration, so a caller can treat it exactly like "no banner".
	 */
	public static function unit($settings, $smallBanner = true){
		if(!self::configured($settings, $smallBanner)){ return ''; }
		$client = htmlspecialchars((string)$settings['adClient'], ENT_QUOTES, 'UTF-8');
		$slot   = htmlspecialchars(self::slotFor($settings, $smallBanner), ENT_QUOTES, 'UTF-8');
		$format = (string)($settings['adFormat'] ?? 'auto');
		if(!array_key_exists($format, self::formats())){ $format = 'auto'; }
		$full   = !empty($settings['adFullWidth']) ? 'true' : 'false';
		$label  = trim((string)($settings['adLabel'] ?? ''));

		$out = self::loader($settings);
		$out .= "<div class=\"ghotiAdSlot ghotiAdSlot".($smallBanner ? 'Small' : 'Wide')."\">\n";
		if($label !== ''){
			//Several jurisdictions require advertising to be identifiable as
			//advertising. The caption is the site operator's wording, not ours.
			$out .= "<span class=\"ghotiAdLabel\">".htmlspecialchars($label, ENT_QUOTES, 'UTF-8')."</span>\n";
		}
		$out .= "<ins class=\"adsbygoogle\" style=\"display:block\""
			." data-ad-client=\"".$client."\""
			." data-ad-slot=\"".$slot."\""
			." data-ad-format=\"".htmlspecialchars($format, ENT_QUOTES, 'UTF-8')."\""
			." data-full-width-responsive=\"".$full."\"";
		if(!empty($settings['adTest'])){
			//Placeholder ads. Nothing is billed and nothing is earned, which is
			//how a new install proves its slots render before it goes live.
			$out .= " data-adtest=\"on\"";
		}
		$out .= "></ins>\n";
		$out .= "<script>(adsbygoogle=window.adsbygoogle||[]).push({});</script>\n";
		$out .= "</div>\n";
		return $out;
	}

	/*
	 * Origins the content-security policy has to allow before a single ad can
	 * render. index.php widens the policy with these ONLY while ads are switched
	 * on, so a site showing its own banners keeps the tighter policy.
	 *
	 * This is the set the documented tag needs: the script host, the ad server
	 * that fills the slot, and the frame host the creative is rendered in. It is
	 * not a promise of completeness - Google serves creatives from hosts it does
	 * not publish a list of, and an install that sees blocked-request warnings
	 * in the browser console may have to add one. docs/banners.md says so.
	 */
	public static function cspOrigins(){
		return array(
			'script'  => array('https://pagead2.googlesyndication.com', 'https://googleads.g.doubleclick.net', 'https://tpc.googlesyndication.com'),
			'connect' => array('https://pagead2.googlesyndication.com', 'https://googleads.g.doubleclick.net'),
			'frame'   => array('https://googleads.g.doubleclick.net', 'https://tpc.googlesyndication.com', 'https://www.google.com'),
		);
	}

	/*
	 * The one line an install has to publish at its domain root before Google
	 * will buy against these slots. Built here so the admin screen and the docs
	 * cannot drift from each other.
	 */
	public static function adsTxtLine($client){
		if(!self::validClient($client)){ return ''; }
		//"pub-..." in ads.txt: the ca- prefix is dropped. f08c47fec0942fa0 is
		//Google's certification authority id, identical for every publisher.
		return 'google.com, '.substr($client, 3).', DIRECT, f08c47fec0942fa0';
	}
}
?>
