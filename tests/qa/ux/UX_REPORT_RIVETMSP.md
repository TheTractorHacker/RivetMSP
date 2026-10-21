# Rivet MSP web UI/UX QA report

Run date 2026-10-21. Edition Rivet MSP 26.10.41 (DB 2.6.106), branch `qa-ux-20261021` cut from `beta` at `65b82edc5`. Web only; Android (Phase E) not started.
Method: `tests/qa/master/UIUX_RUBRIC.md` and `RIVETMSP_MASTER_PROMPT.md`, phases A to D. Scripts and README are in this folder, evidence in `shots/` and `results/`.

Environment: own worktree `/home/sysadmin/qa-ux-rivetmsp`, own server `127.0.0.1:8191`, own database `rivetmsp_qa_ux` (a clone of `rivetmsp_demo`, migrated 2.6.102 -> 2.6.106 so the shared demo on :8445 was not migrated). Demo data only. All created records start with `QA-UI-`. Nothing was sent (no invoices, quotes, e-mail, reminders), nothing was pushed or deployed, `vendor/` untouched. Host PHP 8.5.11 is flagged as exploitable by the environment hook, so the server was bound to loopback only and stopped afterwards.

Read this first: the screenshots in `shots/before/` come from a throwaway export of `beta` served on :8192 against the same data; `shots/after/` come from the fixed worktree. A runtime flip of `data-bs-theme` is NOT a valid dark-mode test (it gave a false "white select box" finding that I discarded); every dark result below uses the real per-user dark preference.

## 1. Totals

| | |
|---|---|
| Distinct admin/agent URLs swept (Phase A, 1920x1080) | 246 (light), 246 (real dark, run before the dark fixes), plus a final light re-run after all fixes |
| Key pages at other sizes | 30 pages x 2560x1440, 1536x864, 1366x768 (light) and 30 pages real dark at 1920x1080 |
| Client portal pages | 19 (light) |
| Controls enumerated and clicked (Phase B) | 1,860 control instances on 185 pages: 596 navigations, 171 modals opened, 387 dropdown/toggle, 283 no visible change, 282 skipped as unsafe/outbound, 141 harness click timeouts |
| Create-through-modal checks (Phase B) | 25 modules: 22 saved and persisted, 2 not reachable (no "New" button found), 1 not completed (Trips: required destination select) |
| Persona tasks performed through the navigation (Phase C) | 31 tasks, 6 personas |
| Defects found | 34 (see A) |
| Defects fixed and re-verified | 27 (some are bundles of one root cause; 4 of them are known items carried over) |
| Defects not fixed | 7 (1 needs a schema change, 1 an owner decision on the colour presets, the rest are low) |
| Improvement proposals | 24 (list B, nothing built) |
| Commits on the branch | 33 on top of `65b82edc5` |

Automated accessibility (axe-core, 246 pages, light): pages with a violation, before -> after.

| Rule | Before | After |
|---|---|---|
| landmark-one-main | 245 | 2 |
| page-has-heading-one | 239 | 2 |
| button-name (critical) | 135 | 9 |
| select-name (critical) | 99 | 0 |
| label (critical) | 86 | 4 |
| color-contrast | 104 | 42 |
| link-in-text-block | 13 | 5 |
| heading-order | 41 | 99 |
| link-name | 27 | 26 |
| empty-table-header | 22 | 25 |
| target-size | 9 | 9 |

heading-order went up because adding a page `<h1>` exposed the templates' h2-less h3 card titles; `aria-level` shifts reduced it from 234 to 99 but not to zero.
The portal's primary buttons are now AA (the teal accent gave white text 3.7:1). The calendar, RMM and portal CSP console errors are gone.

## 2. Coverage

