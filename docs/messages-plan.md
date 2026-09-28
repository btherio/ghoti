# Messages module — implementation plan

End-to-end encrypted private messaging, with a keyring each user controls.
This is a build plan for whoever implements it, not the user guide. That guide
is `docs/messages.md`, which you write in phase 6.

The module is optional and off by default, and it is built like `boards`. Where
this plan says "as boards does", open the matching boards file and copy its
structure.

---

## 0. Ground rules

These rules are not up for negotiation. Every later section depends on them.

1. **The server never sees a usable private key, a passphrase, a recovery code,
   or message plaintext.** Every encryption and decryption step runs in the
   browser with WebCrypto (`crypto.subtle`). PHP stores opaque blobs and checks
   their shape and length. This machine's PHP has no `sodium` extension, and
   the design does not need one.
2. **Don't invent cryptography.** Use only the constructions named in §2, with
   the parameters given there. Deviating from them requires review.
3. **The keyring is protected by a passphrase and a recovery code, and neither
   is the login password.** A password reset never touches the keyring. If a
   user loses both the passphrase and the recovery code, their message history
   is gone. That is intentional, it fails closed the same way two-factor does,
   and the UI states it at setup.
4. **Keys live only in the messages view.** Crypto code and unlocked keys never
   load on ordinary site pages. §4 explains why and how.
5. **Message content is rendered as text only.** Use `textContent`, never
   `innerHTML`, jQuery `.html()`, or markdown.

### What this does not protect against (goes in the user guide as well)

- **A compromised server, or a malicious admin, can serve modified JavaScript**
  that captures the passphrase. Every web app that does its encryption in the
  page has this weakness.
- **Metadata is visible to the server:** who messages whom, when, how often, and
  roughly how long each message is.
- **There is no forward secrecy in v1.** Someone who later gets a user's
  unlocked keyring can read that user's history.
- **Offline guessing:** anyone holding a database dump can try passphrases
  against the wrapped keyring. The KDF cost and the minimum passphrase length
  are the only defence.

---

## 1. Vocabulary

| Term | Meaning |
| --- | --- |
| **Keyring** | One per user. An encrypted JSON bundle holding the user's private keys (all versions), pinned contact keys, and verification marks. |
| **KK** | Keyring key. A random 256-bit AES-GCM key that encrypts the bundle. |
| **Passphrase wrap** | KK encrypted under a key derived from the passphrase. |
| **Recovery wrap** | KK encrypted under a key derived from the recovery code. |
| **Identity key** | A user's X25519 key pair (encryption) plus Ed25519 key pair (signing), versioned. |
| **Thread** | A conversation: `direct` (exactly 2 people) or `group`. |
| **Epoch** | A thread-key generation. It advances whenever membership shrinks or someone's identity key changes. |
| **TK** | Thread key. A random 256-bit AES-GCM key per (thread, epoch), wrapped separately for each member. |

---

## 2. Cryptography

All of this goes in **`mod/messages/messages.crypto.js`**. It is a pure module
with no DOM access, no jQuery, and no network calls, so it runs unchanged in the
browser and under `node` for tests. Everything else calls into it.

### 2.1 Encoding

- Binary values cross the wire as **base64url, unpadded**.
- Every length is fixed and checked on both ends (§5.3).
- Any structured plaintext is canonical JSON (keys sorted, no whitespace),
  encoded as UTF-8 before encryption.

### 2.2 Keyring creation

1. Generate the identity key, version 1:
   - X25519: `generateKey({name:'X25519'}, true, ['deriveBits'])`
   - Ed25519: `generateKey({name:'Ed25519'}, true, ['sign','verify'])`
   - Sign the raw X25519 public key with the Ed25519 private key. This proves
     both public keys belong to the same identity.
2. Generate KK: 32 random bytes.
3. Build the bundle:
   `{v:1, identities:[{version, x25519Pkcs8, ed25519Pkcs8, createdAt}], pins:{}, verified:{}}`.
   Encrypt it with AES-256-GCM under KK, using a random 12-byte nonce and
   AAD = `"ghoti-keyring|" + userId + "|" + keyringVersion`.
