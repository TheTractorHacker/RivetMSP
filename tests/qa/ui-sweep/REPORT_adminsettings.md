# UI sweep report: Administration, settings side (AREA=adminsettings, port 8215)

Branch `ui-sweep-adminsettings` (from `qa-scan-20261102`). Demo DB `rivetmsp_demo`. Scripts in this folder (crawl.js, modals.js, saves.js, edit.js, crud*.js, nav.js, axe.js).
Note: the shared demo admin account's light/dark preference was flipped by other sessions during the run, so pages were captured in whichever theme was active; both themes were covered for the contrast pass (axe) and for the key pages.

## Coverage (executed, screenshot looked at unless marked "metrics only")
| Page / modal | Visited | What was done | Result |
|---|---|---|---|
| admin/settings.php directory | y | all 4 anchors, every card link resolves, light/dark/phone | OK (fixed footer gap) |
| company, localization, theme, appearance, portal branding, defaults, modules | y | view, save unchanged (flash ok), company empty name / bad email validation, theme radios | fixed theme grid, breadcrumb, label alignment |
| ticketing, projects, invoices, quotes, payroll, KB | y | view, save unchanged; ticket prefix edit, reload persists, restored to TCK-; negative number validation | OK |
| accounting (+ client/item mapping, sync status tabs) | y | view; no connect/test | fixed selects, card borders |
| security, compliance, compliance status | y | view, save compliance unchanged; no key rewrap/dry run clicked | fixed status badges, contrast |
| event rules, job queue | y | view, recipes; did not create rules or process jobs | fixed recipes layout |
| mail, email templates, notifications | y | view, notifications save unchanged; no test mail, no OAuth connect | fixed labels |
| identity provider, integrations (RMM tab), endpoint agent, MeshCentral wizard, calendar sync, webhooks (+guides, new), AI, Remote MCP, telemetry | y | view only (no connect/test/enroll) | fixed tab dots, switches, AI Save |
| Integrations tabs Backups/Firewalls/UniFi/Pax8 | metrics only | not screenshot-reviewed | see not tested |
| API docs | y | desktop + phone | fixed rate limit text, phone overflow |
| Users list + New/Edit (Details, Security, Access tabs)/Skills/Archive modals | y | create QA-UI user, edit, archive; empty and short-password validation | fixed labels |
| Roles list + New role (Details/Permissions) | y | created QA-UI-Role x3 (repeated runs), archived all; empty submit | fixed silent block |
| API keys + New key modal | y | create QA-UI-Key, revoke, bulk delete | fixed silent block, header checkbox |
| Sidebar: all 13 links, active state, Billing collapse, sidebar toggle | y | nav.js | OK on desktop |
| Sub pages (tags, categories, custom links, AI providers, ticket status, SLA, holidays, automation, labor, canned, mail queue, logs, mailbox, taxes, payment methods/providers) | metrics + several screenshots | axe/overflow/errors only for most | no defects found by metrics |
| 1366x768 and 390x844 | y (all listed pages, metrics) | horizontal scroll, errors | only api_docs overflowed (fixed) |

## Defects (severity, status)
1. High: API key modal "Create" silently did nothing (required "I have made a copy" checkbox sits on the hidden Keys tab). Fixed globally in js/app.js: the tab holding the invalid field is shown. Same fix covers New Role (Name/Description on hidden Details tab).
2. Medium: Theme page radios detached from swatches, duplicate "yellow" option with duplicate id. Fixed (settings_theme.php).
3. Medium: Event rules recipes cards collapsed into one narrow column (legacy `.er-recipes` grid rule). Fixed.
4. Medium: Compliance status pill badges wrapped into 3 lines ("Needs attention", "Not applicable", "Never reviewed"). Fixed.
5. Medium: API docs said "300 requests/60s" while API Keys setting is 120 (configurable). Now reads the setting.
6. Medium: legacy modal close buttons rendered next to the title (role/tag/doc-template/worksheet/etc. modals). Fixed in css, aria-label added in admin modals.
7. Low: Portal Branding had no "All settings" breadcrumb; logo upload labels centered; footer glued to card; Accounting selects without chevron and one-off green card borders; Identity provider used checkboxes while the rest use switches; AI page Save right-aligned and italic intro; Telemetry id centered; KB header button inset; Compliance "In effect now" table misaligned; endpoint agent tab "dots" were stretched pills; API keys header checkbox raised; "New Key" renamed "New API Key"; users "IR" menu item relabeled "IR: reset all passwords"; unlabeled split-dropdown/ellipsis/switch/readonly inputs got accessible names; webhooks/guides tab lists wrongly had role=tablist.
8. Low (a11y): white-on-green success badges, dark-mode outline-primary text, dark secondary/warning badges, info-alert links, and in-text links below contrast. Fixed in css/itflow_design.edition.css.
9. Low: API docs and phone: <12px text and a copy button overflowing 7px. Fixed. Tables inside `.table-responsive-sm` wrapped emails letter by letter on phone; now nowrap and scroll.

## Files changed
admin/{settings_theme,settings_company,settings_portal_branding,settings_accounting,settings_mail,settings_notification,settings_ai,settings_kb,settings_compliance,settings_telemetry,settings_webhooks,webhook_guides,compliance_status,identity_provider,api_docs,api_keys,users}.php, admin/includes/inc_all_admin.php, admin/modals/api/api_key_add.php, 12 admin modals (aria-label on close), includes/footer.php (1 class), js/app.js (append), css/itflow_design.edition.css (appended rules), tests/qa/ui-sweep/*.

## Proposals (not built, product decisions)
- Modal headers are mixed: many use the dark `bg-dark` header, users add/edit use light. Pick one.
- Phone shell shows two hamburger icons (top navbar toggle and the toolbar one) in the admin area.
- Destructive one-click actions without confirm: users Disable/Activate, API key bulk Delete, API key Revoke (GET links / immediate). Consider confirm dialogs.
- Theme page and Appearance page overlap (two accent pickers); consider merging.
- Admin footer says "Upstream Docs / Forum / Services" (itflow.org) in the RivetMSP product.
- vendor/rivet/rivet-core webhook catalog text says "standard RivetIT envelope" (Core string, not editable here).
- Client id 9 in the demo DB has an empty name (created by an earlier test); the Add Client form should reject empty names (other area).
- Flash wording is inconsistent ("edited", "updated", "saved").
- Per-page selector in list footers is ~500px wide (shared pagination partial).

## Not tested and why
- Send test email, Check/Connect Microsoft/QuickBooks/Xero/MeshCentral/Odoo/UniFi/Pax8, backups, updates, rewrap, enrollment: forbidden/external.
- Integrations Backups/Firewalls/UniFi/Pax8 tab forms, mail/accounting/identity/webhook saves: external credentials; viewed via metrics only.
- Webhook create/edit wizard steps, event rule create, mailbox edit, ticket automation, SLA, holidays, payment provider modals: other-area or only metrics.
- Dark-mode pixel review of every page (axe + several screenshots only); 1366x768 and phone: metrics for all, screenshots for directory/users only.
- File upload inputs (logo/favicon/avatar) were not submitted.