| Area | Pages / controls | Result |
|---|---|---|
| Agent: dashboard, alerts, clients (clients, leads, archived), contacts, locations, assets and asset detail, tickets, ticket detail, recurring tickets, CSAT, requests, problems, changes, projects (list, detail, kanban, gantt), calendar, quotes, invoices (+ detail), recurring invoices, revenues, products, payments, vendors, expenses, recurring expenses, accounts, transfers, trips, credentials, networks, printers, network drives, racks, domains, certificates, files, contracts, services, software, KB list/article, pipeline, opportunities, campaigns, segments, client overview and compliance, onboarding runs, RMM pages, 24 reports, user account tabs | 156 URLs reached by crawling | 4 pages fataled or rendered blank before fixes (Pipeline, Opportunities, Settings > Accounting, sorting Problems/Changes/Runs); all fixed except Accounting |
| Admin: users, roles, API keys/docs, tags and categories, taxes, payment methods/providers, ticketing setup (statuses, categories, templates, SLA policies/calendars, automation), template library, maintenance (job queue, mail queue, logs, audit, update), settings (28 sub-pages), webhooks, event rules, compliance status | 90 URLs | pass after fixes; Accounting fatal remains |
| Client portal: home, tickets (+new, view, all), contacts, assets, documents, domains, certificates, contracts, invoices, recurring invoices, quotes, saved payment methods, profile | 19 URLs, all clicked as `qa251@qa.example` | pass; 3 gaps proposed |
| Modals | 171 opened from list/detail pages; 25 New-record modals submitted | 0 empty, 0 raw errors; 1 wrong button label fixed |
| Tabs | client sidebar tabs, project tabs, lead/new-client modal tabs, ticket reply tabs, settings tab strips | pass |
| Viewports | 1920x1080 light + dark, 2560x1440, 1536x864, 1366x768 | 1366: Tickets table and filter bar clipped (fixed) |
| Dead controls | see Phase B counts | none found; the 283 "no visible change" are external links (new tab), Print, downloads and forms that redirect back to the same URL |

## A. Defects

Format: ID | severity | page | viewport and theme | rule | steps | screenshot before | status | screenshot after | files changed. Screenshots live in `shots/before` and `shots/after`; where none is listed the evidence is the server log or an axe result in `results/`.

### A1. Fixed and re-verified