4. Passphrase wrap:
   - salt = 16 random bytes
   - wrapping key = PBKDF2-HMAC-SHA256(passphrase NFC-normalised, salt, **600 000
     iterations**), 32 bytes
   - encrypt KK with AES-256-GCM under the wrapping key, with a fresh nonce and
     AAD = `"ghoti-pass|" + userId`
   - Store the iteration count so it can be raised later without breaking
     existing keyrings.
5. Recovery code:
   - 20 random bytes, shown as **32 Crockford-base32 characters in 8 groups of
     4**, for example `7K2M-QX9P-…`.
   - Its key: 160 random bits need no slow KDF, so the wrapping key =
     HKDF-SHA256(code bytes, salt, info `"ghoti-recovery"`), 32 bytes.
   - Wrap KK exactly as in step 4, with AAD = `"ghoti-recovery|" + userId`.
6. Upload the ciphertext only: bundle, both wraps, salts, nonces, iteration
   count, and the public keys with their binding signature.

**Passphrase rules**, enforced client-side (the server never sees the
passphrase):

- at least 12 characters
- must differ from the login password: compare the two in the browser before
  either is sent anywhere
- show a strength meter, but don't enforce composition rules

**Recovery code UX:**

- Show the code once, with Copy and Download (`.txt`) buttons.
- Before continuing, the user must re-type two randomly chosen groups.
- The code is never shown again. The user can only regenerate it (§2.4).

### 2.3 Unlocking

- Fetch the keyring row, derive the wrapping key from the passphrase, unwrap KK,
  and decrypt the bundle.
- A GCM authentication failure means the passphrase was wrong. Show "Wrong
  passphrase" and nothing more specific.
- Import the private keys as **non-extractable** `CryptoKey`s and keep them in
  memory only.
- Nothing is written to `localStorage`, `sessionStorage` or IndexedDB in v1.
  Reloading or closing the tab locks the keyring again.
- Lock the keyring automatically after 30 minutes idle, and immediately on
  logout. Locking means dropping every reference.

### 2.4 Keyring maintenance

Every write sends the keyring version it was based on. The server refuses a
stale write with `conflict` (optimistic concurrency, §5.2).

| Action | What changes |
| --- | --- |
| Change passphrase | New salt and passphrase wrap. KK and the bundle stay the same. Requires unlock first. |
| Regenerate recovery code | New code and recovery wrap. The old code stops working. Requires unlock first. |
| Recover | Unwrap KK with the recovery code, then **force** a new passphrase **and** a new recovery code in the same save. The used code is single-use in effect. |
| Rotate identity | Add identity version N+1 to the bundle, publish its public keys, and mark N as retired (not deleted, so history stays readable). Every thread the user is in advances its epoch (§2.6). |
| Reset (both secrets lost) | Destroys the keyring and publishes a fresh identity. Old messages to and from this user become unreadable **for this user only**. Needs the login password plus a second factor if 2FA is on, and a typed confirmation. Every contact sees a key-change warning. |

### 2.5 Fingerprints, pinning, key-change warnings

- Fingerprint = SHA-256(`"ghoti-fp|" || ed25519Pub || x25519Pub`), shown as 12
  groups of 5 decimal digits.
- **Safety number** for a pair of users: both fingerprints sorted and joined,
  shown on each person's screen. It is identical on both, so they can compare it
  in person or over a call.
- **Pin on first use:** the first time you message someone, their current
  public keys are stored in *your* bundle under `pins[userId]`.
- If the server later returns different keys for that user, the client:
  - shows a blocking warning: "X's security key changed"
  - refuses to encrypt to the new key until the user accepts it
  - if the user had marked X as verified, clears that mark and makes them
    re-verify
- `verified[userId]` is kept inside the encrypted bundle, so it syncs across
  devices without the server knowing about it.
- The client checks every fetched public key's binding signature (the Ed25519
  signature over the X25519 key) and rejects the key if it fails.

### 2.6 Threads and epochs

**Creating a thread or advancing its epoch:**

