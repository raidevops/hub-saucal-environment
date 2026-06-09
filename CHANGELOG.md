# Changelog

All notable changes to Saucal Hub are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and the project adheres to
semantic versioning.

## [Unreleased]

### Added — Performance / stability
- **WP-Cron option thrash detection** (`Safety\CronWatch` + `cron_option_thrash`
  check): a runtime monitor attributes every write to the single `cron`
  `wp_options` row to the nearest non-core class + plugin/mu-plugin/theme via a
  backtrace. When a caller rewrites that row repeatedly (e.g. a hook that
  (un)schedules events on every request), the offender — class, file:line,
  affected cron hooks, plugin name/version and any available update — is recorded
  to a site option and surfaced by:
  - the `cron_option_thrash` safety check (works locally and over the shared DB
    for remote sites), and
  - an `admin_notices` alert naming the class + plugin and suggesting an update or
    removal.
  Self-bounding: healthy sites (0–1 cron writes/request) never trip it and it
  never writes to the DB; on a thrashing site it persists at most once per minute
  per offender. Threshold filterable via `saucal_hub_cron_watch_threshold`.
- **`wp saucal-hub cron-forensics`** — active, in-context detection for when the
  passive monitor has nothing yet (e.g. scanning a site from the hub before any
  traffic). It reports what was captured *naturally* during the wp-cli bootstrap
  (the listeners attach before `init` — via the plugin, or via `cli-bootstrap.php`
  on sites that don't have it active), so a single run reproduces and attributes
  the thrash. `--report` persists the findings so the hub UI/notice show them.
  `--replay` (clone-only, `--force` to override) additionally re-fires init/wp_loaded.
  Uses a **two-pass** check (a fresh second process) so legitimate one-time
  scheduling — a plugin scheduling its recurring events because they were missing —
  is excluded; only callers that rewrite the cron row on *every* request are
  reported. `--report` writes an authoritative snapshot, so once the offending code
  is fixed a re-run flips the check back to SAFE immediately (no 24h wait).

### Changed — Admin UX
- The selected site is now reflected in the URL (`?page=saucal-hub&site=<id>`),
  so site views are bookmarkable and browser back/forward switches sites.

### Added — Auditing / logging
- **Granular per-row detail** in scans and logs (capped at 100 rows/check):
  - `transaction_meta`: order/subscription ID, post type, meta key, stored value
  - `payment_tokens`: token id, gateway, type, user id, token value
  - `scheduled_subscription_payments`: action id, hook, schedule, args
  - `user_emails`: user id, login, real email
  Destructive fixes capture these rows *before* mutating, so the log shows exactly
  what was removed/changed.
- **Activity log** (`Safety\Logger`): every fix and toggle is recorded with a full
  before/after snapshot (status, message, concrete changed values) in a ring-buffer
  option; surfaced in a new **Activity** dialog in the hub (remote DB fixes included,
  attributed to the target host).
- **WC_Logger mirror**: when WooCommerce is loaded in-context (local fix or a `wp
  saucal-hub` run on the target), entries are also written under source `saucal-hub`
  and appear at WooCommerce → Status → Logs.
- **Per-check technical-detail expander** in the UI (raw scan data).
- REST: `GET/DELETE /activity`.

### Added — Cross-site (hybrid DB-first + wp-cli)
- **`Source` abstraction** (`Safety\Source\*`): checks read/write through a source,
  so the same check code runs in-context (`LocalSource`) or against another local
  site over the shared DB (`RemoteSource`, cross-DB + wp-config parse).
- **Live remote scanning & fixing from the UI** over the shared database — nothing
  installed on the target; production guard evaluates the **target** host.
- **Auto-discovery** of sibling sites from `/var/www/*` (`Sites\Inspector`), shown
  as a pick-list in the Add-site dialog with manual override.
- **WP-CLI command** `wp saucal-hub scan|make-safe|fix` (`CLI`) + cross-site
  `cli-bootstrap.php` (runs the engine on sites without the plugin active) — the
  authoritative path for what the DB can't determine in-context (e.g. env-var
  `WP_ENVIRONMENT_TYPE`).
- **Host orchestrator** `bin/saucal-hub.sh scan|make-safe <path>|--all`.
- REST: `/discover`, `/sites/{id}/report`, and a `site` param on `/scan` and `/fix(-all)`.

### Added
- **Staging-safety engine** (`SaucalHub\Safety\Engine`): an extensible registry
  of checks, each able to scan and fix, with a `saucal_hub_safety_checks` filter
  for adding custom checks.
- **Production guard** (`ProductionGuard`): refuses to apply fixes on any host
  that doesn't look like a local/staging clone; override via
  `SAUCAL_HUB_ALLOW_UNSAFE_HOST`.
- **Safety checks** ported/expanded from `tbx-sanitize-clone.php`:
  - `environment_type` — detects `WP_ENVIRONMENT_TYPE` (manual remediation).
  - `disable_wp_cron` — detects WP-Cron status (manual remediation).
  - `subscriptions_auto_payments_off` — turns off automatic subscription payments.
  - `scheduled_subscription_payments` — cancels pending Action Scheduler renewals.
  - `gateways_test_mode` — forces WooPayments/Stripe/PayPal into test/sandbox.
  - `payment_tokens` — neutralises saved card tokens (dummy or delete).
  - `transaction_meta` — strips gateway transaction meta (HPOS-aware).
  - `outgoing_email_guard` — enables the outgoing email allow-list.
  - `user_emails` — obfuscates non-allow-listed customer emails.
- **Outgoing email guard** (`EmailGuard`): runtime `wp_mail` interceptor that
  restricts mail to allow-listed domains (default `saucal.com`), block or
  redirect mode.
- **Automatic subscription renewals toggle**: disable any time; re-enabling is
  gated on a safe host + the email guard being ON.
- **Site registry** (`Sites\Registry`): add / list / remove local sites; "this
  site" is always present as the scan/fix target.
- **REST API** under `saucal-hub/v1` (admin + nonce protected): `/sites`,
  `/scan`, `/fix`, `/fix-all`, `/email-guard`, `/subscriptions/automatic`.
- **React + PrimeReact admin app**: site selector, grouped safety checklist with
  per-check Fix and one-click "Make site safe", email-guard panel, subscriptions
  panel. Built with `@wordpress/scripts`; supports watch mode.

### Known limitations
- Remote scan/fix uses the shared DB; after a cross-DB write the target may serve
  stale cached options until its object cache clears — use the wp-cli path for a
  fully in-context apply. The UI cannot trigger the CLI directly (FPM can't exec
  into the wp-cli container); run `bin/saucal-hub.sh` from the host. See `FEATURES.md`.

[Unreleased]: https://saucal.com/
