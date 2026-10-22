# Testing

Two layers: an automated suite that runs on every change, and a manual
acceptance pass to run once against the real Hostinger + SES deployment.

---

## Automated tests

```bash
cd backend
composer install

# Unit tests only — no database needed, runs anywhere
vendor/bin/phpunit --testsuite unit

# Everything, including integration tests
TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=3306 \
TEST_DB_NAME=techzapp_crm_test TEST_DB_USER=root TEST_DB_PASSWORD=secret \
vendor/bin/phpunit
```

Integration tests **TRUNCATE every application table** in `TEST_DB_NAME`. Point
them at a throwaway database, never at production. Without `TEST_DB_NAME` they
skip rather than fail, so `composer test` works on a machine with no MySQL.

Current state: **247 tests, 974 assertions, all passing** (PHP 8.3, MySQL 8.4).

### What the suite covers

| Area | File | Notable cases |
|---|---|---|
| Email addresses | `Unit/EmailAddressTest` | Normalization, header-injection rejection, sender formatting |
| CSV | `Unit/CsvTest` | Formula-injection escaping, delimiter detection, **resume from byte offset**, deadline abort |
| HTML | `Unit/EmailHtmlTest` | Script/handler/`javascript:`/iframe removal, open-redirect rejection, link rewriting |
| Personalization | `Unit/PersonalizationTest` | Fallbacks, HTML escaping of contact data, header-injection stripping in subjects |
| Tokens | `Unit/TokensTest` | Purpose binding, parameter binding, boundary-shift resistance |
| Validation | `Unit/ValidatorTest` | Every input rule, UTC conversion, 422 shape |
| State machine | `Unit/CampaignStateMachineTest` | `completed` and `cancelled` are terminal |
| Report maths | `Unit/ReportMathTest` | Each rate's denominator, null-vs-zero, data-quality notes |
| List assignment | `Integration/ListAssignmentTest` | Exclusivity, move semantics, delete-releases-contacts, **8 concurrent OS processes** racing the same contacts |
| Sending | `Integration/CampaignSendingTest` | Idempotency across repeated runs, **4 concurrent cron workers**, suppression re-check at send time, transient vs permanent vs uncertain outcomes, rate caps, kill switch |
| Import | `Integration/ContactImportTest` | **40,000 rows in bounded chunks with a flat memory profile**, duplicates, invalid rows, resume, opt-out survival |
| Events | `Integration/EventProcessingTest` | SNS dedup, bounce/complaint handling, accepted ≠ delivered, auto-pause, unknown-not-healthy |
| Auth | `Integration/AuthenticationTest` | Hashing, throttling per identity and per IP, and a check that **every API route calls an `Auth::require*` guard** |
| Tracking | `Integration/TrackingAndUnsubscribeTest` | Signature forgery, cross-campaign link access, open dedup, unsubscribe blocking later sends |
| Search & filters | `Integration/SearchAndFilterTest` | Every search path executed against MySQL, LIKE-wildcard escaping, injection treated as a literal, unknown sort column falls back, unknown status filter matches nothing |

Timing-sensitive behaviour (the public rate limiter) injects a small limit
rather than exhausting the real one, so the suite does not depend on how fast
the database answers.

The concurrency tests spawn real `php` subprocesses, so they exercise MySQL's
actual locking rather than a simulation.

### Frontend

```bash
cd frontend
npm ci
npm run lint     # tsc --noEmit, strict mode
npm run build
```

---

## Manual acceptance pass

Run this once against the deployed site. Each item maps to a project
requirement.

### Contacts

- [ ] Import a 40,000-row CSV. It completes in chunks, progress advances, and
      closing the browser does not abandon it (`process_imports` finishes it).
- [ ] The same address twice in one file inserts **one** contact.
- [ ] Re-importing an existing address with "skip" leaves stored values alone;
      with "update" it refreshes them, but blank cells do not erase data.
- [ ] Invalid rows appear in the error report with line numbers; download it as
      CSV.
- [ ] Search and pagination stay responsive at 40,000 rows.
- [ ] Export a CSV and open it in Excel: a contact named `=cmd|…` shows as text,
      not a formula.

### Lists

- [ ] Assigning a contact removes it from the unassigned Contacts page.
- [ ] Moving a contact to another list leaves exactly one membership.
- [ ] Removing a contact returns it to unassigned.
- [ ] Deleting a list asks for confirmation, then releases its contacts —
      the contacts still exist.
- [ ] A list used by a campaign cannot be deleted.

### Authentication

- [ ] A signed-out browser gets 401 from `/api/v1/contacts`, not data.
- [ ] Five wrong passwords lock the account temporarily.
- [ ] The session cookie shows `HttpOnly`, `Secure`, `SameSite=Lax` in devtools.
- [ ] Changing the password works and the old one stops working.
- [ ] There is no URL that creates an account.

### Campaigns

- [ ] Save a draft, reload, and the content is intact.
- [ ] The review step shows eligible, suppressed and unsubscribed counts, the
      estimated duration, and refuses to schedule when the From address is not a
      verified SES identity.
- [ ] Send a test to your own address; it arrives with working links and an
      unsubscribe footer.
- [ ] Schedule a campaign a few minutes out, **close the browser**, and confirm
      it sends.
- [ ] Run `process_campaigns.php` twice in quick succession: the second run
      sends nothing new.
- [ ] Cancel a scheduled campaign; it never starts.
- [ ] Pause a sending campaign and resume it.

### Reporting

- [ ] After a real send, "Accepted by SES" is populated and "Confirmed
      deliveries" stays at zero until delivery events arrive.
- [ ] A bounced address shows as bounced and lands in Suppressions.
- [ ] Replaying the same SNS notification does not change any count.
- [ ] Opening the email updates unique opens; clicking a link updates clicks.
- [ ] Every rate names its denominator in the UI.
- [ ] With too little data, deliverability shows **unknown**, not green.

### Security

```bash
# No secrets in the built frontend
grep -rEi "AKIA[0-9A-Z]{16}|secret_access_key|password_hash|mysql:host" public/assets/ ; echo "exit=$?"

# Backend files are not web-reachable
curl -s -o /dev/null -w "%{http_code}\n" https://crm.example.com/backend/config/config.php   # expect 403/404
curl -s -o /dev/null -w "%{http_code}\n" https://crm.example.com/private/backend/config/config.php

# SQL injection attempt is treated as a literal search string
curl -s -b cookies.txt "https://crm.example.com/api/v1/contacts?search=%27%20OR%201%3D1--"

# Open redirect attempt — the destination is ignored, link id is what counts
curl -s -o /dev/null -w "%{redirect_url}\n" "https://crm.example.com/t/c.php?r=<token>&l=1&s=forged"
```

- [ ] The HTML editor strips `<script>` on save.
- [ ] A contact whose company name is `<script>alert(1)</script>` renders as
      text in the preview and in the delivered email.
- [ ] An unsubscribed address is skipped by the next campaign.
- [ ] The kill switch stops the cron worker (check `storage/logs/cron.log`).

---

## Load expectations

Measured locally on PHP 8.3 / MySQL 8.4; a shared host will be slower.

| Operation | Observed |
|---|---|
| Import 40,000 rows | ~20 s total across chunks, flat memory |
| Build 40,000 recipient rows | 2,000 per pass, a few seconds each |
| Send batch | Bounded by `max_sends_per_cron_run` and the SES rate |
| Deep pagination (page 200 of 40,000) | well under a second |

The binding constraint in production is almost always the SES send rate and the
cron interval, not the database.
