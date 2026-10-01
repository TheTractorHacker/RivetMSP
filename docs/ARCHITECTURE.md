# RivetMSP Architecture

## Overview

RivetMSP is a single-tenant PHP MSP (managed service provider) platform: one install serves one MSP company, which in turn manages many `clients` (the MSP's own customers). There is no framework — it's hand-written PHP with `mysqli` and raw SQL throughout, organized as a set of top-level directories that map directly to URL paths. One MySQL database backs everything.

The codebase is best understood as four cooperating "portals" sharing a common core:

- **`admin/`** — company configuration, user/role management, integrations, billing setup, database migrations
- **`agent/`** — the day-to-day working UI for MSP technicians/staff (tickets, clients, assets, billing, RMM)
- **`client/`** — a self-service portal for the MSP's own customers (the end clients' contacts)
- **`guest/`** — unauthenticated, token-linked pages (approve a quote, pay an invoice, sign a document)

Shared infrastructure lives in `includes/` (session/auth chain, permission functions, integration clients, global settings), `functions.php` (a large grab-bag of app-wide helper functions), `cron/` (background jobs), and `api/v1/` (the REST API, separately authenticated from the web portals).

A related, closed-source fork called **RivetMSP Internal IT** repurposes this same codebase for internal IT departments (renames Client to Department, disables billing/CRM by default, adds directory-sync and other features); its internals are out of scope for this document.

---

## The Four Portals

| Directory | Audience | Bootstrap include | Auth gate |
|---|---|---|---|
| `agent/` | MSP technicians/staff | `agent/includes/inc_all.php` | Logged-in `users` row, `user_type = 1` |
| `admin/` | Agents who also hold an admin role | `admin/includes/inc_all_admin.php` | Same login as agent, plus `$session_is_admin` true |
| `client/` | End-customer contacts | `client/includes/inc_all.php` | Separate `client_logged_in` session, `users` row `user_type = 2` joined to a `contacts` row |
| `guest/` | Anyone with an emailed link | `guest/includes/inc_all_guest.php` | Per-record random token in the URL (`*_url_key` / `item_key` columns) — no session at all |

Important: **admin is not a distinct account type.** There is no separate `admin/login.php`. An "admin" is simply an agent account (`user_type = 1`) whose role has `role_is_admin = 1`; `admin/includes/inc_all_admin.php` runs the identical agent login chain and then adds one extra check for `$session_is_admin`.

A naming trap to be aware of: `agent/includes/inc_all_client.php` is **not** the client-portal bootstrap. It's the bootstrap for "client overview" pages *inside the agent portal* — i.e., a technician viewing one client record — and enforces agent-side permissions (`enforceUserPermission('module_client')`, `enforceClientAccess()`). The actual client-portal login gate lives in `client/includes/check_login.php`.

Root `index.php` is the traffic cop for `/`: if `$_SESSION['logged']` is set it redirects to the agent portal, else if `$_SESSION['client_logged_in']` is set it redirects to `client/`, else to `/login.php`. Agent and admin share one session flag (`logged`); client uses a different one (`client_logged_in`) — both are possible in the same PHP session namespace but are mutually exclusive per login.

---

## Authentication & Sessions

### Agent / admin login

There is a single root-level `login.php` handling **both** agent and client logins against one `users` table, keyed by email + password. It's a small state machine:

1. **Credentials** — looks up `users` (joined to `user_settings`, `contacts`, `clients`) by email and `password_verify()`s against every matching `user_type` row (at most one `user_type=1` and one `user_type=2` row can share an email).
2. **Role choice** (dual-role accounts only) — if the same email verifies against both an agent row and a client row, the user picks which to sign into, tracked by a short-lived `$_SESSION['pending_dual_login']`.
3. **MFA** (agent only, if TOTP is enrolled) — a `$_SESSION['pending_mfa_login']` pending state gates a TOTP check.

Agent authentication factors:

- **Password** — `password_verify()` against `users.user_password` (bcrypt).
- **TOTP 2FA** — `plugins/totp/totp.php`; the secret is `users.user_token`. Can be forced per-user via `user_settings.user_config_force_mfa`, enforced by `agent/user/mfa_enforcement.php`.
- **WebAuthn / passkeys** — a separate flow outside `login.php` (`passkey_auth_begin.php`, `passkey_auth_complete.php`, driven by `includes/webauthn.php` and `js/webauthn_signin.js`). Hard-restricted to `user_type = 1` — client contacts cannot use passkeys. A passkey is treated as sufficient on its own and bypasses TOTP.
- **Optional shared "login key"** — `settings.config_login_key_required`/`config_login_key_secret`, an install-wide perimeter secret checked independently of the user's own credentials.
- **Remember-me** — a hashed cookie in `remember_tokens` (agent-only) that both bypasses 2FA at login and silently re-establishes a session on later visits ("internet outages don't force re-login").

On success, `login.php` sets exactly:

```php
$_SESSION['user_id']    = $user_id;
$_SESSION['csrf_token'] = randomString(32);
$_SESSION['logged']     = true;
session_regenerate_id(true);
```

That is the entire session contract. Everything else — name, role, admin flag, permissions — is re-derived **per request**, not cached in the session, by the include chain below.

### The `includes/check_login.php` chain

Every agent/admin page runs this orchestrator, which in turn requires:

