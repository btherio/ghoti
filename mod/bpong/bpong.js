/*
 * bpong.js - Bitcoin Pong in the page.
 *
 * A port of lib/bitcoin-pong/bitcoin_pong.py, which is a curses game and cannot
 * run in a browser. What survives the port is the part worth keeping: the
 * physics.
 *
 * The game still thinks in the terminal's coordinates - a grid of character
 * cells, 80 by 24 - and the canvas only scales those cells to pixels when it
 * draws. That is why every constant below is the number from the Python file
 * rather than something retuned by eye: paddle speed 28 cells/second, a serve
 * at 22, a bounce capped at 38, the ±17 vertical clamp. Rewriting them in pixel
 * space would have made the port unverifiable; in cell space, tests/bpong.js
 * can run the same three assertions test_bitcoin_pong.py runs.
 *
 * BpongGame holds no reference to the document, which is what lets it be
 * required into node and tested without a browser.
 */

(function(global){
	'use strict';

	var BOARD_WIDTH = 80;   //cells; the terminal game demanded at least 54
	var BOARD_HEIGHT = 24;  //cells; ...and at least 18

	function BpongGame(options){
		options = options || {};
		this.width = options.width || BOARD_WIDTH;
		this.height = options.height || BOARD_HEIGHT;
		this.paddleHeight = options.paddleHeight || 5;
		this.winningScore = options.winningScore || 7;
		//Cells per second the CPU paddle can close. Stored as tenths in the
		//database so the original 15.5 survives an integer column.
		this.cpuSpeed = (options.cpuSpeed || 155) / 10;
		//Injectable so a test is deterministic. Python seeded random.Random;
		//the sequences cannot match across languages, so the seam is the
		//generator itself rather than a seed.
		this.random = options.random || Math.random;

		this.playerScore = 0;
		this.cpuScore = 0;
		this.paused = false;
		this.winner = null;
		this.playerY = this.height / 2;
		this.cpuY = this.height / 2;
		this.ballX = this.width / 2;
		this.ballY = this.height / 2;
		this.ballVx = 0;
		this.ballVy = 0;
		this.aiError = 0;
		this.serve(this.random() < 0.5 ? -1 : 1);
	}

	//The playing field is inset: two rows of chrome at the top, one at the
	//bottom, exactly as the terminal drew its rules.
	BpongGame.prototype.top = function(){ return 3.0; };
	BpongGame.prototype.bottom = function(){ return this.height - 2.0; };

	BpongGame.prototype.uniform = function(low, high){
		return low + this.random() * (high - low);
	};

	BpongGame.prototype.serve = function(direction){
		this.ballX = this.width / 2;
		this.ballY = (this.top() + this.bottom()) / 2;
		this.ballVx = direction * 22.0;
		this.ballVy = (this.random() < 0.5 ? -1 : 1) * this.uniform(6.5, 10.0);
		this.aiError = this.uniform(-2.0, 2.0);
	};

	BpongGame.prototype.resetMatch = function(){
		this.playerScore = 0;
		this.cpuScore = 0;
		this.winner = null;
		this.paused = false;
		this.playerY = this.cpuY = this.height / 2;
		this.serve(this.random() < 0.5 ? -1 : 1);
	};

	BpongGame.prototype.movePlayer = function(direction, dt){
		this.playerY += direction * 28.0 * dt;
		this.clampPaddles();
	};

	BpongGame.prototype.clampPaddles = function(){
		var half = this.paddleHeight / 2;
		var low = this.top() + half;
		var high = this.bottom() - half;
		this.playerY = Math.min(high, Math.max(low, this.playerY));
		this.cpuY = Math.min(high, Math.max(low, this.cpuY));
	};

	/* Advance the match. Returns 'player' or 'cpu' when a point is scored. */
	BpongGame.prototype.update = function(dt){
		if(this.paused || this.winner){ return null; }

		//The CPU tracks the coin but has a speed limit and a standing error, so
		//it is beatable. It gives up and returns to centre once the ball is
		//travelling away from it.
		var target = this.ballY + this.aiError;
		if(this.ballVx < 0){ target = (this.top() + this.bottom()) / 2; }
		var delta = target - this.cpuY;
		var maxStep = this.cpuSpeed * dt;
		this.cpuY += Math.min(maxStep, Math.max(-maxStep, delta));
		this.clampPaddles();

		var previousX = this.ballX;
		this.ballX += this.ballVx * dt;
		this.ballY += this.ballVy * dt;

		if(this.ballY <= this.top()){
			this.ballY = this.top() + (this.top() - this.ballY);
			this.ballVy = Math.abs(this.ballVy);
		}else if(this.ballY >= this.bottom()){
			this.ballY = this.bottom() - (this.ballY - this.bottom());
			this.ballVy = -Math.abs(this.ballVy);
		}

		//Crossing test, not an overlap test: at 38 cells a second the ball can
		//pass a paddle between two frames, and only the crossing catches that.
		var playerX = 3.0;
		var cpuX = this.width - 4.0;
		if(this.ballVx < 0 && previousX >= playerX && this.ballX <= playerX){
			if(this.hits(this.playerY)){ this.bounce(this.playerY, 1); }
		}else if(this.ballVx > 0 && previousX <= cpuX && this.ballX >= cpuX){
			if(this.hits(this.cpuY)){
				this.bounce(this.cpuY, -1);
				this.aiError = this.uniform(-2.4, 2.4);
			}
		}

		if(this.ballX < 1){ return this.score('cpu'); }
		if(this.ballX > this.width - 2){ return this.score('player'); }
		return null;
	};

	BpongGame.prototype.hits = function(paddleY){
		return Math.abs(this.ballY - paddleY) <= this.paddleHeight / 2 + 0.5;
	};

	BpongGame.prototype.bounce = function(paddleY, direction){
		//Where the coin struck the paddle decides the angle, so a player has
		//some control over the return rather than only blocking.
		var offset = (this.ballY - paddleY) / (this.paddleHeight / 2);
		var speed = Math.min(38.0, Math.hypot(this.ballVx, this.ballVy) * 1.055);
		this.ballVx = direction * Math.max(19.0, speed * 0.91);
		this.ballVy = Math.max(-17.0, Math.min(17.0, offset * 15.0 + this.ballVy * 0.25));
		this.ballX = direction > 0 ? 3.4 : this.width - 4.4;
	};

	BpongGame.prototype.score = function(side){
		var direction;
		if(side === 'player'){ this.playerScore += 1; direction = -1; }
		else { this.cpuScore += 1; direction = 1; }
		if(Math.max(this.playerScore, this.cpuScore) >= this.winningScore){
			this.winner = side;
			this.ballVx = this.ballVy = 0;
		}else{
			this.serve(direction);
		}
		return side;
	};

	/* ---------------------------------------------------------------- *
	 *  The board: canvas, input and the frame loop.
	 * ---------------------------------------------------------------- */

	function BpongBoard(root){
		this.root = root;
		this.canvas = root.querySelector('canvas');
		this.status = root.querySelector('[data-bpong-status]');
		this.scoreLine = root.querySelector('[data-bpong-score]');
		var config = {};
		try{ config = JSON.parse(root.getAttribute('data-bpong-config') || '{}'); }
		catch(e){ config = {}; }
		this.game = new BpongGame(config);
		this.held = 0;
		this.heldUntil = 0;
		this.running = false;
		this.focused = false;
		this.last = 0;
		this.bind();
		this.draw();
		this.announce('Press Enter or click the board to play.');
	}

	BpongBoard.prototype.bind = function(){
		var board = this;
		var canvas = this.canvas;

		//Everything binds to the canvas, never to the document: a page can hold
		//more than one [bpong:game], and a reader who is not playing must keep
		//their arrow keys. The canvas carries tabindex="0" so it can hold focus.
		canvas.addEventListener('focus', function(){ board.focused = true; board.start(); });
		canvas.addEventListener('blur', function(){
			board.focused = false;
			board.held = 0;
			//Pausing on blur is the polite reading of "the reader looked away":
			//a match does not silently run out while the page is scrolled past.
			if(!board.game.winner){ board.game.paused = true; board.draw(); board.announce('Paused - the board lost focus.'); }
		});
		canvas.addEventListener('mousedown', function(){ canvas.focus(); });

		canvas.addEventListener('keydown', function(event){
			var handled = board.key(event.key, true);
			//preventDefault only for keys the game actually consumed, and only
			//while focused - otherwise this steals page scrolling.
			if(handled){ event.preventDefault(); }
		});
		canvas.addEventListener('keyup', function(event){
			if(event.key === 'ArrowUp' || event.key === 'ArrowDown'
				|| event.key === 'w' || event.key === 'W' || event.key === 's' || event.key === 'S'){
				board.held = 0;
				event.preventDefault();
			}
		});

		//Touch: the two halves of the board move the paddle up and down.
		canvas.addEventListener('touchstart', function(event){
			var rect = canvas.getBoundingClientRect();
			var y = event.touches[0].clientY - rect.top;
			board.held = y < rect.height / 2 ? -1 : 1;
			board.heldUntil = Infinity;
			canvas.focus();
			event.preventDefault();
		}, {passive: false});
		canvas.addEventListener('touchend', function(){ board.held = 0; });

		var buttons = this.root.querySelectorAll('[data-bpong-action]');
		for(var i = 0; i < buttons.length; i++){
			buttons[i].addEventListener('click', function(event){
				board.key(event.currentTarget.getAttribute('data-bpong-action'), false);
				canvas.focus();
			});
		}
	};

	/* Returns true when the key belonged to the game. */
	BpongBoard.prototype.key = function(key, fromKeyboard){
		var game = this.game;
		switch(key){
			case 'w': case 'W': case 'ArrowUp':
				this.held = -1; this.heldUntil = this.now() + 0.11; this.start(); return true;
			case 's': case 'S': case 'ArrowDown':
				this.held = 1; this.heldUntil = this.now() + 0.11; this.start(); return true;
			case 'p': case 'P': case 'pause':
				if(!game.winner){
					game.paused = !game.paused;
					this.announce(game.paused ? 'Paused.' : 'Playing.');
					if(!game.paused){ this.start(); } else { this.draw(); }
				}
				return true;
			case 'r': case 'R': case 'restart':
				game.resetMatch();
				this.announce('New match.');
				this.start();
				return true;
			case 'Enter': case ' ':
				//Enter starts a keyboard player without needing a mouse.
				if(game.paused){ game.paused = false; this.announce('Playing.'); }
				this.start();
				return true;
			default:
				return false;
		}
	};

	BpongBoard.prototype.now = function(){
		return (global.performance && global.performance.now ? global.performance.now() : Date.now()) / 1000;
	};

	BpongBoard.prototype.start = function(){
		if(this.running){ return; }
		this.running = true;
		this.last = this.now();
		var board = this;
		var step = function(){
			if(!board.running){ return; }
			var now = board.now();
			//Capped exactly as the terminal loop capped it: a backgrounded tab
			//returns with a huge delta, and an uncapped one teleports the ball
			//through a paddle.
			var dt = Math.min(0.05, now - board.last);
			board.last = now;
			if(now > board.heldUntil){ board.held = 0; }
			board.game.movePlayer(board.held, dt);
			board.game.update(dt);
			board.draw();
			if(board.game.winner || board.game.paused){
				board.running = false;
				board.announce(board.game.winner
					? (board.game.winner === 'player' ? 'You win. Press R for a rematch.' : 'CPU wins. Press R for a rematch.')
					: 'Paused.');
				return;
			}
			global.requestAnimationFrame(step);
		};
		global.requestAnimationFrame(step);
	};

	BpongBoard.prototype.announce = function(message){
		if(this.status){ this.status.textContent = message; }
	};

	BpongBoard.prototype.draw = function(){
		var canvas = this.canvas;
		var ctx = canvas.getContext ? canvas.getContext('2d') : null;
		if(!ctx){ return; }
		var game = this.game;
		//One cell in pixels. The canvas is sized in CSS; the backing store is
		//set from its own width/height attributes, so cells stay square.
		var cw = canvas.width / game.width;
		var ch = canvas.height / game.height;
		var styles = global.getComputedStyle ? global.getComputedStyle(canvas) : null;
		var ink = function(name, fallback){
			if(!styles){ return fallback; }
			var value = styles.getPropertyValue(name);
			return value && value.trim() ? value.trim() : fallback;
		};

		ctx.fillStyle = ink('--bpong-bg', '#0b0d12');
		ctx.fillRect(0, 0, canvas.width, canvas.height);

		//Net.
		ctx.fillStyle = ink('--bpong-net', '#243049');
		for(var y = game.top(); y < game.bottom(); y += 1.6){
			ctx.fillRect(canvas.width / 2 - cw * 0.08, y * ch, cw * 0.16, ch);
		}
		//The rules the terminal drew as double lines.
		ctx.fillStyle = ink('--bpong-edge', '#2b6cb0');
		ctx.fillRect(0, (game.top() - 0.5) * ch, canvas.width, Math.max(1, ch * 0.14));
		ctx.fillRect(0, (game.bottom() + 0.5) * ch, canvas.width, Math.max(1, ch * 0.14));

		var half = game.paddleHeight / 2;
		ctx.fillStyle = ink('--bpong-player', '#38bdf8');
		ctx.fillRect(3 * cw - cw / 2, (game.playerY - half) * ch, cw, game.paddleHeight * ch);
		ctx.fillStyle = ink('--bpong-cpu', '#e879f9');
		ctx.fillRect((game.width - 4) * cw - cw / 2, (game.cpuY - half) * ch, cw, game.paddleHeight * ch);

		//The coin. A drawn ₿ rather than an image, so the board needs no assets.
		var radius = Math.min(cw, ch) * 0.85;
		ctx.fillStyle = ink('--bpong-coin', '#f7b32b');
		ctx.beginPath();
		ctx.arc(game.ballX * cw, game.ballY * ch, radius, 0, Math.PI * 2);
		ctx.fill();
		ctx.fillStyle = ink('--bpong-bg', '#0b0d12');
		ctx.font = 'bold ' + Math.round(radius * 1.6) + 'px system-ui, sans-serif';
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';
		ctx.fillText('₿', game.ballX * cw, game.ballY * ch + radius * 0.06);

		if(this.scoreLine){
			this.scoreLine.textContent = 'YOU ' + game.playerScore + '  ◈  ' + game.cpuScore + ' CPU';
		}
	};

	function bpongInit(scope){
		var roots = (scope || global.document).querySelectorAll('.ghotiBpong:not([data-bpong-ready])');
		for(var i = 0; i < roots.length; i++){
			roots[i].setAttribute('data-bpong-ready', '1');
			new BpongBoard(roots[i]);
		}
	}

	global.BpongGame = BpongGame;
	global.bpongInit = bpongInit;

	if(global.document && global.document.addEventListener){
		global.document.addEventListener('DOMContentLoaded', function(){ bpongInit(); });
	}

	//Required into node by tests/bpong.js, which needs the physics and nothing
	//else. Guarded so the same file is a plain script in the browser.
	if(typeof module !== 'undefined' && module.exports){
		module.exports = {BpongGame: BpongGame, BOARD_WIDTH: BOARD_WIDTH, BOARD_HEIGHT: BOARD_HEIGHT};
	}
})(typeof window !== 'undefined' ? window : globalThis);