| ID | Sev | Page | Viewport/theme | Rule | Steps | Before | Status | After | Files |
|---|---|---|---|---|---|---|---|---|---|
| D-01 | High | CRM > Pipeline, Opportunities (and New Opportunity modals) | all | Feedback: blank page, raw PHP fatal hidden | Open CRM > Pipeline: empty content area. Log: `Call to undefined function getOpportunityStages()`. Three helpers were dropped from `functions.php` by release 26.10.14 | `before/pipeline_1920_light.png` | fixed | `after/pipeline_1920_light.png` | `functions.php` |
| D-02 | High | Problems, Changes, Onboarding runs lists | all | Feedback: crash on a header click | Click the Title / Started sort header: page dies, `Unknown column 'problemstitle'` (the sort whitelist stripped the dot of `problems.title`) | server log | fixed, verified on all three | - | `includes/filter_header.php` |
| D-03 | High | Admin > Compliance status; every `badge bg-*` (49 uses) | 1920 light | Colour: text contrast 1.1-1.9:1 | Open the page: Pass/Fail/state pills are blank coloured capsules (grey text on green/red) | `before/compliance_status_1920_light.png` | fixed | `after/compliance_status_1920_light.png` | `css/itflow.shim-bs4.css` |
| D-04 | High | Every list, real dark mode | 1920 dark | Colour: text typed into TomSelect 1.45:1, links 3.2:1 | Dark preference, Invoices, click the category select, type "Har": text invisible | `before/tomselect_dark.png`, `before/tickets_1920_dark.png` | fixed | `after/tomselect_dark.png`, `after/tickets_1920_dark.png` | `css/itflow.bind-tabler.css` |
| D-05 | High | Tickets list | 1366x768 and anything below 1700 wide | Layout: columns, search and "More Filters" clipped off screen; tile labels cut ("UNASSIGNE") | Open Tickets at 1366: Status, Assigned, Created and the search box are beyond the edge, no visible scrollbar | `before/tickets_1366_light.png` | fixed: views panel stacks above the list below 1700, filter rows wrap | `after/tickets_1366_light.png` | `agent/tickets.php`, `css/ticket_view_filters.css`, `css/itflow_custom.css` |
| D-06 | Medium | Invoice, quote, recurring invoice (agent and guest) | 1920 | Polish: broken image with alt text "Company logo" | Any invoice when no company logo is set: `file_exists()` of the folder is true | `before/invoice_1920_light.png` | fixed | `after/invoice_1920_light.png` | `agent/invoice.php`, `agent/quote.php`, `agent/recurring_invoice.php`, `guest/guest_view_invoice.php`, `guest/guest_view_quote.php` |
| D-07 | Medium | Invoice and quote header | 1920 | Layout: labels misaligned with values | "Invoice #:", "Date:", "Due:" sit higher than their values | `before/invoice_1920_light.png` | fixed | `after/invoice_1920_light.png` | same five files |
| D-08 | Medium | RMM Fleet and Add device with the RMM module off | 1920 | Console/CSP: inline scripts blocked, tab title " - " | The footer was `require`d inside a closure so `$csp_nonce` and the page title were undefined | `before/rmm_fleet_1920_light.png` | fixed | `after/rmm_fleet_1920_light.png` | `agent/rmm_fleet.php`, `agent/rmm_add_device.php` |
| D-09 | Medium | Admin > project and onboarding template details | all | Feedback: redirect loop, script never loads | `src="js/pretty_content.js"` resolved to `/admin/js/` (ERR_TOO_MANY_REDIRECTS) | console | fixed | - | `admin/project_template_details.php`, `admin/onboarding_template_details.php` |
| D-10 | Medium | Calendar | 1920 | Polish: previous/next buttons blank | CSP has no `font-src`, the embedded icon font is blocked | `before/calendar_1920_light.png` | fixed (`font-src 'self' data:`) | `after/calendar_1920_light.png` | `includes/header.php` |
| D-11 | Medium | Ticket detail and list for priority "Critical" | all | Feedback/colour: reads "No priority"; list badge uses the Low colour | Open TCK-123 (priority Critical, set by automation rules / seeded) | `shots/before/` list | fixed (shown, red) | - | `agent/ticket.php`, `agent/ticket_list.php`, `agent/dashboard.php` |
| D-12 | Medium | Sidebar badge, dashboard tile | all | Information scent: counts include leads | Create a lead: "Clients 8" becomes 9, "Active Clients" too, but the list shows 8 | - | fixed | - | `agent/includes/get_side_nav_counts.php`, `agent/dashboard.php` |
| D-13 | Low | New Lead modal | all | Consistency: submit says "Create Client" | Clients > Leads > New Lead | `before/new_lead_modal.png` | fixed | `after/new_lead_modal.png` | `agent/modals/client/client_add.php` |
| D-14 | Medium | All pages (agent, admin, portal, guest) | all | Accessibility: no `<main>`, no `<h1>`, 135 pages with unnamed icon buttons, 99 unlabelled selects, 86 unlabelled inputs | axe on 246 pages | results | fixed centrally (`role="main"`, `js/a11y_auto.js` names controls and adds a page h1) | table in section 1 | `js/a11y_auto.js`, `includes/inc_wrapper.php`, `guest/includes/inc_wrapper.php`, `client/includes/header.php`, `includes/footer.php`, `client/includes/footer.php`, `agent/ticket.php` |
| D-15 | Medium | All, light and dark | 1920 | Colour: AA failures (muted text 4.19, `text-success` 2.74, `form-text` 3.0, `btn-success` 2.6, warning alert 2.0, links 4.1, danger/info buttons and text, nav pills, custom-colour pills) | axe `color-contrast` | results | fixed except D-U2 | table in section 1 | `css/itflow.shim-bs4.css`, `css/itflow_design.css`, `css/itflow.bind-tabler.css`, `agent/ticket_list.php` |
| D-16 | Low | Pipeline and kanban boards | 1920 and 1366 | Layout: first column offset by 16px, fifth stage cut off | Open Pipeline | `before/pipeline_1920_light.png` | fixed | `after/pipeline_1920_light.png` | `agent/css/ticket_kanban.css`, `agent/pipeline.php` |
| D-17 | Medium | Dashboard | all | Empty state: 80% of the page blank | Financial and Technical switches both default off; nothing explains it | `before/dashboard_1920_light.png` | fixed (message) | `after/dashboard_1920_light.png` | `agent/dashboard.php` |
| D-18 | Low | Admin > Settings > Ticketing, Ticket prefix | all | Forms: invalid `pattern` (`[A-Za-z-]` under the v flag), field never validated | console error | console | fixed | - | `admin/settings_ticket.php` |
| D-19 | Low | Ticket create, user disable, quote / recurring invoice / portal ticket create | - | Feedback: PHP warnings; audit entry for ticket creation lost the subject, user-disable audit lost the name | create a ticket; disable a user; create a quote from the New modal | server log | fixed (undefined POST keys defaulted, subject kept) | - | `agent/post/ticket.php`, `admin/post/users.php`, `agent/post/quote_model.php`, `agent/post/invoice_model.php`, `client/post.php`, `client/recurring_invoices.php` |
| D-20 | Low | Admin > Roles | - | Typography: members joined as "a,b" | - | - | fixed | - | `admin/roles.php` |
| D-21 | Low | Ticket stat tiles | - | Typography: 11.2px label | - | - | fixed (12px) | - | `agent/tickets.php` |
| D-22 | Medium | Light theme text links | - | WCAG 1.4.1: links in running text and back-links rely on colour only | axe `link-in-text-block` | results | fixed (underline) | - | `css/itflow.bind-tabler.css` |
| D-23 | Medium | Client portal and guest pages | 1920 | Colour: primary buttons and "View all" 3.7:1, footer text 4.3:1 | axe on portal | results | fixed | `after/portal_home.png` | `css/itflow.bind-tabler.css` |