1. Generate TK: 32 random bytes.
2. For each member M, including yourself:
   - `ephemeral` = a fresh X25519 key pair
   - `shared` = X25519(ephemeral.private, M.x25519Pub)
   - `wrapKey` = HKDF-SHA256(shared, salt = ephemeral.pub || M.x25519Pub, info =
     `"ghoti-tk|" + threadId + "|" + epoch + "|" + M.userId`)
   - encrypt TK with AES-256-GCM under `wrapKey`, using a random nonce
   - store `(threadId, epoch, M.userId, M.keyVersion, ephemeral.pub, nonce,
     wrappedTK)`
3. Sign the full set of wraps with your Ed25519 key, so a member can check that
   the wrap set came from a real member and not from the server.

**When the epoch advances:**

- when a member leaves or is removed
- when any member rotates or resets their identity
- optionally, every 1 000 messages

- when a member is added. The add creates epoch N+1 with the new member
  included, and the new member's `joinedEpoch` is N+1, so they see only
  messages sent after they joined. They never receive earlier epochs' keys.

**Direct threads:** there is exactly one per pair of users, enforced by a unique
`directKey = min(userId) + ":" + max(userId)`.

**Group titles** are encrypted with the current TK (AES-GCM, AAD =
`"ghoti-title|" + threadId + "|" + epoch`) and re-encrypted each epoch. The
server never sees a group's name.

### 2.7 Messages

**Plaintext** is canonical JSON:
`{t:"text", body:"…", sentAt:<ms>, replyTo:<clientMsgId|null>}`. The body is
limited to **16 000 characters**.

**Encryption:** AES-256-GCM under the TK for the thread's current epoch, with a
random 12-byte nonce. The AAD is:

```
"ghoti-msg|" + threadId + "|" + epoch + "|" + senderId + "|" + clientMsgId
```

`clientMsgId` is 16 random bytes generated by the client. It lets a retried send
be deduplicated and ties each ciphertext to its slot.

**Signature:** Ed25519 over SHA-256 of
`AAD || nonce || ciphertext`. The receiver:

1. verifies the signature against the sender's pinned key for the key version
   named on the message
2. decrypts
3. checks that `sentAt` is within ±24 h of the server timestamp

If any step fails, the message is shown as "⚠ Could not verify this message".
It is never displayed silently.

**Deleting a message** is a server-side tombstone: the ciphertext is removed and
the row stays. Only the sender can delete, and the UI states that recipients may
already have read the message.

---

## 3. Module scaffolding (copy boards)

Create `mod/messages/`:

| File | Role (boards equivalent) |
| --- | --- |
| `messages.php` | Service object `class messages { public $messagesdb; }` (`boards.php`) |
| `messages.db.php` | `class messagesdb`, PDO with prepared statements only, explicit column lists (`boards.db.php`) |
| `messages.async.php` | Endpoints plus `ghoti_async_register(...)`, `messages_ok()` / `messages_fail()` envelope (`boards.async.php`) |
| `messages.crypto.js` | §2. Pure, testable under node |
| `messages.js` | View, state, rendering, network. Plain DOM, **no jQuery** (§4) |
| `messages.badge.js` | Tiny script for normal pages: unread count in the user menu. **No crypto, no keys** |
| `messages.css` | Styles for the view, using theme tokens |
| `messages.sql` | Schema (§5.1) |
| `insert.sql` | Empty (no seed rows) |

### Core touch points

Grep for `enableBoards` and `boardsObj` to find the full list:

- `ghoti.php`:
  - add `public static $enableMessages = False;` beside the other `enable*` flags
    (around line 43)
  - add `'enableMessages' => 'bool'` to the settings type map (around line 115)
  - in `enabledModules()` (around line 145), add
    `if(self::$enableMessages){ $modules[] = 'messages'; }`
- `ghoti.db.php:51`: add `'messages'` to `$validModules`.
- `index.php:74`: `if(ghoti::$enableMessages){ $_SESSION['messagesObj'] = new messages(); } else { unset($_SESSION['messagesObj']); }`
- `ghoti.async.php:161`: add `'messagesObj'` to the session-object list.
- `ghoti.async.php`, Site Settings → Optional modules (around line 1144):
  - add an `enableMessages` checkbox, with help text that links to
    `docs/messages.md` and states, in plain words, that admins cannot read
    messages and that lost passphrases plus recovery codes cannot be recovered
