# Saucal Hub — Features

Tracks what is implemented (checked) vs planned (unchecked). Update this list as
features land.

## Done

### Foundation
- [x] Plugin scaffold on the Saucal boilerplate (PSR-4 `SaucalHub\`, composer autoload)
- [x] React + PrimeReact admin app built with `@wordpress/scripts` (webpack)
- [x] Watch / dev build mode (`npm run watch`)
- [x] Production build (minified + unminified) (`npm run build:assets`)
- [x] Scoped admin asset enqueue (deps + version read from generated `*.asset.php`)
- [x] WooCommerce made optional (Woo checks degrade to N/A on non-Woo sites)

### Site management
- [x] Site registry: add / list / remove sites (label, URL, path, db, prefix)
- [x] "This site" always present as the scan/fix target
- [x] Add-site dialog in the UI
- [x] Auto-discovery of sibling sites from `/var/www/*` (pick-list + manual override)

### Cross-site (the "hub") — DB-first + CLI hybrid
- [x] **Remote scanning over the shared database** (live from the UI, no CLI run, nothing installed on the target)
- [x] **Remote fixes over the shared database** (cross-DB writes, guarded on the TARGET host)
- [x] `Source` abstraction: `LocalSource` (in-context WP funcs) + `RemoteSource` (cross-DB + wp-config parse) — one check codebase, two backends
- [x] WP-CLI command `wp saucal-hub scan|make-safe|fix` (authoritative in-context path; resolves what DB can't, e.g. env-var WP_ENVIRONMENT_TYPE)
- [x] Cross-site CLI bootstrap (runs the engine on a site without the plugin active)
- [x] Host orchestrator `bin/saucal-hub.sh scan|make-safe <path>|--all`
- [x] `--report` stores results in the target's option; hub reads them back cross-DB
- [x] Production guard evaluates the TARGET host (not the hub) before remote fixes

### Safety engine
- [x] Extensible check engine + `saucal_hub_safety_checks` filter
- [x] Abstract `Check` base (scan + fix + applicability + severity)
- [x] Production guard (refuses fixes on non-clone hosts)
- [x] Full scan with per-check status (Safe / Unsafe / Warning / N/A) + summary
- [x] Per-check fix
- [x] One-click "Make site safe" (fix all unsafe/warning)

### Checks
- [x] Environment type detection (`WP_ENVIRONMENT_TYPE`) — manual remediation
- [x] WP-Cron disabled detection — manual remediation
- [x] Subscriptions automatic payments OFF (fixable)
- [x] Cancel pending scheduled subscription-payment actions (fixable)
- [x] Payment gateways forced to test/sandbox — WooPayments, Stripe, PayPal (fixable)
- [x] Saved payment tokens neutralised — dummy or delete (fixable)
- [x] Transaction/charge/intent meta stripped — HPOS-aware (fixable)
- [x] Outgoing email guard — restrict to allowed domains (fixable)
- [x] Customer email obfuscation outside allow-list (fixable)
- [x] WP-Cron option thrash detection — names the class + plugin hammering the `cron`
      row (runtime monitor + admin alert; works locally and over the shared DB) — manual remediation

### Payments / email safety
- [x] Outgoing email guard runtime (`wp_mail` interception, block/redirect)
- [x] Email allow-list configurable from the UI (default `saucal.com`)
- [x] Disable automatic subscription renewals
- [x] Re-enable automatic renewals — gated on safe host + email guard ON
- [x] Dummy/padded payment tokens (vs. hard delete)

### Auditing / logging
- [x] Activity log with full before → after snapshots (status, message, changed values)
- [x] Activity dialog in the hub UI (includes remote DB fixes, attributed to target host)
- [x] WC_Logger mirror (source `saucal-hub`) → WooCommerce → Status → Logs (in-context)
- [x] Per-check "Technical detail" expander (raw scan data)
- [x] Granular per-row detail (capped 100/check): order id, meta key/value, token rows,
      pending action args, affected user emails — captured before destructive fixes
- [x] Log subscription / email-guard toggles
- [x] REST `GET/DELETE /activity`

### Docs
- [x] README.md (features + architecture + dev)
- [x] CHANGELOG.md
- [x] FEATURES.md (this file)

## Pending / planned

### Cross-site (the "hub")
- [ ] Per-site safety status dashboard (aggregate across all registered sites at a glance)
- [ ] UI button to trigger the in-context CLI scan/fix directly (needs a host-side
      runner/daemon, since PHP-FPM can't exec into the nodephp container)
- [ ] Signed-REST companion mode for sites NOT on the shared DB/host (cf. serviceapp-client)
- [ ] Object-cache invalidation on the target after a cross-DB write (currently the
      target may serve stale cached options until its cache clears; the CLI path avoids this)

### Checks
- [ ] AutomateWoo / follow-up emails neutralisation
- [ ] WC webhooks disable
- [ ] REST API keys revoke
- [ ] Third-party SMTP plugin detection (warn if mail bypasses `wp_mail`)
- [ ] Search-and-replace of the production domain in the DB
- [ ] Scheduled-actions broad health check (beyond subscription payments)

### UX / ops
- [ ] Dry-run preview (show what each fix *would* change before applying)
- [ ] Scan history / audit log of applied fixes
- [ ] WP-CLI command (`wp saucal-hub scan|make-safe`) to reuse the engine headless
- [ ] ACF Pro options screen for default allow-list domains / per-site config
- [ ] Run the full safety pass automatically on detected domain change (clone import)
- [ ] Email guard: redirect-to address field in the UI (backend already supports it)
