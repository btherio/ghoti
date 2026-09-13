# Harden account recovery, session revocation, and shared security boundaries

Database outages previously exposed public setup when no key was configured, fresh public signup could become admin, and reset emails trusted the request Host. This change requires an operator key for setup, adds CLI-only initial admin provisioning, and builds recovery links from an explicitly configured HTTPS URL.

It also revokes stale credential sessions, serializes reset consumption, preserves concurrent throttle updates, removes raw session IDs from new analytics, neutralizes CSV formulas, checks protected filesystem ancestors and legacy URL schemes, and bounds RPC envelopes. Existing login sessions must sign in again. Configure GHOTI_PUBLIC_URL and writable root login.throttle.json before rollout; GHOTI_SETUP_KEY is required only to enable web setup.

Validation: 85 PHP security assertions, 7 loopback HTTP cases, 6 JavaScript URL cases, PHP syntax across all 50 PHP files, and diff whitespace checks. No production DB or mail was used. Real MySQL/MariaDB reset concurrency and deployed Apache rules still require staging validation.

See security-review-2026-09-12.md for severity, prerequisites, evidence, compatibility changes, and unresolved risks. The branch starts from fix/analytics-block-and-mysql-pdo-constant at c6e4ac3; choose that as the initial PR base unless its changes have already merged into main.