- `ghoti.js:974`: send `enableMessages` with the settings save.
- `ghoti.header.php`: load **only** `messages.badge.js` when the module is on.
  **Do not** load `messages.js` or `messages.crypto.js` here.
- `ghoti.documentation.php`: add a module status line, as boards does.
- `mod/login/login.async.php` (around lines 1085–1101): add a **Messages** entry
  to the signed-in user's menu when the module is enabled. It links to
  `index.php?view=messages`.
- `mod/login/login.db.php:296` `deleteUser()`: call
  `$_SESSION['messagesObj']->messagesdb->deleteUserContent($userId)` if it is
  set, as boards does. That method is defined in §5.4.

---

## 4. The messages view: an isolated page

The site's normal pages send `script-src 'unsafe-inline'` along with CDN
origins, and they use inline `onclick` handlers throughout. Any XSS on those
pages would be able to reach unlocked keys if the keys lived there. So the
messages view gets its own page shell and its own policy.

**Routing:** in `index.php`, after session validation and
`ghoti_async_handle_request()`, and **before** the normal security headers and
render:

```php
if(($_GET['view'] ?? null) === 'messages' && ghoti::$enableMessages){
	require __DIR__.'/mod/messages/messages.view.php'; //emits its own headers + shell, then exits
}
```

**`messages.view.php`** does the following:

- Redirects a signed-out visitor to the login page.
- Sends these headers:
  ```
  Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; object-src 'none'; require-trusted-types-for 'script'
  Cross-Origin-Opener-Policy: same-origin
  Cross-Origin-Resource-Policy: same-origin
  Referrer-Policy: no-referrer
  Cache-Control: no-store
  X-Content-Type-Options: nosniff
  ```
  Notes on the policy:
  - **No `'unsafe-inline'` in `script-src`, and no CDN.**
  - `'unsafe-inline'` in `style-src` is only there so theme CSS works. Drop it if
    the active theme doesn't need it.
  - `require-trusted-types-for 'script'` makes any `innerHTML` assignment throw,
    which enforces rule 0.5.
  - `COOP: same-origin` stops a compromised ordinary page from using
    `window.open()` to get a scriptable handle on the messages view.
- Emits a minimal HTML shell:
  - the theme stylesheet
  - `messages.css`
  - `<script src>` for `messages.crypto.js` and `messages.js`, with
    cache-busting through `$ghotiAsset`
  - no inline `<script>`
  - no `on*` attributes
- Passes the CSRF token and the current user id in `data-` attributes on
  `<body>`, and `messages.js` reads them from there.
  `ghoti.async.php:217` emits its `x_*` stubs as an **inline** script, so this
  page cannot use them. `messages.js` has its own `fetch` wrapper that posts the
  same payload to `index.php`:
  `{__ghoti_async:1, fn, args, token}`
- Keeps the site header and menu minimal: plain links back to the site, built
  server-side with escaped text.

### Service-worker check

A service worker registered by an XSS on another page could intercept this view.
Registration requires a same-origin script served with a JavaScript MIME type.

- Confirm that **no user-controlled upload path** (file manager, gallery, store
  downloads) can ever be served as `text/javascript` or `application/javascript`.
- Add a test that asserts this (§8).
- `messages.js` also calls `navigator.serviceWorker.getRegistrations()` at start.
  If it finds any registration that the site did not create itself, it refuses
  to unlock and shows a warning.

### Browser support

Feature-detect X25519 and Ed25519 in `crypto.subtle` by trying `generateKey`.
If either is missing, show "Your browser doesn't support encrypted messages yet"
and stop. There is no polyfill and no fallback to weaker cryptography.

### Optional stronger isolation (later)

Serve the view from its own subdomain, for example `messages.<site>`, with its
own vhost and session. Then an XSS on the main site is a different origin
entirely. This is out of scope for v1, but nothing in the design should rule it
out.

---

## 5. Server side

### 5.1 Schema (`messages.sql`)

- The module's own table **must be named `messages`**, because
  `loadModuleSql()` looks for a table with the module's name to detect a fresh
  install.
- Use `utf8mb4` and InnoDB throughout.
- Binary values are stored as base64url in `varchar`, or `mediumtext` for
  ciphertext, so the SQL backup/restore path keeps working unchanged.
