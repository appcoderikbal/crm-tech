# Deploying to Hostinger shared hosting

This guide assumes a Hostinger shared plan with SSH access, a MySQL database and
a domain with free SSL. Every path below is an example — step 2 shows how to find
**your** real paths rather than guessing them.

> Do not copy the example paths. Hostinger account names, PHP binary locations
> and directory layouts differ between plans and between servers.

---

## 0. Prerequisites checklist

| Requirement | How to check | If missing |
|---|---|---|
| PHP 8.2+ | hPanel → Advanced → PHP Configuration | Switch the PHP version |
| PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `json`, `curl`, `dom`, `fileinfo` | `php -m` over SSH | Enable in PHP Configuration |
| MySQL 8.0+ / MariaDB 10.5+ | hPanel → Databases → phpMyAdmin → footer | — |
| SSH access | hPanel → Advanced → SSH Access | Enable it; it is needed for Composer and the admin setup |
| Cron jobs | hPanel → Advanced → Cron Jobs | Required — scheduled sending does not work without them |
| A verified Amazon SES identity in production access | AWS Console → SES | Request production access before sending to real recipients |
| HTTPS on the domain | hPanel → Security → SSL | Install the free certificate |

Composer may already be available as `composer`. If not, install it locally:

```bash
curl -sS https://getcomposer.org/installer | php -- --install-dir=~/bin --filename=composer
```

---

## 1. Decide the directory layout

The application code, vendor directory, configuration and storage must live
**outside** `public_html`. Only the frontend build and four small entry points
are public.

```
/home/uXXXXXXX/domains/example.com/
├── public_html/                 <- served by Apache
│   ├── index.html               <- React build
│   ├── assets/                  <- React build
│   ├── .htaccess
│   ├── _bootstrap.php
│   ├── api/index.php
│   ├── t/o.php  t/c.php         <- open pixel, click redirect
│   ├── u/index.php              <- unsubscribe
│   └── webhooks/ses.php         <- SNS endpoint
└── private/
    └── backend/                 <- NOT web-accessible
        ├── src/  cron/  bin/  migrations/
        ├── config/config.php    <- secrets
        ├── storage/             <- imports, logs, sessions
        └── vendor/
```

## 2. Find your real paths

Over SSH:

```bash
# Absolute path to the account home and the document root
echo $HOME
ls ~/domains

# Absolute path to the PHP CLI binary — note it down, cron needs it
which php
php -v

# Hostinger often provides versioned binaries; prefer an explicit one:
ls /usr/bin/php* /opt/alt/php*/usr/bin/php 2>/dev/null
```

Write down three values; every later step uses them.

| Name | Example | Yours |
|---|---|---|
| `ACCOUNT_HOME` | `/home/u123456789` | |
| `DOMAIN_ROOT` | `$ACCOUNT_HOME/domains/example.com` | |
| `PHP_BIN` | `/usr/bin/php8.2` | |

> **Docker is for local development only.** `docker-compose.yml` in the repo
> root runs MySQL on your machine so you do not have to install one. Hostinger
> provides managed MySQL; nothing in this guide uses Docker, and there is no
> container to deploy.

## 3. Create the database and a restricted user

hPanel → Databases → MySQL Databases.

1. Create a database, e.g. `u123456789_techzapp_crm`.
2. Create a user, e.g. `u123456789_crm`, with a long random password.
3. Grant that user privileges on **that database only** — never "All databases".

The application needs `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX,
DROP, REFERENCES` (DDL only for migrations). It never needs `FILE`, `SUPER` or
`PROCESS`.

## 4. Build the frontend locally

The build happens on your machine or in CI — Node.js is **not** needed on
Hostinger.

```bash
cd frontend
npm ci
npm run build          # outputs frontend/dist/
```

## 5. Upload the files

Via SSH (preferred) or the hPanel File Manager:

```bash
mkdir -p $DOMAIN_ROOT/private

# Backend (everything except vendor, which is installed on the server)
rsync -av --exclude 'vendor' --exclude 'config/config.php' \
      backend/ $DOMAIN_ROOT/private/backend/

# Public entry points
rsync -av public/ $DOMAIN_ROOT/public_html/

# Frontend build
rsync -av frontend/dist/ $DOMAIN_ROOT/public_html/
```

Then set permissions:

```bash
cd $DOMAIN_ROOT/private/backend
chmod 750 . config storage
chmod -R 750 storage
find . -type f -name '*.php' -exec chmod 640 {} \;
```