Known open items from the master prompt, verified first. Carried over by cherry-picking `6255c0e9a` from branch `qa-ui-fixes-2026-10` (not on `beta`), then re-checked here:

| ID | Item | Result |
|---|---|---|
| D-24 | CSP nonce on `agent/csat.php` and portal footer scripts | fixed, no CSP console error on either |
| D-25 | contacts.php `$client_id` warning, ticket.php warnings without params | fixed |
| D-26 | blank / whitespace client name accepted | fixed: "Client name is required" (the modal closes, so the typed data is lost; see B-22) |
| D-27 | duplicate user e-mail | fixed: "A user with the email ... already exists" |
| - | invalid asset IP/MAC | code present (`validateAssetNetworkFields`); the IP field is on a hidden tab and I did not exercise it in the UI, so it is not claimed as verified |
| - | Tickets filter-bar overflow | fixed under D-05 |
| - | no `<main>`/`<h1>`, unnamed controls | D-14 |
| - | calendar font blocked by CSP | D-10 |

### A2. Not fixed

| ID | Sev | Page | Rule | Steps | Why not fixed |
|---|---|---|---|---|---|
| D-U1 | High | Admin > Settings > Accounting (QuickBooks), both tabs | Feedback: blank page, fatal | `Unknown column 'map_remote_name'` in `includes/accounting_functions.php:148`; the code also reads `map_local_subtype` (line 236). `db.sql` and `database_updates.php` create `accounting_entity_map` without either column (also true on current `beta`) | Needs a migration (schema change). Suggested: `ALTER TABLE accounting_entity_map ADD map_remote_name varchar(255) DEFAULT NULL AFTER map_remote_id, ADD map_local_subtype varchar(20) DEFAULT NULL AFTER map_local_type;` plus a unique-key review, in `database_updates.php` and `db.sql` |
| D-U2 | Medium | Ticket status pills on the palette presets (e.g. `#007bff` with white 3.97:1) | Colour | axe on Tickets | Colour is user-chosen. The text colour now follows luminance, but the preset itself cannot reach 4.5:1 either way; the preset palette in `tagColorPalette()` needs darker values (owner decision) |
| D-U3 | Low | Admin > Settings > Company details | Layout/polish | Native "Choose File" button, inputs stretch to about 1500px at 1920, phone country shows "-" | Visual redesign, not a defect fix |
| D-U4 | Low | Footer on admin pages, "Docs" in the sidebar | Polish | "Upstream Docs / Upstream Forum / Upstream Services" and `docs.itflow.org` in a RivetMSP product | Branding decision |
| D-U5 | Low | Calendar | Typography | Event titles are cut ("TCK-122 created - Ma") with no tooltip | FullCalendar configuration choice; "+N more" works |
| D-U6 | Low | 26 pages | Accessibility | empty anchors (a client row with a blank name, icon-only links), 25 empty table headers, 9 small hit areas, 99 pages with a residual heading-order skip | Long tail, each needs per-template markup |
| D-U7 | Low | Money fields (price, amount, opening balance, miles) | Forms | Browser message "Please match the requested format." with no hint of the format | Needs per-field message text |

