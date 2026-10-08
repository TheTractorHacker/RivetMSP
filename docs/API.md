# RivetMSP API v1 Reference

## What this is

RivetMSP ships a REST API used by the RivetMSP mobile app, ITPanel Pro, RMM automation scripts, and any custom tooling you want to build against your RivetMSP instance. It's the same API surface the built-in mobile app runs on — nothing about it is a stripped-down or read-only subset held back from external integrations.

**Base path:** all endpoints below are relative to `/api/v1` on your RivetMSP install, e.g. `https://your-instance.example.com/api/v1/tickets`.

**Versioning:** the API is currently unversioned beyond the `/v1` path segment (spec version `1.0.0` in `info.version`). There is no `v2` today; breaking changes would arrive under a new path prefix rather than silently changing `/v1` behavior.

**Machine-readable spec:** the full OpenAPI 3.0 document is served live from the running instance at `GET /api/v1/openapi.yaml`. Import it into Swagger UI, Postman, or Insomnia to browse and try every endpoint interactively, including full request/response JSON Schemas that this document intentionally does not restate in full (see [Where to find full schemas](#where-to-find-full-schemas)).

**Human-readable browsing:** the same spec is also rendered as a searchable, dark-console-styled HTML reference at `GET /api/v1/docs` (also linked from **Settings → API Docs** in the admin panel). It's generated live from `openapi.yaml`, so it's always in sync with what the server actually does.

This document is a narrative companion to those two — read this first to understand the shape of the API and how auth/pagination/errors work, then use `/openapi` or `/docs` for the exact fields on any one endpoint.

---

## Authentication

Every endpoint except the handful marked **public** below requires one of two credentials, sent on every request:

### 1. `Authorization: Bearer <token>` — user tokens (recommended for new integrations)

A per-user token issued by `POST /api/v1/auth` (password or passkey login — see [Auth](#auth)). This is the primary mechanism for anything acting as a specific technician or contact: the mobile app, a personal automation script, anything where "who did this" matters. Tokens are revoked with `DELETE /api/v1/auth` (logout).

Use bearer tokens when you need:
- Actions attributed to a specific user (ticket replies, time entries, worksheet signatures)
- Access to `/me` or `/credentials` (both explicitly deny legacy API keys — see below)
- Push token / biometric key registration (mobile app)

### 2. `X-Api-Key: <key>` — legacy instance API keys

A long-lived, instance-wide key managed under **Settings → API Keys** in the admin panel. It resolves internally to the instance's first active admin user rather than to a specific person, which is why it's blocked from `/credentials` (decrypted secrets) and `/me` (a per-user profile) — there's no real "current user" to answer either request as.

Legacy keys are a good fit for server-to-server integrations and scripts that aren't acting as any one technician: RMM webhook receivers, backup-report ingestion, nightly export jobs, that kind of thing.

Two scoping options apply to a legacy key, both set when creating/editing it under Settings → API Keys:

- **Client scope** — a key can be locked to a single client, in which case every client-scoped resource (tickets, assets, credentials list, contracts, etc.) is filtered to that client and cross-client requests are rejected.
- **Permission (read / write)** — new in this release. A key's **Permission** field is either `write` (the default — full access, same as before) or `read`. A `read` key may only issue `GET` requests; any `POST`/`PUT`/`PATCH`/`DELETE` with a read-only key is rejected with `403 {"error": "This API key is read-only"}` before it reaches the resource handler. Use a read-only key for anything that only needs to pull data (dashboards, reporting pulls, read-only RMM lookups) so a leaked key can't be used to modify data.

### Public endpoints (no credential required)

A small set of endpoints are intentionally reachable with no `Authorization` header and no `X-Api-Key` at all:

| Endpoint | Why |
|---|---|
| `POST /auth`, `GET /auth` | You don't have a token yet — this is how you get one. |
| `POST /crash-reports` | Semi-public: honors a bearer token when present (for correlation) but never requires one, because the crash worth capturing most is often the one that happens *before* login succeeds or while a token has just expired. |
| `GET /csat` | Fully public aggregate rating summary, meant to be embedded on a public marketing site (see [Public](#public)). |
| `GET /openapi`, `GET /docs` | The spec and its rendered reference need to be readable before you have credentials to explore anything else. |

### Biometric step-up (credential secrets only)

Decrypted credential secrets (`GET /credentials/{id}`) require more than a bearer token: a signed biometric challenge, on top of normal auth. The flow is:

1. Call `GET /auth` to obtain a challenge (`PasskeyBeginResponse`, including a `challengeToken`).
2. Sign the challenge locally with the device's registered biometric key (registered previously via `PUT /me` with `device_public_key_pem`).
3. Send the request with two extra headers: `X-Biometric-Challenge-Token: <challengeToken>` and `X-Biometric-Signature: <base64 signature>`.

This is the same challenge mechanism used for passkey login completion — `GET /auth` is the one place a challenge originates from, whether you're about to complete a passkey login or about to prove device possession to unseal a credential.

---

## Conventions

These hold across (almost) every endpoint; exceptions are called out per-endpoint in the tables below.

**Lists** return a JSON object:

```json
{ "data": [ ... ], "total": 137 }
```

Some paged endpoints also echo back `page` and `limit`. A handful of list endpoints intentionally return a **bare JSON array** instead of this envelope — usually small, unpaged reference lists (statuses, categories, asset types, worksheet templates) or nested "everything for this one client/ticket" lists. Each one is marked **bare array** in the tables below.

**Creates** return the new row's id:

```json
{ "id": 4821 }
```
sometimes with a few extra fields alongside it (e.g. ticket creation also returns `number` and any `attachments` uploaded with it).

**Simple mutations** (status changes, marking things read, signing, acknowledging alerts) return:

```json
{ "ok": true }
```

**Errors** are always a JSON object with an `error` string, paired with the matching HTTP status code:

```json
{ "error": "Ticket not found" }
```

Common status codes you'll see: `400` (bad request — missing/invalid fields), `401` (missing or invalid credential), `403` (authenticated but not permitted — wrong client scope, read-only key doing a write, missing biometric step-up), `404` (not found), `429` (rate limited).

**Pagination** — where supported, `page` (default `1`) and `limit` (default `20`) query parameters control paging; combine with `total` in the response to compute page count. Most list endpoints also accept a `search` query parameter for a simple text filter, and many accept resource-specific filters (`client_id`, `status`, `priority`, etc. — see each endpoint's table row and the OpenAPI spec for the exact parameter set).

**Rate limiting** — 300 requests per 60 seconds per caller (per token or per API key; unauthenticated callers are limited by IP). Exceeding it returns `429` with a `Retry-After` header telling you how many seconds to wait. `POST /crash-reports` has its own tighter limit (20 requests / 5 minutes per IP) since it's reachable without auth.

**Content types** — most endpoints take `application/json`. A few accept file uploads and support **either** `application/json` or `multipart/form-data` on the same route (ticket creation, ticket replies); a few are multipart-only (attachments, expense receipts). These are noted per-endpoint below.

**Streaming** — two endpoints (`GET /tickets/{id}/chat?stream=1` and `GET /notifications/stream`) return `text/event-stream` (Server-Sent Events) instead of a single JSON response. Treat these as long-lived connections (`EventSource` in a browser, or an HTTP client that doesn't buffer/timeout the response) rather than a normal request/response call.

### Where to find full schemas

To keep this document maintainable and accurate, it does not attempt to reproduce full request/response JSON Schemas for all 91 endpoints. For the exact shape of any request body or response — field names, types, which fields are required, enum values — use:

- `GET /api/v1/openapi.yaml` — the canonical OpenAPI 3.0 document, importable into Swagger UI / Postman / Insomnia for interactive "try it" requests against your own instance.
- `GET /api/v1/docs` (or **Settings → API Docs** in the admin panel) — the same spec rendered as a searchable human-readable page.

---

## Quick start

A minimal end-to-end flow: log in, list open tickets, create one, reply to it.

**1. Log in with a username/password**

```bash
curl -X POST https://your-instance.example.com/api/v1/auth \
  -H "Content-Type: application/json" \
  -d '{
        "username": "jdoe",
        "password": "correct horse battery staple",
        "device_name": "jdoe-macbook"
      }'
```

Response is either a token:

```json
{ "token": "eyJ...", "user": { "id": 7, "name": "Jane Doe", "email": "jdoe@example.com", "type": 3 } }
```

or, if the account has 2FA enabled:

```json
{ "requires_2fa": true }
```

— in which case re-POST the same request with a `totp_code` field added.

Save the token and send it as `Authorization: Bearer <token>` on every subsequent request. (Passkey/WebAuthn login instead begins with `GET /auth` for a challenge, then `POST /auth` with `passkey_response` + `challenge_token`.)

**2. List your open tickets**

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "https://your-instance.example.com/api/v1/tickets?status=open&mine=1&limit=20"
```

**3. Create a ticket**

```bash
curl -X POST https://your-instance.example.com/api/v1/tickets \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
        "subject": "Printer offline",
        "details": "Front desk printer is not responding to print jobs.",
        "client_id": 42,
        "priority": "medium"
      }'
```

```json
{ "id": 1183, "number": 1183, "attachments": [] }
```

**4. Reply to the ticket (and log time in the same call)**

```bash
curl -X POST https://your-instance.example.com/api/v1/tickets/1183/reply \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
        "reply": "Power-cycled the printer and cleared a paper jam. Confirmed working.",
        "type": "reply",
        "time_worked": "00:15:00"
      }'
```

**Using a legacy instance key instead of a bearer token** — swap the `Authorization` header for `X-Api-Key`:

```bash
curl -H "X-Api-Key: $ITFLOW_API_KEY" \
  "https://your-instance.example.com/api/v1/clients"
```

---

## Endpoint reference

91 endpoints across 24 resource groups. Method color/verb meaning follows normal REST convention: `GET` read, `POST` create/action, `PUT` update, `DELETE` remove.

### Auth

Login (password or passkey), passkey challenge issuance, and logout. Public except logout.

| Method | Path | Description |
|---|---|---|
| POST | `/auth` | Password login — returns a bearer token, or `requires_2fa`. Also accepts a completed passkey response. Public. |
| GET | `/auth` | Begin a WebAuthn/passkey assertion — returns a challenge (also reused for the credential biometric step-up flow). Public. |
| DELETE | `/auth` | Logout — revokes the current bearer token. |

### Dashboard

The agent home-screen summary: open-ticket counters and the caller's personal ticket queue.

| Method | Path | Description |
|---|---|---|
| GET | `/dashboard` | Agent dashboard counters and personal ticket queue. |

### Tickets

Core service-desk workflow: list/create/view tickets, reply (with optional file attachments, inline or via a separate upload call), log time, change status, run the live chat channel, and add billable charges.

| Method | Path | Description |
|---|---|---|
| GET | `/tickets` | List tickets — paged, filterable by `status`, `mine`, `priority`, `onsite`, `category_id`, `client_id`, `contact_id`, `overdue`, `due_today`, `search`. |
| POST | `/tickets` | Create a ticket (JSON or multipart with files). Requires `subject`. |
| GET | `/tickets/{id}` | Ticket detail with replies and attachments. |
| POST | `/tickets/{id}/reply` | Add a reply or internal note (JSON or multipart with files). Requires `reply`; `type` distinguishes reply/note/client-portal. |
| DELETE | `/tickets/{id}/reply/{replyId}` | Delete a reply and its attachments. |
| POST | `/tickets/{id}/time` | Log time against a ticket (creates an internal reply). Requires `time_worked` (HH:MM:SS). |
| POST | `/tickets/{id}/status` | Change ticket status; setting a Closed-type status resolves the ticket. Requires `status_id`. |
| POST | `/tickets/{id}/attachments` | Upload one or more attachments to a ticket (multipart). |
| GET | `/tickets/{id}/chat` | Live-chat messages for a ticket. Add `?stream=1` for a `text/event-stream` SSE feed instead of a JSON snapshot; `since_id` for incremental polling. |
| POST | `/tickets/{id}/chat` | Send a live-chat message. Requires `message`; optional `contact_id` to post on behalf of a client contact. |
| GET | `/tickets/{id}/charges` | List charges on a ticket, plus a running total. |
| POST | `/tickets/{id}/charges` | Add a charge to a ticket. Requires `name`; optional `description`, `quantity`, `unit_price`. |

### Ticket Meta

Small reference lists used to populate ticket forms/filters in a client app.

| Method | Path | Description |
|---|---|---|
| GET | `/statuses` | List active ticket statuses. **Bare array.** |
| GET | `/ticket-categories` | List ticket categories. **Bare array.** |
| GET | `/ticket-views` | List saved ticket views, translated into ready-to-use list query params. **Bare array.** |

### Clients

Client records and everything scoped to one client — tickets, assets, locations, credential names, contracts, files, and (new) included-hours allowance usage.

| Method | Path | Description |
|---|---|---|
| GET | `/clients` | List clients — paged, `search`. |
| GET | `/clients/{id}` | Client detail with contacts and primary location. |
| GET | `/clients/{id}/tickets` | Recent tickets for a client. **Bare array.** |
| GET | `/clients/{id}/assets` | Assets for a client. **Bare array.** |
| GET | `/clients/{id}/locations` | Locations for a client. **Bare array.** |
| GET | `/clients/{id}/credentials` | Credential names/URIs for a client — no secrets. **Bare array.** |
| GET | `/clients/{id}/contracts` | Contracts for a client. **Bare array.** |
| GET | `/clients/{id}/files` | Signed outtake (device pickup) forms for a client. **Bare array.** |
| GET | `/clients/{id}/allowance` | **New.** Included support-hours allowance vs. usage for a calendar month, rolled up across the client's active contracts plus a per-contract breakdown. Optional `month` (1-12) / `year` query params, default current month. When no active contract configures an allowance, `included`/`remaining`/`pct` come back `null` — treat that as "feature inactive," not zero. |

### Contacts

Client contact records.

| Method | Path | Description |
|---|---|---|
| GET | `/contacts` | List contacts — paged, filterable by `client_id`, `search`. |

### Assets

Managed devices/equipment.

| Method | Path | Description |
|---|---|---|
| GET | `/assets` | List assets — paged, filterable by `type`, `client_id`, `search`. |
| GET | `/assets/types` | Distinct asset types currently in use. **Bare array of strings.** |
| GET | `/assets/{id}` | Asset detail. |

### Credentials

Stored client passwords/secrets. Both endpoints deny legacy `X-Api-Key` auth — a user bearer token is required.

| Method | Path | Description |
|---|---|---|
| GET | `/credentials` | List credentials — names/URIs only, no secrets. Paged, filterable by `client_id`, `search`. Bearer token only. |
| GET | `/credentials/{id}` | Decrypted credential detail. Bearer token only, **plus** the biometric step-up headers `X-Biometric-Challenge-Token` and `X-Biometric-Signature` (see [Biometric step-up](#biometric-step-up-credential-secrets-only)). |

### Quotes

| Method | Path | Description |
|---|---|---|
| GET | `/quotes` | List quotes — paged, filterable by `client_id`, `search`. |
| GET | `/quotes/{id}` | Quote detail with line items. |

### Invoices

| Method | Path | Description |
|---|---|---|
| GET | `/invoices` | List invoices — paged, filterable by `client_id`, `status`. |
| GET | `/invoices/{id}` | Invoice detail with line items. |

### Expenses

| Method | Path | Description |
|---|---|---|
| GET | `/expenses` | List expenses — paged. |
| POST | `/expenses` | Create an expense (multipart). Requires `description`, `amount`; optional `date`, `currency`, `reference`, `payment_method`, `client_id`, and a `receipt` file upload. |

### Worksheets

Structured checklists/forms attached to tickets, built from admin-defined templates — created on a ticket, filled in, and optionally signed off.

| Method | Path | Description |
|---|---|---|
| GET | `/tickets/{id}/worksheets` | List worksheets attached to a ticket. |
| POST | `/tickets/{id}/worksheets` | Create a worksheet on a ticket from a template. Requires `template_id`; optional `is_outtake` flag. |
| GET | `/worksheets/{id}` | Worksheet detail with fields and current responses. |
| DELETE | `/worksheets/{id}` | Delete a worksheet. |
| POST | `/worksheets/{id}/sign` | Sign a worksheet. |
| POST | `/worksheets/{id}/complete` | Mark a worksheet complete or incomplete without signing. |
| POST | `/worksheets/{id}/responses` | Save worksheet field responses (marks the worksheet complete). |
| GET | `/worksheet-templates` | List worksheet templates. **Bare array.** |

### Outtakes

Device pickup/drop-off ("outtake") forms — a specialized worksheet-like form for logging equipment handed to or received from a client, with signature capture.

| Method | Path | Description |
|---|---|---|
| GET | `/tickets/{id}/outtakes` | List device-outtake forms for a ticket. |
| POST | `/tickets/{id}/outtake` | Create a device-outtake form on a ticket. |
| GET | `/outtakes/{id}` | Outtake form detail. |
| DELETE | `/outtakes/{id}` | Delete an outtake form. |
| POST | `/outtakes/{id}/sign` | Sign an outtake form. |

### Products

| Method | Path | Description |
|---|---|---|
| GET | `/products` | List products/services for charge selection. Filterable by `type`, `search`. **Bare array.** |

### Search

| Method | Path | Description |
|---|---|---|
| GET | `/search` | Global search across tickets, clients, and assets. Requires `q` (min length 2); returns results grouped by resource type. |

### Reports

Operational and financial reporting. Most accept a `year` query parameter (default current year); a few take their own filters as noted. All return a report-shaped JSON object whose exact fields are report-specific — see the OpenAPI spec for each one's schema.

| Method | Path | Description |
|---|---|---|
| GET | `/reports/time` | Time logged, grouped by client. `period` (week/month/all), `mine`. |
| GET | `/reports/tickets` | Ticket volume by month for a year. |
| GET | `/reports/tickets-by-client` | Ticket counts by client. Optional `month`. |
| GET | `/reports/time-by-tech` | Time logged, grouped by technician. |
| GET | `/reports/tech-performance` | Technician performance summary. |
| GET | `/reports/technician-performance` | Detailed technician performance report. |
| GET | `/reports/service-desk` | Service-desk report. |
| GET | `/reports/csat` | Customer satisfaction report — ratings distribution and trend, per-technician/per-client breakdowns, raw feedback feed. |
| GET | `/reports/mrr` | Monthly recurring revenue report. |
| GET | `/reports/rmm-health` | RMM fleet health report. |
| GET | `/reports/unbilled-tickets` | Clients with unbilled billable closed tickets. |
| GET | `/reports/clients-with-balance` | Clients carrying an outstanding balance. |
| GET | `/reports/income-summary` | Income summary for a year. |
| GET | `/reports/expense-summary` | Expense summary for a year. |
| GET | `/reports/profit-loss` | Monthly profit and loss for a year. |
| GET | `/reports/expiring` | Domains or certificates expiring within N days. `type` (domains/certificates), `days` (default 30). |
| GET | `/reports/overview` | Open-ticket breakdown by priority/status/category, plus average resolution time. |

### Knowledge Base

| Method | Path | Description |
|---|---|---|
| GET | `/kb/categories` | List KB categories. **Bare array.** |
| GET | `/kb/articles` | List KB articles — paged, filterable by `category_id`, `client_id`, `search`. |
| GET | `/kb/articles/{id}` | KB article detail (content + attachments). |

### Profile

The calling user's own profile — also doubles as the endpoint for registering a mobile push token or a biometric public key. Both deny legacy `X-Api-Key` auth.

| Method | Path | Description |
|---|---|---|
| GET | `/me` | Current user profile. Bearer token only. |
| PUT | `/me` | Update profile/password, or register an `fcm_token` (push) or `device_public_key_pem` (biometric key). |
| POST | `/me` | Alias of PUT for profile/password updates. (Push token and biometric key registration require PUT.) |

### Appointments

Scheduled/onsite entries against a ticket.

| Method | Path | Description |
|---|---|---|
| GET | `/appointments` | List ticket appointments. `when` (past/today/future), `mine`, `client_id`. **Bare array.** |
| POST | `/appointments` | Create a ticket appointment. Requires `ticket_id`, `schedule_start`. |

### Endpoint agent (optional RMM module, off by default)

Only when the RMM module is switched on (Administration > Settings > Endpoint agent; see `docs/RMM_MODULE.md`). While it is off the device endpoints answer `503 {"code":"module_disabled"}` with `Retry-After: 3600`, and `endpoint_devices` answers `404 {"code":"disabled"}` after authentication.

| Method | Path | Notes |
|---|---|---|
| POST | `/agent_enroll`, `/agent_installer` | Device-facing, enrollment-token gated, no Bearer. |
| POST | `/agent_checkin` | Device-facing (per-device credential). |
| GET, POST | `/agent_jobs` | Device-facing: signed job offer (long poll) and job report. |
| GET | `/agent_update` | Device-facing: hosted agent update download. |
| GET | `/endpoint_devices`, `/endpoint_devices/{id}`, `/endpoint_devices/{id}/jobs` | User token only (the legacy `X-Api-Key` is refused). Role `module_rmm`; devices outside the caller's clients are a 404. |
| POST | `/endpoint_devices/{id}/jobs`, `/endpoint_devices/{id}/jobs/{job_id}/cancel`, `/endpoint_devices/{id}/remote` | Needs `module_rmm_scripts` / `module_rmm_remote_connect`; a reboot needs `"confirm": true`. |

The wire protocol of the device endpoints is frozen and documented in rivet-core (`docs/rmm/PROTOCOL.md`).

### Notifications

| Method | Path | Description |
|---|---|---|
| GET | `/notifications` | List undismissed notifications — paged. |
| POST | `/notifications/{id}/read` | Mark a single notification read/dismissed. |
| POST | `/notifications/read-all` | Mark all notifications read/dismissed. |
| GET | `/notifications/stream` | Server-Sent Events stream of new notifications (`text/event-stream`). |

### Alerts

Combined view of RMM and backup alerts, with a shared acknowledge/resolve action.

| Method | Path | Description |
|---|---|---|
| GET | `/alerts` | List combined RMM and backup alerts. Filterable by `status`, `severity`, `source` (all/rmm/backup), `client_id`. |
| POST | `/alerts` | Acknowledge or resolve an alert. Requires `source` (rmm/backup), `id`, `action` (acknowledge/resolve). |

### Diagnostics

| Method | Path | Description |
|---|---|---|
| POST | `/crash-reports` | Report a mobile app crash (logged to App Logs under category `mobile_crash`). Requires `stack_trace`. Public — a bearer token is honored for correlation when present but never required, since the crash worth capturing is often the one that happens before/around a failed login. Its own tighter rate limit applies: 20 requests / 5 minutes per IP. |

### Public

| Method | Path | Description |
|---|---|---|
| GET | `/csat` | Aggregate CSAT rating summary for public embedding (e.g. a website trust badge). Fully public — no credential accepted or required. Returns only the aggregate rating, distribution, and anonymized 4-5 star comment snippets — never client/contact names or ticket subjects. Response is cached 5 minutes (`Cache-Control: public, max-age=300`). |

### Meta

| Method | Path | Description |
|---|---|---|
| GET | `/validate_api_key` | Validate the presented bearer token or API key (legacy compatibility probe). |
| GET | `/openapi` | This OpenAPI 3.0 spec, as YAML. Public. |
| GET | `/docs` | The human-readable HTML API reference, self-contained. Public. |

---

## Notes for common integration scenarios

- **Mobile app / anything acting as a specific technician:** use bearer tokens from `POST /auth`. Register a push token and (optionally) a biometric public key via `PUT /me` once logged in, so you can support push notifications and the biometric step-up flow for credential secrets.
- **RMM scripts / server-to-server jobs with no specific user:** use a legacy `X-Api-Key`. Scope it to a single client if the integration only ever needs one client's data, and set its Permission to `read` unless the integration genuinely needs to create/update records — a leaked read-only key is a much smaller incident.
- **Public-facing widgets (e.g. a "customer satisfaction" badge on a marketing site):** `GET /csat` needs no credential at all and is safe to call directly from client-side JavaScript.
- **Polling vs. streaming:** ticket chat and notifications both support either a plain polling `GET` (with `since_id` on chat for incremental fetches) or an SSE stream (`?stream=1` on chat, `/notifications/stream` for notifications) — pick streaming for a live UI, polling for anything simpler or running somewhere SSE is awkward (e.g. some serverless environments).
## Public company branding

`GET /api/v1/branding` returns `{ "name": "Example MSP", "logo_path": "/uploads/settings/example.png" }` without authentication. These are the same public identity fields shown on the login screen. `logo_path` is null when no company logo is configured. Uploaded company artwork takes precedence over RivetMSP artwork; resolve the path against the selected server origin. Clients should cache branding per server for offline use and refresh it on resume. No secrets or broader company settings are exposed. The endpoint accepts GET only and is rate limited to 60 requests per minute per IP.