## 6. Install PHP dependencies on the server

```bash
cd $DOMAIN_ROOT/private/backend
composer install --no-dev --optimize-autoloader
```

If Composer cannot run on the server, run it locally with the same PHP version
and upload `vendor/` — but prefer running it on the server so platform checks
are accurate.

## 7. Configure secrets

```bash
cd $DOMAIN_ROOT/private/backend
cp config/config.example.php config/config.php
chmod 600 config/config.php

# Generate the application secret (used for tracking/unsubscribe signatures)
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Edit `config/config.php` and set at minimum:

- `app.url` — `https://crm.example.com`, no trailing slash
- `app.env` — `production`
- `app.secret` — the value you just generated
- `database.*` — the database created in step 3
- `ses.region`, `ses.access_key_id`, `ses.secret_access_key`
- `ses.configuration_set` — see step 11
- `ses.verified_identities` — e.g. `news@example.com,example.com`
- `sns.allowed_topic_arns` — filled in at step 11
- `cron.http_token` — only if you must trigger cron over HTTP

> **Rotating `app.secret` invalidates every tracking and unsubscribe link in
> already-delivered emails.** Set it once, before the first send.

If `public_html` is not a sibling of `private`, tell the entry points where the
backend is, in `public_html/.htaccess`:

```apache
SetEnv CRM_BACKEND_PATH /home/u123456789/domains/example.com/private/backend
```

## 8. Run the migrations

```bash
cd $DOMAIN_ROOT/private/backend
php bin/migrate.php
php bin/migrate.php --status     # confirm everything is applied
```

## 9. Create the administrator

There is no public registration endpoint; the first account is created over SSH.

```bash
php bin/create_admin.php --email=you@example.com --name="Your Name"
# or, to have a strong password generated and printed once:
php bin/create_admin.php --email=you@example.com --generate
```

Sign in at `https://crm.example.com`, then change the password from
Settings → Profile.

## 10. Verify HTTPS

`public_html/.htaccess` already redirects HTTP to HTTPS. Confirm:

```bash
curl -I http://crm.example.com          # expect 301 to https
curl -s https://crm.example.com/api/v1/health
# {"data":{"status":"ok","time":"…"}}
```

Session cookies are issued with `Secure`, so the application will not log you in
over plain HTTP.

## 11. Configure Amazon SES

### 11a. IAM user

Create an IAM user with programmatic access and this policy — nothing wider:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["ses:SendEmail", "ses:GetAccount"],
      "Resource": "*"
    }
  ]
}
```

Put the access key and secret in `config/config.php` only.

### 11b. Verify the sender identity

SES Console → Identities → Create identity. Verify the domain (preferred, with
DKIM) or the individual address. Add the verified identity to
`ses.verified_identities`; the application refuses to send from anything else.

### 11c. Request production access

A new SES account is in the sandbox and can only send to verified addresses.
Request production access before a real campaign.

### 11d. Configuration set and event publishing

1. SES Console → Configuration sets → Create: `techzapp-crm-events`.
2. Add an event destination of type **Amazon SNS**, and select these event
   types: `Send`, `Delivery`, `Bounce`, `Complaint`, `Reject`,
   `Rendering Failure`, `Delivery Delay`, `Open`, `Click`.
3. Create an SNS topic (e.g. `ses-events`) and note its ARN.
4. In the SNS topic, create a subscription:
   - Protocol: **HTTPS**
   - Endpoint: `https://crm.example.com/webhooks/ses.php`
5. Put the configuration set name in `ses.configuration_set` and the topic ARN
   in `sns.allowed_topic_arns`.

The endpoint confirms the subscription automatically (`sns.auto_confirm_subscription`),
verifies every message signature, and rejects messages from any other topic.

Verify it is wired up:

```bash
# After the subscription is confirmed, the SNS console shows "Confirmed".
# Send a test campaign, then:
php -r 'require "vendor/autoload.php"; Techzapp\Crm\Bootstrap::init();
  var_dump(Techzapp\Crm\Core\Database::all("SELECT status, COUNT(*) c FROM provider_notifications GROUP BY status"));'
```

## 12. Configure the cron jobs

hPanel → Advanced → Cron Jobs. Use the **absolute** paths from step 2.

