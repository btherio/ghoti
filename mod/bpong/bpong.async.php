<?php
/*
 * bpong.async.php - shortcode, admin endpoints, and class bpongui.
 *
 * Same layout as every other module's async file: endpoints first, registered
 * through the ghoti async wrapper, then the renderer.
 *
 * Note what is NOT here: no endpoint a player can call. The match runs in the
 * browser and its result is never submitted, so the only endpoints are the
 * admin screen and its save - both behind the admin gate. A high-score table
 * would have meant an unauthenticated write endpoint on a public page, which is
 * a bigger decision than a pong game deserves.
 */

function bpongRequireAdmin(){
	if(!isset($_SESSION['userId']) || !isAdmin($_SESSION['userId'])){
		ghoti::logWarn("bpong.async.php", "Unauthorized pong settings attempt from ".ghoti_remote_addr());
		return false;
	}
	return true;
}

function bpongDb(){
	return $_SESSION['bpongObj']->bpongdb;
}

function showBpongManager(){
	if(!bpongRequireAdmin()){ return "<h1>Pong</h1><p>Admin access required.</p>"; }
	return $_SESSION['bpongObj']->bpongui->manageBpong(bpongDb()->getSettings());
}

function saveBpongSettings($winningScore,$paddleHeight,$cpuSpeed,$showControls){
	if(!bpongRequireAdmin()){ return "Admin access required."; }
	try{
		$v = ghoti_validate();
		//intInRange clamps rather than throwing, and clamping is the right
		//behaviour here: every one of these is a <select> or a number input with
		//the same bounds, and a value outside them makes an odd board, not a
		//security problem.
		$limits = bpongdb::limits();
		$winningScore = $v->intInRange($winningScore, $limits['winningScore'][0], $limits['winningScore'][1], "winning score");
		$paddleHeight = $v->intInRange($paddleHeight, $limits['paddleHeight'][0], $limits['paddleHeight'][1], "paddle height");
		$cpuSpeed     = $v->intInRange($cpuSpeed, $limits['cpuSpeed'][0], $limits['cpuSpeed'][1], "CPU speed");
		$showControls = $v->boolInt($showControls);
	}catch (Exception $e) {
		return $e->getMessage();
	}
	$saved = bpongDb()->saveSettings(array(
		'winningScore' => $winningScore,
		'paddleHeight' => $paddleHeight,
		'cpuSpeed'     => $cpuSpeed,
		'showControls' => $showControls === 1,
	));
	return $saved ? "Pong settings saved." : "Saving pong settings failed.";
}

ghoti_async_register(
	"showBpongManager",
	"saveBpongSettings"
);

/* ---------------------------------------------------------------- *
 *  Shortcode: [bpong:game] inside page content.
 * ---------------------------------------------------------------- */

function bpong_shortcode_expand($matches){
	$what = isset($matches[1]) ? strtolower(trim($matches[1])) : '';
	if(!isset($_SESSION['bpongObj'])){
		//The module is switched off, or the page is being rendered somewhere
		//that never loaded it. Emit nothing rather than a broken board.
		return '';
	}
	if($what !== 'game'){
		return '<p class="ghotiBpongMissing">Unknown pong shortcode. Use [bpong:game].</p>';
	}
	return $_SESSION['bpongObj']->board();
}
ghoti_register_shortcode('bpong', 'bpong_shortcode_expand');

/* ---------------------------------------------------------------- *
 *  Renderer
 * ---------------------------------------------------------------- */

class bpongui{
	public $output;

