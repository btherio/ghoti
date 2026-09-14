#!/usr/bin/env python3
"""Bitcoin Pong: a small, dependency-free terminal game."""

from __future__ import annotations

import argparse
import curses
import locale
import math
import random
import time
from dataclasses import dataclass


WINNING_SCORE = 7
MIN_WIDTH = 54
MIN_HEIGHT = 18
FRAME_TIME = 1 / 60


@dataclass
class Game:
    width: int
    height: int
    rng: random.Random
    paddle_height: int = 5
    player_score: int = 0
    cpu_score: int = 0
    paused: bool = False
    winner: str | None = None

    def __post_init__(self) -> None:
        self.player_y = self.height / 2
        self.cpu_y = self.height / 2
        self.ball_x = self.width / 2
        self.ball_y = self.height / 2
        self.ball_vx = 0.0
        self.ball_vy = 0.0
        self.ai_error = 0.0
        self.serve(direction=self.rng.choice((-1, 1)))

    @property
    def top(self) -> float:
        return 3.0

    @property
    def bottom(self) -> float:
        return self.height - 2.0

    def serve(self, direction: int) -> None:
        self.ball_x = self.width / 2
        self.ball_y = (self.top + self.bottom) / 2
        self.ball_vx = direction * 22.0
        self.ball_vy = self.rng.choice((-1, 1)) * self.rng.uniform(6.5, 10.0)
        self.ai_error = self.rng.uniform(-2.0, 2.0)

    def reset_match(self) -> None:
        self.player_score = self.cpu_score = 0
        self.winner = None
        self.player_y = self.cpu_y = self.height / 2
        self.serve(direction=self.rng.choice((-1, 1)))

    def resize(self, width: int, height: int) -> None:
        old_width, old_height = self.width, self.height
        self.width, self.height = width, height
        if old_width > 0 and old_height > 0:
            self.ball_x *= width / old_width
            self.ball_y *= height / old_height
            self.player_y *= height / old_height
            self.cpu_y *= height / old_height
        self._clamp_paddles()

    def move_player(self, direction: int, dt: float) -> None:
        self.player_y += direction * 28.0 * dt
        self._clamp_paddles()

    def _clamp_paddles(self) -> None:
        half = self.paddle_height / 2
        low, high = self.top + half, self.bottom - half
        self.player_y = min(high, max(low, self.player_y))
        self.cpu_y = min(high, max(low, self.cpu_y))

    def update(self, dt: float) -> str | None:
        """Advance the game and return 'player'/'cpu' when a point is scored."""
        if self.paused or self.winner:
            return None

        # The CPU tracks the coin but has limited speed and a shifting error.
        target = self.ball_y + self.ai_error
        if self.ball_vx < 0:
            target = (self.top + self.bottom) / 2
        delta = target - self.cpu_y
        max_step = 15.5 * dt
        self.cpu_y += min(max_step, max(-max_step, delta))
        self._clamp_paddles()

        previous_x = self.ball_x
        self.ball_x += self.ball_vx * dt
        self.ball_y += self.ball_vy * dt

        if self.ball_y <= self.top:
            self.ball_y = self.top + (self.top - self.ball_y)
            self.ball_vy = abs(self.ball_vy)
        elif self.ball_y >= self.bottom:
            self.ball_y = self.bottom - (self.ball_y - self.bottom)
            self.ball_vy = -abs(self.ball_vy)

        player_x, cpu_x = 3.0, self.width - 4.0
        if self.ball_vx < 0 and previous_x >= player_x and self.ball_x <= player_x:
            if self._hits(self.player_y):
                self._bounce(self.player_y, 1)
        elif self.ball_vx > 0 and previous_x <= cpu_x and self.ball_x >= cpu_x:
            if self._hits(self.cpu_y):
                self._bounce(self.cpu_y, -1)
                self.ai_error = self.rng.uniform(-2.4, 2.4)

        if self.ball_x < 1:
            return self._score("cpu")
        if self.ball_x > self.width - 2:
            return self._score("player")
        return None

    def _hits(self, paddle_y: float) -> bool:
        return abs(self.ball_y - paddle_y) <= self.paddle_height / 2 + 0.5

    def _bounce(self, paddle_y: float, direction: int) -> None:
        offset = (self.ball_y - paddle_y) / (self.paddle_height / 2)
        speed = min(38.0, math.hypot(self.ball_vx, self.ball_vy) * 1.055)
        self.ball_vx = direction * max(19.0, speed * 0.91)
        self.ball_vy = max(-17.0, min(17.0, offset * 15.0 + self.ball_vy * 0.25))
        self.ball_x = 3.4 if direction > 0 else self.width - 4.4

    def _score(self, side: str) -> str:
        if side == "player":
            self.player_score += 1
            direction = -1
        else:
            self.cpu_score += 1
            direction = 1
        if max(self.player_score, self.cpu_score) >= WINNING_SCORE:
            self.winner = side
            self.ball_vx = self.ball_vy = 0
        else:
            self.serve(direction)
        return side