- Mirror boards' comment style and put the reasoning in the SQL comments.

```sql
create table if not exists messages(            -- one encrypted message
	`messageId` bigint not null auto_increment,
	`threadId` int(11) not null default 0,
	`senderId` int(11) not null default 0,
	`senderKeyVersion` int(11) not null default 0,
	`epoch` int(11) not null default 0,
	`clientMsgId` char(22) not null default '',   -- 16 bytes base64url
	`nonce` char(16) not null default '',          -- 12 bytes
	`ciphertext` mediumtext not null,
	`signature` char(86) not null default '',      -- 64 bytes
	`sentAt` int(11) not null default 0,           -- server clock
	`deletedAt` int(11) not null default 0,        -- tombstone; ciphertext emptied
  PRIMARY KEY (`messageId`),
  UNIQUE KEY `uq_messages_client` (`senderId`,`clientMsgId`),
  KEY `idx_messages_thread` (`threadId`,`messageId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

create table if not exists message_keyrings(    -- one per user; opaque to the server
	`userId` int(11) not null,
	`version` int(11) not null default 1,           -- optimistic concurrency
	`bundleNonce` char(16) not null default '',
	`bundle` mediumtext not null,
	`passSalt` char(22) not null default '',
	`passIterations` int(11) not null default 600000,
	`passNonce` char(16) not null default '',
	`passWrap` varchar(80) not null default '',
	`recoverySalt` char(22) not null default '',
	`recoveryNonce` char(16) not null default '',
	`recoveryWrap` varchar(80) not null default '',
	`recoveryIssuedAt` int(11) not null default 0,
	`createdAt` int(11) not null default 0,
	`updatedAt` int(11) not null default 0,
  PRIMARY KEY (`userId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

create table if not exists message_public_keys( -- published identity keys, all versions
	`userId` int(11) not null,
	`version` int(11) not null,
	`x25519Pub` char(43) not null,
	`ed25519Pub` char(43) not null,
	`binding` char(86) not null,                    -- Ed25519 sig over x25519Pub
	`createdAt` int(11) not null default 0,
	`retiredAt` int(11) not null default 0,
  PRIMARY KEY (`userId`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

create table if not exists message_threads(
	`threadId` int(11) not null auto_increment,
	`kind` varchar(8) not null default 'direct',    -- 'direct' | 'group'
	`directKey` varchar(24) null default null,      -- "minId:maxId", NULL for groups
	`createdBy` int(11) not null default 0,
	`epoch` int(11) not null default 1,
	`titleNonce` char(16) not null default '',
	`title` varchar(700) not null default '',       -- encrypted, groups only
	`createdAt` int(11) not null default 0,
	`lastMessageAt` int(11) not null default 0,
  PRIMARY KEY (`threadId`),
  UNIQUE KEY `uq_message_threads_direct` (`directKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

create table if not exists message_members(
	`threadId` int(11) not null,
	`userId` int(11) not null,
	`role` varchar(8) not null default 'member',    -- 'owner' | 'member' (groups)
	`joinedEpoch` int(11) not null default 1,
	`leftAt` int(11) not null default 0,
	`lastReadMessageId` bigint not null default 0,
	`muted` int(1) not null default 0,
  PRIMARY KEY (`threadId`,`userId`),
  KEY `idx_message_members_user` (`userId`,`leftAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

create table if not exists message_thread_keys( -- TK wrapped per member per epoch
	`threadId` int(11) not null,
	`epoch` int(11) not null,
	`userId` int(11) not null,
	`recipientKeyVersion` int(11) not null,
	`ephemeralPub` char(43) not null,
	`nonce` char(16) not null,
	`wrappedKey` char(64) not null,                 -- 32 + 16 tag bytes
	`wrappedBy` int(11) not null,
	`wrapSetSignature` char(86) not null,
  PRIMARY KEY (`threadId`,`epoch`,`userId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

create table if not exists message_blocks(
	`userId` int(11) not null,
	`blockedUserId` int(11) not null,
	`createdAt` int(11) not null default 0,
  PRIMARY KEY (`userId`,`blockedUserId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

create table if not exists message_notify(       -- opt-in, like board_notify
	`userId` int(11) not null,
	`email` int(1) not null default 0,
	`lastNotifiedAt` int(11) not null default 0,
  PRIMARY KEY (`userId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Check every `char(n)` against the exact base64url lengths in §5.3 before you
ship.

### 5.2 Endpoints (`messages.async.php`)

Every endpoint:

1. Requires a signed-in user and fails with `forbidden` otherwise.
2. Re-derives authorization from the database. It never trusts a `userId` sent
   by the client.
3. Validates every argument (§5.3).
4. Answers `messages_ok($data)` or `messages_fail($code, $message)`.

Error codes: `forbidden`, `invalid`, `not_found`, `conflict`, `blocked`,
`rate_limited`, `no_keyring`, `stale_epoch`, `db_error`.

| Endpoint | Does | Key checks |
| --- | --- | --- |
| `messagesGetKeyring()` | Returns your keyring row, or `no_keyring` | own row only |
| `messagesCreateKeyring(k, pub)` | First-time setup: keyring and public key v1 | refuses if a keyring exists |
| `messagesSaveKeyring(k, baseVersion)` | Passphrase change, recovery-code regen, recover, pin updates | `version = baseVersion` or `conflict`; bumps version |
| `messagesRotateIdentity(k, baseVersion, pub)` | New key version; retires old | as above; `pub.version = max+1` |
| `messagesResetKeyring(password, totp, k, pub)` | Destroy and recreate (§2.4) | verifies the login password and 2FA through the login module; logs at WARN; raises an admin alert via `ghoti.alerts.php` (flood check) |
| `messagesGetPublicKeys(userIds[])` | Current and retired public keys | ≤ 50 ids; only users sharing a thread with you, or found through `messagesFindUser` |
| `messagesFindUser(query)` | Directory lookup by exact username | exact match only, no prefix search, rate-limited; hides users who blocked you |
| `messagesListThreads(cursor)` | Your threads: members, epoch, encrypted title, unread count, last activity | membership |
| `messagesOpenDirect(otherId, wraps, sig)` | Get or create the 1:1 thread | not blocked either way; the other user has a keyring; wraps cover both members at their current key versions |
| `messagesCreateGroup(memberIds, titleEnc, wraps, sig)` | New group | ≤ 50 members; all have keyrings; none have blocked you |
| `messagesGetThreadKeys(threadId, epochs[])` | Your wraps for those epochs | membership; only epochs at or after `joinedEpoch` |
| `messagesGetMessages(threadId, beforeId\|afterId)` | Page of 50 | membership; only epochs at or after `joinedEpoch` |
| `messagesSend(threadId, epoch, clientMsgId, nonce, ct, sig, keyVersion)` | Store a message | member and not left; `epoch == thread.epoch` or `stale_epoch`; `keyVersion` is the sender's current one; duplicate `clientMsgId` returns the existing row (idempotent); rate limit |
| `messagesAdvanceEpoch(threadId, fromEpoch, wraps, sig, titleEnc?)` | New epoch for current members | member; `fromEpoch == thread.epoch` or `conflict`; wraps cover every current member exactly |
| `messagesAddMembers(threadId, ids, wraps, sig, titleEnc)` | Group only; implies a new epoch | owner; checks as for create |
| `messagesRemoveMember(threadId, id, wraps, sig, titleEnc)` / `messagesLeave(threadId)` | Removes the member | owner or self. Leaving sets `leftAt`, and the **next sender** must advance the epoch first: `messagesSend` returns `stale_epoch` until someone does |
| `messagesMarkRead(threadId, messageId)` | Read pointer | membership |
| `messagesDelete(messageId)` | Tombstone | sender only |
| `messagesBlock(userId, on)` | Block or unblock | not yourself |
| `messagesPoll(sinceMessageId)` | `{unread, threadsChanged[]}` for the badge and open view | cheap indexed query |
| `messagesGetNotify()` / `messagesSaveNotify(email)` | Email opt-in | own row |

Register them all with `ghoti_async_register(...)` at the bottom, as boards
does.

**Real-time updates, v1:** poll `messagesPoll` every 10 s while the view is
visible and every 60 s from `messages.badge.js`. Pause both on
`visibilitychange`. Server-Sent Events, modelled on
`mod/analytics/apachelog.stream.php`, can come later.

### 5.3 Validation helpers (pure, unit-tested)

- `messages_b64(value, bytes)`: strict base64url (`/^[A-Za-z0-9_-]+$/`, no
  padding). It decodes the value and requires **exactly** `bytes` bytes.
- Fixed sizes (base64url length in brackets):
  - public keys: 32 bytes [43]
  - signatures: 64 bytes [86]
  - nonces: 12 bytes [16]
  - salts: 16 bytes [22]
  - `clientMsgId`: 16 bytes [22]
  - wrapped TK: 48 bytes [64]
  - passphrase and recovery wraps: 48 bytes [64]
- Maximum sizes:
  - message ciphertext ≤ 64 KiB decoded (16 000 characters is at most 64 000
    bytes of UTF-8, plus JSON overhead, plus the 16-byte GCM tag; set the exact
    cap from a measured worst case)
  - bundle ≤ 256 KiB
  - encrypted title ≤ 512 bytes
- The whole async request is already capped at 1 MiB
  (`ghoti_async_read_request`). A group-create call carrying 50 wraps fits well
  inside that.
- IDs: positive integers. Lists: deduplicated and length-capped.
- **The server does not verify Ed25519 signatures.** The PHP here can't do it
  reliably without `sodium`, and it doesn't need to: clients verify, and the
  server enforces only shape, membership and epoch rules.

### 5.4 Data lifecycle

`messagesdb::deleteUserContent($userId)` runs inside one transaction and:

- deletes the user's keyring, public keys, `message_notify` row, and block rows
  in both directions
- removes their `message_members` rows. For each group they were in, it sets
  `leftAt`, and the epoch advances on the next send, as for a leave
- deletes their messages, which is consistent with how boards drops a deleted
  user's posts
- deletes direct threads left with fewer than two members, together with their
  keys and messages

**Password reset** (`password-reset.php`, `resetPasswordWithToken`) must **not**
touch messages tables. Add a test for that.

**Backups** keep only ciphertext. Nothing in `ghoti.backup.lib.php` changes,
except that you should confirm restore validation still passes with the new
tables present.

**Privacy page** (`ghoti.privacy.php`): add what is stored (ciphertext, public
keys, metadata), who can read it (no one but participants), what admins can see
(metadata), and what deleting an account removes.

---

## 6. Client (`messages.js`)

States, in order:

1. **Unsupported browser.** Stop (§4).
2. **No keyring.** Setup wizard: explain → choose passphrase → show recovery
   code → confirm two groups → create.
3. **Locked.** Passphrase prompt, with "Use recovery code instead" and "I've
   lost both" (which goes to the reset flow, behind a heavy warning).
4. **Unlocked.**
   - Thread list: decrypted titles for groups, names for direct threads, unread
     badges.
   - Thread view: paged history, composer.
   - New-message dialog: exact-username lookup.
   - Contact info panel: fingerprint, safety number, "Mark as verified", Block.
   - Keyring settings: change passphrase, new recovery code, rotate identity,
     email notification opt-in, lock now.

Rules:

- Build all DOM with `document.createElement` and `textContent`. Autolink URLs
  only through `createElement('a')`, and only for `https:` and `http:` URLs, with
  `rel="noopener noreferrer nofollow"` and `target="_blank"`.
- Cache decrypted TKs per (thread, epoch) in memory, and drop them on lock.
- When `messagesSend` returns `stale_epoch`, fetch the thread and advance the
  epoch if your client is the one expected to, then re-encrypt and retry once.
- Every key-change or verification problem **stops** sending in that thread
  until the user makes a decision. Never downgrade silently.
- Use the site's existing button spinner and disabled-state conventions
  (`tests/button-spinners.js`), and keep button labels on one line.
- Accessibility: thread list is `role="list"`; new messages are announced
  through an `aria-live="polite"` region; everything is operable by keyboard.
  Follow `docs/privacy-accessibility-review-2026-09-13.md`.

---

## 7. Notifications

- Email is opt-in (`message_notify.email`).
- It goes through the themed mailer, as boards reply notices do. Use
  `boards_notify_reply` as the model.
- The content is **"You have a new message on <site>"** with a link to
  `index.php?view=messages`. It includes **no sender name and no excerpt**:
  the server can't produce an excerpt, and the sender name would put metadata
  into email.
- Throttle to at most one email per recipient per 15 minutes, using
  `lastNotifiedAt`. Nothing is sent while the recipient has polled in the last
  2 minutes, because they're online.
- `tests/mail-themed.php` will fail on the new `->send()` call site. Review it
  and add it to that test's approved list.

---

## 8. Tests

Follow the existing style: `php tests/<name>.php`, with no database, network or
browser for the unit tests; JavaScript tests run under `node`.

| Test | Covers |
| --- | --- |
| `tests/messages-crypto.js` (node) | Keyring create → unlock round trip; wrong passphrase fails; recovery code unlocks; passphrase change keeps the bundle readable; regenerating the code invalidates the old one; TK wrap/unwrap for 3 members; message encrypt/sign/verify/decrypt; a flipped bit in ciphertext, nonce, AAD field or signature is rejected; canonical JSON is stable; fingerprint and safety number are the same from both sides |
| `tests/messages.php` | Envelope contract; `messages_b64` exact-length checks; every endpoint refuses signed-out users; a non-member can't read, send, or fetch keys; `stale_epoch` and `conflict` paths; `clientMsgId` idempotency; `joinedEpoch` history cutoff; block enforcement; `deleteUserContent` coverage |
| `tests/messages-disabled.php` | Off by default; no menu entry, no endpoints, no assets, and `?view=messages` falls through to a normal page (mirror `tests/bpong-disabled.php`) |
| `tests/messages-csp.php` | The view's headers exactly as in §4: no `unsafe-inline` in `script-src`, no third-party script origins, COOP present; the shell contains no inline `<script>` and no `on*=` attributes (mirror `tests/store-csp.php`) |
| `tests/messages-uploads-mime.php` | No upload or download path serves a JavaScript MIME type (§4 service-worker check) |
| `tests/messages-password-reset.php` | A password reset leaves every `message_*` row untouched |
| `tests/schema-upgrade.php` | Extend it so the new `messages.sql` applies cleanly on a fresh install and on upgrade |
| `tests/mail-themed.php` | Updated for the notify call site |
| Browser run (manual, or `tests/*-browser.js` style) | Two users: set up, exchange direct messages, create a group of 3, remove one member (they can't read new messages), rotate an identity (key-change warning appears), log out and back in (history still decrypts), password reset then unlock with the passphrase (history intact), unlock with the recovery code |

---

## 9. Build order

Each phase ends with its tests passing and is committed separately using
Conventional Commits, for example `feat(messages): keyring setup and unlock`.

1. **Crypto core:** `messages.crypto.js` plus `tests/messages-crypto.js`. No UI,
   no server. Get this reviewed before building on it.
2. **Scaffolding:**
   - the module skeleton, schema, and settings checkbox
   - the core touch points (§3)
   - `messages-disabled.php`
3. **Isolated view:** `messages.view.php` with its CSP and COOP, the fetch
   wrapper, `messages-csp.php`, and the upload MIME audit.
4. **Keyring:** create, unlock, lock, change passphrase, regenerate recovery
   code, recover, reset, and fingerprints. Endpoints plus UI plus tests,
   including the password-reset test.
5. **Direct messages:** find a user, open a direct thread, send, page history,
   poll, mark read, delete, block, the unread badge on normal pages, and
   key-change warnings with verification.
6. **Groups:** create, encrypted titles, add, remove, leave, epoch advance, and
   rotating an identity across threads.
7. **Notifications and lifecycle:** email opt-in, `deleteUserContent`, privacy
   page, `docs/messages.md` user guide (including the limitations list from §0),
   and documentation status.
8. **Review:** an independent read of §2 and of `messages.crypto.js` before the
   module is described anywhere as "secure" or "private".

**Deferred, not in v1:**

- attachments: need chunked client-side encryption and an opaque blob store
  outside the upload MIME rules
- multi-device "remember this device"
- Server-Sent Events
- forward secrecy (Double Ratchet or MLS)
- a separate subdomain for the view