| Schedule | Command | Why |
|---|---|---|
| Every minute | `/usr/bin/php8.2 /home/u123456789/domains/example.com/private/backend/cron/process_campaigns.php >> /home/u123456789/domains/example.com/private/backend/storage/logs/cron.log 2>&1` | Starts due campaigns and sends batches |
| Every 5 minutes | `/usr/bin/php8.2 …/private/backend/cron/process_events.php >> …/storage/logs/cron.log 2>&1` | Parses SNS notifications, refreshes reports, evaluates alerts |
| Every minute | `/usr/bin/php8.2 …/private/backend/cron/process_imports.php >> …/storage/logs/cron.log 2>&1` | Finishes large CSV imports |
| Hourly | `/usr/bin/php8.2 …/private/backend/cron/maintenance.php >> …/storage/logs/cron.log 2>&1` | Deletes expired uploads, prunes counters, rotates logs |

If your plan does not offer a one-minute interval, use the smallest available
(often 5 or 15 minutes). Sending still works — campaigns simply progress in
larger, less frequent batches, and `campaign_overdue_minutes` in Settings should
be raised above your cron interval so scheduled campaigns are not flagged as
overdue immediately.

Each job takes a lock, so an overrun never produces two concurrent workers, and
each stops after `cron.max_runtime_seconds` (default 50) so it finishes well
inside the host's process limit.

**Verify after the first minute:**

```bash
tail -20 $DOMAIN_ROOT/private/backend/storage/logs/cron.log
```

Settings → Background workers shows the last run of every job. Until a job has
run once, the dashboard reports a critical "cron job has never run" alert — by
design.

## 13. Test the installation

Work through `docs/TESTING.md`. The short version:

1. **Import** — upload a small CSV; confirm counts and the error report.
2. **Assignment** — assign a contact to a list; confirm it leaves the unassigned
   Contacts page.
3. **Send** — create a campaign to a list containing only your own address,
   send a test, then schedule it a few minutes out and *close the browser*.
   It must send anyway.
4. **Events** — after delivery, the campaign report must show a confirmed
   delivery, not just "accepted by SES".
5. **Tracking** — open the email and click a link; confirm the report updates.
6. **Unsubscribe** — click the footer link, confirm the address appears under
   Suppressions and that a second campaign skips it.
7. **Kill switch** — Settings → stop all sending; confirm the cron log reports
   `kill switch engaged` and nothing sends.

## 14. Backups

hPanel → Files → Backups covers files and databases on most plans. In addition:

```bash
# Database dump, kept outside public_html
mysqldump -u u123456789_crm -p u123456789_techzapp_crm \
  | gzip > $DOMAIN_ROOT/private/backups/crm-$(date +%F).sql.gz
```

Back up `private/backend/config/config.php` separately and securely — it holds
the signing secret and the AWS credentials.

Log rotation runs in the hourly `maintenance.php`; files older than 30 days are
deleted and any single log is rotated past 8 MB.

## 15. Updating the application

```bash
cd frontend && npm ci && npm run build          # locally
rsync -av frontend/dist/ $DOMAIN_ROOT/public_html/
rsync -av --exclude 'vendor' --exclude 'config/config.php' \
      backend/ $DOMAIN_ROOT/private/backend/
ssh …
cd $DOMAIN_ROOT/private/backend
composer install --no-dev --optimize-autoloader
php bin/migrate.php
```

Migrations are additive and re-runnable. Deploying while a campaign is sending
is safe — the worker finishes its current batch and the next run picks up the
new code.

---

## Troubleshooting

**"Application backend not found"** — `public_html/_bootstrap.php` could not
locate `private/backend`. Set `CRM_BACKEND_PATH` in `public_html/.htaccess`.

**"Configuration file is missing"** — `config/config.php` has not been created,
or PHP cannot read it. Check `chmod 600` and that the file owner matches the PHP
user.

**Login succeeds then immediately logs out** — the session directory is not
writable (`backend/storage/sessions`), or the site is being accessed over HTTP
while `session.secure_cookie` is true.

**Scheduled campaign never sends** — check Settings → Background workers. If
`process_campaigns` has never run, the cron command path is wrong; run it
manually over SSH to see the error.

**Events never arrive** — check the SNS subscription is "Confirmed", that the
topic ARN is in `sns.allowed_topic_arns`, and look for
`webhook.invalid_signature` or `webhook.topic_not_allowed` in
`storage/logs/webhook-*.log`.

**502/500 on the API** — look in `storage/logs/api-*.log`. Errors are never
rendered to the browser in production.