## B. Improvement proposals (not implemented)

| ID | Persona | Task | Problem observed | Proposal | Expected benefit | Effort | Priority | Evidence |
|---|---|---|---|---|---|---|---|---|
| B-01 | Sales | Move a lead through statuses | The Leads list shows no status, owner, source or score (the "Lead" column shows "-") although the form captures all four; no filter by status; no pipeline-by-status view | Add Status/Owner/Source/Score columns, a status filter and a lead-status board | Pipeline is visible without opening each lead | M | P1 | `shots/persona/leads_list.png` |
| B-02 | Account manager | Monthly client report | No per-client monthly report. Closest are Income by Client, Tickets by Client and Client Time Detail Audit | One "Client report" (tickets, hours, SLA, spend, assets, renewals) with PDF and CSV export, schedulable (Reports > Scheduled exists) | Saves manual QBR prep | L | P1 | Reports menu, `persona_msp.json` |
| B-03 | Billing | Invoice from tracked time | Reports > Unbilled Tickets is counts per client with no links; invoicing means opening each ticket (6 clicks) | Drill-down from the count to the tickets, multi-select and "Invoice selected" | Unbilled time stops leaking | M | P1 | `persona_msp.json` |
| B-04 | Billing | Chase an overdue invoice | Invoices list has no Overdue filter or badge and no bulk "send reminder"; the AR aging report cannot link to the invoices | Overdue filter, age badge, bulk reminder (via the existing mail path), aging cells link to a filtered list | Shorter collections loop | M | P1 | `persona_msp.json` |
| B-05 | Technician | Look up a KB answer and attach it | No KB entry in the technician sidebar ("Docs" goes to the upstream site); the reply editor only offers canned responses | KB link in the sidebar and "Insert KB article" in the reply toolbar | Faster consistent answers | M | P1 | `persona_tech.json` |
| B-06 | Client contact | Submit a ticket with an attachment | Portal New Ticket has no attachment field; profile only allows a password change; no KB | Attachment upload, editable name/phone, KB search | Fewer back-and-forth tickets | M | P1 | `shots/persona/portal_ticket_add_no_attachment.png` |
| B-07 | Technician | Filter my tickets by priority | The Priority filter offers High/Medium/Low only, yet tickets can be Critical (automation, AI triage, seeded data) | One shared priority list (add Critical, or stop producing it) used by filter, edit modal and sort | Critical tickets findable | S | P1 | `persona_tech.json` |
| B-08 | Manager | Weekly summary / compare technicians | No "overdue by technician this week" or "SLA by client" report; dashboard tiles do not drill down | Add the two reports with CSV; link every dashboard tile to its filtered list | Answers common manager questions | M | P2 | Reports menu |
| B-09 | Technician | Start of day | After login the app opens the Clients list, not the queue or dashboard; dashboard charts default to off | Role-based landing page; default Technical section on for technicians | 2 fewer clicks every day | S | P2 | `persona_tech.json` |
| B-10 | All | First run | "No Results" with a sad face on Expenses, Credentials, Products, SLA policies, Leads, with no next step | Empty states with what it is and a primary "New ..." button; differentiate "nothing yet" from "filtered out" | Better first-run | S | P2 | screenshots in the sweep |
| B-11 | Technician | Log time | Time can only be logged inside a reply/note; the Time Entry panel is a timer | A "Log time" quick action on the ticket | Fewer notes written just to book time | S | P2 | `persona_tech.json` |
| B-12 | Account manager | See one client's health | Client overview lacks contracts, expiring items, spend/revenue and asset health on the first screen | Add contract renewal, 12-month spend, asset warranty/patch status, and a health summary | One-page client review | M | P2 | `shots/` overview |
| B-13 | Billing/Manager | Reuse views | Saved views and a column chooser exist only for tickets | Saved views for Clients, Invoices, Assets, Contacts | Less re-filtering | M | P2 | - |
| B-14 | Admin | Find who changed something | Audit log is dominated by "viewed the Credentials page" rows; no export on `audit_log.php` (only `audit_trail`) | Event-type filter default (changes only), CSV export | Faster investigations | S | P2 | `shots/` audit log |
| B-15 | Billing | Income by client | Title and hard-coded threshold "payments of 600 or more", table empty for small shops | Make the threshold a filter, default 0 | Report usable out of the box | S | P3 | `results/` report pages |
| B-16 | Technician | Bulk work | Tickets have Bulk Action; invoices, quotes, expenses, assets have a select-all box but bulk actions are limited | Bulk status/assign/archive on invoices, quotes, assets | Less repetition | M | P3 | - |
| B-17 | Technician | Ticket filters | Two date controls side by side ("All time" and "Created") with different looks and unclear roles | Merge into one range picker with presets | Less confusion | S | P3 | `after/tickets_1366_light.png` |
| B-18 | All | Page size | The "10" page-size box is a wide unlabelled text-looking input | Labelled select (10/25/50/100) beside "Showing x to y" | Clearer control | S | P3 | tickets/clients sweeps |
| B-19 | Admin | Users | MFA column is a red open padlock with no text or tooltip | Text "Off / App / Passkey" | No guesswork | S | P3 | users sweep |
| B-20 | Admin | Disable a user | Disables immediately, flash is styled as an error | Confirm with a short consequence line; success style | Safer, calmer | S | P3 | `persona_msp.json` |
| B-21 | Sales | New lead | Primary contact is required and sits on the Contact tab | Optional for leads, or move it to Details | Faster capture | S | P3 | `shots/persona/persona_sales_new_lead_modal.png` |
| B-22 | All | Error recovery | A rejected New Client (blank name) closes the modal and loses what was typed | Return to the open modal with a field-level message | No lost work | M | P3 | `persona_msp.json` |
| B-23 | Admin | Large forms | Settings forms are one long column at full width with 11px help text | Two-column grouping with a capped width | Readability | M | P3 | `shots/` ticket settings |
| B-24 | Mobile | Parity | Attachments, KB, bulk actions and the audit log exist on web only or not at all | Check against the Android app in Phase E | Phone users covered | - | P3 | not tested here |

