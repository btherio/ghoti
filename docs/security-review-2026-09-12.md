# ghoti security review — 12 September 2026

## Outcome and scope

The review found account-takeover paths in database recovery, first-user provisioning, and password-reset URL construction, plus weaknesses in session revocation, reset concurrency, throttling, exports, and filesystem protection. This branch fixes the issues listed below. **This is a source review with targeted regression tests, not a certification that the installation is vulnerability-free.**

Reviewed baseline: `c6e4ac3e598a1ec2ad0718600a30bcf6c8d17100` on `fix/analytics-block-and-mysql-pdo-constant`. Fix branch: `security/codebase-review`. Worktree: `/tmp/ghoti-security-review`. The earlier uncommitted logo work remains in the original workspace and is not part of this branch. No production data, credentials, web-server configuration, or running application was changed.

The basic pass covered request entry points, RPC registration, CSRF, authentication, role checks, SQL construction, output encoding, uploads/downloads, theme includes, SMTP, and sensitive-file exposure. The deeper pass traced credential recovery and session lifetime across independent endpoints, concurrency in shared state, stored data reaching browsers and spreadsheets, and canonical filesystem paths. PHP source and relevant JavaScript sinks were inspected; third-party library code was not comprehensively audited.

Severity is qualitative and includes the stated prerequisites. A source-confirmed weakness does not imply it has been exploited on this installation.

## Findings and fixes

| ID | Severity | Finding | Status |
| --- | --- | --- | --- |
| S01 | Critical, during DB outage/unconfigured state | Public database reconfiguration when no setup key is configured | Fixed |
| S02 | High, empty installation | First public registrant automatically becomes administrator | Fixed |
| S03 | High, conditional on Host routing and victim opening email | Password-reset link poisoning | Fixed |
| S04 | High, requires existing session access | Password changes/reset and account deletion do not reliably revoke sessions | Fixed |
| S05 | Medium, requires valid reset link | Reset-token replay through concurrent requests or partial failure | Fixed, DB integration validation outstanding |
| S06 | Medium | Concurrent login failures lose rate-limit state; protection fails open | Fixed |
| S07 | Medium, requires opening exported CSV | Attacker-controlled analytics fields become spreadsheet formulas | Fixed |
| S08 | Medium, requires analytics/DB/export access | Raw session IDs copied into analytics | Fixed for new data; historical cleanup remains |
| S09 | Medium, deployment-dependent | Hidden-directory HTTP protection fails for subdirectory installs and misses new names | Fixed for Apache rule; deployed-server verification remains |
| S10 | Low, requires administrator and existing symlink | File-manager protection bypass through a hidden/denied ancestor | Fixed; admin remains a code-execution role |
| S11 | Medium, requires legacy/imported dangerous URL and click | Stored link/banner URL schemes not revalidated at output | Fixed |
| S12 | Low, resource-exhaustion hardening | RPC JSON read unbounded and envelope types unchecked | Fixed |

### S01 — Database recovery is an unauthenticated configuration boundary

**Evidence:** `ghoti.setup.php`, `ghoti_setup_access_ok()` and `ghoti_setup_dispatch()`; `index.php` routes here whenever `ghotidb::isConfigured()` fails. The original key check only ran when `GHOTI_SETUP_KEY` was nonempty. A visitor could obtain their own valid CSRF token and use setup during an outage. CSRF proves request/session continuity, not operator authority.

**Impact:** An attacker could test arbitrary database hosts/ports and persist a working attacker-selected database connection. Controlling the replacement database can provide an administrative account; the existing file manager then permits PHP edits/uploads. Thus database outage can become application takeover and potentially server-side code execution under the web-server account. This requires a database reachable and accepted by the setup connection test, not merely submitting random credentials.

**Fix:** Setup now returns 503 before showing configuration or parsing setup actions when no operator key exists. Both the operator key and CSRF token must match when enabled. Tests cover missing key, wrong key, wrong token, and authorized validation; loopback HTTP tests cover missing-key GET and POST. Setup keys should still be removed from access-log retention where possible because the authorized page uses `?k=`.

### S02 — First-user administrator takeover

**Evidence:** `mod/login/login.db.php::addUser()` inserted a normal user, counted users, then promoted all nonadmins if the count was one. `ghoti::$allowRegister` defaulted to true. Anyone reaching a fresh site first could become its administrator; concurrent signup also made bootstrap behavior unreliable.

