# UI sweep: adminother (admin non-settings pages, user profile, global chrome)

Branch `ui-sweep-adminother`, base `qa-scan-20261102`. Server on 127.0.0.1:8216 (demo DB rivetmsp_demo; the `rivetmsp_scan` DB named in config.php no longer exists, so the demo config was used). Scripts in this folder (crawl.js, modals.js, crud.js, chrome1.js, etc.).

## Coverage (executed and screenshot-reviewed unless noted)
| Area | Visited | Notes |
|---|---|---|
| 79 admin/*.php (excluding settings_*, users, roles, api_keys, oauth_*, post*, webhook*/accounting_*/comet_status which redirect into settings) light 1920 | y | all viewed as 2x2 montages, axe + overflow + console/network audit |
| Same set dark 1920 | y (80 pages incl. profile) | axe + montage review of a sample, others by audit only |
| Phone 390 / laptop 1366 all of the above | audit y (hscroll/clipping/errors); screenshots reviewed for a sample | |
| Maintenance hub, backup, update, audit log, app log, email log, audit trail, debug, server status, cron, job queue, mail queue, credential restore | y view only | no backup/update/cron-run/restore/migration triggered |
| Tags & Categories, category, tag, custom links | y | add (empty + valid), edit modal, delete via confirm |
| Ticketing: statuses, labor types, SLA policies/calendars, holidays, automation, mailbox, mail requests | y | add/empty validation/persist; ticket status edit (set Inactive: no delete exists) |
| Templates (ticket, canned, worksheet, project, contract, document, software, vendor, onboarding, workflow, structured doc types, service catalog) | y | add flow with empty submit and persist, delete |
| Payroll admin (settings, employees, periods, runs, deductions) | y | deductions add/delete; run/period detail with no id |
| ~48 ajax modals opened (Esc, backdrop click, focus, overflow, console) | y | edit modals on a few pages not reachable by my selector (dropdown rows) except those listed above |
| Profile: details, security, preferences, activity, integrations, MFA enforcement | y | password wrong/short, MFA modal, passkey add (no authenticator: modal stays open), details save/persist/restore, preferences save/persist/restore, theme toggle light/dark |
| Chrome: top bar, live search, bell modal, user menu, sidebar links (33 all 200, active state), theme, footer, flash toasts, confirm dialog, 403, not-found pages, login, empty-submit login, phone nav | y | |

## Defects (all fixed unless noted)
| # | Severity | Defect | Status |
|---|---|---|---|
| 1 | High | Admin/permission denial = bare unstyled two lines on white page (no styling, no way back) | Fixed: `denyAccess()` branded 403 page for page loads; fragments for modals/ajax unchanged (functions.php, inc_all_admin.php) |
| 2 | High | `Admin > Modules > New Module` opened an error dialog (modal file does not exist, 404) | Fixed: dead button removed (modules are predefined) |
| 3 | High | Preferences save silently disabled the user's browser-extension key every time (no extension control exists on that form) | Fixed: only when the form posts `extension_form` (agent/user/post/profile.php) |
| 4 | Med | Document template add/edit accepted an empty name (created blank template) | Fixed: `required` |
| 5 | Med | PHP warnings/fatal: missing `id` on workflow/ticket/worksheet template details (SQL fatal, header-after-output warning), contract template undefined SLA/rate keys, `$extended_log_description` undefined on preferences save | Fixed |
| 6 | Med | Notifications modal: "See all Notifications" white-on-white button, unclosed span, BS4 classes, unlabeled pager buttons | Fixed |
| 7 | Med | Modal header close X glued to the title in every modal (float in flex header) | Fixed in css/itflow_design.edition.css |
| 8 | Med | Phone: two stacked hamburgers (Tabler toggler above top bar) | Fixed (hidden while menu closed) |
| 9 | Med | Phone horizontal scroll: long pagination (audit log), Mailboxes header buttons, API docs copy button; 1366 scroll on contract templates | Fixed |
| 10 | Med | Global search: blank page with no query (and phone header icon links there with no box); no "no results" message | Fixed: search box and no-results state |
| 11 | Med | Missing empty states (header only): labor types, mail requests, mailboxes, client workflows | Fixed |
| 12 | Med | Event rules "Recipes" squeezed into a narrow third column (Core `.er-recipes` grid vs edition markup) | Fixed (edition CSS) |
| 13 | Med | Dark mode contrast: outline-primary buttons and Templates "+ New" (3.2:1), yellow badge text, secondary badge | Fixed (edition CSS) |
| 14 | Low | Light contrast: `badge bg-success` white text (2.7:1); backup page orange warning text | Fixed |
| 15 | Low | Compliance status pill wrapped to a circle ("Needs attentio n") | Fixed |
| 16 | Low | Canned response preview ran words together ("Hello,Your") | Fixed |
| 17 | Low | MFA enforcement gate still branded "ITFlow" | Fixed (APP_NAME logo) |
| 18 | Low | Login: stray empty divider under Sign In when no SSO/forgot-password | Fixed |
| 19 | Low | Active sessions showed truncated raw user agent; Revoke token buttons heavy red and "x" glued to label | Fixed (browser/OS + outline buttons, spacing) |
| 20 | Low | Sidebar Dashboard not active on my_dashboard.php; truncated "Onboarding & Offboard..." had no tooltip | Fixed |
| 21 | Low | inline `code` text 10.5px | Fixed (min 11.5px) |
| - | Low | Compliance report (printable, Core ReportRenderer in vendor/) overflows at 390px; not editable here | Unfixed |
| - | Low | Status badge colours chosen by users (e.g. #007bff "Open") are 3.8:1 with either text colour | Unfixed (data) |
| - | Low | 11.5px table headers/badges are design-system tokens, left as is | Unfixed |
| - | Info | Unknown URLs redirect to the clients page under the demo router (router.php behaviour; real nginx 404 not testable here) | Not tested |

## Proposals (not built)
- Confirm dialog says only "Are you sure?"; include the record name and consequence.
- Row action styles differ between admin lists (ellipsis dropdown vs Edit/Delete buttons vs icon buttons); pick one.
- Custom ticket statuses cannot be deleted/archived (only set inactive).
- Admin card hubs (Maintenance/Templates/Ticketing) use fixed ~157px cards leaving a large empty right side on wide screens.
- TinyMCE editors stay white in dark mode.
- Notification count badge grows without bound ("10"); consider "9+".

## Not tested and why
Backup/update/restore/cron "Run now"/Check Odoo/test push (external or destructive); passkey registration (no authenticator); 2FA code entry (no MFA user); recovery codes; forgot-password flow (needs SMTP, link hidden on this install); user/role/API key/RMM pages (other agents); webhook/accounting/comet/settings pages (redirect to settings family); holiday add and ticket automation rule save (add control inside a dropdown); edit modals for rows behind dropdown menus on several pages; full dark-mode screenshot review of every page (audited by script, sample reviewed); TomSelect/date pickers/TinyMCE only seen inside modals opened here.
Test records were named QA-UI-ADM-*; all deleted/archived (ticket status set Inactive). Shared demo DB also contained other agents' QA-UI records.
