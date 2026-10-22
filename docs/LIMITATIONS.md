# Known limitations and operational risks

Written so that whoever operates this system knows where the edges are. Nothing
here is a bug list — these are deliberate trade-offs, platform constraints, or
risks that need a human decision.

---

## 1. Shared hosting constraints

### Cron frequency is the real scheduling resolution
A campaign scheduled for 09:00 starts on the first cron run *after* 09:00. With
a one-minute cron that is near-instant; with a 15-minute cron the campaign can
start up to 15 minutes late. Raise `campaign_overdue_minutes` above your cron
interval or the dashboard will flag a perfectly healthy campaign as overdue.

### Throughput is bounded by cron runs, not by SES
Each run sends at most `max_sends_per_cron_run` (default 400) within
`cron.max_runtime_seconds` (default 50). With a one-minute cron that is roughly
**24,000 messages/hour** at the default settings — a 40,000-recipient campaign
takes about 100 minutes. Raising the per-run cap only helps if the host lets the
process run long enough; it is safer to keep runs short and frequent.

### No persistent workers
Shared hosting cannot run daemons or queue workers. Everything is bounded,
resumable batch work driven by cron. This is why every operation records where
it got to rather than holding state in memory.

### PHP limits apply to uploads
`upload_max_filesize` and `post_max_size` cap the CSV upload, typically at
8–128 MB depending on plan. The application's own limit
(`import_max_file_mb`, default 64) cannot exceed what PHP accepts. A 40,000-row
contact CSV is normally 3–6 MB, so this is rarely the binding constraint.
Settings → Hosting environment shows the live values.

### Disk and inode budgets
Import uploads live in `backend/storage/imports` until the retention period
expires (default 48 h). Logs rotate at 8 MB and are deleted after 30 days. If
`maintenance.php` is not scheduled, both grow without limit.

### Database size
40,000 contacts is roughly 12 MB. The cost driver is `email_events`: a campaign
to 40,000 recipients with full event publishing generates 150,000–250,000 rows
(~40–70 MB). Ten such campaigns will approach a 1 GB database allowance.
`maintenance.php` prunes raw SNS payloads after 14 days but **keeps every
derived event** — reports depend on them. Plan for growth or archive old
campaigns.

---

## 2. Email and deliverability

### SES acceptance is not delivery
The system deliberately separates `accepted` (the SES API took the message) from
`delivered` (a Delivery event arrived). If event publishing is not configured,
delivery, bounce and complaint figures stay at zero — and the reports say so
rather than showing a healthy-looking 100%.

### Open and click tracking is indicative, not factual
Apple Mail Privacy Protection, Gmail image proxying and corporate security
scanners all fetch tracking pixels and follow links without a human involved.
Opens are over-counted for some audiences and under-counted for others. Clicks
are more reliable but still inflated by link scanners. Every screen labels these
as *tracked engagement*.

### Open rate can exceed 100%
"Unique opens ÷ confirmed deliveries" goes above 100% when Open events arrive
but Delivery events do not — usually because the SES configuration set does not
publish Delivery. The number is not capped; a data-quality note explains the
cause instead.

### Alert thresholds are ours, not Amazon's
The defaults (bounce 2%/5%, complaint 0.05%/0.1%) mirror commonly cited industry
guidance. **Amazon applies its own criteria and does not publish exact
triggers.** Staying under these thresholds is not a guarantee against review or
suspension. Always check the SES console's reputation metrics as well.

### Automatic pause is aggressive by design
A complaint rate at the *warning* level engages the kill switch immediately
(`complaint_spike_auto_pause`). A delayed campaign is cheap; a damaged sending
domain is not. Turn it off only if you have another control in place.

### Soft bounces are suppressed after repeated failures
An address that soft-bounces `soft_bounce_max_attempts` times (default 3) across
campaigns is suppressed under `soft_bounce_threshold`. A mailbox that was
temporarily full can therefore end up suppressed; review this reason
periodically in Suppressions and release individually where appropriate.

### No dedicated IP warm-up logic
The system enforces rate limits but has no warm-up schedule. On a new dedicated
IP, ramp volume manually by raising `max_sends_per_day` gradually.

---

## 3. Functional scope

### One list per contact
A contact holds at most one active list membership — enforced by a UNIQUE
constraint, not just application code. Assigning to a new list *moves* the
contact. Multi-list membership, segments and tags are not supported; adding them
means dropping that constraint and reworking the Contacts page's
"unassigned only" rule.

### No A/B testing, automation or drip sequences
Campaigns are one-shot sends to one list.

### No template library or drag-and-drop builder
The editor produces clean semantic HTML. Complex multi-column layouts need
hand-written table markup pasted into the plain-text/HTML field, within the
sanitizer's allow-list (tables, inline styles and images are permitted;
`<script>`, `<style>`, `<iframe>` and event handlers are not).

