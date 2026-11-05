# RivetMSP user guide

How each part of RivetMSP works, one page per area, with screenshots. RivetMSP is the managed-service-provider edition: a service desk, billing, a CRM and endpoint management in one application. This guide covers release 26.10.49 (database 2.6.136), which is a beta.

Every page has the same shape: what the area is for, where to find it, who can use it, a screenshot tour with numbered call-outs, step-by-step tasks, a reference section and tips. A figure with a number is a screenshot you can match to the explanation beside it.

## Who this guide is for

| Audience | What they do in RivetMSP |
|---|---|
| **Administrators** | Install and configure RivetMSP, add users and roles, turn modules on, run backups, manage security and integrations. |
| **Technicians** | Work tickets, schedule visits, log time and charges, keep client documentation and credentials up to date. |
| **Billing staff and accountants** | Turn work into quotes and invoices, record payments, track expenses and payroll, reconcile with the accounting package. |
| **Sales and account managers** | Track leads and opportunities, run campaigns, manage contracts and renewals. |
| **Endpoint engineers** | Monitor devices, run scripts, patch, handle alerts and backups. |
| **Integrators** | Connect other systems through mail, webhooks, the API and the mobile app. |
| **Client contacts** | People at your clients who use the client portal and guest links. Their own screens are covered in the portal pages, which are written for the technicians and administrators who set them up. |

## Start here

| If you are... | Read, in this order |
|---|---|
| Installing RivetMSP for the first time | [First-time setup](00-first-time-setup.md), then [System settings and maintenance](36-system-settings-maintenance.md) |
| A technician on your first day | [Getting started](01-getting-started.md), [Service desk: tickets](05-service-desk-tickets.md), then [Clients, contacts and locations](03-clients-contacts-locations.md) |
| An administrator adding your team | [Users, roles and security](34-users-roles-security.md), then [Ticketing setup and templates](37-ticketing-setup-and-templates.md) |
| Billing clerk or accountant | [Products, quotes and invoices](15-products-quotes-invoices.md), [Payments, AutoPay and Stripe](17-payments-autopay-stripe.md), then [Expenses, revenues and accounts](18-expenses-revenues-accounts.md) |
| Sales or account manager | [Leads, opportunities and the sales pipeline](21-crm-leads-and-pipeline.md), then [Campaigns and segments](22-campaigns-and-segments.md) |
| Responsible for documentation | [Assets](11-assets.md), [Documentation records](12-documentation-records.md), [Knowledge base](13-knowledge-base.md) and [Credentials and the vault](14-credentials-and-vault.md) |
| Running endpoint monitoring | [Endpoints overview](25-endpoints-rmm-overview.md), then [Devices, jobs and policies](26-rmm-devices-jobs-policies.md) |
| Connecting other systems | [Integrations](29-integrations.md), [Mail intake and sending](30-mail-intake-and-sending.md), [Webhooks, event rules and jobs](30b-webhooks-event-rules-and-jobs.md) and [AI and the MCP server](30c-ai-and-remote-mcp.md), then [API and mobile](31-api-and-mobile.md) |
| Rolling the client portal out | [Client portal](32-client-portal.md), then [Guest links](33-guest-links.md) |
| Preparing for an audit | [Compliance administration](35-compliance-admin.md) and [Client workflows and client compliance](04-client-workflows-and-compliance-per-client.md) |

## All pages

### Set up and get around

| Page | What it covers |
|---|---|
| [First-time setup](00-first-time-setup.md) | The ways to install, the browser installer step by step, the command-line setup, and what to do straight afterwards. |
| [Getting started](01-getting-started.md) | Signing in, the sidebar and its three navigation scopes, your account, notifications, search, roles and the list controls every page shares. |
| [Dashboard](02-dashboard.md) | The widget dashboard, the money band and how to customise it. |

### Clients

| Page | What it covers |
|---|---|
| [Clients, contacts and locations](03-clients-contacts-locations.md) | The client list, adding and importing clients, contacts and their portal access, locations, archiving. |
| [Client workflows and client compliance](04-client-workflows-and-compliance-per-client.md) | Onboarding and offboarding checklists, and the per-client compliance page and snapshots. |

### Service desk and field work

| Page | What it covers |
|---|---|
| [Service desk: tickets](05-service-desk-tickets.md) | The ticket list, views, boards, creating and working tickets, time, merging and watchers. |
| [Ticket billing and worksheets](06-ticket-billing-and-worksheets.md) | Billable tickets, charges, invoices from tickets, worksheets and outtake forms. |
| [Recurring tickets, Request Something, CSAT and Requests](07-recurring-tickets-catalog-csat-requests.md) | Tickets that raise themselves, one-click request tiles, satisfaction ratings and mail from unknown senders. |
| [Dispatch, scheduling and calendar](08-dispatch-scheduling-calendar.md) | Appointments, remote and on-site visits, calendar views, trips and automation rules. |
| [Projects](09-projects.md) | Projects with milestones, tasks, board and Gantt views, and templates. |
| [Problems and changes](10-problems-and-changes.md) | Root-cause tracking and change planning. |

### Documentation

