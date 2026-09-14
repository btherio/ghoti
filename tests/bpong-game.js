/*
 * Run: node tests/bpong-game.js - no browser, no database, no network.
 *
 * The point of this file is that the port is checkable. mod/bpong/bpong.js is a
 * translation of lib/bitcoin-pong/bitcoin_pong.py, and a translation is only
 * worth anything if it behaves the same. The first three cases below are
 * test_bitcoin_pong.py's three cases, transliterated; the rest cover the
 * constants and edge cases the Python tests never had to state because curses
 * only ever ran one board at 60fps.
 *
 * BpongGame holds no reference to the document, which is what makes this
 * possible at all.
 */
'use strict';

const {BpongGame, BOARD_WIDTH, BOARD_HEIGHT} = require('../mod/bpong/bpong.js');

let checks = 0;
function check(condition, message){
	if(!condition){ throw new Error(message); }
	checks++;
}

//A fixed generator: the Python tests seeded random.Random(1), and the two
//languages cannot produce the same sequence, so the seam is the generator.
function game(options){
	return new BpongGame(Object.assign({random: () => 0.5}, options || {}));
}

/* ---- the three cases from test_bitcoin_pong.py ---- */

//test_player_paddle_bounces_coin
{
	const g = game();
	g.ballX = 3.1;
	g.ballY = g.playerY;
	g.ballVx = -20;
	g.ballVy = 0;
	check(g.update(0.01) === null, 'A bounced coin scored a point');
	check(g.ballVx > 0, 'The player paddle did not send the coin back');
}

//test_missed_coin_scores_for_cpu
{
	const g = game();
	g.ballX = 0.5;
	g.ballY = g.top();
	g.ballVx = -20;
	check(g.update(0.01) === 'cpu', 'A missed coin did not score for the CPU');
	check(g.cpuScore === 1, 'The CPU score did not advance');
}

//test_match_ends_at_seven
{
	const g = game();
	g.playerScore = g.winningScore - 1;
	g.ballX = g.width - 1.5;
	g.ballVx = 20;
	check(g.update(0.01) === 'player', 'The winning point did not score');
	check(g.winner === 'player', 'The match did not end at the winning score');
	check(g.ballVx === 0 && g.ballVy === 0, 'The coin kept moving after the match ended');
	check(g.update(0.01) === null, 'A finished match kept playing');
}

/* ---- the constants carried over from the Python file ---- */
{
	const g = game();
	check(g.width === BOARD_WIDTH && g.width === 80, 'Board width changed');
	check(g.height === BOARD_HEIGHT && g.height === 24, 'Board height changed');
	check(g.winningScore === 7, 'Winning score is not the original 7');
	check(g.paddleHeight === 5, 'Paddle height is not the original 5');
	check(g.cpuSpeed === 15.5, 'CPU speed is not the original 15.5 cells/sec');
	check(g.top() === 3.0, 'Top rule moved');
	check(g.bottom() === 22.0, 'Bottom rule moved');
	check(Math.abs(g.ballVx) === 22.0, 'Serve speed is not 22');

	//Serve: vy is drawn from uniform(6.5, 10.0); the fixed generator lands
	//exactly halfway, which is a value the range must contain.
	check(Math.abs(g.ballVy) === 8.25, 'Serve vertical speed left the 6.5-10.0 band');

	//Paddle speed: 28 cells a second. Measured over a step small enough not to
	//run into the clamp, which is asserted separately below.
	const before = g.playerY;
	g.movePlayer(1, 0.1);
	check(Math.abs((g.playerY - before) - 2.8) < 1e-9, 'Paddle speed is not 28 cells/sec');
}

/* ---- bounce shaping ---- */
{
	//Struck off-centre, the return is angled: that is the only control a player
	//has over where the coin goes, so it is worth pinning.
	const g = game();
	g.ballVx = -20; g.ballVy = 0;
	g.ballY = g.playerY + 2;          //below centre
	g.bounce(g.playerY, 1);
	check(g.ballVx > 0, 'Bounce did not reverse direction');
	check(g.ballVy > 0, 'An off-centre hit did not angle the return downward');
	check(g.ballX === 3.4, 'The coin was not nudged clear of the player paddle');

	const h = game();
	h.ballVx = 20; h.ballVy = 0;
	h.ballY = h.cpuY - 2;             //above centre
	h.bounce(h.cpuY, -1);
	check(h.ballVy < 0, 'An off-centre hit did not angle the return upward');
	check(h.ballX === h.width - 4.4, 'The coin was not nudged clear of the CPU paddle');

	//Speed is capped at 38 and never drops below 19, however hard it is hit.
	const fast = game();
	fast.ballVx = -300; fast.ballVy = 300;
	fast.ballY = fast.playerY;
	fast.bounce(fast.playerY, 1);
	check(fast.ballVx <= 38.0, 'Bounce exceeded the speed cap');
	check(fast.ballVx >= 19.0, 'Bounce fell under the minimum speed');
	check(Math.abs(fast.ballVy) <= 17.0, 'Vertical speed exceeded the clamp');

	const slow = game();
	slow.ballVx = -1; slow.ballVy = 0;
	slow.ballY = slow.playerY;
	slow.bounce(slow.playerY, 1);
	check(slow.ballVx === 19.0, 'A slow coin did not come off the paddle at the floor speed');
}

