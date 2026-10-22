# Techzapp CRM

A self-hosted email marketing CRM for Hostinger shared hosting: contacts, lists,
campaigns, scheduled sending through Amazon SES, delivery and engagement
tracking, suppression management and deliverability monitoring.

No third-party CRM, no external database, no separate backend service. A React
SPA served as static files, a PHP API, MySQL, and cron jobs that do the
background work.

---

## How it works

```
Browser ──HTTPS──▶ public_html/
                   ├── index.html + assets/     React SPA (static)
                   ├── api/index.php            JSON API  ─┐
                   ├── t/o.php  t/c.php         tracking   │
                   ├── u/index.php              unsubscribe│
                   └── webhooks/ses.php         SNS events │
                                                           ▼
                                              private/backend/   (outside the web root)
                                              ├── src/           services, controllers
                                              ├── config/        secrets
                                              ├── storage/       imports, logs, sessions
                                              └── cron/ ─────┐
                                                             │
Hostinger cron ──────────────────────────────────────────────┘
   every minute   process_campaigns.php   send in bounded batches
   every 5 min    process_events.php      parse SNS, refresh reports, alerts
   every minute   process_imports.php     continue large CSV imports
   hourly         maintenance.php         cleanup, pruning, log rotation

                                              Amazon SES ──▶ recipient
                                                   │
                                                   └── SNS ──▶ /webhooks/ses.php
```

Three ideas shape the whole system:

1. **Nothing long-running.** Shared hosting has no daemons and short request
   limits, so every operation is a bounded, resumable batch that records where it
   stopped. A 40,000-recipient campaign is simply a campaign that takes many
   cron runs.
2. **The database enforces the rules.** Single-list membership, one recipient row
   per campaign, one event per provider notification — all UNIQUE constraints,
   not application-level checks. Concurrent cron runs cannot break them.
3. **Accepted is not delivered.** The SES API accepting a message and a Delivery
   event arriving are tracked separately and never conflated, so a report can
   show that SES took 40,000 messages and only 38,000 arrived.

## Features

**Contacts** — server-side pagination over 40k+ rows, search and filters,
chunked CSV import with column mapping, preview, duplicate handling and a
downloadable error report, CSV export with spreadsheet-formula protection,
custom fields, per-contact campaign and suppression history.

**Lists** — exclusive membership enforced by the schema. The Contacts page shows
unassigned contacts only; assigning a contact moves it off that page. Deleting a
list releases its contacts and never deletes them.

**Campaigns** — four-step builder (details, content, preview and test, schedule),
rich-text editor with merge tags, desktop and mobile preview, restricted test
sends, a pre-flight readiness check, UTC scheduling with timezone display, and a
state machine where `completed` is terminal so a doubled cron run cannot resend.

**Sending** — Amazon SES v2 through the AWS SDK, per-recipient personalization,
rate and daily caps, exponential backoff for transient failures, a distinct
"uncertain" outcome that is reconciled rather than retried, and a global kill
switch.

**Tracking** — open pixel and click redirects on your own domain. Tracking URLs
carry an opaque token and an HMAC bound to the purpose, never a database id, and
the click endpoint resolves destinations from a pre-registered table so it cannot
be turned into an open redirect.

**Reporting** — per-campaign and account-level metrics with every denominator
documented in the UI, idempotent event processing, and data-quality notes that
explain incomplete data instead of hiding it.

