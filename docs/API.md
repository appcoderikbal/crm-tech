# API reference

Base path: `/api/v1`. All responses are JSON.

## Conventions

**Success**

```json
{ "data": { … }, "meta": { "total": 120, "page": 1, "per_page": 25, "pages": 5 } }
```

`meta` is present on collection endpoints and omitted otherwise.

**Failure**

```json
{ "error": { "code": "validation_failed", "message": "The submitted data is invalid.",
             "details": { "fields": { "email": "Enter a valid email address." } } } }
```

| Status | `code` | Meaning |
|---|---|---|
| 400 | `bad_request`, `invalid_json` | Malformed request |
| 401 | `unauthenticated` | No valid session |
| 403 | `forbidden`, `https_required` | Authenticated but not permitted |
| 404 | `not_found` | No such resource |
| 405 | `method_not_allowed` | Wrong verb for this path |
| 409 | `conflict` | State conflict; may carry `requires_confirmation` |
| 413 | `payload_too_large` | Body exceeded `security.max_request_bytes` |
| 419 | `csrf_mismatch` | Missing or stale CSRF token |
| 422 | `validation_failed`, `unprocessable` | Valid shape, unacceptable content |
| 429 | `rate_limited` | Throttled; `details.retry_after` in seconds |
| 500 | `server_error` | Logged server-side; no details exposed |

## Authentication

Session cookie (`HttpOnly`, `Secure`, `SameSite=Lax`). Every state-changing
request must send the CSRF token from `/auth/login` or `/auth/me`:

```
X-CSRF-Token: <token>
```

File-download endpoints are submitted as HTML forms and may send the token as a
`_csrf` form field instead.

**Roles**: `admin` (everything), `operator` (read + write, no settings or
suppression release), `viewer` (read only).

---

## Endpoints

### Authentication

| Method | Path | Role | Notes |
|---|---|---|---|
| POST | `/auth/login` | — | `{email, password}`. Throttled per identity and per IP |
| POST | `/auth/logout` | any | Destroys the session |
| GET | `/auth/me` | any | Current user, CSRF token, alert counts |
| PATCH | `/auth/profile` | any | `{name?, timezone?}` |
| POST | `/auth/change-password` | any | `{current_password, new_password}` |

There is deliberately **no registration endpoint**. The first administrator is
created with `backend/bin/create_admin.php` over SSH.

### Contacts

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/contacts` | any | Query: `assignment` (`unassigned`\|`assigned`\|`any`, default `unassigned`), `search`, `status`, `country`, `source`, `suppressed`, `unsubscribed`, `list_id`, `sort`, `direction`, `page`, `per_page` |
| GET | `/contacts/meta` | any | Counts plus distinct countries/sources for filters |
| POST | `/contacts` | write | Create one contact |
| GET | `/contacts/{id}` | any | Contact, campaign history, suppression history |
| PATCH | `/contacts/{id}` | write | Partial update |
| DELETE | `/contacts/{id}` | write | Suppression survives deletion |
| POST | `/contacts/bulk-delete` | write | `{contact_ids: []}` |
| POST | `/contacts/bulk-assign` | write | `{list_id, contact_ids[]}` or `{list_id, all_matching_filter: true, filters: {}}` |
| POST | `/contacts/export` | any | Streams CSV; form POST |

### Imports

| Method | Path | Role | Notes |
|---|---|---|---|
| POST | `/contacts/import` | write | `multipart/form-data` with `file`. Returns header, preview and a suggested mapping |
| GET | `/imports` | any | Import history |
| GET | `/imports/{id}` | any | Job state, header, preview |
| POST | `/imports/{id}/start` | write | `{mapping, custom_fields, duplicate_mode, target_list_id, has_header}` |
| POST | `/imports/{id}/process` | write | Processes one bounded chunk; call repeatedly for a progress bar |
| POST | `/imports/{id}/cancel` | write | |
| GET | `/imports/{id}/errors` | any | `?format=csv` for the downloadable error report |

`duplicate_mode`: `skip` (keep stored values), `update` (overwrite with non-empty
incoming values), `new_only`.

### Lists

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/lists` | any | With contact and sendable counts |
| POST | `/lists` | write | `{name, description?}` |
| GET | `/lists/{id}` | any | List plus campaign history |
| GET | `/lists/{id}/members` | any | Paginated members |
| PATCH | `/lists/{id}` | write | Rename |
| DELETE | `/lists/{id}` | write | Requires `{confirm: true}`; releases contacts, never deletes them |
| POST | `/lists/{id}/assign` | write | `{contact_ids[]}` or `{all_matching_filter, filters}` |
| POST | `/lists/{id}/remove` | write | `{contact_ids[]}` or `{all: true}` |