- **`includes/session_init.php`** — starts the session with `httponly`/`secure` cookies and a configurable lifetime (`settings.config_login_session_lifetime`, clamped 30 min–30 days).
- **`includes/auth_check.php`** — the actual gate. If `$_SESSION['logged']` isn't set, tries to auto-restore from the `rememberme` cookie (rotating the token, single-use); otherwise redirects to `/login.php`.
- **`includes/load_user_session.php`** — the important one. Re-verifies `user_type === 1`, `user_status === 1`, and not archived (killing a disabled/archived user's session on their very next request), and sets the request-scoped globals nearly everything else depends on:
  - `$session_user_role` — `users.user_role_id`
  - **`$session_is_admin`** — derived from the joined `user_roles.role_is_admin` column; the single source of truth for admin-ness
  - `$client_access_array` / `$client_access_string` / `$access_permission_query` — per-user client scoping (see below)
- **`includes/load_company_settings.php`** / **`includes/load_global_settings.php`** — load the singleton `settings`/`companies` rows into globals shared by all four portals (module toggles, numbering sequences, SMTP config, etc.).

### Client portal login

Client-portal identity is **not** a separate table — it reuses `users` with `user_type = 2`, 1:1-linked to a `contacts` row via `contacts.contact_user_id`. Portal access is granted explicitly: when an agent creates/edits a contact with login credentials (`agent/post/contact.php`), both a `users` row and the linking `contacts.contact_user_id` are written together. A `contacts` row with no linked `users` row simply has no portal login.

Client logins go through the same `login.php` STEP 1 password check (one shared email field for both account types). The client branch additionally:

- Checks `settings.config_client_portal_enable` — if the portal is disabled company-wide, login is refused even with a correct password.
- Requires `user_auth_method === 'local'`; SSO users go through `client/login_microsoft.php` instead (Microsoft Entra ID OAuth2, described under Integrations).

On success it sets `client_logged_in`, `client_id`, `user_id`, `user_type = 2`, `contact_id`, and (for shared-check convenience) `logged = true` as well.

Per-request, `client/includes/check_login.php` re-derives everything from the session, re-verifies `user_type === 2` / active / not archived against the DB (duplicating the agent-side pattern rather than sharing code), and loads three boolean flags off the contact's own row — `contact_technical`, `contact_billing`, `contact_primary` — which are the **entire** client-portal permission model (see below). No TOTP/WebAuthn option exists for client contacts.

### Guest portal

`guest/includes/inc_all_guest.php` has no login/session-identity include at all. It sets its own security headers (CSP, `X-Frame-Options: DENY`, etc.) since it's the unauthenticated surface, and individual pages resolve their target record from a random per-record token in the URL rather than any user identity.

---

## Permission Model

There are, in effect, **three separate, independently-evolved layers**, worth keeping distinct rather than conflating:

### 1. Module + role-based permissions (agent/admin, the live system)

Schema: `modules` (a small fixed catalog — `module_client`, `module_support`, `module_credential`, `module_sales`, `module_financial`, `module_reporting`, `module_kb`, and others referenced in code such as `module_rmm`), `user_roles` (`role_id`, `role_name`, `role_is_admin`), `user_role_permissions` (`user_role_id` + `module_id` → an access level), `users.user_role_id`.

Core functions in `functions.php`:

- `lookupUserPermission($module)` — returns an access level 1 (read) / 2 (write) / 3 (full), or `false`. Admins (`$session_is_admin`) always get 3, bypassing the table entirely.
- `enforceUserPermission($module, $check_access_level = 1)` — a **hard `exit()`** (not a redirect) if the level isn't met. Used both defensively at the top of write handlers and to conditionally show/hide nav/UI.

Roles are managed at `admin/roles.php` (admin-only).

### 2. Module *enable* toggles — a separate axis (company-wide, not per-user)

Boolean columns on the singleton `settings` row, loaded once per request into globals like `$config_module_enable_kb`, `$config_module_enable_accounting`, `$config_module_enable_rmm`, `$config_client_portal_enable`, etc. (full list under "Module / Feature Toggles" below). These answer "does this feature exist for this install at all", independent of who's allowed to use it. Nav code typically ANDs both axes together, e.g. a knowledge-base link only shows when the KB module is both enabled *and* the current role has read permission on `module_kb`.

### 3. Per-client scoping (`user_client_permissions`)

Schema: `user_client_permissions(user_id, client_id)` — a simple allow-list.

Two independent consumers:

- **Read-side / list filtering**: `$access_permission_query` (built in `load_user_session.php`) is spliced directly into raw SQL across roughly ninety files (client lists, ticket lists, most reports and modals) as `AND clients.client_id IN (...)`.
- **Write-side / single-record guard**: `enforceClientAccess($client_id)` in `functions.php`.

**The nuance worth internalizing**: this is allow-list, but only *activates* once at least one row exists for that user. A non-admin user with **zero** `user_client_permissions` rows can see/touch every client (unrestricted default); adding even one row switches that user into a strict allow-list containing only the listed clients. It's easy to misread "empty" as "deny" — it means the opposite.

### 4. Client-portal permissions (much simpler)

Client contacts have no modules/roles at all. Visibility inside `client/` is driven purely by three booleans loaded off the contact's own row: `contact_primary`, `contact_billing` (technical/asset/ticket-adjacent), `contact_technical`. The primary contact for a client implicitly gets both billing and technical visibility. These combine with the same company-wide `config_module_enable_*` toggles used elsewhere.

### 5. Legacy dead-ish code (flagged, not the real model)

`functions.php` still defines `validateAdminRole()` / `validateTechRole()` / `validateAccountantRole()`, explicitly commented as legacy (comparing a flat role int against hard-coded 1/2/3), predating the modules/`user_role_permissions` system. Only a couple of call sites remain in the whole tree (`admin/post/update.php`, `agent/custom/index.php`). Treat this as vestigial, not as the current permission model.

### Session/identity variables at a glance

| Variable | Set where | Meaning |
|---|---|---|
| `$_SESSION['logged']` | `login.php` (agent path), passkey completion, remember-me restore | agent/admin session exists |
| `$_SESSION['client_logged_in']` | `login.php` (client path) | client-portal session exists |
| `$session_is_admin` | `includes/load_user_session.php`, per-request from `user_roles.role_is_admin` | admin gate + permission bypass |
| `$session_user_role` | `includes/load_user_session.php` | `users.user_role_id`, the permission lookup key |
| `$client_access_string` / `$access_permission_query` | `includes/load_user_session.php` | per-user client scoping, spliced into SQL app-wide |
| `$session_contact_is_technical_contact` / `_is_billing_contact` / `$session_contact_primary` | `client/includes/check_login.php` | client-portal's entire visibility model |

---

## Data Model

RivetMSP is single-tenant per install — one `companies` row (`company_id = 1`, the MSP's own org profile), entirely separate from `clients` (the MSP's customers, the hub everything else hangs off).

The dominant relational convention is **naming, not database-enforced foreign keys**: child tables carry an `int NOT NULL DEFAULT 0` column named `<entity>_client_id`, `<entity>_ticket_id`, etc., and the application joins on it in code. Most core tables (`clients`, `contacts`, `locations`, `tickets`, `ticket_replies`, `assets`, `credentials`, `invoices`, `quotes`) declare **no** `FOREIGN KEY` constraint — `contracts` is the one notable exception, with a real FK to `clients`. By contrast, small many-to-many/join/tag tables (`asset_credentials`, `contact_tags`, `client_tags`, `calendar_event_attendees`, etc.) do use real FKs with `ON DELETE CASCADE`. In short: core "belongs-to" relationships are an app-code convention; join/pivot tables are database-enforced.

Central entities, in prose form:

- **`clients`** — the root. `client_id` PK; everything else references it via `*_client_id`.
- **`contacts`** — belongs to a client; optionally linked to a `users` row (`contact_user_id`) for portal login; carries the `contact_primary`/`contact_billing`/`contact_technical` role flags used by the client portal's permission model.
- **`locations`** — belongs to a client, with an optional site contact.
- **`contracts`** — belongs to a client (real FK); carries per-priority SLA response/resolution times and included support-hours allowances, feeding SLA due-date computation on tickets.
- **`tickets`** — the join point for nearly everything: links to client, contact, location, asset, contract, quote, invoice, project, vendor. Scheduling/appointment fields (`ticket_schedule`, `ticket_onsite`, etc.) live directly on the ticket row — there is no separate appointments table. SLA due timestamps are computed from the linked contract.
- **`ticket_replies`** — ticket conversation/work-log entries, including time-worked.
- **`ticket_charges`** — per-ticket billable line items (product/labor/tax/quantity), distinct from the general-ledger accounting tables; swept into invoices via `charge_invoiced_at`.
- **`ticket_worksheets`** / **`ticket_worksheet_responses`** and a separate **`ticket_outtake_forms`** table both support ticket sign-off/signature capture with overlapping purposes; which is the fully "live" path was not conclusively determined from static inspection alone.
- **`assets`** — belongs to a client, with optional location/contact/vendor, hardware fields, and RMM/warranty metadata.
- **`credentials`** — belongs to a client, with optional links to a contact/asset/vendor/software/folder; the password column is encrypted at rest, with an OTP secret field for stored TOTP.
- **`invoices`** / **`invoice_items`**, **`quotes`** (parallel shape), **`recurring_invoices`** (cron-driven generation), **`payments`**, **`expenses`** — the accounting/billing surface.
- **`kb_articles`** / **`kb_categories`** — a client id of 0 means a company-wide article; a full-text index supports search.
- **`projects`** — belongs to a client, with a per-install numbering sequence; tickets can optionally belong to a project.

Other notable tables not detailed above: `recurring_tickets`, `ticket_automation_rules`/`ticket_automation_runs`, `ticket_watchers`, per-entity audit-trail tables (`ticket_history`, `asset_history`, `credential_history`, `domain_history`, `certificate_history`), `networks`/`racks`/`rack_units`, `domains`/`certificates`, `vendors`, `software`/`software_keys`, plus the RMM (`rmm_*`, 7 tables) and UniFi (`unifi_*`, 3 tables) integration tables.

---

## Module / Feature Toggle System

Feature availability is controlled at two independent layers, and it's worth keeping them distinct when reading nav/gating code:

1. **Company-wide enable toggles** — boolean columns on the singleton `settings` row, loaded once per request by `includes/load_global_settings.php` into globals such as:

   | Global | Gates |
   |---|---|
   | `$config_module_enable_itdoc` | IT documentation (assets/credentials/etc.) |
   | `$config_module_enable_ticketing` | Ticketing throughout the app |
   | `$config_module_enable_accounting` | Full invoicing/quotes/recurring-invoices/expenses |
   | `$config_module_enable_ticket_charges` | Billable time/charges on tickets, usable independently of full accounting |
   | `$config_module_enable_kb` | Knowledge base (agent and client) |
   | `$config_module_enable_live_chat` | Real-time ticket chat |
   | `$config_module_enable_payroll` | Payroll (gross-pay only, no tax withholding); admin-only nav |
   | `$config_module_enable_rmm` | RMM integration UI, toggled from the integrations settings page rather than the generic modules page |
   | `$config_module_enable_unifi` | UniFi integration |
   | `$config_client_portal_enable` | Whether the client portal accepts logins at all |

   Managed mainly at `admin/settings_module.php`; RMM has its own settings screen.

2. **Per-role module permissions** — the `modules`/`user_role_permissions`/`user_roles` system described under Permissions, answering "can *this* role see it" rather than "does this feature exist."

Nav code typically requires both: the feature must be turned on for the install **and** the current role must hold at least read access on the corresponding permission module. CRM and Projects have no company-wide enable toggle at all — they're always-on core features, gated only by role permissions.

---

## Database Migrations

`includes/database_version.php` defines `LATEST_DATABASE_VERSION` (a semantic-ish version string). Each install's current version is stored in `settings.config_current_database_version`.

`admin/database_updates.php` is a flat sequence of blocks shaped like:

```php
if (CURRENT_DATABASE_VERSION == '2.6.48') {
    // ALTER/CREATE TABLE statements for this one step
    mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.49'");
}
```

Because the version is a PHP constant fixed once per request and the blocks are plain `if` (not a loop), **exactly one version step advances per page load**. The admin UI (`admin/update.php`) re-shows its "Update Database" button as long as a newer version exists, so upgrading across many versions means clicking it repeatedly; there's a CLI equivalent (`scripts/update_cli.php --update_db`) with the same one-step-per-invocation behavior. Migration blocks increasingly use `IF NOT EXISTS` guards for idempotency.

A discrepancy worth knowing about: `db.sql` (the fresh-install schema dump) is not a clean snapshot of any single point in this migration chain — it is missing some features added by earlier migrations (notably the entire Payroll table set, and some newer settings/CSAT columns) while containing at least one column from a later migration. Since a fresh install loads `db.sql` and then stamps itself as already fully current *without* running `database_updates.php`, a new install can end up permanently missing tables/columns that the migration chain would otherwise have backfilled. Treat `db.sql` as broadly representative of the schema, but don't assume it's authoritative for every recently-added feature — check `database_updates.php` for anything payroll- or CSAT-adjacent, or anything else suspected to be recent.

---

## REST API

`api/v1/index.php` is a flat, single-router REST API dispatcher, entirely separate from the session-cookie auth used by the four portals above. It supports two independent auth mechanisms — per-user Bearer tokens (`api_tokens`, the primary/current mechanism, usable by both agent- and client-type users) and a legacy company-wide `X-Api-Key` (`api_keys`, with its own read/write permission level and optional single-client scoping). It also serves a self-documenting OpenAPI spec at `/api/v1/openapi.yaml`. Per project history there is a second, dead legacy API implementation elsewhere in the tree that is not the live one. Full endpoint-by-endpoint reference lives in `docs/API.md` — this section intentionally does not duplicate it.

---

## Integrations

- **RMM (Remote Monitoring & Management)** — a factory/abstraction (`includes/rmm_client_factory.php`) dispatches to one of four provider clients based on `rmm_integrations.type`: Tactical RMM, Level.io, Action1 (OAuth2 patch management), and Sophos Central (OAuth2, scoped to firewall inventory/alerts). `includes/class_rmm_asset_mapper.php` matches RMM agents to RivetMSP `assets` (by linked ID, then serial, MAC, hostname) and syncs alerts. Sync runs on every cron cycle from `cron/cron.php`, and can auto-create tickets from severity-gated alerts.
- **UniFi networking** — a local-controller client and a cloud-controller client, mapped into RivetMSP `assets`/`credentials`/`networks` by `includes/class_unifi_sync_mapper.php`. Notably, this sync runs from a standalone script (`scripts/unifi_sync_cli.php`), **not** wired into the main `cron/cron.php` dispatcher — it needs its own separately configured cron entry.
- **QuickBooks Online (accounting)** — one-way push only (RivetMSP → QBO, never back). `includes/class_qbo_client.php` handles OAuth2 token refresh and Customer/Item/Invoice/Payment sync. A queue (`accounting_sync_queue`) is drained by `cron/accounting_sync.php` with exponential backoff and dependency ordering (customer before invoice, invoice before payment), with idempotency guaranteed via an `accounting_entity_map` table.
- **Stripe (payments)** — behind a generic `PaymentProviderInterface`/factory (designed for future additional gateways), currently implemented only by `includes/class_stripe_payment_provider.php`. Used for guest invoice payment, webhooks, client-portal saved cards, and agent-recorded payments.
- **Microsoft Entra ID (client portal SSO)** — OAuth2 authorization-code flow (`client/login_microsoft.php`) plus a Microsoft Graph `/me` call for profile info at login time. This is login-only — no broader directory sync was found. Microsoft OAuth is used separately for mailbox connections (below); no Odoo integration exists anywhere in the codebase.
- **Email** — inbound: `cron/ticket_email_parser.php` polls configured mailboxes via IMAP (Webklex library, with OAuth2 support for Microsoft 365/Google Workspace) to create/update tickets from incoming mail. Outbound: `cron/mail_queue.php` drains an app-wide mail queue via PHPMailer (SMTP or OAuth token-based sending); enqueued app-wide via `addToMailQueue()` in `functions.php`.
- **Real-time (SSE + push)** — Server-Sent Events backed by Redis pub/sub power live ticket updates, live ticket chat (for API/mobile clients), and live in-app notifications. Firebase Cloud Messaging (`includes/firebase.php`, hand-rolled JWT/OAuth exchange) delivers push notifications to mobile devices.

### Background jobs (`cron/`)

All are standalone-runnable PHP CLI scripts, most also `require_once`'d from the umbrella `cron/cron.php` dispatcher on an admin-configurable schedule (`admin/cron.php`):

| File | Purpose |
|---|---|
| `cron/cron.php` | Main dispatcher — recurring tickets/invoices/expenses, backups, ticket-automation rules, RMM sync + auto-ticketing |
| `cron/mail_queue.php` | Outbound email delivery |
| `cron/ticket_email_parser.php` | Inbound IMAP mail to tickets |
| `cron/accounting_sync.php` (+ `accounting_sync_standalone.php`) | QuickBooks push queue worker |
| `cron/crm_reminders.php` | Due CRM activity reminder notifications |
| `cron/certificate_refresher.php` | SSL certificate monitoring refresh |
| `cron/domain_refresher.php` | Domain WHOIS/expiry monitoring refresh |
| `cron/metrics_rollup.php` | Daily ticket-flow metrics snapshot for dashboards |
| `cron/outlook_schedule_sync.php` | Pull-direction half of two-way Outlook calendar sync |
| `cron/report_scheduler.php` | Scheduled report emails |

`scripts/unifi_sync_cli.php` is the one notable exception — it is not included from `cron/cron.php` and needs its own separately configured cron entry.

---

## Editions

This document covers RivetMSP, the MSP platform built on upstream ITFlow. Its sibling **RivetIT** serves internal IT departments, with department terminology and different default modules. RivetIT internals are outside this document’s scope.