	private function esc($value){ return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

	//A page can hold more than one [bpong:game]; each board needs its own ids
	//for the label/ARIA wiring. Instance state, not a global, so nothing has to
	//be reset between requests.
	private $instances = 0;

	/*
	 * One board. The settings ride along as a JSON attribute rather than an
	 * inline <script> per board: the values are integers clamped by bpongdb,
	 * the markup stays free of generated script, and bpong.js can set up every
	 * board on the page with one pass.
	 */
	public function board($settings){
		$this->instances++;
		$id = 'ghotiBpong'.$this->instances;
		$config = json_encode(array(
			'winningScore' => (int)$settings['winningScore'],
			'paddleHeight' => (int)$settings['paddleHeight'],
			'cpuSpeed'     => (int)$settings['cpuSpeed'],
		));

		$out  = '<figure class="ghotiBpong" id="'.$id.'" data-bpong-config="'.$this->esc($config).'">'."\n";
		$out .= '<figcaption class="ghotiBpongHead"><span class="ghotiBpongTitle">Bitcoin Pong</span>'
			.'<span class="ghotiBpongScore" data-bpong-score aria-hidden="true">YOU 0 &#9672; 0 CPU</span></figcaption>'."\n";
		//width/height are the backing-store size; CSS sizes the element itself.
		//tabindex makes the canvas focusable, which is what keeps the key
		//handlers off the document and out of a reader's way.
		$out .= '<canvas width="960" height="288" tabindex="0" role="application"'
			.' aria-label="Bitcoin Pong. Press Enter to play, W and S or the arrow keys to move your paddle, P to pause, R to restart."></canvas>'."\n";
		$out .= '<p class="ghotiBpongStatus" data-bpong-status role="status">Press Enter or click the board to play.</p>'."\n";
		if(!empty($settings['showControls'])){
			$out .= '<p class="ghotiBpongKeys"><kbd>W</kbd><kbd>S</kbd> or <kbd>&uarr;</kbd><kbd>&darr;</kbd> move'
				.' &middot; <kbd>P</kbd> pause &middot; <kbd>R</kbd> restart'
				.' &middot; first to '.(int)$settings['winningScore'].'</p>'."\n";
		}
		//Buttons for anyone who cannot use the keys at all - a touch device with
		//no keyboard, or a switch user. They drive the same handler the keys do.
		$out .= '<p class="ghotiBpongActions">'
			.'<button type="button" class="ghotiButton ghotiButtonCompact ghotiButtonSecondary" data-bpong-action="pause">Pause</button>'
			.'<button type="button" class="ghotiButton ghotiButtonCompact ghotiButtonSecondary" data-bpong-action="restart">Restart</button>'
			.'</p>'."\n";
		$out .= '</figure>'."\n";
		return $out;
	}

	public function manageBpong($settings){
		$out  = '<section id="ghotiManageBpong" class="ghotiAdminPanel">'."\n";
		$out .= '<div class="ghotiCrudHeader"><h1>Pong</h1></div>'."\n";
		$out .= '<form id="bpongSettingsForm" class="ghotiForm" action="#" onsubmit="saveBpongSettings(); return false;">'."\n";
		$out .= '<div class="ghotiFormGrid">'."\n";
		$limits = bpongdb::limits();
		$out .= $this->numberField('bpongWinningScore', 'Points to win', $settings['winningScore'], $limits['winningScore']);
		$out .= $this->numberField('bpongPaddleHeight', 'Paddle height (cells)', $settings['paddleHeight'], $limits['paddleHeight']);
		//Stored as tenths so the original 15.5 cells/second survives an integer
		//column; shown as a plain difficulty so nobody has to know that.
		$out .= '<label class="ghotiField"><span>CPU difficulty</span><select id="bpongCpuSpeed">'."\n";
		foreach(array(90 => 'Relaxed', 125 => 'Steady', 155 => 'Original (15.5 cells/sec)', 200 => 'Quick', 260 => 'Merciless') as $value => $label){
			$selected = (int)$settings['cpuSpeed'] === $value ? ' selected="selected"' : '';
			$out .= '<option value="'.$value.'"'.$selected.'>'.$this->esc($label).'</option>'."\n";
		}
		//A value saved outside the preset list (hand-edited, or a preset that has
		//since changed) would otherwise vanish from the form and be silently
		//reset on the next save.
		if(!in_array((int)$settings['cpuSpeed'], array(90,125,155,200,260), true)){
			$out .= '<option value="'.(int)$settings['cpuSpeed'].'" selected="selected">Custom ('.number_format($settings['cpuSpeed'] / 10, 1).' cells/sec)</option>'."\n";
		}
		$out .= '</select></label>'."\n";
		$out .= '</div>'."\n";
		$out .= '<label class="ghotiInlineChoice"><input type="checkbox" id="bpongShowControls"'.(!empty($settings['showControls']) ? ' checked="checked"' : '').' /> Show the key legend under each board</label>'."\n";
		$out .= '<div class="ghotiFormActions"><button type="submit" class="ghotiButton">Save pong settings</button></div>'."\n";
		$out .= '<span id="bpongSettingsMessages"></span></form>'."\n";

		$out .= '<h2>Preview</h2>'."\n";
		$out .= $this->board($settings);

		$out .= ghoti_docs_panel("How to use pong", "shortcode, settings", array(
			array('heading' => 'Put a game on a page',
				'list' => array(
					'Edit any page and type <b>[bpong:game]</b> where the board should appear.',
					'The board can be used more than once on a page; each one plays independently.',
					'Nothing else is needed - visitors do not have to sign in, and no result is recorded.')),
			array('heading' => 'Settings',
				'list' => array(
					'<b>Points to win</b> ends the match; <b>paddle height</b> and <b>CPU difficulty</b> set how hard it is.',
					'The defaults are the original terminal game&rsquo;s numbers.')),
			array('heading' => 'Playing',
				'list' => array(
					'<b>W</b>/<b>S</b> or the arrow keys move, <b>P</b> pauses, <b>R</b> restarts.',
					'Keys only work while the board has focus, so the page still scrolls normally for a reader who is not playing.',
					'On a touch screen, tap the upper or lower half of the board.'))
		));
		$out .= '</section>'."\n";
		return $out;
	}

	private function numberField($id, $label, $value, $limits){
		return '<label class="ghotiField"><span>'.$this->esc($label).'</span>'
			.'<input type="number" id="'.$this->esc($id).'" min="'.(int)$limits[0].'" max="'.(int)$limits[1].'" value="'.(int)$value.'" /></label>'."\n";
	}
}