def safe_addstr(screen: curses.window, y: int, x: int, text: str, attrs: int = 0) -> None:
    """Draw while quietly ignoring the lower-right-corner curses quirk."""
    height, width = screen.getmaxyx()
    if 0 <= y < height and x < width:
        try:
            screen.addnstr(y, max(0, x), text[max(0, -x):], max(0, width - max(0, x)), attrs)
        except curses.error:
            pass


def draw(screen: curses.window, game: Game, coin: str, colors: dict[str, int]) -> None:
    screen.erase()
    height, width = screen.getmaxyx()
    if width < MIN_WIDTH or height < MIN_HEIGHT:
        message = f"Terminal too small — resize to at least {MIN_WIDTH}x{MIN_HEIGHT}"
        safe_addstr(screen, height // 2, max(0, (width - len(message)) // 2), message, curses.A_BOLD)
        screen.refresh()
        return

    safe_addstr(screen, 0, 2, " BITCOIN PONG ", colors["coin"] | curses.A_BOLD)
    score = f"YOU  {game.player_score}   ◈   {game.cpu_score}  CPU"
    safe_addstr(screen, 0, (width - len(score)) // 2, score, curses.A_BOLD)
    safe_addstr(screen, 0, width - 30, " W/S or ↑/↓  P pause  Q quit ", curses.A_DIM)
    safe_addstr(screen, 2, 0, "═" * width, colors["edge"])
    safe_addstr(screen, height - 1, 0, "═" * width, colors["edge"])

    for y in range(3, height - 1):
        safe_addstr(screen, y, width // 2, "·", colors["net"])

    half = game.paddle_height // 2
    for offset in range(-half, half + 1):
        safe_addstr(screen, int(round(game.player_y)) + offset, 3, "█", colors["player"])
        safe_addstr(screen, int(round(game.cpu_y)) + offset, width - 4, "█", colors["cpu"])

    bx, by = int(round(game.ball_x)), int(round(game.ball_y))
    safe_addstr(screen, by, bx, coin, colors["coin"] | curses.A_BOLD)

    if game.paused:
        banner = "  PAUSED — press P to continue  "
        safe_addstr(screen, height // 2, (width - len(banner)) // 2, banner, curses.A_REVERSE)
    elif game.winner:
        result = "YOU WIN!" if game.winner == "player" else "CPU WINS"
        banner = f"  {result} — R rematch · Q quit  "
        safe_addstr(screen, height // 2, (width - len(banner)) // 2, banner, colors["coin"] | curses.A_BOLD)
    screen.refresh()


def setup_colors() -> dict[str, int]:
    colors = {"coin": curses.A_BOLD, "edge": 0, "net": curses.A_DIM, "player": 0, "cpu": 0}
    if curses.has_colors():
        curses.start_color()
        curses.use_default_colors()
        curses.init_pair(1, curses.COLOR_YELLOW, -1)
        curses.init_pair(2, curses.COLOR_CYAN, -1)
        curses.init_pair(3, curses.COLOR_MAGENTA, -1)
        curses.init_pair(4, curses.COLOR_BLUE, -1)
        colors.update(coin=curses.color_pair(1), player=curses.color_pair(2),
                      cpu=curses.color_pair(3), edge=curses.color_pair(4), net=curses.color_pair(4))
    return colors


def play(screen: curses.window, seed: int | None = None) -> None:
    try:
        curses.curs_set(0)
    except curses.error:
        # A few minimal terminal emulators do not support cursor visibility.
        pass
    screen.nodelay(True)
    screen.keypad(True)
    colors = setup_colors()
    height, width = screen.getmaxyx()
    game = Game(width, height, random.Random(seed))
    coin = "₿" if "UTF" in locale.getpreferredencoding().upper() else "B"
    held_direction = 0
    held_until = 0.0
    last = time.monotonic()

    while True:
        now = time.monotonic()
        dt = min(0.05, now - last)
        last = now
        height, width = screen.getmaxyx()
        if (width, height) != (game.width, game.height):
            game.resize(width, height)

        key = screen.getch()
        while key != -1:
            if key in (ord("q"), ord("Q"), 27):
                return
            if key in (ord("p"), ord("P")) and not game.winner:
                game.paused = not game.paused
            elif key in (ord("r"), ord("R")):
                game.reset_match()
                game.paused = False
            elif key in (curses.KEY_UP, ord("w"), ord("W")):
                held_direction, held_until = -1, now + 0.11
            elif key in (curses.KEY_DOWN, ord("s"), ord("S")):
                held_direction, held_until = 1, now + 0.11
            key = screen.getch()

        if now > held_until:
            held_direction = 0
        if width >= MIN_WIDTH and height >= MIN_HEIGHT:
            game.move_player(held_direction, dt)
            game.update(dt)
        draw(screen, game, coin, colors)
        delay = FRAME_TIME - (time.monotonic() - now)
        if delay > 0:
            time.sleep(delay)


def main() -> None:
    parser = argparse.ArgumentParser(description="Play Bitcoin Pong in your terminal.")
    parser.add_argument("--seed", type=int, help=argparse.SUPPRESS)
    args = parser.parse_args()
    try:
        curses.wrapper(play, args.seed)
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
