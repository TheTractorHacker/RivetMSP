<div id="top"></div>

<!-- PROJECT SHIELDS -->
[![Contributors][contributors-shield]][contributors-url]
[![Stargazers][stars-shield]][stars-url]
[![Commits][commit-shield]][commit-url]
[![GPL License][license-shield]][license-url]

<div align="center">

  <img src="img/branding/rivetmsp-logo.png" alt="RivetMSP" width="480">
  <h3 align="center">RivetMSP</h3>

  <p align="center">
    A fork of <a href="https://github.com/itflow-org/itflow">ITFlow</a> with extended MSP workflow features built by <a href="https://foleyit.com">Foley IT</a>.
    <br />
    <br />
    <a href="https://github.com/itflow-org/itflow">Upstream Project</a>
    ·
    <a href="https://docs.itflow.org">Docs</a>
    ·
    <a href="docs/ARCHITECTURE.md">Architecture</a>
    ·
    <a href="docs/API.md">API Reference</a>
    ·
    <a href="https://github.com/TheTractorHacker/RivetMSP/releases">Releases</a>
    ·
    <a href="https://github.com/TheTractorHacker/RivetMSP/issues">Report Bug</a>
    ·
    <a href="https://github.com/TheTractorHacker/rivetmsp-mobile">📱 Android App</a>
  </p>
</div>

---