Top 10 by priority: B-01, B-02, B-03, B-04, B-05, B-06, B-07 (all P1), then B-08, B-09, B-10 (P2).

## C. Persona tasks and click counts

Clicks count navigation, buttons, tabs and dropdown choices; typing is listed separately. Every task started from the app's landing page and used only the navigation (no direct URLs). Nothing was emailed.

| Persona | Task | Clicks | Typed | Outcome | Where I hesitated or what was missing |
|---|---|---|---|---|---|
| Technician | Triage queue: unassigned and overdue | 4 | 0 | done | Lands on Clients, not the queue; tiles on Tickets filter correctly |
| Technician | Open ticket, add internal note | 4 | 1 | done | The editor must be clicked before typing |
| Technician | Change status and assign | 8 | 0 | done | Status opens a modal (4 clicks); assign is a plain select (1) |
| Technician | Log 15 minutes | 3 | 2 | done | Only possible with a note (B-11) |
| Technician | Filter my tickets by priority | 5 | 0 | done | No Critical option (B-07) |
| Technician | Find an asset and its history | 1 | 1 | done | Global search is good |
| Technician | Find a contact and phone/email | 1 | 1 | done | tel/mailto links present |
| Technician | KB answer into a reply | 0 | 0 | blocked | No KB path (B-05) |
| Technician | Create a ticket for a user | 4 | 1 | done | Required marks and tab validation are visible |
| Technician | Three tickets at once | 3 | 0 | done | "Bulk Action (3)" appears |
| Admin | Create a user with a role | 7 | 3 | done | Role is required and flagged; first attempt without a role showed a clear message |
| Admin | Disable a user | 5 | 0 | done | No confirmation (B-20) |
| Admin | Audit log | 4 | 0 | done | Under Maintenance, not Settings; nothing on the landing page says so |
| Admin | Run and export a report | 3 | 0 | done | CSV `ar_aging_2026-10-21.csv` downloaded |
| Admin | System health (view only) | 3 | 0 | done | Maintenance page is clear |
| Admin | Statuses, categories, templates, SLAs | 3 | 0 | done | Ticketing page groups them well |
| Billing user | Invoice from a ticket | 6 | 0 | modal reached, not submitted | Unbilled report has no links (B-03) |
| Billing user | Record a payment | 4 | 0 | modal reached | Seeded invoice left untouched |
| Billing user | Chase an overdue invoice | 2 | 0 | partial | No reminder or overdue filter (B-04) |
| Billing user | Aged-debt report | 2 | 0 | done | AR aging with buckets and CSV |
| Account manager | One client's health | 3 | 0 | partial | No contracts/spend/asset health (B-12) |
| Account manager | Monthly client report | 1 | 0 | partial | Does not exist (B-02) |
| Sales | Capture a lead | 6 | 2 | done | Required contact on a hidden tab (B-21) |
| Sales | Opportunity and pipeline | 3 | 1 | done | Works after D-01 |
| Sales | Move and convert a lead | 4 | 0 | partial | Convert exists; status is not shown (B-01) |
| Client contact | Submit a ticket | 3 | 2 | done | No attachment field (B-06) |
| Client contact | Status and reply | 2 | 0 | done | - |
| Client contact | KB article | 0 | 0 | blocked | No KB in the portal |
| Client contact | Update profile | 2 | 0 | partial | Password only |
| Client contact | See invoices | 2 | 0 | done | Empty state is clear |
| Client contact | Find a quote | 2 | 0 | not exercised | No seeded quote; approve/pay not tried |
| Client contact | Assets and documents | 1 | 0 | done | - |

