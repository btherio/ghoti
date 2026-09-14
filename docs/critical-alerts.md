# Critical log email alerts

In **Site Settings → Logging & alerts**, enable **E-mail critical log alerts**. There is no recipient field: alerts go to every administrator account, one message each, addressed individually so administrators do not see each other’s addresses. Delivery uses the existing SMTP configuration under **Mail Settings**. Alerts default to off and cannot be enabled while no administrator account has a valid e-mail address; an address that fails validation is skipped at send time. No external intrusion-detection service is configured by this feature.

| Signal | Alert threshold |
|---|---|
| ERROR log entry, including caught application exceptions | First event |
| Unhandled exception or fatal PHP error after the handler is installed in the main or password-recovery entry point | First event |
| Failed/blocked logins | Five events site-wide in a 15-minute window |
| Rejected RPC/CSRF requests, denied central admin checks, or session-identity spoof attempts | Five events site-wide in a 15-minute window |

Each category permits one delivery attempt every 15 minutes. Counts and cooldowns persist across requests in `critical-alerts.json`, protected by a file lock, excluded from Git and denied by Apache. Replicate that deny rule when using another web server. Events before alerts are enabled are not replayed. Disabling/re-enabling does not erase a cooldown.

Emails include the site name, category, UTC time, and count. They exclude raw log messages, usernames, addresses of visitors, passwords, request URLs and authentication tokens. Inspect the protected application log for details. Signals indicate suspicious activity, not proof of compromise; ordinary expired sessions can also produce CSRF failures.

Delivery is best-effort and synchronous through the configured SMTP client. A recursion guard prevents SMTP errors from generating more alert emails. Recipients are resolved once per alert, so one failing address does not stop the others and does not consume an extra cooldown slot. A failed delivery still consumes the cooldown, and a generic diagnostic goes to PHP's error log. Disabled mail, unavailable state storage, process termination before bootstrap, out-of-memory failures, and an unavailable PHP/server process can prevent delivery. This is not a queued or externally monitored alert service. Use independent host monitoring for those cases.

Validation: `php tests/alerts.php` covers classifications, thresholds, persisted cooldowns, recipient validation and fan-out to each administrator, message minimization, disabled alerts, delivery failure and recursion; the transport is mocked. `python3 tests/alerts-runtime.py` checks uncaught exceptions and fatal errors in subprocesses without sending mail.

The consolidated security changes also require operators to set `GHOTI_PUBLIC_URL` to the canonical HTTPS site URL for password-recovery links and explicitly configure `GHOTI_SETUP_KEY` for browser-based setup/recovery. See the security review and bootstrap CLI instructions before deployment.