/* ---- walls, paddles and the crossing test ---- */
{
	//The ball reflects off both rules rather than escaping the field.
	const g = game();
	g.ballX = g.width / 2; g.ballVx = 0;
	g.ballY = g.top() + 0.05; g.ballVy = -20;
	g.update(0.02);
	check(g.ballVy > 0, 'The coin did not reflect off the top rule');
	check(g.ballY >= g.top(), 'The coin went through the top rule');

	g.ballY = g.bottom() - 0.05; g.ballVy = 20;
	g.update(0.02);
	check(g.ballVy < 0, 'The coin did not reflect off the bottom rule');
	check(g.ballY <= g.bottom(), 'The coin went through the bottom rule');
}
{
	//A miss by more than the paddle's half-height plus half a cell is a point,
	//not a save.
	const g = game();
	g.playerY = 10;
	g.ballY = 10 + g.paddleHeight / 2 + 0.6;
	check(!g.hits(g.playerY), 'A clear miss registered as a hit');
	g.ballY = 10 + g.paddleHeight / 2 + 0.4;
	check(g.hits(g.playerY), 'A hit on the paddle edge was missed');
}
{
	//The crossing test, not an overlap test: at 38 cells/second and a 0.05s
	//frame the coin moves nearly two cells, so an overlap test would let it
	//through the paddle. This is the case that would silently regress.
	const g = game();
	g.playerY = 12;
	g.ballY = 12;
	g.ballX = 3.0 + 1.8;
	g.ballVx = -38;
	g.ballVy = 0;
	const result = g.update(0.05);   //carries the coin from 4.8 to about 2.9
	check(result === null, 'A fast coin passed through the paddle and scored');
	check(g.ballVx > 0, 'A fast coin was not returned by the paddle');
}

/* ---- paddles stay on the field ---- */
{
	const g = game();
	const half = g.paddleHeight / 2;
	g.movePlayer(-1, 10);
	check(g.playerY >= g.top() + half - 1e-9, 'The paddle left the field at the top');
	g.movePlayer(1, 10);
	check(g.playerY <= g.bottom() - half + 1e-9, 'The paddle left the field at the bottom');
}

/* ---- the CPU is beatable ---- */
{
	//It tracks with a speed limit and a standing error. Both are what make the
	//game winnable, so both are asserted rather than assumed.
	const g = game();
	g.aiError = 0;
	g.cpuY = 10;
	g.ballY = 20;
	g.ballVx = 22;          //coming toward the CPU
	const start = g.cpuY;
	g.update(0.1);
	check(g.cpuY > start, 'The CPU did not track the coin');
	check(g.cpuY - start <= g.cpuSpeed * 0.1 + 1e-9, 'The CPU exceeded its speed limit');

	//With the coin going away from it, the CPU returns to centre instead of
	//shadowing it - otherwise it would be unbeatable.
	const h = game();
	h.aiError = 0;
	h.cpuY = 20;
	h.ballY = 20;
	h.ballVx = -22;
	h.update(0.1);
	check(h.cpuY < 20, 'The CPU shadowed a coin travelling away from it');
}

/* ---- settings actually change the game ---- */
{
	const short = game({winningScore: 2});
	short.playerScore = 1;
	short.ballX = short.width - 1.5;
	short.ballVx = 20;
	check(short.update(0.01) === 'player' && short.winner === 'player', 'A shorter match did not end early');

	const big = game({paddleHeight: 11});
	big.playerY = 12;
	big.ballY = 12 + 5;
	check(big.hits(big.playerY), 'A taller paddle did not cover more of the field');

	const quick = game({cpuSpeed: 260});
	check(quick.cpuSpeed === 26.0, 'CPU speed is not stored as tenths');
}

/* ---- a match can be restarted ---- */
{
	const g = game();
	g.playerScore = 3; g.cpuScore = 4; g.winner = 'cpu'; g.paused = true;
	g.resetMatch();
	check(g.playerScore === 0 && g.cpuScore === 0, 'Restart did not clear the score');
	check(g.winner === null, 'Restart did not clear the winner');
	check(g.paused === false, 'Restart left the match paused');
	check(Math.abs(g.ballVx) === 22.0, 'Restart did not serve');
}

/* ---- a paused match does not advance ---- */
{
	const g = game();
	g.paused = true;
	const x = g.ballX, y = g.ballY;
	check(g.update(0.05) === null, 'A paused match scored');
	check(g.ballX === x && g.ballY === y, 'A paused match kept moving');
}

/* ---- instances are independent ---- */
{
	//A page can hold more than one [bpong:game]. Nothing may be shared between
	//two boards - the failure mode would be two games with one ball.
	const a = game();
	const b = game();
	a.playerScore = 5;
	a.ballX = 40;
	check(b.playerScore === 0, 'Two boards shared a score');
	check(b.ballX !== 40 || a !== b, 'Two boards shared state');
	b.update(0.02);
	check(a.ballX === 40, 'Updating one board moved another');
}

console.log(`PASS: ${checks} pong physics assertions; no browser, no database, no network`);