Assignment is exclusive: assigning a contact to a list removes it from any other.

### Campaigns

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/campaigns` | any | `status` accepts a comma-separated list |
| POST | `/campaigns` | write | Creates a draft |
| GET | `/campaigns/{id}` | any | Campaign plus a readiness report |
| PATCH | `/campaigns/{id}` | write | Draft/scheduled/failed only. Editing a scheduled campaign returns it to draft |
| DELETE | `/campaigns/{id}` | write | Draft/cancelled/failed only |
| POST | `/campaigns/{id}/duplicate` | write | New draft copy |
| GET | `/campaigns/{id}/preview` | any | `?contact_id=` to render with real data |
| POST | `/campaigns/{id}/test` | write | `{email}` — restricted recipients |
| POST | `/campaigns/{id}/schedule` | write | `{scheduled_at, timezone, send_rate_per_minute?, confirm: true}` |
| POST | `/campaigns/{id}/send-now` | write | `{confirm: true}` |
| POST | `/campaigns/{id}/cancel` | write | |
| POST | `/campaigns/{id}/pause` | write | |
| POST | `/campaigns/{id}/resume` | write | |
| GET | `/campaigns/{id}/report` | any | Metrics, rates, denominators, timeline, links |
| GET | `/campaigns/{id}/recipients` | any | Per-recipient outcomes |

`scheduled_at` is a local time in `timezone`; it is converted to UTC for storage.

**Statuses**: `draft → scheduled|queued → sending → completed`, with
`paused`, `cancelled` and `failed`. `completed` and `cancelled` are terminal —
no request can move a campaign out of them.

### Suppressions

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/suppressions` | any | `search`, `reason` |
| POST | `/suppressions` | write | `{email, reason, notes?}` |
| GET | `/suppressions/history` | any | `?email=` — append-only audit trail |
| DELETE | `/suppressions/{id}` | **admin** | Releases the opt-out; always audited |
| POST | `/suppressions/export` | any | Streams CSV |

Reasons: `unsubscribe`, `complaint`, `hard_bounce`, `manual_block`,
`invalid_address`, `soft_bounce_threshold`.

### Reporting

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/reports/dashboard` | any | `from`, `to` (UTC). Everything the dashboard shows |
| GET | `/reports/deliverability` | any | `window_hours` (default from settings) |
| GET | `/reports/events` | any | Raw event feed: `event_type`, `campaign_id`, `from`, `to` |
| GET | `/reports/campaigns/{id}` | any | Same as the campaign report |
| GET | `/alerts` | any | Active and acknowledged alerts |
| POST | `/alerts/evaluate` | write | Re-runs every monitoring check |
| POST | `/alerts/{id}/acknowledge` | write | |

Every report carries `denominators` (what each rate divides by) and
`data_quality` (notes explaining incomplete data, e.g. missing delivery events).

### Settings and administration

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/settings` | any | Settings, provider facts, runtime info, cron heartbeats. **Never returns secrets** |
| PATCH | `/settings` | **admin** | Only keys in the editable allow-list |
| POST | `/settings/kill-switch` | **admin** | `{engage: bool, reason}` — reason required to engage |
| GET | `/settings/audit` | **admin** | Audit log |
| GET | `/settings/users` | **admin** | User list |

### Health

| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/health` | — | `{status, time}`. No version or configuration detail |

---

## Public endpoints (no authentication)

These are outside `/api/v1` and are reached directly by recipients and by AWS.

| Path | Method | Purpose |
|---|---|---|
| `/t/o.php?r=&s=` | GET | Open pixel. **Always** returns a 1×1 GIF, valid token or not |
| `/t/c.php?r=&l=&s=` | GET | Click redirect. The destination comes from `campaign_links`, never from the URL |
| `/u/?r=&s=` | GET | Unsubscribe confirmation page |
| `/u/?r=&s=` | POST | Performs the opt-out; also the `List-Unsubscribe-Post` target (RFC 8058) |
| `/webhooks/ses.php` | POST | SNS endpoint; verifies the signature and the topic ARN |

`r` is an opaque 32-character recipient token; `s` is an HMAC bound to the
purpose and every parameter. No database id or email address appears in any of
these URLs. All are rate-limited per IP.

---

## Cron entry points (CLI)

Not routable over HTTP unless `cron.http_token` is set, and even then a matching
`?token=` is required.

| Script | Interval | Work |
|---|---|---|
| `cron/process_campaigns.php` | 1 min | Queue due campaigns, send batches, reconcile stale rows |
| `cron/process_events.php` | 5 min | Parse SNS notifications, refresh stats, evaluate alerts |
| `cron/process_imports.php` | 1 min | Continue queued CSV imports |
| `cron/maintenance.php` | hourly | Delete expired uploads, prune counters, rotate logs |