| Page | What it covers |
|---|---|
| [Assets](11-assets.md) | Devices: owners, locations, interfaces, history and statuses. |
| [Documentation records](12-documentation-records.md) | Licenses, domains, certificates, networks, racks, printers, services, vendors, files and structured documents. |
| [Files, structured documents and sharing](12b-files-structured-docs-and-sharing.md) | Racks, printers, network drives, files, structured documents, relationships and sharing. |
| [Knowledge base](13-knowledge-base.md) | Writing, importing, sharing and versioning articles. |
| [Credentials and the vault](14-credentials-and-vault.md) | The encrypted credential vault, one-time codes and the rotation report. |

### Billing and money

| Page | What it covers |
|---|---|
| [Products, quotes and invoices](15-products-quotes-invoices.md) | The product catalog, quotes, invoices, statuses, reminders, late fees and credits. |
| [Contracts and recurring invoices](16-contracts-and-recurring-invoices.md) | Contracts, included hours, SLAs and recurring billing. |
| [Payments, AutoPay and Stripe](17-payments-autopay-stripe.md) | Recording payments, saved cards, AutoPay and the payment provider. |
| [Expenses, revenues and accounts](18-expenses-revenues-accounts.md) | Expenses, recurring expenses, revenues, vendors, accounts, transfers and trips. |
| [Payroll](19-payroll.md) | Employees, deductions, pay periods and runs. |
| [Accounting and Pax8 sync](20-accounting-pax8-sync.md) | QuickBooks and Xero mapping and sync, and Pax8 cost sync. |

### CRM

| Page | What it covers |
|---|---|
| [Leads, opportunities and the sales pipeline](21-crm-leads-and-pipeline.md) | Leads and their board, converting a lead, opportunities and pipeline totals. |
| [Campaigns and segments](22-campaigns-and-segments.md) | Segments, email campaigns and CRM reminders. |

### Insight

| Page | What it covers |
|---|---|
| [Reports](23-reports.md) | The report hub, date ranges, every financial and technical report, and exports. |

### Endpoints

| Page | What it covers |
|---|---|
| [Endpoints overview](25-endpoints-rmm-overview.md) | Choosing a vendor RMM or the built-in agent, the dashboard, fleet and adding devices. |
| [Devices, jobs and policies](26-rmm-devices-jobs-policies.md) | The device page, scripts, checks, policies, schedules and approvals. |
| [Patching, software and remote access](27-rmm-patching-software-remote.md) | Patches, software, logs and remote connections. |
| [Alerts, backups, network and RivetPanel](28-alerts-backups-network-panel.md) | The central alerts page, Comet backups, firewalls and the RivetPanel. |

### Connections

| Page | What it covers |
|---|---|
| [Integrations](29-integrations.md) | Microsoft Intune and Entra, UniFi, Outlook calendar, Comet and the vendor RMM hub. |
| [Mail intake and sending](30-mail-intake-and-sending.md), [Webhooks, event rules and jobs](30b-webhooks-event-rules-and-jobs.md) and [AI and the MCP server](30c-ai-and-remote-mcp.md) | Mail in and out, templates, webhooks, event rules, the job queue, AI and the MCP server. |
| [API and mobile](31-api-and-mobile.md) | API keys, tokens, limits, the API docs page and the mobile app. |

### Portals

| Page | What it covers |
|---|---|
| [Client portal](32-client-portal.md) | What clients see and do, and how to set the portal up. |
| [Guest links](33-guest-links.md) | Invoice payment, quote acceptance, shared items, approvals and signing without an account. |

### Administration

| Page | What it covers |
|---|---|
| [Users, roles and security](34-users-roles-security.md) | Users, roles, client scoping, MFA, passkeys, sign-in policy, audit trail, sessions and encryption keys. |
| [Compliance administration](35-compliance-admin.md) | Compliance settings, frameworks, status and the auditor export. |
| [System settings and maintenance](36-system-settings-maintenance.md) | Modules, company details, mail, notifications, cron, backup and restore, updates. |
| [Ticketing setup and templates](37-ticketing-setup-and-templates.md) | Statuses, labor types, SLA, automation, categories, tags and templates. |

## Conventions

- **Bold** is a label exactly as it appears on screen. An arrow joins the steps of a menu path: Administration → **Settings** → **Modules**.
- Words: **Client** (an organisation you look after), **Contact** (a person at a client), **Technician** (a member of your staff), **Client portal** (the self-service site for contacts). [Getting started](01-getting-started.md#words-used-in-this-guide) has the full list.
- Each page's opening table says where to find the area, which **permission module** and level are needed (**Read**, **Modify** or **Full**; administrators always have Full) and which switch turns it on.
- Screenshots come from a demonstration company, Lakeshore Managed IT, whose clients, people and domains are made up and end in `.example`. Where a screen needs an outside service (Stripe, Pax8, QuickBooks or Xero, Intune, Outlook, Comet, UniFi, a real endpoint agent, the mobile app) the page says so, and shows example data or a settings screen. Those features were not verified on a live service for this guide.
- A **Note** describes behaviour that may surprise you in this release, including a few that look like defects.
- Red numbered badges on a screenshot match the numbers in the text beside it.

## Pictures without call-outs

Every screenshot also exists without the red call-outs, for reuse in your own material, in [`images-clean/`](images-clean/), in the same folders and with the same file names as [`images/`](images/).

## Keeping the guide accurate

`tools/` holds what rebuilds the guide's demonstration server and screenshots: `build-demo.sh` (the server and its sample data, from `tools/seed/`), `capture/*.cjs` (the screenshot scripts), `run-all.cjs`, `optimize-images.sh` and `check-guide.py`, which checks links, anchors, images and wording.
