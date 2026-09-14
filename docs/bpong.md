# Pong

An optional module that puts a playable Bitcoin Pong board on any page. Off by
default: enable it under **Site Settings → Optional modules**, then type
`[bpong:game]` into a page where the board should appear.

```
[bpong:game]
```

That is the whole setup. The board needs no configuration to work, visitors do
not have to sign in, and nothing about a match is recorded.

## Where it came from

`lib/bitcoin-pong/bitcoin_pong.py` is a curses game — it draws with character
cells in a terminal and cannot run in a browser at all. So this module is a
port, not a wrapper, and the port keeps the part that matters: the physics.

The game still thinks in the terminal's coordinates, a grid **80 cells by 24**,
and the canvas only scales cells to pixels when it draws. That is why the
constants in `mod/bpong/bpong.js` are the numbers from the Python file rather
than values retuned by eye:

| | |
| --- | --- |
| Paddle speed | 28 cells/second |
| Serve | 22 horizontal, 6.5–10.0 vertical |
| Bounce | capped at 38, floor of 19, vertical clamped to ±17 |
| CPU | 15.5 cells/second, with a ±2.0 standing error |
| Field | rules at row 3 and row *height*−2; paddles at column 3 and *width*−4 |
| Match | first to 7 |

Working in cell space is what makes the port checkable instead of eyeballed:
`tests/bpong-game.js` runs the same three cases `test_bitcoin_pong.py` runs,
plus the constants and the edge cases above.

The original file stays in `lib/bitcoin-pong` — it was not removed, and it is
still the reference for anything this port gets wrong.

## Playing

- **W** / **S** or the **arrow keys** move your paddle
- **P** pauses, **R** restarts, **Enter** starts
- On a touch screen, tap the upper or lower half of the board

**The keys only work while the board has focus.** Click or tab to the board to
play; a reader who is not playing keeps their arrow keys and the page scrolls
normally. The focus ring is not decoration here — it is the only way to tell
whether the board is listening. A board that loses focus pauses rather than
running a match out while the page is scrolled past.

**Pause** and **Restart** buttons sit under the board for anyone who cannot use
the keys at all.

## Settings

**Admin Menu → Pong**, with a live preview under the form.

| Setting | Range | Default |
| --- | --- | --- |
| Points to win | 1–21 | 7 |
| Paddle height | 3–11 cells | 5 |
| CPU difficulty | 6.0–30.0 cells/sec | Original (15.5) |
| Key legend | on/off | on |

Every value is clamped rather than rejected: they are bounded inputs on a form,
and an out-of-range number makes an odd board, not a security problem. The
clamp is applied in `bpongdb` as well as in the endpoint, because the values are
printed into the page as a config attribute.

CPU speed is stored as tenths (`155` = 15.5 cells/second) so the original
constant survives an integer column. A speed saved outside the preset list still
appears in the form as *Custom*, rather than silently resetting on the next save.

## What it does not do

- **No scores are kept.** Not per visitor, not site-wide. This is the whole
  reason the module is safe to drop into a public page: a leaderboard means an
  endpoint an anonymous visitor can write to, and that is a much bigger decision
  than a pong game deserves. There is no score table and no endpoint a player
  can reach — the only two endpoints are the admin screen and its save, both
  behind the admin gate.
- **No two-player mode.** The original was single-player against the CPU.
- **No sound.**
- **The board does not resize the field.** The terminal game reflowed when the
  window changed; here the field is a fixed 80×24 and the canvas scales, which
  is why the CSS pins `aspect-ratio: 80 / 24`. Removing that makes the cells
  non-square and the ball visibly drifts.

## Turning it off

Unticking the module in Site Settings stops the script and stylesheet loading
and removes the admin screen. A page that still contains `[bpong:game]` will
show **the literal text** `[bpong:game]`, because the shortcode engine leaves
unknown tags untouched — that is how every module's shortcode behaves. Remove
the shortcode from the page as well if the module is going away for good.

Saved settings are left alone, so turning it back on restores what you had.

## Files

| File | What it is |
| --- | --- |
| `mod/bpong/bpong.php` | Entry point |
| `mod/bpong/bpong.db.php` | The settings row, and the clamp |
| `mod/bpong/bpong.async.php` | `[bpong:game]`, the admin screen, `bpongui` |
| `mod/bpong/bpong.js` | `BpongGame` (the port) and `BpongBoard` (canvas, input, frame loop) |
| `mod/bpong/bpong.sql` | One settings table, named `bpong` |
| `tests/bpong-game.js` | `node tests/bpong-game.js` — the physics, against the Python original's cases |
| `tests/bpong.php` | Shortcode, admin gate, clamp, markup |
| `tests/bpong-disabled.php` | Off by default, and off means absent |

Boards are set up by `bpongInit()`, which runs on `DOMContentLoaded` **and**
again from `printPage()` in `ghoti.js`. That second call is not optional: this
app replaces the page content through `printPage()` long after
`DOMContentLoaded` — for in-app navigation and for the admin preview alike — so
a board that only waited on that event would render as dead markup. Most modules
never hit this because they drive their markup with inline `onclick` attributes,
which survive having the content replaced; a canvas with bound listeners does
not. `bpongInit()` skips any board carrying `data-bpong-ready`, so calling it
repeatedly is safe.

`BpongGame` holds no reference to the document, which is what lets `node` require
it and test the physics without a browser. Keep it that way — the moment it
touches `document`, the port stops being verifiable.

No content-security-policy change was needed: the board is a canvas driven by a
same-origin script, with no third-party origin involved. `tests/bpong.php`
asserts that the policy block does not mention the module, so a future change
that needs one is a deliberate decision rather than an accident.

## What is not verified

The physics are tested directly and the PHP half is tested against a fake
database. What is **not** covered by an automated test is the browser half:
canvas drawing, the frame loop, focus handling and touch input were written to
the documented APIs but have not been run in a browser here. Open a page with
`[bpong:game]` on it and play a point before trusting them.

As elsewhere in this CMS, the database paths are verified against fakes — this
host has no database driver.
