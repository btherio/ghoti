<?php
/*
 * bpong.php - bitcoin pong module entry point.
 *
 * Puts a playable Pong board on any page with the shortcode [bpong:game].
 *
 *   bpong.db.php    - the settings row (winning score, paddle, CPU speed)
 *   bpong.async.php - the [bpong:game] shortcode, the admin screen, class bpongui
 *   bpong.js        - the game itself, ported from lib/bitcoin-pong
 *
 * The game is played entirely in the visitor's browser. Nothing about a match
 * reaches the server: no score is submitted, no endpoint writes anything, and
 * an anonymous visitor can play without a session being touched. That is what
 * makes it safe to drop into a public page.
 *
 * The module is optional and off by default: enable it under Site Settings →
 * Optional modules.
 */
include_once('bpong.db.php');
include_once('bpong.async.php'); //shortcode + endpoints + class bpongui
class bpong{
	public $bpongdb,$bpongui;
	public function __construct(){
		$this->bpongdb = new bpongdb();
		$this->bpongui = new bpongui();
	}

	//Render one board. Called by the shortcode; also usable from a theme.
	public function board(){
		return $this->bpongui->board($this->bpongdb->getSettings());
	}
}
?>