**Deliverability** — configurable bounce and complaint thresholds, rolling
windows, red/amber/green/**unknown** health, automatic pause on a complaint
spike, and alerts for stalled cron jobs, overdue campaigns, failed event
processing and SES account problems.

**Suppression** — global and keyed on the email address, so it survives list
changes, re-imports and contact deletion. One-click unsubscribe (RFC 8058) with
a landing page, plus an append-only audit trail.

## Requirements

| | Minimum |
|---|---|
| PHP | 8.2 (8.3 recommended) with `pdo_mysql`, `mbstring`, `openssl`, `json`, `curl`, `dom`, `fileinfo` |
| MySQL | 8.0, or MariaDB 10.5 |
| Node.js | 20+ — **build machine only**, never on the server |
| Composer | 2.x |
| Docker | Optional, for the local MySQL in `docker-compose.yml` |
| Hosting | Shared hosting with SSH and cron |
| Email | An Amazon SES account with a verified identity and production access |

## Local development

MySQL runs in Docker; PHP and Node run on your machine.

```bash
# 1. Databases — one for development, one throwaway for the test suite
docker compose up -d
docker compose ps            # wait for both to report (healthy)

# 2. Backend dependencies
cd backend
composer install

# 3. Configuration
cp config/config.example.php config/config.php
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"   # paste into app.secret
```

Set these in `config/config.php` for local work:

```php
'app'      => ['url' => 'http://localhost:8788', 'env' => 'development', 'debug' => true, …],
'database' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'techzapp_crm',
                'user' => 'techzapp', 'password' => 'techzapp_dev', …],
'session'  => ['secure_cookie' => false, …],   // no HTTPS locally
'ses'      => ['transport' => 'log', …],       // writes to storage/logs/mail.log, sends nothing
```

```bash
# 4. Schema and first user
php bin/migrate.php
php bin/create_admin.php --email=you@example.com --generate

# 5. Frontend
cd ../frontend
npm install
npm run build

# 6. Serve
cd ..
rm -rf public/assets && cp -r frontend/dist/* public/
php -S localhost:8788 -t public backend/bin/dev_server.php
```

Open <http://localhost:8788>.

`backend/bin/dev_server.php` reproduces what `public_html/.htaccess` does on
Hostinger: `/api/v1/*` goes to the front controller, real files are served, and
every other path returns the SPA shell so a hard refresh on `/campaigns/4/report`
works.

For hot reload, run `npm run dev` in `frontend/` instead of step 5 — Vite serves
the UI on :5173 and proxies `/api`, `/t` and `/u` to :8788, so keep the PHP
server running too.

### Docker commands

```bash
docker compose up -d           # start both databases
docker compose ps              # health
docker compose logs -f mysql   # startup problems
docker compose down            # stop, keeping the dev data volume
docker compose down -v         # stop and wipe the data
```

| Container | Port | Database | Purpose |
|---|---|---|---|
| `techzapp-crm-mysql` | 3306 | `techzapp_crm` | Development data, persisted in a named volume |
| `techzapp-crm-mysql-test` | 3307 | `techzapp_crm_test` | Test suite. RAM-backed (tmpfs) and truncated by every run |

Both are MySQL 8.4 with `--default-time-zone=+00:00`, matching the UTC
assumption the application makes everywhere.

> The credentials in `docker-compose.yml` are local-only. Real secrets live in
> `backend/config/config.php`, which is git-ignored.

### Background workers

Nothing schedules these locally — run them by hand, or leave a loop running:

```bash
php backend/cron/process_campaigns.php   # queue due campaigns, send batches
php backend/cron/process_events.php      # parse SNS events, refresh reports, alerts
php backend/cron/process_imports.php     # continue large CSV imports
php backend/cron/maintenance.php         # cleanup and log rotation

# or, roughly what cron does:
while true; do php backend/cron/process_campaigns.php; sleep 60; done
```

Until one has run, the dashboard reports cron health as **unknown** — that is
the intended behaviour, not a bug.

With `ses.transport = 'log'` nothing leaves your machine; sent messages are
appended to `backend/storage/logs/mail.log`.

## Tests

```bash
cd backend
vendor/bin/phpunit --testsuite unit                 # no database needed

TEST_DB_NAME=techzapp_crm_test TEST_DB_USER=root TEST_DB_PASSWORD=secret \
  vendor/bin/phpunit                                # full suite
```

247 tests, 974 assertions. The integration suite includes a real 40,000-row
import and concurrency tests that spawn multiple OS processes to race the same
campaign and the same contacts. See [docs/TESTING.md](docs/TESTING.md).

## Deployment

Step-by-step for Hostinger: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). It covers the
directory layout, finding your real paths, the database user, Composer, secrets,
migrations, the first administrator, SES identities and event publishing, the
cron commands, backups and troubleshooting.

## Project layout

```
backend/
├── bin/           migrate.php, create_admin.php, dev_server.php
├── config/        config.example.php (copy to config.php — git-ignored)
├── cron/          the four background workers
├── migrations/    SQL, applied in filename order
├── src/
│   ├── Core/      Config, Database, Auth, Settings, Lock, Logger, Audit, CronRunner
│   ├── Http/      Request, Response, Router, Validator, ApiException
│   ├── Controllers/
│   ├── Services/  Contact, List, Campaign, Sender, Renderer, Import, Event,
│   │              Report, Alert, Suppression, Tracking, Mail/
│   └── Support/   EmailAddress, Csv, EmailHtml, Personalization, Tokens
├── storage/       imports, logs, sessions, tmp  (never web-accessible)
└── tests/         Unit/, Integration/, workers/

frontend/src/
├── components/    ui/ primitives, layout/, RichTextEditor
├── features/      auth, dashboard, contacts, lists, campaigns, reports,
│                  suppressions, settings
├── hooks/         useResource, useDebounced, usePoll
└── lib/           api client, types, formatting helpers

public/            everything that goes in public_html
docker-compose.yml local MySQL for development and tests
docs/              deployment, API, testing, limitations
```

## Security

- Argon2id password hashing (bcrypt fallback), login throttling per identity and
  per IP.
- `HttpOnly` + `Secure` + `SameSite=Lax` session cookies, session id regenerated
  on login, idle timeout, client binding.
- CSRF token required on every state-changing request.
- Every database access is a prepared statement; the test suite asserts that no
  SQL reuses a named placeholder and that every route calls an auth guard.
- Campaign HTML is sanitized with HTMLPurifier; contact data is HTML-escaped at
  render time.
- CSV exports are protected against spreadsheet formula injection.
- Tracking and unsubscribe URLs carry no database ids and are HMAC-signed.
- SNS webhooks are signature-verified and topic-restricted.
- Secrets live in `backend/config/config.php` outside the web root — never in the
  database, never in the frontend bundle, never in git.
- Administrative actions are written to an append-only audit log.

Run a security review of the deployed site with the checklist in
[docs/TESTING.md](docs/TESTING.md).

## Known limitations

Read [docs/LIMITATIONS.md](docs/LIMITATIONS.md) before going live. The short version:
cron frequency sets your scheduling resolution and throughput; open tracking is
indicative rather than factual; the alert thresholds are this application's, not
Amazon's; a contact belongs to one list at a time; and nothing here verifies that
your recipients consented — that remains your responsibility.

## Licence

Proprietary — built for Techzapp.