Only the over-5 case: Technician "Change status and assign" (8) and "Filter by priority" (5 plus the missing option).

## D. Not tested, and why

- Android (Phase E): separate session, not started.
- 1680x1050 (not requested in this run's scope; 1536x864 was added instead).
- Real dark mode for all 246 pages after the dark fixes: the full dark sweep ran before them; after the fixes only the 30 key pages were re-run. The portal in dark mode was not run.
- Approving a quote, paying an invoice, sending any invoice/quote/reminder, e-mail templates, Odoo, backups, updates, cron: forbidden by the rules.
- Submitting a payment, converting a lead and archiving were not done on seeded data (only QA records); convert was not submitted.
- Invalid asset IP/MAC through the UI (field on a hidden tab), the Trips form (required destination select), Campaigns and Segments "New" modals (button not found by my selector).
- Phase B harness limits: 141 click timeouts (collapsed `<details>`, stale element indexes after an external navigation). Nine were re-tested by hand and passed; the rest are not claimed as tested.
- The persona "Chase an overdue invoice" status-filter options could not be read from the page, so the overdue claim rests on the absence of "Overdue" and "Remind" in the invoice list.
- I judged visuals from screenshots plus axe; I could not judge animation, print layouts or PDFs.
- `beta` has moved 17 commits since this branch was cut (e-mail templates and others). A trial `git merge-tree` against current `beta` is clean; the fixes were not re-run on that newer code.
