import random
import unittest

from bitcoin_pong import Game, WINNING_SCORE


class GameTests(unittest.TestCase):
    def game(self):
        return Game(80, 24, random.Random(1))

    def test_player_paddle_bounces_coin(self):
        game = self.game()
        game.ball_x = 3.1
        game.ball_y = game.player_y
        game.ball_vx = -20
        game.ball_vy = 0
        self.assertIsNone(game.update(0.01))
        self.assertGreater(game.ball_vx, 0)

    def test_missed_coin_scores_for_cpu(self):
        game = self.game()
        game.ball_x = 0.5
        game.ball_y = game.top
        game.ball_vx = -20
        self.assertEqual(game.update(0.01), "cpu")
        self.assertEqual(game.cpu_score, 1)

    def test_match_ends_at_seven(self):
        game = self.game()
        game.player_score = WINNING_SCORE - 1
        game.ball_x = game.width - 1.5
        game.ball_vx = 20
        self.assertEqual(game.update(0.01), "player")
        self.assertEqual(game.winner, "player")


if __name__ == "__main__":
    unittest.main()