**Fix:** Public registration always inserts `admin=0`; the default is now disabled. `bin/create-admin.php` is CLI-only and creates the initial administrator only when the users table is empty, with a database-scoped bootstrap lock. Existing installation settings can still explicitly enable normal public registration. Tests verify that first-user registration does not promote and that the default is off. Bootstrap has not been run on production.

### S03 — Password-reset Host-header poisoning

**Evidence:** `password-reset.php::passwordResetBaseUrl()` constructed credential-bearing links from `HTTP_HOST` and `REQUEST_URI`. Stripping punctuation does not establish that a host belongs to the site. If the virtual host/proxy accepts an attacker-controlled Host, the attacker can request a victim's reset email containing a link to their own domain. Opening that link discloses the reset token.

**Fix:** `ghoti_password_reset_url()` uses only the operator's `GHOTI_PUBLIC_URL`, requires HTTPS, rejects credentials/query/fragment/control characters, and appends a fixed reset-script path. Missing/invalid configuration disables mail recovery with an operator-directed message. Tests poison both Host and URI and verify the trusted link is unchanged. This follows [OWASP password-recovery guidance](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html).

### S04 — Sessions outlive credential recovery

**Evidence:** Normal authorization relied on session `loggedIn`/`userId`, with no binding to current password state. Resetting a password did not revoke an attacker's existing session. Deleted ordinary accounts could retain login-only access. Standalone analytics/file downloads omitted the main entry point's inactivity enforcement.

**Fix:** Login records a server-side SHA-256 fingerprint of the verified password hash. The main entry point and both standalone downloads compare it with current database state and reject missing, stale, deleted, or unavailable credentials. Invalidating a session also clears its private-page context. Standalone checks enforce 30-minute inactivity. Conditional password rehash updates cannot overwrite a concurrent password reset. Existing sessions without a fingerprint must sign in again; password changes also end the current login on its next request. This does not cancel requests already authorized and in flight.

### S05 — Reset consumption was separate from password mutation

**Evidence:** `resetPasswordWithToken()` originally read the unused token, hashed/updated the password, and only then marked links used. Two requests can pass the same initial validation, or a failure after the password update can leave a used credential link valid. Authenticated password changes did not retire old reset links either.

