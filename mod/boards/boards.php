<?php
/*
 * boards.php - the boards module's service object.
 *
 * Replaces the comments module: instead of every page carrying a comment list,
 * a board is placed deliberately with [board:slug] and configured in Admin ->
 * Boards. See boards.async.php for the endpoints and boards.sql for the shape.
 */
include_once('boards.db.php');
include_once('boards.async.php'); //endpoints + the [board:slug] shortcode
class boards{
	public $boardsdb;
	public function __construct(){
		$this->boardsdb = new boardsdb();
	}
}
?>
