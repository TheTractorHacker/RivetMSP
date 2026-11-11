# UI sweep report: agentcore (agent area)

Branch `ui-sweep-agentcore`, server 127.0.0.1:8212, DB rivetmsp_demo (the copied config pointed at the non-existent rivetmsp_scan; demo credentials used in the worktree copy only). Harness: `tests/qa/ui-sweep/*.js` (lib, pages, modals, axe, probe, flow scripts t_*.js).
Note: admin alex's dark-mode preference was seen flipping during the run (shared demo DB), so some "light" shots were dark. Dark checks via injected `data-bs-theme=dark` give false positives on server-rendered inline styles; those results were not relied on.

## Coverage (executed = screenshot taken and looked at, unless noted "audit only")
| Page / area | 1920 light | 1920 dark | 1366 | 390 | Technician (priya) | Controls exercised / result |
|---|---|---|---|---|---|---|
| my_dashboard / dashboard / index | y (viewed) | y (viewed) | audit | audit | audit+axe | loads; redirects ok; no errors |
| tickets.php (list) | y | y | audit | y (viewed) | audit+axe | filters bar, search no-result empty state, saved view add (empty + valid), bulk select -> bulk bar, 14 modals opened (bulk assign/priority/category/email/reply/merge/resolve/project, export, saved view add/edit, new ticket, billable) |
| ticket_list / ticket_kanban (includes) | y | - | audit | audit | - | direct URL guarded; kanban view y |
| ticket.php | y | audit | audit | y (viewed) | audit+axe | internal note post, public note empty submit, priority modal save, canned reply insert, task add, attachment upload, 23 modals opened (edit, merge, contact, asset, vendor, watcher, change client, status, tags, priority, delivery, billable, redact, reply edit, schedule, worksheet, charge, link), 7 dropdown menus. NOT done: public reply send, merge/resolve/delete confirm, schedule save, tags save, time-entry timer, kanban drag |
| recurring_tickets | y | audit | audit | audit | audit | add modal empty -> tab validation, valid create persisted |
| problems / problem_details, changes / change_details | y | audit | audit | audit | audit | add modal empty + valid, detail page after create loads (axe clean) |
| mail_requests, alerts, notifications, global_search | y | audit | audit | audit | audit | load only; modals audit-only (notifications modal opened) |
| calendar | y (viewed) | y (viewed) | audit | audit | audit | no event add/edit tested |
| clients, client_overview | y | y | audit | y (viewed) | audit | add client (empty/no contact/valid), overview page viewed; overview has no tabs, sidebar links only |
| contacts / contact_details, locations | y | y | audit | audit | audit | add contact (empty, bad email, valid), add location (empty, valid); edit modals opened; contact_details visited audit-only |
| leads_board, pipeline, opportunities | y | y | audit | audit | audit | add lead (validation then valid), add opportunity (empty, valid); no drag/convert tested |
| campaigns, segments | y | audit | audit | audit | audit | save segment, save campaign draft (empty + valid) |
| workflow_runs / workflow_run | y | audit | audit | audit | audit | start modal opened only |

## Defects found
| # | Severity | Defect | Status |
|---|---|---|---|
| 1 | High | Priority modal save ended on a blank post.php page (no redirect/flash); a stray "Priority updated" flash sat unreachable in the delivery-method handler | fixed (agent/post/ticket.php) |
| 2 | High | New Ticket / any tabbed modal: required field on a hidden tab blocked submit silently ("invalid form control not focusable") | fixed (js/ajax_modal.js switches to the tab of the first invalid field) |
| 3 | High | Ticket list Created/Last response columns wrapped per character at 1920 (rows 143px), table scrolled sideways inside its card, tight-table padding rule never applied | fixed (agent/tickets.php, ticket_list.php) |
| 4 | High | Location edit modal read a non-existent column (`location_extension`), so the extension field was always empty and saving wiped it (+ PHP warning) | fixed |
| 5 | Med | JS TypeErrors "reading 'vendors'/'assets'" in New Ticket modal: ajax.php lookups returned JSON `null` when empty (6 endpoints) | fixed (agent/ajax.php) |
| 6 | Med | Sideways page scroll at 390px on ticket kanban, pipeline (min-width 400px) and calendar toolbar | fixed |
| 7 | Med | `toastr.danger is not a function` for any flash_alert(..., 'danger') | fixed (includes/inc_alert_feedback.php) |
| 8 | Med | Empty-reply submit on a ticket wrote PHP warnings and logged a bogus reply | fixed |
| 9 | Med | Campaigns and Segments lists had no empty state (blank card) | fixed |
| 10 | Med | Contrast (AA): status pills on mid-tone colours (tagTextClass used #f9fafb; "Open" #007bff 3.97), badge-secondary in dark, warning text in light, lead convert zone, expiring heading, calendar day numbers/Subscribe button in dark, dark outline buttons | fixed (functions.php tagTextClass/tagSafeBg, css/itflow_design.edition.css, leads_board.css, client_overview.php) |
| 11 | Low | "New Ticket (v2)/(v1)" dev labels in modal title | fixed |
| 12 | Low | New Lead modal footer said Close, others Cancel; mobile/lead-source placeholders clipped | fixed |
| 13 | Low | Lead create said "Client ... created" | fixed |
| 14 | Low | Empty category pill on kanban cards; 11px text (ticket stat labels, notification badge); billable toggle and calendar chevrons unnamed; activity scroller not keyboard focusable | fixed |
| 15 | Low | ticket_list.php / ticket_kanban.php direct hit gave a raw 500 / unguarded include | fixed for ticket_list (guard); kanban already guarded |

## Unfixed / observations
- Global search renders credential passwords into the HTML (popover `data-content`, copy button) for anyone with the credentials module, bypassing the audited `credential_reveal.php` flow used on the credentials page; eye button also uses BS4 `data-content` so it likely does nothing. Security/product decision: unfixed.
- Ticket Views sidebar highlights several views at once (Default, All Unresolved, a saved copy) when their filters are identical; filter card has ~40px of empty space under the buttons; New Ticket split-button loses its caret in the no-results state.
- Live "new activity from QA-UI Tech" banner appeared on a fresh ticket: Redis pub/sub channel appears keyed by ticket id only, so other demo instances sharing the Redis collide. Environment artefact, single prod instance fine.
- Existing QA data: client 9 has a blank name (renders empty links, axe link-name), client 8/10 names contain a literal `&amp;`. Not mine; left.
- FullCalendar "+N more" link has empty aria-controls (library). Empty watcher submit says "Added watcher(s)".
- Dark-mode full verification not trustworthy (see note).

## Proposals (product decisions, not built)
- Make priority/status pills real buttons (keyboard accessible dropdowns; they are spans).
- Offer one shared "empty state" partial for list cards (campaigns/segments used ad-hoc text).
- Add a reveal-audited path to global search credentials.
- Consider hiding always-empty Board/CSAT/Tags columns in the ticket list to save width.

## Not tested and why
Public reply (would queue email), merge/resolve/delete/archive confirms and schedule/tag/watcher saves, time entry timer, kanban/leads/pipeline drag and drop, lead convert, campaign send, calendar event CRUD, workflow run start, mail request actions, bulk actions beyond opening modals, contact_details and problem/change detail actions beyond load, global search interactions, 1366 and 390 screenshots (audit script only, except those marked viewed), real server-side dark mode.