**Fix:** A per-account, database-scoped MySQL named lock serializes reset/password-change mutations. Reset validation is repeated after acquiring the lock. Outstanding links are consumed before changing the password. A subsequent write failure leaves the links unusable and requires a fresh recovery email. This is deliberately fail-closed rather than all-or-nothing recovery: the legacy `users` table is MyISAM, so simply wrapping the old code in a PDO transaction would not provide rollback. See the [PDO transaction limitations](https://www.php.net/pdo.transactions.php).

**Validation limit:** Tests model competing reset completion, lock failure, password-write failure, lock release, replay, and authenticated password changes. No real MySQL/MariaDB concurrency test was run. Validate `GET_LOCK`/`RELEASE_LOCK` on staging with the actual DB topology. Named locks are server-local; this implementation assumes credential writes reach the same database server, as the current single-host connection configuration does.

### S06 — Login throttle state can disappear

**Evidence:** Each `login_throttle` instance loaded a snapshot, then rewrote a shared `.tmp` file and renamed it. Concurrent requests overwrite each other's changes; locking the individual write does not lock the read/modify/write sequence. Write failure silently disabled the shared control. Successful authentication also cleared the IP bucket, allowing a valid account to reset failures against other accounts. The store used `mod/login.throttle.json`, despite documentation locating it in the project root.

**Fix:** Every operation opens and locks the same state file, reads current state inside the lock, and performs any update before releasing it. Missing writable storage or corrupt JSON fails closed. Successful login clears only the username bucket. Storage is consistently at the root. A real 12-process regression verifies that all concurrent writes survive. This does not make the entire authenticate/check/failure sequence an atomic rate-limit reservation; a burst already in flight can still exceed five attempts. External request-level limits remain valuable.

### S07 — CSV formula injection

**Evidence:** Analytics records request headers such as User-Agent and referrer, then passes raw values to `fputcsv()`. Ordinary CSV quoting does not prevent a spreadsheet from interpreting a cell beginning with `=`, `+`, `-`, or `@` as a formula.

**Fix:** Export cells receive a leading apostrophe when they resemble a formula, including leading whitespace/control variations. Export responses also use `Cache-Control: no-store`. Six dangerous patterns and ordinary text/numbers are tested. Exact execution and warning behavior depends on the spreadsheet application; this review did not execute malicious formulas in Excel/LibreOffice. See [OWASP CSV injection](https://owasp.org/www-community/attacks/CSV_Injection).

### S08 — Analytics stores authentication material

**Evidence:** `trackPageView()` passed `session_id()` directly into analytics, which is also exported. This needlessly duplicates session bearer material into a reporting database and downloaded files. Actual session reuse also depends on the app's IP/User-Agent binding and session lifetime.

**Fix:** New analytics records store a SHA-256 digest of the random session ID, preserving distinct-session grouping without storing the cookie itself. Existing rows and old exports are not rewritten by the code change. Review their retention and remove or hash old raw identifiers through a separately reviewed data-maintenance operation. Legacy sessions are rejected by S04 after rollout.

### S09 — Apache hidden-directory protection is incomplete

**Evidence:** Root rules such as `^/\.git` do not match `/ghoti/.git/config`. They also only named `.git`, `.reasonix`, and `.claude`, omitting `.codex`, `.agents`, and other hidden paths. Whether files are reachable depends on actual server and proxy configuration.

**Fix:** Hidden path segments are denied at every installation depth, retaining `.well-known` for ACME. Existing sensitive-file rules remain. Apache `.htaccess` parsing and deployed HTTP denial were not tested here. Nginx ignores `.htaccess` and requires equivalent server rules; PHP's development server is also not a substitute for Apache validation.

### S10 — Canonical path checks missed protected ancestors

**Evidence:** File-manager resolution confined targets to the root and checked only the final basename. A benign directory symlink targeting an internal hidden directory could therefore expose its children without leaving the root.

**Fix:** Every component of the canonical path is checked against hidden names and denied basenames; resolved directories must be directories. Throttle temporary names are denied too. A real filesystem symlink fixture confirms denial. This is defense in depth: file-manager administrators intentionally can edit PHP, so this feature does not isolate a malicious admin from the operating-system privileges of the web process. Filesystem check/use races against a local writer are not eliminated.

### S11 — Escaping is insufficient for legacy URL schemes

**Evidence:** Link and banner rendering escaped HTML attributes but accepted stored `javascript:` URLs. The current write endpoints validate schemes, so this finding concerns legacy/imported rows, database tampering, or an earlier validation bypass; no new anonymous write path was demonstrated.

**Fix:** JavaScript link rendering suppresses disallowed schemes; PHP banner rendering validates before attribute encoding. Tests cover dangerous schemes, embedded tabs, relative paths, HTTPS, and mailto. Generic feedback messages now use `.text()` so diagnostic strings are not interpreted as markup.

### S12 — RPC input limits and additional hardening

JSON input was read in full before validation, despite downstream field limits. The reader now caps nonmultipart bodies at 1 MiB and returns 413; malformed function/token/argument envelopes return 400. PHP/server upload limits still govern multipart parsing. The arbitrary-content `getPage` renderer is no longer publicly registered; legitimate page retrieval remains through ID/title/default endpoints. This removes unnecessary attack surface, but is not claimed as a demonstrated cross-user XSS exploit because the old RPC already required CSRF. Secure CSRF generation now throws instead of falling back to `md5(uniqid())`. Analytics embedded JSON uses explicit HTML-safe encoding. Request cleanup also drops `mailObj`, avoiding needless serialization of mail settings into sessions. Comment refresh rechecks current page visibility so an anonymous session cannot continue reading comments after a public page becomes private.

## Remaining risks and follow-up work

1. **Account identity uniqueness and admin invariants — medium, confirmed race potential.** `users` has only a primary key, while duplicate username/email checks are separate from INSERT/UPDATE. Concurrent operations can create duplicate identities and disrupt login/reset semantics. Last-admin checks also use separate count/update operations. Plan a migration that detects and resolves existing duplicates, introduces unique normalized identifiers, and serializes role changes. Do not blindly add unique indexes to unknown existing data.
2. **Public abuse controls — medium.** The arithmetic captcha is machine-solvable, registration lacks durable quotas, reset counts are separate from issuance, and reset limiting fails open on a DB error. Comments/analytics can grow without retention or robust submission limits. Registration is off by default after this change, but enabled installations should add transactional/centralized rate limiting and monitoring. Uniform reset messages do not eliminate timing-based account enumeration when real SMTP delivery differs from the nonexistent-account path.
3. **Upload/admin trust — high impact after admin compromise, not an anonymous upload bypass found.** File manager deliberately permits PHP upload/editing across the site. Gallery images have extension/MIME controls and random names; shell arguments are escaped. Image conversion still relies on ImageMagick/delegate policy, has no explicit pixel/memory/disk quotas, and accepts MIME checks only when available. Browser-safe images retain metadata. Consider restricting file management to an asset directory, disabling PHP editing in production, and sandboxing conversion. Gallery URLs/files are public even if embedded into a private CMS page; private media needs its own authorization model.
4. **Browser and legacy data exposure.** CSP permits inline script and several CDN hosts. Existing admin-authored page HTML is rendered as trusted content. Imported/legacy data and HTML helper usage warrant a migration/rendering audit; this pass does not prove every possible stored row safe. Mail recovery/error handlers occasionally surface raw exception text; normal RPC exceptions are generic. Move toward context-aware output and typed public errors throughout.
5. **Deployment and secrets.** HTTPS/HSTS, proxy trust, PHP `display_errors`, web-root file permissions, SMTP TLS choices, DB privileges, DB transport, and host package patch levels were not audited on the live server. Setup keys still appear in authorized URLs. Use a unique session name per installation; the default cookie path/name can collide across sites sharing a host. A signature scan of tracked text found no private-key or known API-token patterns; this was not an exhaustive secret scan or Git-history review. Local credential files were not copied or used in tests.
6. **Dependencies.** The application loads local jQuery 4.0.0 and the active themes later load CDN jQuery 3.7.1, plus GSAP 3.12.5 and Lenis 1.0.42 with SRI. Consolidate the duplicate jQuery loading and establish an inventory/advisory workflow. The [jQuery advisory page](https://github.com/jquery/jquery/security/advisories) was checked; no new applicable jQuery advisory was identified in that check. This is not a comprehensive dependency CVE audit. jQuery documents 3.x as critical-fixes-only in its [support policy](https://github.com/jquery/jquery#version-support).

## Validation performed

- `php tests/security.php`: **85 assertions passed**, including actual process concurrency and filesystem symlink tests. Reset SQL sequencing is modeled with a test double, not a live DB.
- `python3 tests/security-http.py`: **7 loopback HTTP cases passed**, covering setup GET/POST disabled without a key, invalid CSRF, unregistered renderer, malformed fields, and oversize JSON. The harness uses no production database or mail server.
- Six JavaScript URL validation cases passed; changed JavaScript passed `node --check`.
- All **50 PHP files** passed syntax checking; `git diff --check` passed.
- No destructive remote probes, live credential attacks, real password-reset email, production account creation, database migrations, or production deployment were performed.

## Deployment notes and staging checks

Before deploying, configure `GHOTI_PUBLIC_URL` to the HTTPS installation base (for example, `https://example.com/ghoti`), without a trailing script name, query, or fragment. Set a high-entropy `GHOTI_SETUP_KEY` only when operator-controlled web setup is needed. Without it an outage shows a locked maintenance response. Ensure the root `login.throttle.json` can be created/written by PHP and is blocked from HTTP; the old `mod/login.throttle.json` will no longer be read. A corrupt throttle file now requires operator repair instead of silently bypassing protection.

For a fresh, empty installation, provision the first administrator using the same database environment as the web process:

```bash
read -r -s -p 'Initial admin password: ' ghoti_admin_password
printf '%s\n' "$ghoti_admin_password" | php bin/create-admin.php admin admin@example.com
unset ghoti_admin_password
```

Keep public registration disabled until bootstrap is complete. Existing accounts do not require bootstrap. Existing login sessions will be rejected and users must log in again. No schema migration is required for these fixes. Keep test files out of production deployments; the PHP test entry point also rejects web execution.

Staging acceptance should include: normal/admin login; session revocation after reset/change/deletion; real SMTP reset links behind the actual proxy; concurrent consumption of the same and different reset tokens on the same account; GET_LOCK availability on the configured DB topology; bootstrap on an isolated empty DB; multipart gallery/file uploads; legacy theme navigation; ordinary feedback display; CSV inspection; and HTTP requests to sensitive files/hidden directories at the deployed subdirectory path. Confirm these before merge/deployment, especially the DB-dependent recovery changes. Review historical analytics identifiers and logs separately.