### No image hosting
`<img>` tags are allowed but images must already be hosted somewhere public.
There is no media library and no upload path for images.

### Personalization in link targets is not click-tracked
A link whose `href` contains `{{website}}` is left as a direct link: its
destination differs per recipient, so it cannot be pre-registered as an approved
click-tracking target. Those clicks are not counted.

### Plain-text links are not tracked
The text part keeps real destinations so it stays readable and trustworthy.
Clicks from text-only clients therefore do not appear in reports.

### Test sends are restricted
Test emails go only to your own address, another CRM user, or a domain listed in
`test_send_allowed_domains`. This is deliberate: without it the test endpoint is
an open relay for anyone with an operator account.

---

## 4. Security boundaries

### Sessions are server-side and single-device-friendly
A session is bound to the browser's user-agent, not its IP (mobile networks
rotate addresses). Changing your password re-issues *your* session but does not
forcibly terminate sessions on other devices; PHP's native session storage has
no index by user. If an account is believed compromised, deactivate it in the
database and delete `backend/storage/sessions/*`.

### Suppression release is an administrator action
Releasing a suppression undoes a recipient's opt-out. It is restricted to
administrators and recorded permanently in `suppression_events` — but the
application cannot tell whether the recipient actually consented. That remains a
human responsibility.

### Unsubscribe links are long-lived
Tracking and unsubscribe tokens have no expiry: a recipient must be able to
unsubscribe from an email received a year ago. They are random and
HMAC-signed, so they cannot be guessed or edited, but anyone who obtains the URL
can unsubscribe that recipient. That is the correct trade-off for an opt-out.

### The webhook trusts Amazon's signature chain
SNS messages are validated with the official `aws/aws-php-sns-message-validator`,
which fetches Amazon's signing certificate over HTTPS. If outbound HTTPS from
the host is blocked, validation fails and events are rejected — visible as
`webhook.invalid_signature` in the logs.

### CSV content is stored verbatim
Imported values are stored as data, never executed, and escaped at every output
boundary (HTML-escaped in emails, prefixed in CSV exports). A field containing
`<script>` is stored as-is and displayed as text. That is intentional — altering
customer data on import would be worse.

---

## 5. Reporting caveats

### Counts are eventually consistent
`campaign_stats` is a materialised cache refreshed by `process_events.php` and
after each sending batch. A report viewed mid-campaign can be up to a few
minutes behind; opening a campaign report forces a recalculation.

### Deleting a contact keeps its campaign history
`campaign_recipients.contact_id` becomes NULL but the email snapshot, outcome
and events remain, so historical reports stay accurate. Suppression also
survives, keyed on the address.

### Events for messages sent outside this system
If the SNS topic also receives events for mail sent by other applications, those
events are recorded with no recipient link. They appear in account-level
deliverability figures but not in any campaign report. Use a dedicated
configuration set for this application to avoid mixing.

### The dashboard date range filters by recipient, not by event
"Last 30 days" means *recipients queued in the last 30 days* and all of their
events, whenever those events arrived. This keeps numerators and denominators
describing the same messages; it means a bounce that arrives today for a
campaign sent 40 days ago is counted in that older window.

---

## 6. Operational risks to watch

| Risk | Signal | Mitigation |
|---|---|---|
| Cron stops running (plan change, path change, account suspension) | Critical alert "cron job is not running"; Settings → Background workers | Check the cron log; re-create the job with the absolute PHP path |
| SES account placed under review | Provider health turns red; `ses_sending_disabled` alert | Pause sending, fix the underlying bounce/complaint cause, reply to AWS |
| `app.secret` rotated by accident | Every tracking and unsubscribe link in already-sent mail stops working | Keep the secret in backups; never regenerate casually |
| Database full | Import and send failures in the logs | Prune old `email_events`, archive completed campaigns, upgrade the plan |
| Importing a purchased list | High bounce and complaint rates within hours | Do not. The application cannot verify consent and will not protect you from the consequences |
| Shared-host IP reputation | Poor delivery with no bounces | SES sends from its own infrastructure, so this affects the app's own HTTP endpoints only, not mail |

---

## 7. What this system does not promise

- It does not guarantee inbox placement. Nothing can.
- It does not verify that your recipients consented. That is your legal
  responsibility, and the confirmation checkbox before scheduling exists to make
  that explicit.
- It does not provide unlimited capacity. Real limits are your disk quota, your
  database allowance, your CPU budget and your SES sending quota — all finite,
  and all visible in Settings.
- It does not replace monitoring of the SES console. The health panel reports
  what SES tells it through the API; AWS communicates account decisions by email.
