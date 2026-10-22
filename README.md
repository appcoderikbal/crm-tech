# Techzapp CRM — WordPress plugin

Email marketing inside WordPress: contacts, lists, campaigns, scheduled sending
through Amazon SES, delivery and engagement tracking, suppression management and
deliverability monitoring.

> **Status: migration in progress.** The data layer, service layer, background
> workers, public endpoints, CLI and updater are complete and verified in
> WordPress. Contacts and importing work; the campaign screens are still being
> written — see "What works today" below.

## Installing

This repository **is** the plugin, with its libraries committed, so it installs
without a build step.

### From the repository

1. **Code → Download ZIP** on GitHub
2. WordPress admin → **Plugins → Add New → Upload Plugin**
3. Choose the ZIP, install, activate

WordPress names the folder after the ZIP, so a download from the main branch
installs as `crm-tech-main`. That works, but the folder from a release ZIP is
the tidier `techzapp-crm`.

### From a release (recommended)

Releases at
[github.com/appcoderikbal/crm-tech/releases](https://github.com/appcoderikbal/crm-tech/releases)
carry a `techzapp-crm.zip` built by `bin/build-release.sh`. That is also the
file the in-WordPress updater installs, so the folder name stays consistent
between a first install and every update afterwards.

### Over SSH

```bash
cd wp-content/plugins
git clone https://github.com/appcoderikbal/crm-tech.git techzapp-crm
wp plugin activate techzapp-crm
```

Then `git pull` to update, though the admin update screen is easier.

## How it fits into WordPress

| Concern | Standalone app | This plugin |
|---|---|---|
| Authentication | Own users table, sessions | WordPress users |
| Authorization | admin/operator/viewer roles | `techzapp_crm_view` / `_manage` / `_settings` capabilities |
| CSRF | Own token | WordPress nonces |
| Database | Own PDO wrapper | `$wpdb`, tables prefixed `wp_techzapp_` |
| Schema changes | SQL migration files | `dbDelta()` on activation |
| Settings | Config file + table | One `wp_options` row |
| Secrets | File outside the web root | `wp-config.php` constants |
| Scheduling | System cron | WP-Cron, or WP-CLI from system cron |
| Public endpoints | PHP files in public_html | REST routes |
| Admin UI | React SPA | Native WordPress screens |

The service layer — sending pipeline, chunked import, event processing,
reporting, alerting — was ported essentially intact, because it only ever
touched six Core classes. Those were swapped for WordPress-backed equivalents.

## Secrets

Put credentials in `wp-config.php`, not in the Settings screen:

```php
define( 'TECHZAPP_CRM_AWS_ACCESS_KEY_ID',     'AKIA…' );
define( 'TECHZAPP_CRM_AWS_SECRET_ACCESS_KEY', '…' );
define( 'TECHZAPP_CRM_AWS_REGION',            'eu-west-1' );

/* Optional. Defaults to a WordPress salt, which means changing your salts
   invalidates unsubscribe links in mail already delivered. */
define( 'TECHZAPP_CRM_SECRET', 'base64-encoded-32-bytes' );
```

The Settings screen says which source is actually in use, and warns when
credentials are sitting in the database.

## Background jobs

WP-Cron works out of the box but only runs when someone visits the site, so a
quiet site sends late. For real volume, disable it and use system cron:

```php
define( 'DISABLE_WP_CRON', true );
```

```cron
* * * * * cd /path/to/wordpress && wp techzapp-crm run process_campaigns --quiet
* * * * * cd /path/to/wordpress && wp techzapp-crm run process_imports --quiet
*/5 * * * * cd /path/to/wordpress && wp techzapp-crm run process_events --quiet
0 * * * * cd /path/to/wordpress && wp techzapp-crm run maintenance --quiet
```

Other commands:

```bash
wp techzapp-crm status                      # queue, workers, health
wp techzapp-crm check                       # re-run deliverability checks
wp techzapp-crm sending stop --reason="…"   # kill switch
wp techzapp-crm sending resume
wp techzapp-crm install-schema              # re-apply dbDelta
```

## Endpoints registered for AWS

Shown on the Settings screen with the live URLs for your install:

| Purpose | Route |
|---|---|
| SNS webhook | `/wp-json/techzapp-crm/v1/webhooks/ses` |
| Open pixel | `/wp-json/techzapp-crm/v1/t/o` |
| Click redirect | `/wp-json/techzapp-crm/v1/t/c` |
| Unsubscribe | `/wp-json/techzapp-crm/v1/u` |

All four are public by necessity — a recipient must be able to unsubscribe
without an account. Authorisation is an opaque token plus an HMAC bound to the
purpose and every parameter.

## Uploaded files

A plugin cannot write outside the web root, so import uploads live in
`wp-content/uploads/techzapp-crm/` behind three layers: an `.htaccess` deny
rule, an `index.php`, and a per-install random suffix in every filename. On
nginx the deny rule is ignored and the unguessable name is what protects them;
the Settings screen warns when it detects that.

## What works today

Verified inside WordPress 6.8 / PHP 8.3 / MySQL 8.4:

- Activation creates all 19 tables via `dbDelta`, seeds settings, grants
  capabilities, creates storage and schedules all four jobs
- All four background jobs run and record heartbeats; health moves from
  "unknown" to "green" once they have reported
- Dashboard, Contacts (with filters, bulk assign/delete, CSV export) and
  Settings screens render
- All four REST endpoints registered and behaving safely: the pixel returns a
  GIF for an invalid token, a forged click signature redirects to the site root
  rather than anywhere attacker-supplied
- WP-CLI commands

Still to build: the Lists, Campaigns, Campaign editor, Campaign report, Import,
Suppressions, Deliverability, Contact edit and Audit log screens. Menu items
only appear for screens that exist, so nothing in the UI leads to a broken page.

## Updating production from GitHub

The plugin checks this repository for new releases and offers them through the
normal WordPress update screen — the same "Update now" button as any other
plugin. No FTP, no re-uploading.

### Cutting a release

```bash
cd techzapp-crm
./bin/release.sh 1.0.1 --notes "Adds the campaigns screen"
```

That bumps the version in both places WordPress reads, builds the ZIP, verifies
the packaged version matches, commits, tags `v1.0.1`, pushes, and publishes a
GitHub Release with the ZIP attached.

Use `--dry-run` first to bump and build without committing anything.

Publishing needs either the [GitHub CLI](https://cli.github.com/) authenticated,
or `GITHUB_TOKEN` exported. Without one the script still commits and pushes the
tag, then tells you to attach the ZIP by hand.

### Why the ZIP has to be attached

GitHub generates a source archive for every tag, and that archive is **not
installable**. `vendor/` is git-ignored, so it contains no AWS SDK and no
HTMLPurifier — a site updated from it would fatal on the next send.

The updater therefore only offers a release that has a `techzapp-crm.zip`
asset attached. A release without one is reported as "no update available"
rather than installed, which is the safe way round.

### On the production site

- Updates appear within six hours, or immediately via
  **Plugins → Techzapp CRM → Check for updates**
- **Settings → Hosting environment** shows the installed version and whether a
  newer one is waiting
- Every version stays available: releases are immutable, so rolling back is a
  matter of installing an older release's ZIP

For a private repository, or to lift GitHub's 60-requests-per-hour anonymous
limit, add a token to `wp-config.php`:

```php
define( 'TECHZAPP_CRM_GITHUB_TOKEN', 'ghp_…' );
```

### Version numbering

`MAJOR.MINOR.PATCH`. The database schema carries its own `DB_VERSION`, so a
release that does not touch the schema will not re-run `dbDelta`. Schema
changes are additive and applied automatically on upgrade.

## Repository layout

```
techzapp-crm.php      plugin entry point
includes/
  Core/               database adapter, settings, capabilities, cron, updater
  Services/           contacts, lists, campaigns, sending, import, events,
                      reporting, alerting, SES transports
  Support/            email addresses, CSV, HTML sanitising, personalisation
  Admin/              menu, screens, list tables, setup guide
  Rest/               tracking, unsubscribe and SNS endpoints
  Cli/                WP-CLI commands
assets/               admin CSS and JS
vendor/               bundled libraries, committed so the ZIP installs as-is
bin/                  build and release scripts (not shipped in the ZIP)
```

`vendor/` is about 8 MB rather than the 72 MB a plain `composer install`
produces: `bin/install-vendor.sh` removes every AWS service definition except
SES and SNS. Run it after changing `composer.json`, then commit the result.

## Requirements

PHP 8.2+, WordPress 6.0+, MySQL 8.0+ / MariaDB 10.5+, and an Amazon SES account
with a verified identity and production access.
