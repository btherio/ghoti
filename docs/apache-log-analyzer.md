# Apache log analyzer

The **Apache logs** card at the bottom of **Analytics** parses the web server's own
access and error logs: it groups entries by severity and category, suggests where to
look first, and can follow a file live. It is read-only — nothing in the CMS writes to,
rotates, or clears an Apache log.

This was a standalone tool (`lib/Apache-tool`) with its own password login. Inside ghoti
the admin session is the authentication, and the tool's sudo-backed "clear log" action
was dropped rather than carried over: ghoti's pattern for privileged writes is a
root-owned helper plus a sudoers rule (see [vhost enablement](vhosts-enablement.md)), not
a web form that collects the host's sudo password.

## What it needs

The PHP process must be able to read the log directory. On most distributions Apache logs
are owned by root and readable only by root or an `adm`-style group, so either add the web
server user to that group or point the analyzer at a directory it can already read. When
the directory is unreadable the card says so instead of failing silently; individual files
that cannot be read are listed but not selectable.

## Configuration

All settings are environment variables read once per request. There is no Site Settings
entry: these are per-install infrastructure facts, not editorial choices.

| Variable | Default | Meaning |
| --- | --- | --- |
| `APACHE_LOG_DIR` | `/var/log/httpd` | Directory that is listed and read |
| `APACHE_LOG_MAX_INITIAL_BYTES` | 25 MB | Tail window read from a file on first analysis |
| `APACHE_LOG_JSON_ENTRY_LIMIT` | 5000 | Entries sent to the browser for the table |
| `APACHE_LOG_PDF_ENTRY_LIMIT` | 5000 | Entries included in a PDF export |
| `APACHE_LOG_STREAM_MAX_SECONDS` | 900 | How long one live stream may run |
| `APACHE_LOG_STREAM_POLL_US` | 1500000 | Poll interval while following a file |
| `APACHE_LOG_READ_CHUNK_BYTES` | 1 MB | Read size while following a file |

Summary metrics always count every parsed entry in the window; the entry limits only bound
how many individual lines are shipped for browsing or printing.

## How the pieces fit

| File | Role |
| --- | --- |
| `mod/analytics/apachelog.php` | Parser, report builder, live streamer, PDF writer. `declare(strict_types=1)`, so it stays a separate file, required lazily |
| `mod/analytics/analytics.async.php` | `listApacheLogs` / `analyzeApacheLog` endpoints and the card's HTML shell |
| `mod/analytics/apachelog.stream.php` | Server-Sent Events endpoint for **Follow live** |
| `mod/analytics/apachelog.export.php` | PDF download |
| `mod/analytics/apachelog.js` | Draws the report; one renderer for both the one-shot and live paths |
| `mod/analytics/apachelog.css` | Card styling, themed from the analytics dashboard's variables |

## Access control

Every entry point re-checks admin status. The two async endpoints go through the CMS's
usual CSRF-protected RPC layer. The streaming and PDF endpoints are plain URLs — a download
and an `EventSource` connection cannot be XHR responses — so each one re-checks the session
against the database and verifies the session CSRF token from the query string, the same
way the analytics CSV export does.

File selection never accepts a path. The browser sends a base64url-encoded *file name*,
which is rejected unless it resolves, through `realpath()`, to a regular file directly
inside the configured directory. Compressed logs are listed but refused. Log content is
untrusted text and is written to the page with `textContent`, never as HTML.

## Following a log live

**Follow live** opens an SSE stream that re-sends the whole report as the file grows, and
rebuilds it from scratch if the file is rotated or truncated underneath. The stream closes
the PHP session before its read loop starts — PHP holds an exclusive lock on the session
file for the life of a request, so without that the rest of the admin UI would block behind
the stream. If the stream cannot be opened at all (EventSource unavailable, a proxy that
buffers it away), the card falls back to a one-time analysis.

Each live stream occupies one web server worker until it ends, the admin presses **Stop
stream**, or `APACHE_LOG_STREAM_MAX_SECONDS` elapses.

## Validation

`php tests/apachelog.php` covers listing, file-name resolution and the traversal
refusals, error- and access-log parsing, continuation lines, entry limits, the read
window, PDF output, and the absence of any write or shell-out path in the analyzer.
`python3 tests/apachelog-http.py` checks over loopback that the streaming and PDF
endpoints refuse an unauthenticated caller. Neither test needs a database, a web
server, or a real log directory.
