# Saucal Hub

A WordPress admin tool for managing **local / staging clones** of Saucal sites and
making them **safe to test on** — so a clone imported from production can never
double-charge a customer, fire renewals, or email real people.

It productises the one-off `tbx-sanitize-clone.php` script into an extensible,
UI-driven plugin: register sites, run a full safety scan, fix issues individually
or all at once, and control the guards that let you safely test payments.

- **Admin UI:** React + [PrimeReact](https://primereact.org/), built with
  `@wordpress/scripts` (webpack). Supports a watch/dev build.
- **Backend:** PHP 8.1+, PSR-4 (`SaucalHub\`), a REST API under `saucal-hub/v1`.
- **Where it runs:** activate it on the **hub** site. It scans/fixes "this site"
  **and** other local sites — auto-discovered and operated on over the shared
  database (with wp-cli as the authoritative fallback). See *Remote sites* below.

---

## Why it exists

Importing a production database into a clone brings live gateway config, saved
cards, active subscriptions and a full Action Scheduler queue. If WP-Cron runs
and a gateway is live, **renewals charge real customers with no trace on prod.**
Saucal Hub detects and remediates every one of those vectors, and adds runtime
guards (email allow-list) so you can still *test* payments without collateral.

A **hard production guard** refuses to apply any fix unless the current host
looks like a local/staging clone (`.local`, `.test`, ngrok, `staging`/`dev`, or
`WP_ENVIRONMENT_TYPE` in local/staging/development). Override only via
`define( 'SAUCAL_HUB_ALLOW_UNSAFE_HOST', true )`.

---

## Features

### Site management
- **Add / list / remove sites** (label, URL, optional local path). The current
  site is always present as **"this site"** and is the scan/fix target.

### Full safety scan
One click runs every registered check and reports a status per check —
**Safe / Unsafe / Warning / N/A** — grouped by area, with a summary count and a
banner telling you whether the host is safe to mutate.

### Safety checks (extensible)
Each check can **scan** (report state) and most can **fix** (remediate):

| Group | Check | Fixable | What it does |
|-------|-------|---------|--------------|
| Environment | `environment_type` | manual | Flags `WP_ENVIRONMENT_TYPE=production` on a clone (shows the exact `wp config set` command). |
| Environment | `disable_wp_cron` | manual | Warns if WP-Cron is enabled (shows the `wp config set DISABLE_WP_CRON true --raw` command). |
| Subscriptions | `subscriptions_auto_payments_off` | ✅ | Forces `woocommerce_subscriptions_turn_off_automatic_payments=yes` — the primary lever; all renewals become manual. |
| Subscriptions | `scheduled_subscription_payments` | ✅ | Cancels pending Action Scheduler `scheduled_subscription_payment` actions. |
| Payments | `gateways_test_mode` | ✅ | Forces WooPayments / Stripe / PayPal (ppcp) into test/sandbox mode. |
| Payments | `payment_tokens` | ✅ | Neutralises saved card tokens — replaces gateway ids with dummy values (or deletes, `mode=delete`). |
| Payments | `transaction_meta` | ✅ | Strips stored gateway transaction/charge/intent meta from orders & subscriptions (HPOS-aware). |
| Email | `outgoing_email_guard` | ✅ | Enables the runtime guard so mail only reaches allow-listed domains. |
| Email | `user_emails` | ✅ | Obfuscates customer emails outside the allow-list to non-routable `.invalid` addresses. |

WooCommerce-specific checks report **N/A** automatically on sites without
WooCommerce / Subscriptions.

### "Make site safe" (one click)
Runs the fix for every applicable, unsafe/warning check at once, then re-scans.

### Activity log (before → after audit trail)
Every fix and toggle is recorded with the full **before/after** technical snapshot
(status, message and the concrete values/counts that changed). View it from the
**Activity** button in the hub — including remote DB fixes, attributed to the
target host. When WooCommerce is loaded in-context (a local fix, or a `wp
saucal-hub` run on the target) the same entry is also written to **`WC_Logger`**
under source `saucal-hub`, so it appears at **WooCommerce → Status → Logs**. Each
check row also has a *Technical detail* expander showing its raw scan data.

### Outgoing email guard
A runtime `wp_mail` interceptor. When ON, outgoing mail can only reach
allow-listed domains (default `saucal.com`); everything else is blocked (or
redirected). This is what makes it safe to exercise renewals/notifications on a
clone. Configurable from the UI (toggle + domains).

### Automatic subscription renewals toggle (guarded)
Turn automatic renewals **off** (manual — safe) any time. **Re-enabling** is
gated: it's only allowed when the host is a safe clone **and** the email guard is
ON — so re-enabled renewals can only ever email allowed domains. This is the
"test payments safely without colliding with production" workflow.

### Adding your own checks
Checks are registered on a filter. Drop in a class extending
`\SaucalHub\Safety\Check` and append it:

```php
add_filter( 'saucal_hub_safety_checks', function ( $checks ) {
    $checks['my_check'] = new \My\Plugin\MyCheck();
    return $checks;
} );
```

A check implements `id()`, `label()`, `scan()` and (optionally) `fix()`; see
`includes/Safety/Checks/*` for examples.

---

## Remote sites (hybrid: DB-first + wp-cli)

The hub manages other local sites without anything installed on them. Because
every site in this docker environment shares one MySQL server (and the hub
connects as root), the hub reads/writes any site's data directly:

- **Auto-discovery:** the hub scans `/var/www/*` for `wp-config.php`, parses each
  site's DB name + table prefix, and reads its URL/name from the shared DB. The
  "Add site" dialog shows these as a pick-list (with manual override).
- **DB-first scan & fix (default):** the same check code runs through a
  `RemoteSource` that issues fully-qualified `db.table` queries and parses
  `wp-config.php` for the two PHP constants. Scans and fixes are **live from the
  UI** — no CLI run, nothing installed on the target. Fixes are guarded on the
  **target** host (a remote production site is refused).
- **wp-cli as the authoritative fallback:** anything the DB can't answer
  in-context (e.g. `WP_ENVIRONMENT_TYPE` set via an env var rather than a
  wp-config define) is resolved by running the engine inside the site's own
  bootstrap:

  ```bash
  # one site
  bin/saucal-hub.sh scan /var/www/talkboxmom/ngrok
  bin/saucal-hub.sh make-safe /var/www/talkboxmom/ngrok
  # every registered site
  bin/saucal-hub.sh scan --all
  ```

  This runs `wp --path=<site> --require=<plugin>/cli-bootstrap.php saucal-hub
  <action> --report`, storing the result in the target's `saucal_hub_last_report`
  option, which the hub reads back over the shared DB.

> Why CLI can't be triggered from the UI button: the plugin runs under PHP-FPM,
> which can't `docker exec` into the `nodephp` (wp-cli) container. Run the
> orchestrator from the host. (A host-side runner is a planned enhancement.)

## Architecture

```
saucal-hub.php              Bootstrap (composer autoload guard) → Main::bootstrap()
includes/
  Main.php                  Plugin lifecycle; wires REST + EmailGuard + admin
  Admin/Page.php            Top-level menu + scoped React asset enqueue
  Rest/Controller.php       REST API (saucal-hub/v1): sites, discover, scan, fix, email-guard, subscriptions
  CLI.php                   wp saucal-hub scan|make-safe|fix
  Safety/
    Engine.php              Check registry + scan/fix through a Source (local or remote)
    Check.php               Abstract base check (reads/writes via $this->src())
    ProductionGuard.php     Refuses fixes on non-clone hosts (evaluates the target host)
    EmailGuard.php          Runtime wp_mail allow-list
    Source/Source.php       Data-source interface
    Source/LocalSource.php  In-context WP functions
    Source/RemoteSource.php Cross-DB + wp-config parse (other local sites)
    Checks/*.php            The individual checks
  Sites/Registry.php        Site registry (options)
  Sites/Inspector.php       Auto-discovery + cross-DB report read-back
cli-bootstrap.php           Loads the engine into any site's wp-cli (--require)
bin/saucal-hub.sh           Host orchestrator (docker-env root): runs the CLI per site
src/js/admin/saucal-hub-admin.js   React + PrimeReact SPA (single entry)
src/js/admin/styles.css            App layout (bundled via JS import)
assets/                     Built JS/CSS (generated; not committed)
```

### REST API (`saucal-hub/v1`, requires `manage_options` + `wp_rest` nonce)
| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/sites` | List sites (self + registered) |
| POST | `/sites` | Add a site `{label,url,path,db_name,table_prefix}` |
| DELETE | `/sites/{id}` | Remove a site |
| GET | `/discover` | Auto-discover sibling sites on the host |
| GET | `/sites/{id}/report` | Stored CLI report + the wp-cli commands for a site |
| GET | `/scan?site=<id>` | Live scan (`self` = local, others = cross-DB) |
| POST | `/fix` | Fix one check `{check, site, args}` |
| POST | `/fix-all` | Fix all unsafe checks `{site}` |
| GET/POST | `/email-guard` | Read / update email guard (this site) |
| POST | `/subscriptions/automatic` | Toggle automatic renewals (guarded) |
| GET/DELETE | `/activity` | Read / clear the before→after activity log |

---

## Development

Requires Node 20+ and Composer. wp-cli for this docker env lives in the
`nodephp` container.

```bash
cd wp-content/plugins/saucal-hub

# install
npm install
composer install

# build assets (production: minified + unminified)
npm run build:assets

# watch / rebuild on change
npm run watch

# lint
npm run lint:js
npm run lint:php
```

In this docker environment, run npm via the container:

```bash
docker compose exec nodephp sh -lc \
  'cd /var/www/hubmanager/public/wp-content/plugins/saucal-hub && npm run watch'
```

Built files land in `assets/` (gitignored). The admin page reads the
webpack-generated `*.asset.php` for the correct script dependencies and version.

> Tip: set `define( 'SCRIPT_DEBUG', true )` to load the unminified bundle.

> Note: the REST API uses pretty permalinks (`/wp-json/...`). On a fresh install,
> set them with `wp rewrite structure '/%postname%/'`.

---

## Usage

1. Activate the plugin on a local/staging site.
2. Open **Saucal Hub** in the admin menu.
3. The scan runs automatically. Review the checklist.
4. Fix issues individually, or click **Make site safe**.
5. To test payments/renewals: enable the **email guard**, then re-enable
   automatic renewals from the Subscriptions panel.

See `FEATURES.md` for what's implemented vs planned, and `CHANGELOG.md` for
version history.