> **This is a fork.** It tracks the upstream [itflow-org/itflow](https://github.com/itflow-org/itflow) and merges updates regularly. All original credit goes to the ITFlow contributors. MSP-specific additions are maintained here by Foley IT / TractorHacker.

---

<!-- ABOUT -->
## About

**RivetMSP** is a hardened, feature-extended build of ITFlow — the free and open-source IT documentation, ticketing, and accounting platform for managed service providers.

This fork adds real-world MSP dispatch and scheduling workflows that go beyond the upstream project, while staying in sync with upstream security patches and improvements.

We also built a **native Android app** from scratch to go alongside this fork — giving technicians full mobile access to tickets, assets, clients, worksheets, and more. Check it out at [TheTractorHacker/rivetmsp-mobile](https://github.com/TheTractorHacker/rivetmsp-mobile).

New to this codebase? [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) covers the admin/agent/client/guest portal structure, auth & permission model, data model, module toggles, and integrations. [docs/API.md](docs/API.md) is the narrative companion to the REST API.

---

## 📱 Android App

A native Android companion app is available at **[TheTractorHacker/rivetmsp-mobile](https://github.com/TheTractorHacker/rivetmsp-mobile)**.

Built with Kotlin + Jetpack Compose + Material 3. Features include:

- Dashboard with open ticket counts, recent activity, and alerts
- Full ticket management — view, reply, change status, assign, add charges
- Asset browsing and detail view with barcode/QR scanner
- Client list with contacts, locations, and credentials
- Worksheet viewing and response entry
- Global search across tickets, clients, and assets
- Push notifications for new tickets and assignments

> Requires your RivetMSP server running **v2.4.12+** with the REST API enabled.

---

<!-- MSP ADDITIONS -->
## What's Added in This Fork

### Ticket Automation
- **Rule-based automation engine** — create rules that run automatically on every cron cycle
- **Conditions**: ticket age, idle time since last reply, priority, status, assigned user, or **ticket category** (On-Site, Remote, Project, etc.)
- **Actions**: set priority, assign to user, set status, add internal note, notify assignee, close ticket, or **automatically attach a worksheet template**
- Smart dropdowns in the rule builder — category and worksheet selects populate from your live data

### Cron Manager
- **Web UI cron scheduler** — change the main cron schedule (5 min / 15 min / 30 min / hourly / custom) without touching the server
- **Run Now** button to trigger cron immediately from the admin panel
- Shows last successful run time and all scheduled cron jobs

### Ticketing
- **Ticket categories** with parent/group hierarchy and collapsible grouped list view
- **Inline pill-style dropdowns** — change Category, Assigned Tech, Priority, and Status directly from the ticket list without opening the ticket
- **Syncro-style appointments** — end time, duration picker (30 min – 8 hr), Remote/Onsite toggle, appointment notes, and live preview
- **Ticket reply draft autosave** — localStorage autosave with Restore/Discard banner so replies survive accidental navigation

### Worksheets
- **Unfinalize button** — unlock a finalized worksheet to edit it again (unavailable on client-signed worksheets)
- **Worksheet template drag-and-drop field reordering**
- **Worksheet percent counter** — accurately counts all field types
- **Automation-attached worksheets** — rules can auto-attach a worksheet template when a ticket matches a condition

### Calendar & Scheduling
- **Outlook Calendar push sync** — each technician connects their Microsoft account once; scheduled tickets automatically create, update, and cancel events in their personal Outlook calendar via Microsoft Graph API
- **iCal subscription feed** — per-user webcal:// URL for subscribing scheduled tickets into Outlook Classic, Apple Calendar, or Google Calendar
- **Per-tech calendar colors** — each technician picks a color shown on the RivetMSP dispatch calendar

### Contracts & SLA
- **SLA tracking on contracts** — define response/resolution hours per priority tier
- **Contract billing frequency** — Monthly, Quarterly, Annual, or Other
- **Live SLA hint on ticket add** — shows expected response/resolution time when a contract is selected

### REST API (for Mobile App)
- Full REST API layer under `/api/v1/` powering the Android app
- Endpoints: tickets, clients, assets, contacts, locations, credentials, worksheets, charges, appointments, search, reports
- Token-based auth with rate limiting, token expiry, and payload size limits
- Full reference: [docs/API.md](docs/API.md) (narrative guide) · [live OpenAPI spec](https://github.com/TheTractorHacker/RivetMSP/blob/Syncro-Beta/api/v1/openapi.yaml) · in-app searchable reference at Settings → API Docs (`/api/v1/docs` on your own instance)

### Security Fixes (beyond upstream)
- Fixed authorization bypass on ticket charge handlers (client access not enforced)
- Fixed XSS in outtake/worksheet signature storage
- Fixed SQL injection in contract name logAction call

---

<!-- SYNCING WITH UPSTREAM -->
## Keeping Up With Upstream

This fork merges upstream changes periodically:

```bash
git fetch origin        # origin = itflow-org/itflow
git merge origin/master
git push fork master    # fork = TheTractorHacker/RivetMSP
```

<!-- GETTING STARTED -->
## Getting Started

Two supported ways to get a running instance, depending on what you're doing:

### Option 1 — Docker Compose (fastest way to try it)

```bash
git clone https://github.com/TheTractorHacker/RivetMSP.git
cd RivetMSP
cp .env.example .env    # edit DB_PASSWORD/DB_ROOT_PASSWORD, and DOCKER_UID/DOCKER_GID (run `id -u`/`id -g`)
docker compose up -d --build
```

Then visit `http://localhost:8080/` (or whatever `APP_PORT` you set in `.env`) — it redirects straight
to the same browser-based `/setup/` wizard a manual install would use. When it asks for a database host,
enter `db` and the credentials from your `.env`.

This runs nginx + PHP-FPM + MariaDB + Redis in containers, with the app code bind-mounted from this
checkout so `config.php`/`uploads/`/`backups/` all persist on the host and `git pull` +
`docker compose up -d --build` is the update path. It's deliberately not hardened the way
`deploy/install.sh` is (no fail2ban/ufw equivalent, no TLS termination) — put a real reverse proxy in
front of it for anything beyond local evaluation.

Standing this container up from an existing `deploy/backup.sh` backup instead of a fresh install: drop
the backup file and its passphrase file under `./restore/` (bind-mounted read-only into the container),
set `RESTORE_FROM`/`RESTORE_PASSPHRASE_FILE` in `.env` to point at them, then `docker compose up -d
--build` — see the comments in `.env.example` and [`deploy/README.md`](deploy/README.md#restoresh).

### Option 2 — bare-metal install (recommended for a production instance)

The deployment tooling in [`deploy/`](deploy/README.md) provisions a whole box from scratch:

```bash
git clone https://github.com/TheTractorHacker/RivetMSP.git
cd RivetMSP
sudo deploy/install.sh --domain=itflow.example.com
```

It provisions nginx, PHP 8.4, MariaDB, and Redis; sets up TLS; applies security hardening; and runs the
app's own first-run setup (or, with `--restore-from`/`--restore-passphrase-file`, restores an existing
`deploy/backup.sh` backup onto the new box instead) — see [`deploy/README.md`](deploy/README.md) for the
full flag reference, worked examples (including adding a second company's instance to a box that already
runs one), backups, and updates. If you'd rather install manually or use the upstream one-liner, see the
[official docs](https://docs.itflow.org/installation) — after installing that way, replace the files
with this fork's content or clone this repo directly into your web root.

Either way, `deploy/backup.sh` (encrypted, scheduled) is the disaster-recovery path, with
`deploy/restore.sh` as its counterpart for standing a fresh box back up from one of those backups — see
[`deploy/README.md`](deploy/README.md#restoresh).

<!-- RELEASES -->
## Releases

| Release | Notes |
|---------|-------|
| v2.6.0 | Ticket automation rules, Cron Manager UI, worksheet unfinalize, Android app |
| v2.5.2 | Outtake forms, ticket filters, worksheet delete |
| v2.5.0 | Worksheet/charge creation, onsite tracking, products API |
| v1.2.6-msp | Webhooks, Passkeys, Backup, Comet, Contract Docs |
| v1.2.5-msp | Outlook push sync, per-tech calendar colors, modal bug fixes |
| v1.2.3-msp | Categories, SLA, contracts, worksheets, inline ticket actions |

See [all releases](https://github.com/TheTractorHacker/RivetMSP/releases) for full changelogs.

## License

ITFlow is distributed under the GPL License. This fork inherits the same license. See [`LICENSE`](https://github.com/itflow-org/itflow/blob/master/LICENSE) for details.

## Security

If you find a security issue in the upstream project, report it [here](https://github.com/itflow-org/itflow/security/policy).
For issues specific to this fork, open an [issue](https://github.com/TheTractorHacker/RivetMSP/issues).

<!-- MARKDOWN LINKS & IMAGES -->
[contributors-shield]: https://img.shields.io/github/contributors/TheTractorHacker/RivetMSP.svg?style=for-the-badge
[contributors-url]: https://github.com/TheTractorHacker/RivetMSP/graphs/contributors
[stars-shield]: https://img.shields.io/github/stars/TheTractorHacker/RivetMSP.svg?style=for-the-badge
[stars-url]: https://github.com/TheTractorHacker/RivetMSP/stargazers
[license-shield]: https://img.shields.io/github/license/TheTractorHacker/RivetMSP.svg?style=for-the-badge
[license-url]: https://github.com/itflow-org/itflow/blob/master/LICENSE
[commit-shield]: https://img.shields.io/github/last-commit/TheTractorHacker/RivetMSP?style=for-the-badge
[commit-url]: https://github.com/TheTractorHacker/RivetMSP/commits/master
