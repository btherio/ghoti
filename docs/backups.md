# Backup delivery

Use **Admin Menu → Backup / Restore** to generate the site ZIP and SQL dump.
Keep both for a complete recovery set.

- If the mail module is available, sending is enabled, and **Send test message**
  has succeeded at least once, each export is attached to a separate email to
  every administrator's valid email address from **Manage Users**. Browser
  downloads are disabled, including direct requests to old download URLs.
- If mail is unavailable/disabled or no successful test is recorded, the page
  offers the existing downloadable ZIP and SQL exports.

Send a test from **Mail Settings** after this upgrade to establish the first
recorded success. Earlier tests were not persisted and cannot be inferred.
The new `mail.successfulTestAt` column is added by the normal module schema
upgrade and starts at zero. Ordinary messages and failed tests do not set it;
settings saves and later failed tests preserve its history. Disabling mail
restores downloads; re-enabling mail with recorded test success restores email
exports. SMTP acceptance counts as a successful send; check the test inbox too.

Email requests require an authenticated admin session, CSRF token, and POST.
Recipients come from administrator accounts, never from the export request.
Each recipient receives the same generated attachment privately. Temporary
exports are created outside the site directory with restrictive permissions and
removed after success or failure. Attachments are streamed with base64 encoding
to avoid loading an entire site archive into PHP memory.

If some recipients fail, the remaining administrators are still attempted and
the page reports how many succeeded and failed. A failure does **not** enable a
download. Correct the addresses or SMTP settings and retry; recipients who
already received the previous attempt may receive another copy. If a request is
interrupted, check inboxes before retrying. Large archives may exceed the mail
server's message-size limit (base64 adds roughly one third); such errors remain
email-delivery errors rather than exposing a browser download. If mail settings
cannot be read, exports fail rather than assuming downloads are allowed.

Restoration is unchanged: upload a Ghoti archive/dump and reconfirm your admin
password and `RESTORE`.

## Verification

- `php tests/backup-delivery.php`: delivery decisions, UI modes, all-admin fanout,
  partial failure, cleanup, successful test persistence, and admin authorization.
- `php tests/mail-attachments.php`: MIME headers, exact binary attachment content,
  filename validation, dot transparency, and a fake loopback SMTP conversation.
- `python tests/backup-delivery-http.py`: real transfer endpoint with isolated
  authentication/mail fixtures; direct download blocking, fallback downloads,
  email delivery, CSRF/method checks, and failure behaviour.
- `php tests/schema-upgrade.php`: additive schema upgrade parser.

These tests never send real backups to real email addresses.
