# Boards

An optional module that puts a message board or a comment section anywhere you
choose. Off by default: enable it under **Site Settings → Optional modules**,
create a board under **Admin Menu → Boards**, then type its shortcode into a
page where it should appear.

```
[board:general]
```

## What replaced what

Boards replace the old comments module, and the difference is where discussion
lives.

Commenting used to be a property of *every page*. `getPage()` appended a comment
list and a comment button to whatever you were looking at, and the page id came
from the session — so a comment belonged to the page that happened to be open.
There was no way to have discussion on one page and not another, and no way to
have two separate conversations.

A board is a thing you **place**. Nothing appears on a page unless a
`[board:slug]` tag puts it there, the same board can be placed on more than one
page (it is one conversation either way), and a page with no tag has no
discussion on it at all. Deleting a page deletes the tag, not the conversation;
boards are removed under **Admin Menu → Boards**.

## The two modes

Both modes are the same renderer, with the topic list turned off for one of
them. They are not separate features, and a board can be switched between them
at any time without touching its posts.

| Mode | What it shows | Use it for |
| --- | --- | --- |
| **Message board** | A list of topics, each opening into its own thread | A forum with several conversations |
| **Comment section** | One flat stream of posts, no topic list | The foot of a page you want commentable |

A comment section keeps its single thread implicitly — it is created by the
first comment, so a page that has never been commented on needs no setup.

## Configuring a board

Each board in **Admin Menu → Boards** carries:

| Setting | Meaning |
| --- | --- |
| **Name** | What readers see at the top of the board |
| **Shortcode name** | What `[board:...]` names. Leave it blank to derive it from the name |
| **Description** | One line under the name; optional |
| **Mode** | Message board, or comment section (above) |
| **Readers** | Anyone, or signed-in users only |
| **Posting** | Signed-in users, or moderators only |
| **Locked** | Read-only for everyone but the board's moderators |
| **Order** | Sorts the admin list; it does not affect the page |

Spaces and underscores in a shortcode name fold to hyphens, so a board called
*Trip Reports* is `[board:trip-reports]`. The shortcode syntax only carries
letters, numbers, `_`, `.` and `-`, so a name that needs anything else is
refused rather than saved as a board no page could reach. Use **Copy** beside a
board to grab its exact shortcode.

## Moderators and post counts

Moderators are named per board, in **Admin Menu → Users**. Each account there
shows its total post count across every board, and the boards it moderates;
press **Moderates** to change them.

A moderator of a board may:

- edit or delete any post on it
- lock, pin, or delete any of its topics
- post on it even when it is locked or set to moderators-only

They have no powers on a board they are not named on. Administrators moderate
every board without being listed, which is why an admin's row reads *All
boards*.

Post counts are derived, not stored — they are counted from the posts
themselves whenever they are shown. Deleting a post, a topic, a board or an
account keeps them correct with no bookkeeping.

## Reply notifications

Any signed-in member can ask to be e-mailed when someone replies, under
**Your account → Notifications** (the item appears only while boards are
enabled). A *reply* is any new post in a thread they have posted in; in a
comment section, where the whole board is one thread, that means any later
comment.

- **Opt-in.** Nobody receives anything until they tick the box. The choice is
  stored per account in `board_notify` and removed with the account.
- **Who is mailed.** Other participants of that thread who opted in and have a
  valid address — never the author of the new post, and each address once.
- **What they get.** A themed message naming the author and the thread, with an
  excerpt of the post (first 600 characters). Its footer says how to turn
  notices off.
- **Limits.** Notices are sent during the request that saved the post, capped
  at 25 per post so a busy thread cannot stall the poster. Nothing is sent
  while site mail (**Site Settings → Mail**) is off, and a mail failure is
  logged but never turns a saved post into an error.

`tests/board-notify.php` covers recipient selection, the author exclusion, the
mail-off case and the menu gating.

## What is enforced where

Every check is made on the server, in `mod/boards/boards.async.php`, and fails
closed. The read policy, the post policy, the board and topic locks, and the
"your own post, or a board you moderate" rule for editing and deleting are all
re-checked on each request — the buttons the browser draws are a convenience,
never the control. `tests/boards.php` asserts each of those refusals directly
against the endpoints.

Post bodies are stored and rendered as plain text; markup in a post is escaped,
not interpreted.

## Turning it off

Unticking **Boards** hides the module, its admin screen and its placed boards. A
`[board:...]` tag on a page renders as nothing. No board, topic, post or
moderator grant is deleted, so turning it back on restores everything.
