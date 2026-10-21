# Changelog

This file documents all notable changes made to ITFlow.

## [Unreleased]

### RivetMSP — Email Templates: admin-editable ticket and client portal emails, in a branded frame (DB 2.6.107) *(placeholder entry; version/date set at release)*

**Deploy notes:** back up the database, pull, run **Administration > Updates** (DB 2.6.106 to 2.6.107: one new table `mail_templates` - `CREATE TABLE IF NOT EXISTS`, one row per email event seeded with its default text - and two nullable `settings` columns, `config_mail_template_header_html` / `config_mail_template_footer_html`, `ADD COLUMN IF NOT EXISTS`; nothing existing changed, safe to re-run). Until the step has run, every email still goes out with its default text. No RivetCore change, no new agent, no nginx change. **What changes on its own after the update:** every ticket/portal email below now arrives inside a simple branded frame (light background, white card, a thin stripe in the company's theme colour, the company logo - or its name - on top) and the "-----" fence around a ticket's details or a reply is a shaded quote box; the wording is otherwise what each email already said (see "converged" below).

- **Admin > Settings > Email templates** (ported from RivetIT's Email Templates, same architecture): one card per email - ticket created, reply, resolved, closed, assigned (to the technician), client portal password reset and its confirmation - each with an on/off switch, the subject, a drag-and-drop body builder (text, quote box, button, divider, spacer, reply marker, advanced HTML), a live preview beside it and **Send test email to me**; **Reset to default**; an optional shared header (replaces the logo/name) and footer. Placeholders use the event-rule merge-field syntax, `{contact_name}`; an unknown one is left blank. A switched-off email is simply not sent (the ticket action still happens).
- **One renderer for all 17 call sites** (`renderMailTemplate()` in `includes/mail_templates.php`): `agent/post/ticket.php` (create, edit and contact-change notify, assign, reply, resolving reply, resolve, close, bulk resolve, bulk reply), `agent/ajax.php` (kanban to Resolved), `agent/post/recurring_ticket.php` (both "create now" paths), `cron/cron.php` (scheduled recurring tickets), `functions.php` `addTicket()` (the inbound-email auto-reply) and `client/login_reset.php`. Values are HTML-escaped except the ticket details/reply and the satisfaction-survey links (HTML the app builds itself). The theme colour, logo and company name are read when each email is sent, so a theme change reaches every email, saved templates included.
- **Converged copy/paste drift** (the old copies of one email differed slightly; each now sends the version most call sites used): the create email's subject is `Ticket Created - [T123] - ...` everywhere (it was `Ticket Created [T123]` from the New ticket form, `Ticket created - ...` from the email parser, and `... (scheduled)` from recurring tickets); recurring and email-parser create emails say "has been created for you" (not "automatically created" / "Thank you for your email.") and link "View ticket" instead of printing the portal address; the bulk-resolve email quotes its text in the box, says "Status:" and links the guest view like the other resolve emails.
- **Fixed on the way:** the stray backslashes every old email carried (`style=\'...\'`, `didn\'t`, and before every quote in the email-parser auto-reply); the bulk-reply email's empty "Status:" line; blank company name/phone in recurring "create now" emails; scheduled (cron) recurring tickets had no guest-link key, so their emails' links could not open; bulk resolve / bulk reply / kanban could re-queue the previous ticket's emails when a later ticket's contact had no valid address. Watchers get the same email with the collaborator notice inside the frame.
- **Reply marker:** the "reply above this line" marker now leads every created/reply/resolved email, above the frame, so a customer's parsed reply never picks up the header. The editor warns (in-app dialog, never a browser `confirm()`) before saving a reply-capable email without the marker (or with it no longer first) or a portal email without `{portal_link}`; it is saved anyway if you confirm. Closed ("do not reply") and assigned emails carry no marker.
- **Not changed:** the mail queue, its retry logic and the Mail queue viewer. Not covered by templates (still hardcoded): the collaborator-added notice, task approval requests, auto-close and survey-reminder emails, invoice emails.
- **Tests:** new `tests/mail_templates.php` (192, scratch DB: byte-identity of every default against the old hardcoded strings, verbatim from git, at the renderer and at every real call site; escaping; enabled/disabled; edit/reset; branding; the reply marker through the real parser; the DB step), `tests/mail_template_compile.php` (39, the browser and server compilers byte-identical, node vs php) and `tests/browser/mail_templates_smoke.mjs` (9 real-Chrome checks). `tests/rmm_alert_ticket_defaults.php` (131) still passes; `tests/notification_preferences.php` and `tests/mail_intake_db.php` had an assertion updated (a hardcoded latest DB version; the auto-reply subject's capital C).

## [26.10.41] RivetMSP — RivetCore 1.0.0-rc.30: per-OS agent binaries and releases (no new DB version; DB step 2.6.103 gains migration 0037)

**Deploy notes:** back up the database, pull this tag (tracked `vendor/rivet/rivet-core` is the v1.0.0-rc.30 git archive, verified byte-identical against a fresh `composer update rivet/rivet-core`), run **Administration > Updates**. The edition DB version stays **2.6.104**: Core migration 0037 (`os` varchar(10) NOT NULL DEFAULT 'windows' on `endpoint_agent_binaries` and `endpoint_agent_releases`, unique keys widened to (version, arch, os) / (version, ring, arch, os); guarded and idempotent, no backfill since every existing row is a Windows one) runs inside the existing **2.6.103** step, as Core's adoption notes ask. **A database that already ran 2.6.103 with the rc.29 package will NOT run 0037 by itself** (it is past that step); none is known to have been (the `beta` branch is still at 2.6.102), but if one was, run Core's migration runner once (or the next DB step that calls it) before publishing a Linux binary. Until 0037 has run, Core keeps the Windows-only behaviour and refuses to publish a Linux binary, in words. Agents: nothing to roll out; an rc.30 agent adds `os=` to its update request, older agents are served for the OS stored on their device row.

- **Fixed: a Linux device could be offered, and download, the Windows .exe of the same version** as its self-update (Core keyed binaries and releases on version + architecture only). Every binary and release now belongs to one OS; a device is offered and served only its own, an `os=` that disagrees with the device's stored OS gets nothing, and a stored file that is not its row's platform is never streamed (deactivated with its releases, audited, shown as **Wrong platform** on the Agent binaries list).
- **Linux self-update now exists in this edition.** RivetMSP's hosted Linux files (DB 2.6.104) were only ever used for installing and never created a release, so an enrolled Linux device had no update path of its own (before rc.30 it was offered the Windows build). Every path that hosts a Linux agent program (build host, Agent updates > Publish, the manual Linux upload) now also publishes it into Core's agent binaries with os=linux through `RmmAdmin::uploadBinary()`, offered to the ring chosen there; Core checks the ELF, the CPU and that the program contains its version. The hosted-files table and `api/v1/agent_linux_file.php` stay for the Add device install command (pre-enrollment, token-derived credential, install-linux.sh and tarballs). A program hosted earlier gets an **Add to agent binaries** button on the Linux files card.
- **Platform shown and chosen:** Agent binaries and Agent updates and rings lists show Windows/Linux next to the architecture; the binary upload form, the manual release form and `scripts/endpoint_agent_publish.php --os` take a platform; "Make current" is Windows only. Agent updates card ring badges name Linux offers.
- **Not applicable here:** the addendum's `devpve` recovery (`clearUpdateFailures()` for a real Proxmox host on mwa) is RivetIT-specific; RivetMSP has no such device. Publishing a release now clears earlier update failures of that version for devices of that OS by itself (Core).
- **Not verified on real hardware:** fixture PE/ELF files over loopback HTTP only; no real Linux, Proxmox or Hyper-V host and no rc.30 agent has been run against this.
- **Tests:** new `tests/rmm_agent_os.php` (93: schema keys, upload/release keyed on os, the Linux-vs-Windows offer and download regression, old-agent fallback to the stored os, platform_ok and the serve-time check, the edition's Linux-to-Core paths, admin cards, CLI; a mutation of Core's os filter fails 18 of its checks); `tests/rmm_agent_localbuild.php` (285), `tests/rmm_agent_feed.php` (277), `tests/rmm_linux_hosting.php` (68), plus `tests/rmm_installer_ui.php` (136), `tests/endpoint_agent_smoke.php` (47), `tests/rmm_mesh_wizard.php` (70), `tests/rmm_device_type.php` (15), `tests/rmm_rc25.php` (75). `ea_reset()` now also clears hosted Linux files (a pre-existing cross-file leak that failed 6 checks of `tests/rmm_agent_feed.php` when it ran after `tests/rmm_linux_hosting.php`).

## [26.10.41] RivetMSP — RMM tickets are Maintenance with no SLA and no email by default; per-ticket SLA and email switches (DB 2.6.105)

**Deploy notes:** back up the database, pull this tag, run **Administration > Updates** (DB 2.6.104 to 2.6.105: four additive `tickets` columns - `ticket_rmm_sourced` default 0, `ticket_counts_sla` default 1, `ticket_send_email` default 1, `ticket_sla_started_at` NULL - and two `settings` columns - `config_rmm_alert_ticket_sla`, `config_rmm_alert_ticket_email`, both 0; `ADD COLUMN IF NOT EXISTS`, safe to re-run, no row changed). Every existing ticket keeps its SLA and its email exactly as before. **What changes on its own after the update:** NEW tickets made from RMM alerts (all four paths: auto-ticketing, the "Create ticket" button, automation rules, endpoint escalation) are labelled RMM tickets, get no SLA and send no email unless an administrator turns those defaults on. To make them land in the Maintenance category, use the existing one-click button on the Alert tickets card (Administration > Integrations > RMM). Existing RMM tickets are only relabelled if an administrator runs the backfill on the same card (dry run first).

- **RMM tickets: Maintenance, no SLA by default.** `createTicketFromRmmAlert()` (the single function all four alert-to-ticket paths use) always sets `ticket_rmm_sourced = 1` and seeds `ticket_counts_sla` / `ticket_send_email` from two new switches on the Alert tickets card, "RMM tickets count toward SLA" and "Send email for RMM tickets" (both off). The settings are read at creation only. With the SLA switch on, a new RMM ticket now gets its SLA due dates immediately (it never called the SLA engine before). The card's existing "Create a Maintenance category and use it" button now also offers "Use the Maintenance category" when one exists but is not chosen.
- **Per-ticket "Count toward SLA" switch** on the ticket header, next to the status (technicians with support edit rights, the same level as status and priority; client access enforced). Turning it on asks (a new `RivetDialog.choice`) whether the SLA starts now or from ticket creation; "now" is stored in `ticket_sla_started_at` so later recalculations keep it. Turning it off clears the due dates. A ticket that does not count toward SLA has no due dates: it is never at risk or breached on any list, badge, dashboard or automation condition, and is left out of the Service Desk report's SLA numbers. This beats the "pauses the SLA clock" statuses: a pause can never bring SLA back; turning SLA on while a ticket waits on someone starts paused. Both directions are in the ticket history and the audit log.
- **Per-ticket "Email notifications" switch** next to it. Off = no outbound email about the ticket at all: new ticket, replies, status changes, resolve/close, assignment (including the bulk-assign summary when every ticket in it is off), watchers, schedule and cancellation, task approvals, the outtake signing link, auto-close and the satisfaction survey and its reminder. Internal notes, in-app notifications and webhooks are unaffected. Enforced in ONE place, `addToMailQueue()`; every ticket mail call site now passes its ticket id (six never identified their ticket before; the outtake link wrote the queue table directly and now uses `addToMailQueue()`). Turning it off cancels the ticket's mail that is queued or waiting for a retry. A ticket created from an inbound email always sends email: its switch is locked on. Reply form: a banner when email is off, with "Email this one reply anyway". Ticket list: a "No email" marker and an "Email Notifications" bulk action. Audited like the SLA switch. RMM escalation emails (on-call notices about the alert, sent to the escalation policy's recipients) are deliberately NOT tied to the ticket's switch.
- **Existing tickets from alerts** (Alert tickets card): live count, Preview (dry run) and Apply. Labels tickets an RMM alert links to, or created as "RMM Alert" / "RMM Automation" / "RMM Escalation", and turns their email off. Label only: no SLA field is touched. Inbound-email tickets are never touched. Not "Automation: ..." tickets without an alert link (event-bus rules write that source for non-RMM tickets).
- **Service Desk report**: a "Maintenance (RMM) tickets: Include / Exclude / Only these" filter (also `?maintenance=` on `GET /api/v1/reports/service-desk`). Default Include keeps every number as before; RMM no-SLA tickets stay in the volume numbers.
- **Tests:** new `tests/rmm_alert_ticket_defaults.php` (131 checks, scratch DB, mutation-checked: disabling the email gate fails 22 of them) and `tests/browser/ticket_flags_smoke.mjs` (6 real-Chrome checks of the switches and `RivetDialog.choice`, native dialogs trapped). `tests/rmm_alert_category.php` (78), `tests/sla_pause.php`, `tests/mail_intake_db.php` (90), `tests/endpoint_agent_smoke.php` (47), `tests/entity_links.php` (177), `tests/no_native_dialogs.php`, and the full `tests/rmm_ui.php` on a fresh scratch database (2251/2251) pass.

## [26.10.41] RivetMSP — In-app notification preferences, daily update check (DB 2.6.106)

**Deploy notes:** back up the database, pull this tag, run **Administration > Updates** (DB 2.6.105 to 2.6.106: two nullable columns, `user_settings.user_config_inapp_muted_types` and `settings.config_update_check_last_at`, `ADD COLUMN IF NOT EXISTS`, nothing existing changed, safe to re-run). Nothing is muted for anyone after the update. Until the step is applied, notifications keep arriving as before and the background update check pauses. No RivetCore change, no new agent, no nginx change.

- **Choose which notifications you get.** Account > Preferences has a new **In-app Notifications** card with one switch per category: Tickets, CRM follow-ups, Invoices & expenses, Quotes, Expirations & reviews, Backups, Backup & sync health, Mail problems, RMM housekeeping, Integrations, Shared links viewed, Update available, and Scheduled tasks ran ("Cron successfully executed"). A category you switch off is not created for you at all, so it does not show under the bell, in the mobile app's list, on the live stream or as a push. Other users are not affected. **Security** (master key retrieved, credential reveal limit) and **RMM alert escalations** are always on. Notification types that do not belong to any category are always delivered, and a new test fails when the code adds a type without a category.
- **The update check runs once a day.** `cron/cron.php` used to check for a new RivetMSP release (a `git fetch`) on every run, every 5 minutes on a standard install, and while the server was behind it sent every technician a new "Update" notification each time. It now checks once a day, on the first cron run at or after **03:00 in the app's time zone** (the same overnight window as the 02:30 backup and the 03:30/03:45 restore drill). If 03:00 is missed, it checks on the next run that day. The first cron run after this update checks once straight away. Administration > Update still checks every time you open it.
- **Tests:** `tests/notification_preferences.php` (91).

## [26.10.41] RivetMSP — Administration > Update redesigned (look and layout only, no database change)

**Deploy notes:** pull this tag; no database update, no RivetCore change, no new setting. The update mechanism is untouched: the same buttons go to the same `post.php?update` / `?update_db` / `save_release_channel` handlers with the same fields and CSRF token, decided by the same conditions as before.

- **Status first.** The page now opens with one status card: "Up to date", "Update available", "Database update needed", "Database is newer than this code" or "Could not check for updates", with a coloured edge, icon and word (never colour alone), and four tiles: running version, latest on the release channel, database version (with "Needs x" / "Matches the code" / "Newer than code"), RivetCore. The old page buried this at the bottom and said "You are up to date!" even when the database was newer than the code or the update check could not reach the update source; it now says what is actually known.
- **Update buttons** sit in that card under a compact backup warning (same wording and links) instead of a full-width DANGER block and oversized buttons. The list of waiting changes is its own "What this update installs" card (description, commit, when), left aligned and scrollable on a phone.
- **While an update runs** the page shows an "Updating the app / database" panel with a running timer and the steps the server goes through, and the update buttons are disabled so they cannot be clicked twice from the page. **Afterwards** the result message is kept on the page as a green or red banner instead of only a toast that fades after a few seconds.
- **Update history** card: the last 10 app updates, database updates and channel changes from the activity log (who and when; the log does not record version numbers), with a link to the audit log.
- **Checks:** status pills were unreadable in the light theme (coloured text on the same colour); they now use the same readable pills as the rest of the app. Problems and warnings stay listed; passed checks fold into "N checks passed". Long paths wrap on a phone instead of running off the card. The database check now says "Press Update Database at the top of this page".
- **Release channel** card restyled (the selected channel is outlined; "This server" badge readable), same form and wording.
- Checked in light and dark theme and at 390 px phone width (no sideways scrolling).

## [26.10.40] RivetMSP — RivetCore 1.0.0-rc.29: hypervisor visibility, device type, install-apps-with-agent, hosted Linux installers (DB 2.6.104)

**Deploy notes:** back up the database, pull this tag (tracked `vendor/rivet/rivet-core` is the v1.0.0-rc.29 git archive, re-exported and verified byte-identical against a fresh `composer update rivet/rivet-core`), run **Administration > Updates** (DB 2.6.102 to 2.6.103: one edition step runs RivetCore's own migration runner for all four pending Core migrations at once - 0033 install-apps-with-the-agent, 0034 Mesh-after-approval, 0035 device type, 0036 hypervisors - CREATE TABLE IF NOT EXISTS / guarded ADD COLUMN only, nothing existing altered, safe to re-run; then 2.6.103 to 2.6.104: one new edition-only table `endpoint_agent_linux_files` for hosted Linux installers, also additive). Nothing new is switched on by the update: hypervisor visibility, auto-install and Mesh-after-approval all stay off/empty until an administrator turns them on or a technician ticks a box; device type defaults to "Detect automatically" everywhere. Agents: the existing agent keeps working unchanged; hosting Linux files or running the Core migrations needs no new agent build.

- **Hypervisor visibility** (Proxmox VE and Hyper-V only; switch `hypervisor`, off by default). A hosts page (search, platform, client and "problems only" filters, fleet summary tiles), a host detail page (storage table, VM table with its own search/state/type/missing filters, cluster/quorum display), a VM detail page (facts, open alerts, state history, "Ignore this VM"), ignore/resume for both VM and host, and a settings card with all ten thresholds and six per-class alert switches. Abilities `rmm.hypervisor.view` / `rmm.hypervisor.manage` (view gated the same way as device/software view - `module_rmm` level 1 and 2 respectively); the six hypervisor events forward through the existing event catalog with no changes needed. **Never run against a real Proxmox VE or Hyper-V host** - every test is fixture data; say so plainly to anyone enabling it. The VM-to-asset "suggest a link" helper (`HypervisorLinker::suggest()`, suggest-only, never auto-link) was left out of this pass given time, not attempted half-done.
- **Device type** (migration 0035). The installer/Add device dialog gets a "Detect automatically / Laptop / Desktop / Server / Virtual machine / Hypervisor" dropdown (last argument of the installer command/download call); it is stored on the enrollment token, never in the installer file, and only used when a brand-new asset is created for a device that enrolls with it - an existing asset's type is never changed. The pending-approval screen shows the installer's choice or the agent's own hardware-detected type next to "Create a new asset". The token list shows the chosen type. Classification itself (laptop vs. desktop vs. server vs. VM) is RivetCore's own `DeviceTypeClassifier`; none of it has run against real hardware of every kind yet.
- **Install apps when the agent installs** (migration 0033). A software-catalog package can be marked auto-install (off / every new device / chosen clients / one OS), with an install order, a required flag and sealed vendor parameters; a technician can also tick specific catalog apps right in the Add device dialog and the Quick download dialog (checkboxes now actually render and re-fetch live as the client/location/OS change - the dialogs only posted `app_ids[]` by hand before this release). The device page shows "Apps installed with the agent" (queued/installing/installed/failed/skipped, with why). Hosted installer files (upload a vendor's .exe/.msi directly) and four starter templates (huntress_agent, custom_exe and two more - all marked UNVERIFIED, carry no binary/address/key).
- **Mesh agent after approval** (migration 0034, RivetCore's own rc.27 architecture change, not new here). The installer's "Also install the MeshCentral agent" tick-box no longer embeds the Mesh server address, group id or pin in the installer payload or command; it only queues the push for once an administrator approves the device. Wording updated on the dialog and the device page to match.
- **Hosted Linux agent files** (ported from RivetIT's own feature, edition conventions kept separate - e.g. a different HMAC domain-separation string so credentials never cross-shape between editions): this server can host `install-linux.sh` and the Linux agent (amd64/arm64) itself, from a local build, from GitHub Publish ingestion, or a manual upload card. When hosted, the Add device page's Linux command downloads and verifies everything from this server with a derived, per-file, time-limited credential and needs no tarball unpacked by hand; falls back to the existing GitHub-release-link commands, then the original tarball-expected form, when nothing is hosted yet. New route `api/v1/agent_linux_file.php` (pre-auth, `X-Install-Key` / `Authorization: Bearer`, uniform 401/404/429/503/500).
- **Device page header** rebuilt on RivetCore's rc.28 view-model (`RmmReadModel::header()` for the four CPU/RAM/disk/uptime chips and the storage-volumes "+N more" modal, `RemoteActions::headerRemote()` for the Desktop/Terminal/Files split button's disabled/not-configured/unmapped/ready/offline states) instead of ad hoc queries.
- **Schedule builder:** Debian/Red Hat service name pairs (ssh/sshd, cron/crond, chronyd/systemd-timesyncd) are now one check in the picker, not two; ticking it schedules both names with `missing: 'ignore'` on the alias so a host missing one name of the pair is not flagged. Fixed a duplicated "Windows Windows 11" asset-type label on auto-create.
- **Mobile (ManageEngine MDM) overview was investigated and explicitly not built in this release:** RivetMSP has no ManageEngine MDM integration at all (client, sync, mapping, settings) to put an overview page on top of - it exists only in the RivetIT sibling edition. Porting that whole integration is a materially larger, separate piece of work; attempting a half port would have been worse than leaving this out. Flagged here rather than silently dropped.
- Two bugs found and fixed during this work, not left as "pre-existing": the installer handler's `device_type` field resolved to an empty string instead of "auto" whenever a caller omitted it (a ternary re-read `$_POST['device_type']` directly instead of reusing the already-coalesced value), which made every installer request without an explicit device type fail with a 422 - affected every pre-rc.29 caller until fixed; and the VM facts table printed two host/guest-reported strings (replication state, backup result) unescaped, a stored-XSS pattern caught by security review and fixed with a regression test proven to fail without the fix.
- **Tests:** `tests/rmm_hypervisor.php` (31), `tests/rmm_device_type.php` (15) plus Core's `EndpointAssetsConformanceTest` (17) and `EndpointAccessPolicyConformanceTest`, `tests/rmm_auto_install.php` (38, incl. the new app-checkbox AJAX endpoint), `tests/rmm_policy_pack.php` (28), `tests/rmm_checks_simple.php` (114), `tests/rmm_linux_hosting.php` (68), `tests/rmm_agent_localbuild.php` (278), `tests/rmm_agent_feed.php` (272), `tests/rmm_installer_ui.php` (136), `tests/rmm_rc25.php` (75), `tests/rmm_ui.php` updated for the new header view-model. Full-repo regression run across `tests/*.php` after all of the above landed; see the branch's own notes for which pre-existing failures are unrelated flakiness (vault-key decryption, scratch-DB cross-test state) versus anything this release actually touched.

## [26.10.39] RivetMSP — MSI installer verified on a real Windows PC (no database change)

**Deploy notes:** pull this tag; no database update, no RivetCore change, no new web setting.

- **The MSI installer has now been run and verified on a real Windows PC.** The earlier "unsigned, and not yet verified on Windows" / "UNVERIFIED" warnings (whether `msiexec` accepts a package with the payload appended after the end of the file, the whole custom-action flow) are removed from the MSI templates card, the Add device / Build installer dialog's Format help text, the Deployment card's MSI instructions, and the download-success message - all of them, they are confirmed working.
- **Still true, kept:** the MSI is unsigned, so Windows SmartScreen or an application-control policy may still warn; and upgrade, repair and uninstall are not yet tested (only a first install was verified).
- `tests/rmm_installer_ui.php` updated: the MSI templates card assertion now checks the "unverified"/"never run" wording is GONE while the SmartScreen note and the "Upgrade, repair and uninstall are not yet tested" note are still shown.

## [26.10.38] RivetMSP — RivetCore 1.0.0-rc.25: per-OS checks, clock-skew notice, change monitor switch, one Add device entry (DB 2.6.102)

**Deploy notes:** back up the database, pull this tag (tracked `vendor/rivet/rivet-core` is the v1.0.0-rc.25 git archive), run **Administration > Updates** (DB 2.6.101 to 2.6.102: Core migrations 0030, 0031, 0032 through the Core runner, one nullable-free settings column, the stored check schedule learns which OS each service check is for, then ONE `reconcileChecks()` that removes the check rows of checks a device no longer gets and resolves their alerts without a ticket). Nothing is switched on: `change_monitor` is off; `metrics_extended` is Core's default (on). Agents: ship the rc.25 agent for per-OS filtering, clock self-correction and the extra health details; old agents keep working. Nginx: nothing new on the MSP vhosts (the device endpoint `agent_changes` goes through `api/v1/index.php` like `agent_logs`).

- **Linux service checks no longer go to Windows PCs.** The check schedule sets `os` on every service check from its Windows/Linux choice table (EventLog, Windows Update, Defender... are Windows; sshd, cron, chronyd, rsyslog are Linux). "Add another service" asks Windows or Linux (default Windows); a service already scheduled keeps its OS. A stored schedule is upgraded by the DB step and again whenever the form is read or saved; a custom service stays for both until an OS is picked. Policy check templates have "Applies to: Windows and Linux / Windows only / Linux only". Note on the page: "A check ticked for one OS is only sent to devices of that OS."
- **Clean up checks that no longer apply** (Administration > Endpoint agent > Checks): administrators only, CSRF, audited, safe to press again. Resolved alerts show "This check no longer applies to the device" (system, no ticket).
- **Clock.** Device pages show "This PC's clock is N min ahead of / behind the server: set the time zone and sync the time (Windows: tzutil /s ..., w32tm /resync)" when the skew is over 2 minutes or a check-in was refused; a device that never checked in shows the refusal reason instead of "OS and agent not reported yet"; the fleet list has a "Clock off" badge. The event `rmm.device.clock_skew` is in the event catalog (Core).
- **Send extra health details** switch (limit `metrics_extended`, on by default). The Performance view keeps reading the same five metric keys; the new keys (memory bytes, per-core load, disk I/O, network errors, battery) are stored but not charted in this edition yet.
- **Change monitoring (rc.22), minimal.** Admin switch `change_monitor` (off by default, with the storage acknowledgement), a read-only **Changes** tab on the device page (rmm.change.view = like running a saved script), abilities mapped, route `agent_changes`. Watch presets, custom watches, rules and the search page come in a later release.
- **One "Add device" entry** in the Endpoints menu (opens the full page; "Build installer" and the second entry are gone). The quick dialog is the **Quick download** button on the Add device page and the fleet page. The old `rmm_fleet.php?add=1` address redirects to the full page.
- **Allow Linux devices to enroll** (Administration > Endpoint agent > Overview): admin switch, **on by default**, audited, stored in `settings.config_rmm_allow_linux` (new column in the DB step). The config.php constant `EA_ALLOW_NON_WINDOWS` still forces it on. When off, a Linux enrollment is refused with the existing 422 "device.os".

**Tests.** `tests/rmm_rc25.php` (73 checks), plus the updated installer, mesh wizard and alert-category tests.

## [26.10.37] RivetMSP — Choose the ticket category for tickets made from RMM alerts (DB 2.6.101)

**Deploy notes:** back up the database, pull this tag, run **Administration > Updates** (DB 2.6.100 to 2.6.101: one nullable settings column and one new table, no row touched). No RivetCore change. Until an admin chooses something, behaviour is exactly as before (no category).

- **New "Alert tickets" card** (Administration > Integrations > RMM): pick the ticket category tickets from RMM alerts get; "Create a Maintenance category and use it" does it in one click when there is none. Optional special cases: when a check family (disk space, service, patching, reboot needed, agent update, log rule, change monitor), an RMM source or a severity matches, use another category and optionally a priority. A plain-language summary updates as you edit. Most specific wins: check family, source, severity, default.
- **Applies everywhere an alert becomes a ticket:** automatic ticketing in cron, the "Create ticket" button, the automation action and the endpoint agent's critical-alert escalation (all use `createTicketFromRmmAlert()`). An archived or deleted category is never used.
- RivetMSP has no ticket boards or queues, so only the category (and priority) is chosen.
- Tests: `tests/rmm_alert_category.php` (resolver units, hostile input, the updater step, the real cron run, the page, CSRF, admin only, audit), `tests/browser/rmm_alert_category_smoke.mjs`. `tests/rmm_agent_localbuild.php` now compares with `LATEST_DATABASE_VERSION`. Docs: `docs/RMM_MODULE.md` section 6p.

## [26.10.36] RivetMSP — Every time in the company's time zone (no DB change)

**Deploy notes:** pull this tag; no database update, no RivetCore change, no new web setting. Reload the pages (new `js/rmm_panel.js`, `js/rmm_alr.js`).

- **No more "UTC" on the RMM, Endpoint agent and admin screens.** Agent binaries, MSI templates and releases ("Uploaded"), build jobs and hosts ("last seen"), enrollment tokens, devices, jobs, checks, alerts, maintenance windows, logs, scripts, schedules, approvals, patch, software, scale cards, audit trail and the recovery panel now show the company time zone with its short label (`2026-07-04 10:42 CDT`), DST-correct. Headings no longer say "(UTC)".
- **No double conversion.** Core and `endpoint_agent_*` columns (UTC) are converted; the edition's `NOW()` columns (`rmm_alerts`, `rmm_remote_sessions`, `rmm_script_runs`, `rmm_sync_log`, `audit_events`) are already company time and are only labelled. Durations stay durations.
- **Logs and charts.** The Logs custom range (From/To) is typed in company time; the performance chart hour axis and tooltip use the company zone. A one-time maintenance window shows the company-time equivalent when its own zone differs.
- **Still UTC on purpose:** cron expressions of a schedule (Core evaluates them in UTC) and `UTC` as a name in zone pickers; see `tests/rmm_time_utc_allow.txt`.
- New shared helper `includes/rivet_time.php` (identical in RivetIT) and RMM wrappers `includes/rmm_time.php`. Audit table: `docs/RMM_MODULE.md` section 6o.
- Tests: `tests/rmm_time.php` (47 checks incl. America/Chicago DST transitions and a lint against new hard-coded UTC labels), `tests/rmm_time_pages.php` (30 checks on the real pages with a non-UTC company zone); updated assertions in `tests/rmm_ui_p23_c_alerting.php`, `tests/rmm_ui_p68_admin.php`, `tests/rmm_ui_p68_logs.php`, `tests/browser/rmm_p23_c_smoke.mjs`.
## [26.10.35] RivetMSP — No native browser dialogs (no database change)

**Deploy notes:** pull this tag; nothing else (no database update, no RivetCore change, no setting, no web server route). Reload open tabs once so the browser fetches `js/rivet_dialog.js`.

- **One dialog helper.** `js/rivet_dialog.js` adds `RivetDialog.alert(message, opts)`, `.confirm(...)`, `.prompt(...)` (all Promise based; Cancel, Esc, the X and a click on the dim area give `false` / `null`) and `RivetDialog.gate(event, element, message, opts)` for click and submit handlers. The message is plain text (`textContent` only, newlines kept), titles and button labels are settable, `variant` is primary, danger or warning, the safe button (Cancel) has the initial focus, Tab is trapped, focus returns to the element that opened it, the page behind is inert, dialogs queue one at a time, it sits on top of an already open modal, follows the dark theme and fits a phone. It is loaded by every layout (agent and admin, portal, guest, login, setup, MFA enforcement, AJAX modal footer) and builds its markup on first use. No inline handlers (CSP).
- **Every native `alert()`, `confirm()` and `prompt()` is gone** (about 100 call sites in 28 files): RMM asset and alert pages, integrations settings, passkeys and API tokens, mobile devices, campaigns, ticket reply (no labor type), timer reset, event rules, outtake forms, credential vault password step-up, file upload limits, guest signing. A synchronous `if (!confirm(...)) return;` became `if (!(await RivetDialog.confirm(...))) return;`; a Cancel still stops the form or click and a second click while the dialog is open is ignored. `data-confirm-submit` forms, `.js-confirm-native` links and the new `data-rivet-confirm` buttons use the same helper.
- **Not replaceable:** the browser's own "Leave site?" prompt (`onbeforeunload` in the TinyMCE unsaved-changes guard and the webhook form). Browsers do not allow a page to replace it; both are listed in `tests/no_native_dialogs.allow`.
- Tests: `tests/no_native_dialogs.php` (fails on any native dialog call in first-party JS, in `<script>` blocks and `on*=` attributes of PHP and HTML files, and in browser code echoed from PHP; allowlist in `tests/no_native_dialogs.allow`) and `tests/browser/no_native_dialogs_smoke.mjs` (real Chrome; `window.alert/confirm/prompt` throw and any CDP dialog event fails the check; 13 checks: text-only message, ARIA, focus, Esc, backdrop, queue, trap, stacking on an AJAX modal, `data-confirm-submit`, phone width, dark theme). Docs: `docs/DIALOGS.md`.

## [26.10.34] RivetMSP — Build progress and the RMM Agent Builder installer (DB 2.6.100)

**Deploy notes:** back up the database, pull this tag, run **Administration > Updates** (DB 2.6.99 to 2.6.100: four columns added to `endpoint_agent_build_jobs`, no row touched). No RivetCore change in the web app. Build hosts keep working before and after; to get progress they need the build-host script from RivetCore 9d20031 or later (the new installer fetches exactly that). Optional: `sudo deploy/setup-build-host.sh` on this server (see below).

- **Real build progress.** The Local build list shows a progress bar with percent, the current stage in plain words, the host's detail line, the time since the build was requested and a checklist of the stages (done, active, pending; failed marks where a failed build stopped). It updates live. The GitHub build shows the workflow run's steps as the same kind of checklist with a percent estimate.
- **Strict status fields.** The build host's `status` call accepts optional `stage` (prepare, clone, build_windows_amd64, build_windows_arm64, msi, verify, upload), `pct` (0-100), `detail` (at most 120 printable characters) and `elapsed_s`. Anything else is `422 bad_progress` and stores nothing. The percent never goes backwards for a job, an earlier stage is dropped, a terminal call ignores progress, and a job that returns to the queue forgets it. Every status call renews the lease.
- **RMM Agent Builder installer.** `deploy/setup-build-host.sh` (idempotent; `--check`, `--uninstall`, `--purge-docker`) installs Docker if needed, the `rivetit-build` account (no login, docker group), the build script and systemd timer from RivetCore at a pinned commit with pinned SHA-256 values (it refuses on any mismatch), creates the build host and its token through the application (`scripts/build_host_setup.php`; the token goes straight into `/etc/rivetit/build-host.conf`, mode 600, and is never printed or logged), enables the timer and runs `--register`. It warns that docker group membership is root-equivalent and refuses to run if the web user is in that group. `deploy/install.sh` asks "Would you like to install the RMM Agent Builder ...? [y/N]" (`--with-build-host`, `--no-build-host`). The one-line command is on Agent updates > Local build.
- Database: `endpoint_agent_build_jobs.progress_stage / progress_pct / progress_detail / progress_at` (updater step 2.6.100, `db.sql`).
- Tests: `tests/rmm_agent_localbuild.php` (261 checks), `tests/build_host_setup.php`, `tests/build_host_setup/run.sh`, `container.sh`, `installer_flags.sh`, `tests/browser/rmm_agent_localbuild_smoke.mjs`. Docs: `docs/RMM_MODULE.md` section 6n.

## [26.10.33] RivetMSP — Build on my build host (DB 2.6.99)

**Deploy notes:** back up the database, pull this tag, then run **Administration > Updates** (DB 2.6.98 to 2.6.99: three new tables, nothing existing is changed). No RivetCore change and no new web server route (the build host uses `/api/v1/agent_build_host/...`, inside the existing `/api/v1/` location). Optional: the folder `backups/endpoint-build/` is created on first use (private to the web user, like the other `backups/` folders).

- **Build on my build host.** Administration > Endpoint agent > Agent updates > Build a new version now asks where the build runs: **Build on GitHub** (as before) or **Build on my build host**. A build host is a Linux machine you own with Docker and git that runs RivetCore's `endpoint-agent/scripts/build-host.sh` every minute. It only makes outbound requests to this site; this server needs no compiler and no inbound connection to your network. Protocol: RivetCore `docs/rmm/BUILD_HOST_PROTOCOL.md`; setup: `docs/rmm/BUILD_HOST.md`.
- **Local build section.** Add build host (the token is shown once, with a Copy button and the exact `build-host.conf` lines containing this site's address), a host list with a status dot, last seen and address, Disable / Enable / Rotate token / Delete, a Request form (version, main or a tag, publish automatically with ring and rollout, make current), and a job list with live progress, the host's note, the failure log tail and Cancel / Publish.
- **Security.** The token authenticates the four build calls only. It is stored as a per-token salted SHA-256, compared in constant time, revocable, rate limited and audited. Files go to a per-job quarantine folder under random names; names come from a fixed list; chunks (1 MiB at most) and files have size caps; the assembled files are re-hashed against the manifest and `SHA256SUMS` and checked by the existing PE / architecture / MSI validators, then published through the existing upload code (ring, rollout and make-current from the request). Any mismatch stores nothing.
- Database: `endpoint_agent_build_hosts`, `endpoint_agent_build_jobs`, `endpoint_agent_build_chunks` (updater step 2.6.99, `db.sql`).
- Tests: `tests/rmm_agent_localbuild.php` (mock build host through the real endpoints, 195 checks) and `tests/browser/rmm_agent_localbuild_smoke.mjs`. Files: `includes/rmm_agent_localbuild.php`, `includes/rmm_agent_localbuild_ui.php`, `api/v1/agent_build_host.php`, `admin/post/agent_localbuild_poll.php`, `js/rmm_agent_localbuild.js`.

## [26.10.32] RivetMSP — Administration > Endpoint agent is now tabs (no database change)

**Deploy notes:** pull this tag; nothing else (no database update, no RivetCore change, no new setting).

- **Eight tabs instead of one long scroll.** Overview (module switch, status, the main defaults, devices waiting for approval, devices), Agent updates (the GitHub list and Build a new version, agent binaries, MSI template, per-client installer, rings), Checks (the plain-language check schedule), Remote and Mesh (remote tools, file transfer, MeshCentral and its setup page link), Inventory and logs (software inventory, log collection, scale, metric storage), Patching, Software deployment, Security and policy (certificate and policy text, policies/scripts/alerting, signing key, enrollment tokens). Every card appears once; no field or button changed.
- **Accessible.** `role=tablist/tab/tabpanel`, `aria-selected`, `aria-controls`; Left/Right/Home/End move between tabs; the bar scrolls sideways on a phone. Without JavaScript every part is shown one after the other, as before.
- **The address remembers the tab.** `#tab-checks` opens Checks and every old card anchor (`#inventory`, `#mesh`, `#binaries`, `#tokens`, ...) opens the tab that now holds it. A Save returns to the tab it was pressed in (each form posts a hidden `ea_tab`; a plain redirect goes back to `#tab-...`).
- **Amber dots** on a tab that needs attention: Overview (service off, state file mismatch, devices waiting), Agent updates (no agent published), Remote and Mesh (MeshCentral not set up), Security and policy (no active enrollment token). Tooltip plus screen-reader text.
- **One settings form across tabs.** The check schedule and the CA certificate / coexistence fields sit in Checks and Security and policy but stay part of the one "Service and defaults" form (HTML `form=` attribute); one Save writes the same fields as before, and each of the three tabs has its own Save button.
- Tests: `tests/browser/rmm_eatabs_smoke.mjs` (Chromium over the DevTools protocol); page-structure assertions updated. Files: `includes/rmm_ea_tabs.php`, `js/rmm_ea_tabs.js`, `admin/settings_endpoint_agent.php`, `admin/post/settings_endpoint_agent.php`.

## [26.10.31] RivetMSP — Build a new agent version from Agent updates

**Deploy notes:** back up the database, pull this tag. **No database step** (DB stays 2.6.98; the state table of 26.10.28 holds the new keys) and RivetCore is unchanged. Optional: the cron job below (`deploy/install.sh` adds it on a fresh install; on an existing server add the line by hand, see `docs/RMM_MODULE.md` 6j). Without a token nothing changes for you except a new box on the card.

- **Build a new version** (Administration > Endpoint agent > Agent updates). The new version number is prefilled (latest known + 1); **Build** starts RivetCore's `endpoint-agent.yml` workflow on GitHub (`POST /repos/<repo>/actions/workflows/endpoint-agent.yml/dispatches`, ref `main`, input `version`), which builds the Windows and Linux agents and the MSI and creates the release `agent-v<version>` (a suffix such as `-rc1` makes it a pre-release). The card follows the run (queued, building, finished or failed, with a link to the run) and, once the release is in the list, it appears with the usual Publish. **Publish automatically when built** (ring, rollout, make current) publishes it with the same code as the Publish button, by the page while it is open and otherwise by the new cron job `cron/agent_build_check.php`, as the administrator who started the build.
- **Server-side rules.** The version must be `X.Y.Z` or `X.Y.Z-suffix` (no leading zeros); a version that already exists or is not higher than the latest known release is refused; one build at a time. The repository is only ever the configured one.
- **The GitHub token** (fine-grained, **Actions: Read and write** on the one repository) is stored encrypted, sent only to `api.github.com`, never shown again, never in a log row or message. Step-by-step "How to create the token", **Test token**, **Remove token**; administrators only, CSRF on every action, audit rows (`Agent Build Token Saved/Tested/Removed`, `Agent Build Started`, `Agent Build Finished`). Changing the repository drops the token. A request that carries the token is refused for any other host and a redirect is never followed.
- **No token?** The card says so and offers **Start the build on GitHub** (opens the workflow page) with three plain steps; press Check for updates afterwards.
- Tests: `tests/rmm_agent_feed.php` (259 checks, mock GitHub extended with dispatch/runs; no network) and `tests/browser/rmm_agent_build_smoke.mjs`.
## [26.10.30] RivetMSP — Create installer is easy to find, and a step-by-step Remote access (MeshCentral) setup wizard (no DB change)

- **Create installer.** A primary **Create installer** button in the header of the RMM dashboard and the Agent fleet page, and a link to the full page from the **Add device** dialog. A viewer who cannot use it no longer loses the entry silently: a disabled button and a plain message name what is missing (the RMM module is off; or the permission **Create enrollment tokens**, `rmm.token.manage`). When no Windows agent (or no MSI template) is published the page says so and links to Administration > Endpoint agent > Agent updates. New `includes/rmm_installer_entry.php`.
- **Remote access (MeshCentral) setup** (`Administration > Remote access (MeshCentral) setup`, `admin/rmm_mesh_wizard.php`). Five numbered steps with a live green tick or amber to-do for each: connect the server (address, domain, login token key, Save and test connection), create the device group and paste its download address, set trust (leave the pin empty for a public CA; Read it from the server for a private CA), tick *Also install the MeshCentral agent* in the installer, test Remote on an enrolled device. Plus Troubleshooting (Remote lands on the Mesh home page, node id not mapped, agent not installed, certificate errors). Reachable from Administration, the Endpoint agent page header and Mesh card, and the **Remote** button of a device when Mesh is not set up (administrators; others get a disabled button with the reason).
- Uses the existing Mesh settings and RivetCore calls (`RmmAdmin::saveMesh`, the certificate read); no wire or Core change, no migration. The login key and the device group id are never printed back as text.
- Tests: `tests/rmm_mesh_wizard.php` (70 checks incl. role matrix, CSRF, hostile input), `tests/browser/rmm_mesh_wizard_smoke.mjs` (Chromium over the DevTools protocol, 10 checks). `tests/rmm_ui.php` now expects a disabled Remote button when Mesh is not set up.

## [26.10.29] RivetMSP — plain options instead of JSON boxes (check schedule, policy check types, Mesh, check policies) and a visible Build installer button

**Deploy notes:** back up the database, pull this tag. **No database step** (DB stays 2.6.98) and RivetCore is unchanged (1.0.0-rc.21). Nothing is migrated: the stored check list keeps its format, the signed output sent to agents is byte for byte what the validator already produced, and a list written by hand or by an older release still loads.

- **Administration > Endpoint agent > "What the agent checks".** The "Check schedule delivered to agents (JSON)" textarea is gone. In its place: three ready-made sets (**Recommended**, **Light**, **Strict**) and **Restore defaults**; cards with a switch and one or two plain inputs (**Low disk space**: warn below 5 to 30 percent free; **Waiting for a restart**; **Important services stopped**: a checklist of common Windows and Linux services plus an "add another service" box); **How often to check** in minutes and hours (5, 15, 30 minutes, hourly; a value already stored is offered as "current setting"); a one-line summary ("5 checks every 15 minutes"); and a collapsed **Advanced: run your own script** with a warning that it runs as code on every enrolled computer, the 8 KiB and 1 minute limits, a live byte counter and an off-by-default state. A read-only technical view shows the saved list that agents receive (the server signs every entry on delivery).
- **Server side is authoritative.** The browser posts options (`cs[...]`), never a check definition. `RivetChecksSimple::compose()` (`includes/rmm_checks_simple.php`) reads only the known options, refuses unknown ones, anything outside the offered values, markup or over-long service names, scripts over 8192 bytes, more than 30 services or 10 scripts, and then hands the built list to `ChecksValidator` (unchanged, the same one that always ran). A posted `checks_json` is ignored. A refused save writes nothing, shows the reasons in plain words and puts the typed options back (once, for ten minutes).
- **Nothing stored is lost.** Checks the form has no option for (CPU, memory, web address, certificate, a disk check on one drive, thresholds, flap settings, the built-in C: disk and EventLog checks...) are listed under "Other checks already in your schedule" in plain words and kept exactly as stored unless unticked. They are taken from the STORED list by key, never from the request. Recognised checks keep their keys, so alert history follows them. While no list is stored, the page says the built-in checks are in use and shows the Recommended set; Restore defaults stores nothing again.
- **Other raw-JSON fields converted:** the policy editor's four original check types (service, disk, script, restart) now have plain fields (service name, expected state, start type; drive and free-space limits; script with the 8 KiB / 60 s limits and a warning) instead of "Extra settings of this type (JSON object)"; the Mesh card's two JSON boxes became one box per platform (agent id, install arguments); the older check-policy modal (`agent/rmm_checks.php`) lost "Extra Parameters (JSON)" for plain fields per check type and `agent/post/rmm_check.php` builds the stored settings from them. Left as is, on purpose: the Firebase service-account key (a file Google gives you, pasted whole) in Notifications.
- **Build installer is easy to find.** A primary **Add device / Build installer** button on the Agent updates card and a **Build installer** button beside **Add device** on the Agent fleet page.
- **Tests:** `tests/rmm_checks_simple.php` (logic, hostile input, CSRF, role, unknown and malformed fields, forged keep keys, scripts, Restore defaults, signed output), `tests/browser/rmm_checks_simple_smoke.mjs` (Chromium: presets, summary, byte limit, save and reload, refusal, Restore defaults, phone width, keyboard, both installer buttons, policy and Mesh fields); `tests/rmm_ui_p23_a_policies.php` and `tests/rmm_ui_p1213_mesh.php` updated for the plain fields.
- **Not changed / honest gaps:** "Waiting for a restart" has no "after N days" option because the agent's check takes no parameter for it. The built-in default list uses the names `expect` and `warn_pct`/`fail_pct`, which the agent does not read (it falls back to its own 20 percent and 10 percent free defaults); they are shown and kept as "other checks" and not rewritten.

## [26.10.28] RivetMSP — Agent updates and Build installer (GUI-only agent publishing and installers)

**Deploy notes:** back up the database, pull this tag, run the database update (Administration > Updates or `php scripts/update_cli.php --update_db`). **DB 2.6.98** adds ONE table, `endpoint_agent_feed_state` (`CREATE TABLE IF NOT EXISTS`; nothing altered, no row inserted). RivetCore is unchanged (1.0.0-rc.21). The server needs outbound https to `api.github.com`, `github.com` and `*.githubusercontent.com` release hosts only for the new card; without it the card says so and nothing else changes.

- **Administration > Endpoint agent > Agent updates.** Lists the releases of the public repository (default `TheTractorHacker/rivet-core`, configurable) whose tag starts with `agent-v`: version, pre-release flag, date, files, what is already hosted here (exe per architecture, MSI template, Linux links, ring offers with their rollout percent) and how many devices report each version. **Check for updates** (cached six hours, conditional requests, survives offline, HTTP errors and rate limits by keeping the cached list and saying so) and an optional "new version available" badge. **Publish** (ring and rollout percent, "make current") downloads `rivetit-agent-windows-amd64.exe`, `-arm64.exe` and `rivetit-agent-windows-amd64.msi`, checks every file against the release's `SHA256SUMS` / `SHA256SUMS.msi` (and GitHub's own digest when it disagrees) BEFORE anything is stored, then stores them through the existing upload code (`RmmAdmin::uploadBinary` / `uploadMsiTemplate`: PE and MSI validation, immutability, ring offer, audit). One bad file stops the whole publish. The Linux files (install-linux.sh, tarballs, .deb, .rpm) are recorded as links with their SHA-256 (not copied: the store refuses ELF files on purpose).
- **SSRF and safety.** Only GitHub hosts over https on 443, no userinfo or IP literals, every redirect re-checked (an off-host redirect is refused), connection pinned to a checked public address, size caps (the store's limit for files, 64 KiB for checksum lists, 3 MiB for the API) and time limits. Download URLs are built from the validated repository, tag and a fixed file-name list; no URL from the API response is followed. Administrators only, CSRF, audit rows (`Agent Releases Checked`, `Agent Release Downloaded`, plus the upload code's own `Binary Uploaded` / `MSI Template Uploaded`).
- **Add device / Build installer page** (`Endpoints > Build installer`, `agent/rmm_add_device.php`). Choose the client once; take the stamped **.exe**, the stamped **.msi**, the PowerShell and msiexec commands, or the Linux commands: a script that downloads the recorded release tarball, verifies its SHA-256 and enrolls (any systemd distribution), a **.deb** command and an **.rpm** command, plus the recorded files with SHA-256 and copy-link buttons. Copy buttons everywhere; the token goes through a root-only file, never a command line. `agent/post/rmm_installer.php` also answers `os=windows&mode=commands` now; the Add device dialog is unchanged.
- **Safety UX.** The per-device buttons are now **Install now** (Patches tab, Deployment tab) with their own confirmation and audit; the notes and the Administration cards say that the live-install switches gate ONLY the scheduler's automatic fleet installs. (Core already let a person with `patch.install` / `software.deploy` install on one device with the switch off; this release names it, explains it and tests it.)
- **Tests:** `tests/rmm_agent_feed.php` (mock GitHub, address policy, list and cache, every failure mode, verified publish, tampered / unlisted / mismatching files store nothing, authorization, CSRF, hostile input, the generated Linux scripts run for real against a tarball), with `RMM_TEST_REAL_RELEASE_DIR` it also runs on the real agent-v0.9.1 files; `tests/browser/rmm_agent_feed_smoke.mjs` (13 browser checks); `tests/wave1_integration.php` and `tests/endpoint_agent_module.php` know the 2.6.98 step and table.
- **Not verified:** the .deb / .rpm commands and the Linux script were run with stubs and in a temporary directory, not as root on a real machine; the Windows .msi / .exe installs are as unverified as before.

## [26.10.27] RivetMSP — software deployment and reporting (RivetCore 1.0.0-rc.21, RMM Phase 5)

**Deploy notes:** back up the database, pull this tag, run the database update (Administration > Updates or `php scripts/update_cli.php --update_db`). RivetCore is now **1.0.0-rc.21** (the tracked `vendor/rivet/rivet-core` is the tag's `git archive`, so no `composer install` is needed). **DB 2.6.97**: Core migration `0029_rmm_software_deploy` adds eight tables (`rmm_software_hosts`, `rmm_packages`, `rmm_package_versions`, `rmm_package_assignments`, `rmm_package_installs`, `rmm_package_history`, `rmm_package_jobs`, `rmm_deploy_state`); nothing existing is altered and **no row is inserted**: the catalog and the list of download hosts start empty. **Nothing is switched on:** the `software_deploy` sub-switch is off until an administrator turns it on (Administration > Endpoint agent > Software deployment), an agent that does not announce `software:detect` / `software:install` is never offered a software job, and even with the switch on the scheduler queues **dry runs only** until "live installs" is switched on separately. Ship an agent built from RivetCore 1.0.0-rc.21 to get the software jobs; older agents keep working.

- **Install what you assigned, check that it is there, show the difference.** A catalog of packages (a Windows **MSI** or **EXE** pinned by its SHA-256 hash and exact size, or a package from the machine's own signed **apt** or **dnf** repositories), assignments to everyone, a client, a site, a group, a tag or one device (the most specific scope wins), and a deployed-versus-expected report. A version can never be edited (add a new one; retire the old); an installer can only be downloaded from a host an administrator listed, over https, and the agent runs it only if its hash equals the pin. Core rebuilds every value an agent receives from the catalog row: a technician cannot type an address, hash, argument or package name into a job.
- **Deployment tab on the asset page** (the existing Software tab keeps listing what is installed). What the device should have and has (as wanted, missing, still installed, failed, waiting, scheduled, not checked yet), Check now (read only), Dry run, and Run now with its own confirmation box and dialog, plus the install history. Nothing is restarted.
- **Three Endpoints pages.** **Software catalog** (packages, versions with the whole hash to copy and the exact size, retire and restore, and the list of hosts installers may come from), **Software assignments** (with the patch rings' delays in days, so you can see when a ring will get a package) and **Software report** (by package, client, ring or device, with a **CSV** download; a cell that starts with `=`, `+`, `-` or `@` gets a leading apostrophe so a spreadsheet never runs it).
- **Second person.** A package marked "two people" needs a second person for every assignment and every manual real run: a `software` request on the existing Approvals page (new kind label and detail panel), decidable there even when the script library is switched off (then only patch and software requests can be decided or cancelled). Lowering the flag needs an administrator.
- **Three abilities, mapped to the RivetMSP roles** (`EndpointAccessPolicy`): `rmm.software.view` like `rmm.device.view`, `rmm.software.manage` like `rmm.job.run_saved` (RMM scripts level 2: edit the catalog, check, dry run), `rmm.software.deploy` like `rmm.job.run_script` (RMM scripts level 3: assignments, a real install or removal). Administrators hold all three; the host list stays administrators only. A technician limited to some clients reads their own clients' state and cannot change the fleet-wide catalog (Core's rule).
- **Administration card "Software deployment".** The `software_deploy` sub-switch with its cost text, how often devices are asked what they have, installs at once (all clients and per client), history retention, and the separate **live installs** switch with its own acknowledgement.
- **Events.** Core's three new `rmm.deploy.started`, `.finished` and `.failed` (32 `rmm.*` events) are in the webhook event catalog; none carries an address, a hash or job output.
- **How it is built.** Every read and write is an in-process call into Core's technician API as the signed-in user (`includes/rmm_software_ui.php`, `agent/post/rmm_software.php`, `agent/rmm_software_{catalog,assignments,report,report_csv}.php`), so Core's ability checks, client scoping (another client's device or assignment is the same 404 as a missing one), the source pinning and the audit apply and a forged POST gets the REST API's answer. Only the fields of the chosen format are sent (Core refuses a hash on an apt version), and the form caps sit above Core's so a too-long value is refused, never cut short. Text that comes from a device or an administrator is escaped where it is printed. A database that has not had the 2.6.97 step yet shows a notice instead of an error.
- **Fresh installs:** `db.sql` now carries the eight tables and the ledger row (a fresh install never runs migration 0029).
- **Not verified on a real machine:** the **Windows** install path of the agent (MSI, EXE, the pinned download, the registry reads: unit-tested with fakes and a local TLS server only); dnf and yum hosts. Start with the host list, a dry run on one pilot machine and a small package. winget and Chocolatey are not supported (they cannot be pinned). See RivetCore's `docs/rmm/SOFTWARE_DEPLOY.md`.
- **Tests:** `tests/rmm_ui_p21_software.php` (run by `tests/rmm_ui.php`: the switched-off state, the access matrix for seven roles, IDOR against another client on every handler action and the CSV, CSRF, open redirect, hostile package names, addresses, hashes, sizes, package names and arguments, an immutable version, the two-person flag that only an administrator may lower, two-person approval of an assignment and of a run, the script-library fallback, report grouping, formula-safe CSV, the administration card, a database without the migration), `tests/browser/rmm_p21_software_smoke.mjs` (13 browser checks), `tests/core/EndpointAccessPolicyConformanceTest.php` (the three abilities), `tests/wave1_integration.php` (the 2.6.97 step, `db.sql`, the rc.21 pin), `tests/endpoint_agent_module.php` (the 2.6.76 to latest upgrade, fresh versus upgraded schema); `tests/rmm_ui.php` counts 32 `rmm.*` events.

## [26.10.26] RivetMSP — patch management (RivetCore 1.0.0-rc.20, RMM Phase 4)

**Deploy notes:** back up the database, pull this tag, run the database update (Administration > Updates or `php scripts/update_cli.php --update_db`). RivetCore is now **1.0.0-rc.20** (the tracked `vendor/rivet/rivet-core` is the tag's `git archive`, so no `composer install` is needed). **DB 2.6.96**: Core migration `0028_rmm_patching` adds eight tables (`rmm_patch_scans`, `rmm_patch_available`, `rmm_patch_history`, `rmm_patch_rings`, `rmm_patch_policies`, `rmm_patch_assignments`, `rmm_patch_rules`, `rmm_patch_jobs`) and the three default rings; nothing existing is altered and **nothing is switched on**. No cron change (the housekeeping call already runs Core's patch scheduler while the switch is on). To use it: publish agent **0.8.0** (it announces `patch:scan` and `patch:install`; older agents keep working and are never offered a patch job), then switch on Administration > Settings > Endpoint agent > **Patch management**.

- **Detect first, install gated.** A device reports which updates it is missing (Windows Update Agent, or apt, dnf, yum, zypper), a person or a rule approves them, and an installation happens only for approved updates, only where a patch policy allows it, only inside its install window, never faster than the limits, and the agent never restarts a machine by itself. With no policy a person approves every update and nothing is installed by itself. **Until the administrator also switches on "live installs" the scheduler only queues dry runs** (they list what would be installed and change nothing); a person with the install right can still run a real install from a device.
- **Patches tab on the asset page.** Compliance, last scan, missing updates by kind with status, the effective policy in plain words, Scan now, approve and decline with a reason, Dry run, and Install approved (its own confirmation box and dialog; an optional restart needs the restart right), and the install history. Filters by state, kind and text.
- **Three Endpoints pages.** **Patch compliance** (compliant, out of compliance, never scanned, stale, restart pending, failed installs, missing critical; by client, site or group; the devices that need attention; the rings), **Patch approvals** (each missing update across the devices you can see, with approve and decline for all of them or one client) and **Patch policies** (policies with their typed settings and where they apply, rings, approval rules, the install window with the maintenance-window picker).
- **Second person.** A policy can require a second person for a manual approval. The request is a `patch` approval on the existing Approvals page (new kind label and detail panel); it can be decided there even when the script library is switched off, and in that case ONLY patch requests can be decided or cancelled.
- **Three abilities, mapped to the RivetMSP roles** (`EndpointAccessPolicy`): `rmm.patch.view` like `rmm.device.view` (RMM module level 1), `rmm.patch.manage` like `rmm.job.run_saved` (RMM scripts level 2: scan, approve, decline, dry run, rules, rings, policies), `rmm.patch.install` like `rmm.job.run_script` (RMM scripts level 3, stronger than the reboot grant). Administrators hold all three. A technician limited to some clients can read their own clients' patch state and cannot change fleet-wide rings and policies (Core's rule).
- **Administration card "Patch management".** The `patching` sub-switch, how often devices are scanned, the overdue limit, installs at once (all clients and per client), history retention, and the separate "live installs" switch with its own confirmation box.
- **How it is built.** Every read and write is an in-process call into Core's technician API as the signed-in user (`includes/rmm_patch_ui.php`, `agent/post/rmm_patch.php`, `agent/rmm_patch_{compliance,approvals,policies}.php`), so Core's ability checks, client scoping (another client's device is the same 404 as a missing one) and audit apply and a forged POST gets the REST API's answer. Text that comes from a device (titles, KB numbers, package names) is escaped where it is printed. A database that has not had the 2.6.96 step yet shows a notice instead of an error.
- **Fresh installs:** `db.sql` now carries the eight tables and the default rings (a fresh install never runs migration 0028).
- **Not verified on a real machine:** the Windows install path of the agent (the Windows scan was run read only on one laptop); dnf, yum and zypper hosts. Start with a dry run on one pilot machine. See RivetCore's `docs/rmm/PATCHING.md`.
- **Tests:** `tests/rmm_ui_p20_patching.php` (run by `tests/rmm_ui.php`: switched-off state, the access matrix for seven roles, IDOR against another client on every handler action, CSRF, open redirect, hostile titles, typed policy settings, rings, rules, two-person approval, the script-library fallback, a database without the migration), `tests/core/EndpointAccessPolicyConformanceTest.php` (the three abilities), `tests/wave1_integration.php` (the 2.6.96 step, `db.sql`, the rc.20 pin); `tests/rmm_ui.php` counts 29 `rmm.*` events.
### Incoming (IMAP) and outgoing (SMTP) mail providers no longer change each other

- The Settings > Mail "Connect Microsoft 365" flow belongs to the incoming (IMAP) card, but its callback set **both** `config_imap_provider` and `config_smtp_provider` to Microsoft 365. On a tenant with SMTP AUTH disabled that silently stopped all outgoing mail. The callback now sets the IMAP provider only. `tests/mail_provider_independence.php` fails if any settings update ever sets both providers together.

## [26.10.25] RivetMSP — RivetCore 1.0.0-rc.19: thresholds of policy checks, starter pack 1.0.1

**Deploy notes:** back up the database, pull this tag. RivetCore is now **1.0.0-rc.19** (the tracked `vendor/rivet/rivet-core` is the tag's `git archive`, so no `composer install` is needed). **No database change** (DB stays 2.6.95), no cron change. Then, for the Windows fixes: publish agent **0.7.1** (or newer) to your rings, rebuild the MSI template with it, and install the starter pack's update (Starter pack page, "updated" items).

- **Bug fixed: the Alerting tab of the asset page could not show or override the thresholds of a check delivered by a policy.** The threshold endpoints answered 404 for every check defined only by a policy (the whole starter pack is delivered that way), so the tab said "This check declares no thresholds" and the override form was missing. They now answer for those checks; the editor renders and the override saves like any other.
- **Security (agent):** agent 0.7.1 closes a file-transfer hole on Windows where a junction inside an allowed folder reached files outside it (file transfer is off by default and needs an administrator-listed folder). Anyone running agent 0.6.0 or older with file transfer on should update the agent.
- **Agent fixes from the first run on a real Windows 11 machine:** PowerShell jobs get a proper environment (`ping`, `whoami`, `w32tm` and others are found; nine starter scripts no longer report success with empty sections), PowerShell error output is plain text instead of CLIXML, `proc_kill` waits for the process to end, service checks read services with a restrictive descriptor, and the antivirus check accepts a third-party product Windows Security Center lists as on.
- **Starter pack 1.0.1:** `battery-report`, `defender-status`, `defender-update-signatures` and `disk-health` print `unknown` or a note instead of Windows' sentinel values. Policies you edited and scripts you edited are never overwritten.
- **Tests:** `tests/rmm_ui_p23_c_alerting.php` gains a regression block (a check defined only by a policy: GET thresholds answers, the asset page Alerting tab renders the editor, the override saves and is removed); `tests/rmm_ui_p1213_starter.php` reads the pack version from the pack instead of assuming 1.0.0; `tests/wave1_integration.php` checks the rc.19 pin.

## [26.10.24] RivetMSP — the Remote button finds the device (MeshCentral node ids)

**Deploy notes:** back up the database, pull this tag, run the database update (Administration > Updates or `php scripts/update_cli.php --update_db`). RivetCore is now **1.0.0-rc.18** (the tracked `vendor/rivet/rivet-core` is the tag's `git archive`, so no `composer install` is needed). **DB 2.6.95**: Core migration `0027_rmm_mesh_node_ids` rewrites the stored MeshCentral node ids to one form (data only, no schema change, idempotent). No cron change.

- **Bug fixed: Remote opened the MeshCentral home page instead of the device.** MeshCentral finds a device by its 64-character id (`...?viewmode=11&gotonode=<64 characters>`), but the agent reports the same 48 bytes as 96 hex characters and the link carried `node//<hex>`. The link is now `<mesh>/?login=<token>&gotonode=<64 characters>&viewmode=11&hide=31`; every stored form is converted when the link is built, so it works even before the database update.
- **Paste whatever you have.** The MeshCentral node field (Remote card, legacy device page) accepts the address bar of the device in MeshCentral, `node//...`, the 64 characters after `gotonode=`, or 96 hex characters, and shows `node//` plus 64 characters. Help text: "In MeshCentral open the device and copy the address bar, or paste the node id."
- **Same node, one mapping.** An agent that reports the node in hex and a hand-typed 64-character id are the same node: conflict detection compares them as such.
- **Not verified on a live MeshCentral** with this exact link; the shape is the one MeshCentral shows in its own address bar.
- **Tests:** `tests/rmm_ui_p68_remote.php` (the launch URL carries the 64-character form for a device stored with the hex id, on every view; a pasted address is saved canonical), `tests/wave1_integration.php` (the 2.6.95 step, the rc.18 pin), `tests/browser/rmm_rc17_remote_smoke.mjs` extended.

## [26.10.23] RivetMSP — the Remote button opens the remote screen in a new tab

**Deploy notes:** back up the database, pull this tag, run the database update (Administration > Updates or `php scripts/update_cli.php --update_db`). RivetCore is now **1.0.0-rc.17** (the tracked `vendor/rivet/rivet-core` is the tag's `git archive`, so no `composer install` is needed). **DB 2.6.94**: Core migration `0026_rmm_mesh_hide` adds two columns to `endpoint_agent_mesh_settings` (`mesh_hide` default 31, `mesh_hide_tools` empty); nothing else is touched. No cron change.

- **No MeshCentral screens around the session.** The Remote button used to land on MeshCentral's own interface (header, device list, tabs). The URL now carries MeshCentral's `hide` parameter, as Tactical RMM's "Take control" does: the default `hide=31` hides the masthead, top bar, footer, tab titles and the left bar with the device list, so only the device view is left. From MeshCentral's page source: bit 1 masthead, 2 top bar, 4 footer, 8 titles, 16 left bar, 32 back button.
- **It opens in a NEW tab, reliably.** Browsers block a tab opened after an asynchronous request, so the tab could silently fail to open. The click now opens a blank tab immediately and sends it to the session when the launch call answers. If the launch fails the blank tab is closed and the reason is shown; if the browser refuses the tab an **Open remote session** link appears instead. A tab from an earlier launch is never reused (each launch has its own login token). Same for the Remote tab (Desktop, Terminal, Files), the legacy device page and the Tactical asset page.
- **Settings.** Administration > Endpoint agent > MeshCentral: **Hide MeshCentral interface elements** (0 to 255, default 31; 31 = only the remote screen, 0 = the full MeshCentral and the old URL) and an optional value for Terminal and Files (empty = same; 0 allowed). Only shown once the database update has run.
- **MeshCentral does not connect by itself.** From its source, nothing in the URL starts the connection: the new tab shows the Desktop view with MeshCentral's **Connect** button; click it once. The card says so.
- **Not verified on a live MeshCentral.** The `hide` bits come from MeshCentral's page source (default.handlebars); the smoke runs against a mock Mesh server. See RivetCore's `docs/rmm/REMOTE_AND_LOGS.md`, "The Remote button", for the test to run on yours.
- **Tests:** `tests/rmm_ui_p68_remote.php` (default hide on every view, the form, per-view values, 0 equals the old URL, refusals, a technician cannot change it, the helper is linked before the page scripts and nothing opens a tab after the async answer), new browser smoke `tests/browser/rmm_rc17_remote_smoke.mjs` (10 checks: a tab exists before the launch answers, the final URL has `login`, `gotonode`, `viewmode=11` and `hide=31`, the tab cannot reach its opener, every click opens its own tab, the popup-blocked link, a failed launch closes the tab, hide 0, Terminal and Files, the admin card).

## [26.10.22] RivetMSP — the Run script dialog offers the script library, and one Scripts area

**The bug.** On an asset page the top **Run script** button opened a dialog whose "What to run" list held only "PowerShell text" (and "A saved script" when the older table had rows). The RivetCore script library (the Starter pack and your own scripts) was not in it, and Linux devices had no Run script button at all.

**Deploy notes:** back up the database, pull this tag. No database change (DB stays 2.6.93), no new cron line, no setting. The older addresses keep working.

- **One dialog, every source.** "What to run" is now: **Script library (N)** (when the Scripts switch is on, you may run saved scripts and the library has scripts for the device's platform), **A saved script** (the older list, Windows, only when it has rows), **Synced from <provider> (N)** (older scripts that can run through the vendor RMM the asset is linked to, only with the older scripts grant) and **PowerShell (type or paste)** on Windows or **Shell (type or paste)** on Linux, only where Core supports that free-form job for your role. A single choice is shown as text, not as a one-option dropdown. Linux devices get the button.
- **Script library source.** A search box (name, tag, description), the scripts grouped by risk class (Read only, Changes the system, Disruptive, Not classified), the chosen script's description, tags and risk badge, and its parameter form built from the script's schema (typed inputs, ranges and length hints, required fields, secrets as password fields, choices). **Dry run (nothing is changed)** is a tick box that starts ticked for a script that changes things (and follows the script's own default for a read-only one); unticking it really sends `false`. A script that needs approval says so and the button reads **Request approval**; a destructive script needs its confirmation box. It submits through the same handler as the Run a library script card of the Jobs tab (CSRF, client scoping, Core's checks), and the answer is a message with a link to the Jobs tab (or to Approvals). Empty states explain and link the Starter pack (administrators) or Scripts.
- **One Scripts area.** The Endpoints menu had both *Script library* and *Scripts*. There is now ONE **Scripts** entry and one page with tabs: **Library** (the RivetCore library, the default) and **Synced from <provider>** (the older list, labelled with the vendor RMM's real name; shown when older rows exist or a vendor RMM that provides scripts is on). `rmm_script_library.php` redirects to the Library tab with its query string, `rmm_scripts.php?category=` opens the synced tab, `rmm_script_run.php` is unchanged. The older list is responsive now.
- **Import into the library.** A one-time, idempotent action for the hand-made older scripts (no vendor script id): creates library scripts through Core's API as you (platform from the stored type, the old category as a tag, the risk class you pick, default Changes the system, approval only for Disruptive, note "imported from legacy Scripts"), skips what is already there with the same name and text, copies a name clash to "<name> (legacy)", reports CMD and empty scripts as skipped, audits it, and leaves the older rows alone ("Imported" mark). Needs write access to scripts; Core checks you again for each script.
- **Wording.** "Scripts" in the menu, titles and help text.

**Tests:** new `tests/rmm_ui_p23_d_scripts_hub.php` (dialog sources by platform and role, grouping, forms, dry-run default, approvals, destructive confirmation, client scoping, switch and module off at zero queries, synced scripts, one menu entry, tabs, older addresses, import and its idempotence) and `tests/browser/rmm_run_dialog_smoke.mjs` (desktop and 390 px, light and dark, keyboard); the existing RMM suites and smokes are updated for the single menu entry.

## [26.10.21] RivetMSP — multi-select ticket filters and a default Service Desk view

The Service Desk ticket filters took one value each (one priority, one agent, one board). Every filter is now a group of **checkboxes**, so a saved view can be "High + Medium priority, assigned to Alex or Bo, on boards Network and Email", and an agent or an administrator can choose the view Tickets opens with.

**Deploy notes:** back up the database, pull this tag, `php scripts/update_cli.php --update_db` (2.6.92 to 2.6.93: ONE new table, `ticket_view_defaults`, `CREATE TABLE IF NOT EXISTS`, nothing existing altered; the code works before it runs, only choosing a default needs the table). No new cron line, no nginx change, no new composer package. Existing saved views and bookmarked URLs keep working unchanged.

- **Checkbox filters, OR inside a group and AND across groups.** Assigned to (me / unassigned / any agents, any combination), Priority (Low / Medium / High), Board, Category, On-site / Remote (both ticked = no filter), Due (Overdue and Due today are independent; both ticked matches either) and Status (All open / All closed / Everything, or specific statuses). Each group is a fieldset with a legend, a count badge ("Any" when nothing is ticked) and **Select all / Clear**; every box has a label and works from the keyboard. The Save View form and the live quick-filter panel on the Tickets page use the same groups (the toolbar buttons open a checkbox menu; closing it or pressing Apply runs the filter; "More Filters" holds Assigned to, On-site / Remote and Due).
- **Old and new URLs.** `assigned=me`, `priority=High`, `board=5`, `onsite=1`, `status=Closed`, `status=7`, `overdue=1` and the dashboard's `assigned=0` (nobody) still work and mean the same as the one-item lists. The canonical form is `assigned[]=me&assigned[]=7&priority[]=High&priority[]=Medium`. A stored view of either form opens as the canonical URL, and the highlighted view in the sidebar is decided by what the filters mean, not how the URL spells them. The viewer's own id and `me` are the same choice. `status=All` (the "All Tickets" box) now really shows every ticket.
- **One validator.** `ticketFiltersNormalize()` turns any input into integers, enum members and fixed tokens (hostile values, nested arrays and oversized values are dropped; a group keeps at most 50 values), `ticketFiltersSql()` builds the conditions from that, and the list, the pagination and sort links, the kanban board, the client ticket lists and the API all read it. Kanban now honours Priority, Board, On-site, Due and Tags too (it used to ignore them). `ticketViewDescribe()` reads naturally ("Open tickets · Assigned to Alex, Bo or me · Medium or High priority · Board: Network or Email · Overdue or Due today").
- **Default view.** Add / Edit view: **Make this my default view**. A star beside every view in the Ticket Views list sets or clears it in one click. Opening Tickets with no filter in the URL loads the default (as a redirect to the same page with the view's filters in the address bar, keeping the list / kanban display mode). Any filter in the URL, or a view link, wins. The list offers **Reset to my default** (`?default=1`) and **Show all open** (`?status=Open`). One personal default per agent and one **shared default** (set by an agent with support level 3, Make shared default in the view's menu, or the extra checkbox in the form) for agents who have no personal default; the order is personal, then shared, then the old behaviour. A default that was deleted, un-shared or can no longer be used is skipped gracefully; deleting a view forgets the defaults that point at it. Setting, replacing and clearing a default is audit-logged (type "Ticket View"). A client's own ticket list never gets the default.
- **Permissions tightened on the way.** The Save View and Edit View modals now require write access to the support module, and the edit modal only opens for a view the agent owns (or a shared view for support level 3); `/api/v1/ticket-views` requires support read access and refuses a key locked to one client. Client portal logins never reach any of it (agent pages sign them out; API tokens are agents only).
- **API.** `GET /api/v1/tickets` takes several values for `priority`, `onsite`, `assigned` (me / unassigned / ids), `status_id`, `category_id`, `board_id` and `tag_id` (comma list or repeated `name[]`; OR inside a filter, AND between filters; `mine=1` and `priority=high` work as before; `overdue` + `due_today` together match either). `GET /api/v1/ticket-views` translates a multi-value view into those comma lists (all values still strings) and marks the agent's default with `is_default`. `api/v1/openapi.yaml` and `docs/API.md` updated.
- **Not changed.** The CSV export (an unfiltered export of all tickets, as before), the sidebar counters and the stat boxes (whole-desk numbers), and the per-ticket live stream (`sse_ticket_stream.php`, which follows one ticket, not a list) do not take list filters.

**DB and tests:** DB 2.6.93; `db.sql` gains `ticket_view_defaults` (a fresh import and the 2.6.92-to-2.6.93 update produce the same schema, 301 tables compared, no `uca1400`; the step is idempotent). **Tests:** new `tests/ticket_views.php` (171 checks: old / new forms and the canonical round trip, hostile and capped values, every filter combination against an independent PHP filter on 24 seeded tickets, the form validator, descriptions, default resolution / set / clear / replace / archived / un-shared / deleted fallbacks, the real Tickets page, pagination and sort links, kanban, the redirect and its escapes, handler permissions, audit lines, CSRF, the modals, the API, client portal and client-locked key refusals) and a browser smoke `tests/browser/ticket_views_smoke.mjs` (14 checks: two priorities and two agents by checkbox, save as default, Tickets opens with it, an explicit filter overrides, Reset to my default, Show all open, kanban, the star toggle, Select all / Clear, keyboard, phone width, dark theme). `tests/wave1_integration.php` and `tests/endpoint_agent_module.php` follow the new step.

## [26.10.20] RivetMSP — the integration sync watcher stops crying wolf

Fixes the false-positive and repeating "sync stopped / sync failing" emails the Wave 1 recovery watcher produced on a live install (about twelve a day): *UniFi sync has stopped: no run for 94 days (expected every 17 min)* (UniFi now syncs from an external daily script that does not write `unifi_sync_log`; the old in-app scheduled runs were still used to infer the interval) and *Sophos Firewalls: the last 3 runs all ended in an error* (an external per-minute script looped over every enabled integration, including the built-in `rivetit_agent` type, and wrote that type's refusal, "Endpoint agent devices are managed from the device page, not through an RMM client.", against the previous integration's row). No RivetCore change: the pin stays at 1.0.0-rc.16.

**Deploy notes:** back up the database, pull this tag, `php scripts/update_cli.php --update_db` (2.6.91 to 2.6.92: ONE new table, `recovery_watch_overrides`, `CREATE TABLE IF NOT EXISTS`, nothing existing altered; the code works before it runs, only saving a mute or an expected interval needs the table). No new cron line, no nginx change, no new composer package.

- **Dormant integrations.** An integration whose newest run is older than *Dormant after* (recovery setting `sync_dormant_days`, default 14 days, 0 = never) is **dormant**: no email, shown as "dormant since <date>" with **Mute** and **Forget history** actions. At most ONE "no longer running" notice per 30 days, and only when its last run was good and it has just gone quiet (within a week of crossing the line): an integration that went quiet long ago, such as the 94-day UniFi case, produces nothing. A never-run integration older than the setting is dormant too.
- **Per-integration overrides** (table `recovery_watch_overrides`: source, integration id, muted, expected interval in seconds, note, updated by/at). Admin > Backup > **Integration sync watcher**: Mute / Unmute, Edit (expected to run every N hours, a note, muted), Forget history. An external daily script can be declared "expected every 24 h"; a declared interval is judged at 3x and opts out of dormancy (a declared script that stops is stale). Every change is CSRF-protected and written to the audit log with who, which integration and the new values; Forget history needs its confirmation box and removes only that integration's sync-log rows and alert state.
- **Repeat cadence.** The same problem is mailed at most once per **24 hours** (new setting `alert_repeat_hours`, default 24, was a fixed 6; applies to every recovery alert, the e-mail footer shows the live value), the first occurrence is immediate, and a recovery ("sync is working again" / "running again") is announced **once**, only when the problem had actually been mailed. Stale and failing no longer both keep state for one integration (so no double recovery notice).
- **Failure rule.** "The last N runs failed" counts only that integration's runs with a **real error**: a failed status with a message that is not the "Endpoint agent devices are managed from the device page" sentinel. Sentinel rows are not the integration's runs at all (they neither fail it nor keep it looking fresh). Integration types without a vendor API (`rivetit_agent`, `SyncWatch::UNWATCHED_RMM_TYPES`) are not watched.
- **Panel.** A per-integration table: state (healthy / failing / stale / dormant / muted), last run (UTC), expected interval (and whether declared), the reason, actions. Names and notes are escaped; no inline handlers.
- **Docs.** `docs/RECOVERY_RUNBOOK.md` section 2a: the watcher, and the rule for external sync scripts (write `rmm_sync_log` / `unifi_sync_log`, or declare the interval or mute; any script that copies the "sync all enabled integrations" loop must skip type `rivetit_agent`).
- **Other.** `SyncWatch::snapshot()` (pure read, used by the panel) and `SyncWatch::evaluate()` (pure verdict); `RecoveryAlerts::recovered()`; `RecoveryAlerts::raise()` takes an optional repeat window (the dormant notice uses 30 days). New: `src/Recovery/WatchOverrides.php`, `tests/recovery_watch.php`, `tests/browser/recovery_watch_smoke.mjs`.

**DB and tests:** DB 2.6.92; `db.sql` gains `recovery_watch_overrides` (a fresh import and the 2.6.91-to-2.6.92 update produce the same schema, no `uca1400`; the step is idempotent). **Tests:** new `tests/recovery_watch.php` (80 checks: the exact live cases, UniFi with 94-day-old runs, Sophos with sentinel rows mixed in, a genuinely failing integration mailed once then not for 24 h, the 23 h / 24 h / 48 h boundaries, recovered notice once, a declared 24 h interval, mute, the 30-day dormant notice, forget history, the panel's rendering and escaping, the handlers with their audit lines and clamps, the session-time-zone guard) and a browser smoke `tests/browser/recovery_watch_smoke.mjs` (6 checks: real login, every state rendered, Edit / Mute / Unmute / Forget history through the real forms, phone width). `tests/recovery_drill.php` 125 (the 6 h window became 24 h, failed runs carry a message), `tests/wave1_integration.php` 87 and `tests/endpoint_agent_module.php` 87 (the 2.6.92 step and its idempotence), `tests/rmm_ui.php` 1755, `tests/endpoint_agent_smoke.php` 47, `tests/rmm_installer_ui.php` 141, golden replay 10, RivetCore PHPUnit 229 tests, and the security / crypto / platform / compliance / mail-intake suites all pass. Known failures, identical on 26.10.19 (verified on a clean checkout): `sla_pause` 2, `recovery_integration` 3, the strict golden replay.


## [26.10.19] RivetMSP — RivetCore 1.0.0-rc.16: push the MeshCentral agent to an enrolled device, and an MSI installer

Adopts RivetCore **1.0.0-rc.16**. Two owner requests. **(A)** The Mesh options live only in a stamped installer, so an agent that enrolled without them, or one that updated itself, never got a Mesh agent: a technician can now **push the install** (and the removal) from the device page, and install it on many devices from the fleet page. **(B)** The server could hand out only the stamped exe: it can now also hand out an **`.msi`**, built from an uploaded **MSI template**.

**Deploy notes:** back up the database, pull this tag, `composer install --no-dev --no-plugins --no-scripts`, `php scripts/update_cli.php --update_db` (2.6.90 to 2.6.91: Core migration 0025, one new table `endpoint_agent_msi_templates`; safe before or right after the code, and until it runs everything works, only publishing an MSI template needs the table). No new cron line, no nginx change. **Agents:** a pushed Mesh install needs the rc.16 agent (an older agent shows a disabled button, "update the agent first", and the API answers `409 unsupported`); the MSI needs the rc.16 `setup --from-msi` inside the template. Nothing changes for the exe installer or for older agents. Build the template with RivetCore's `make msi` (release tag `agent-v*` attaches it with `SHA256SUMS.msi`) and upload it under Administration > Endpoint agent > MSI templates (or `php scripts/endpoint_agent_publish.php <file>.msi --msi --version X --arch amd64 --activate`).

### (A) Push the MeshCentral agent
- **Device page, Remote tab** (Remote access card): *Install MeshCentral agent* while MeshCentral is on with an https address and a device group and the device does not report the agent (disabled, with the reason, for an agent that did not announce `job:mesh_install`); *Remove MeshCentral agent* (page confirmation dialog with a box, a destructive job offered once, never retried; also offered after MeshCentral was switched off) and *Run the install again* once it reports the agent. The page follows the job result. The caller sends nothing but `force`: address, device group and pin come from the saved settings when Core creates the job, and the group id is never in the job row, the page or any result. `agent/post/rmm_remote_tools.php` actions `mesh_install` / `mesh_uninstall`.
- **Agent fleet page, bulk install:** a collapsed card (every device I may manage, a client, a tag or a group; *Count the devices*, tick the confirmation, *Install*) that shows what Core queued and what it skipped (installed, queued, agent too old, no permission, error). Capped by `bulk_run_max`; refused above `approval_bulk_threshold` (the approval queue only knows script runs). `agent/post/rmm_mesh_bulk.php`, `js/rmm_mesh_bulk.js`. Never reaches a client the user may not manage.
- Rules unchanged from Core: `rmm.remote.manage` and the remote-tools switch, idempotent (`already_installed`, `already_queued`), audited (`Remote Tool Queued`, `Mesh Agent Bulk Install`, `Mesh Agent Installed` / `Install Failed`).

### (B) MSI installer
- **Format selector exe | msi** in the Add device dialog (Agent fleet page and client page) and in Administration > Deployment; the msi option is disabled, with the reason, when no template is current for the architecture (Core builds x64 only; an ARM64 PC uses the exe). The download is the template plus the payload, never a change to the stored template, named `RivetIT-Agent-<client>-x64.msi`; the *Also install the MeshCentral agent* box works the same.
- **Deployment commands** gain an msi block (msiexec, property form, silent downloader, uninstall, detection) for format msi.
- **MSI templates card:** upload, list (version, size, SHA-256, where the platform was read from), make current, deactivate, reactivate; CLI `--msi`. **Unsigned and UNVERIFIED on Windows**: the card, the dialog and the commands say so (SmartScreen; whether msiexec accepts a package with the payload appended, repair, upgrade and the custom actions have never run on Windows; RivetCore `docs/rmm/MSI_VERIFY.md` is the checklist).
- CSP safe (no inline handlers, no native dialogs), CSRF on every handler, labelled controls and live regions, tables scroll inside their card on a phone, nothing is drawn and no RMM query runs while the module is off.

**Pin and DB:** `rivet/rivet-core ^1.0.0-rc.16` (lock reference `cb27c541b6459b3be032bd410812328d0bbdb8bc`), vendored copy identical to `git archive` of the tag (and `installed.json` / `installed.php`). DB 2.6.91 runs Core migration 0025 through the Core runner; `db.sql` gains `endpoint_agent_msi_templates` and the ledger row (a fresh import and the 2.6.90-to-2.6.91 update produce the same schema, no `uca1400`).

**Tests:** `tests/rmm_ui.php` 1755 checks (88 new, `tests/rmm_ui_p16_mesh_push.php`), `tests/rmm_installer_ui.php` 144 (59 new: the Format selector, upload and its refusals, the download with the real wixl package and the full package from `make msi`: OLE header, decodable trailer, stored template untouched; the Administration cards; the CLI), `tests/endpoint_agent_module.php` 86 (the 2.6.91 step, twenty endpoint_agent_* tables), `tests/wave1_integration.php` 85, a tenth browser smoke `tests/browser/rmm_p16_smoke.mjs` (13 checks: Remote tab install and remove with the in-page dialog, bulk, Format selector, template upload through the real file input, dark mode, phone width), and the real agent proof (98 checks, 27 new: a real rc.16 Linux agent under systemd enrolled WITHOUT Mesh is pushed `mesh_install` through the technician API against the mock Mesh server: wrong pin, unknown group, oversize body, the good case, node mapping, idempotence, bulk, force, removal). The agent's own Go reader decodes a PHP-stamped copy of the real `make msi` package. Known failures, identical on 26.10.18 (rc.15): `sla_pause` 2, `recovery_integration` 3, the strict golden replay, and check 14 of the `rmm_p68_admin_smoke` browser smoke.
- **Not verified:** a real MeshCentral (the pushed install ran against Core's mock behaviour only; the Linux agent ids, install arguments and node-id locations are as unverified as in rc.12), and anything on Windows: the pushed install on Windows, the MSI (unsigned; msiexec with an appended payload, repair, upgrade, custom actions), the Windows agent.

## [26.10.18] RivetMSP — RivetCore 1.0.0-rc.15: the Mesh device group id in MeshCentral's own URL form

Adopts RivetCore **1.0.0-rc.15**. A real MeshCentral (an owner's server) showed that its device group id (`meshid`) is **not hex**: it is 64 characters of base64 in MeshCentral's URL spelling (`@` for `+`, `$` for `/`; 48 raw bytes), with no `mesh//` prefix, and the download address is `https://<mesh>/meshagents?id=4&meshid=<64 characters>&installflags=0`. Core now accepts that, and RivetMSP's Mesh card follows. **DB 2.6.90**: one idempotent step gated on 2.6.89 that runs Core migration `0024_rmm_mesh_group_url` through the Core runner (one added column, `endpoint_agent_mesh_settings.group_id_url varchar(64) NOT NULL DEFAULT ''`, guarded by `information_schema`; nothing else altered; `db.sql` carries it and a fresh import equals a migrated database). **Nothing is switched on by this update.** Rows saved with rc.12 to rc.14 keep working (the URL form is derived from their stored hex). Ported from RivetIT 26.10.41 (same Core pin and the same behaviours; RivetMSP's own card and page structure). The warning is shown inside the Mesh card, once, instead of in the toast.

**Deploy notes:** back up the database, pull this tag, `composer install --no-dev --no-plugins --no-scripts`, `php scripts/update_cli.php --update_db` (2.6.89 to 2.6.90; safe before or right after the code, and until it runs everything works: only the verbatim URL form is not remembered). No new cron line, no nginx change. **Roll out an agent built from RivetCore rc.15 with this server** if you use the optional Mesh install: the installer payload's `mesh.meshid` is now the URL form (same member, payload version 1), which agents from rc.12 to rc.14 cannot use (they record a Mesh error; the RivetMSP install itself is unaffected). After the update, re-save the group id once if you want the verbatim form MeshCentral printed kept; reissue installers from the updated server.

- **Device group id field** (Administration > Endpoint agent > MeshCentral). Help text: paste the download address from MeshCentral (device group, Add Agent, Windows 64-bit installer) or just its `meshid`; it is 64 characters, not hex; treat it like an invitation key. The field is no longer limited to hex: it takes the whole pasted address, the bare 64-character `meshid`, `mesh//<64>` and the old hex form, trims only whitespace and sends the raw value to Core (`mesh_group_id`) with room for a pasted address (2000 characters). Core refuses whitespace inside, quotes, `&`, `#`, a wrong length and another alphabet with one sentence and writes nothing; what was typed is put back escaped. The field stays a masked password input with its show toggle and now reads back `mesh_group_id_url` (the canonical hex stays in `mesh_group_id`). **Warnings** from the save response (today: a pasted address for a host other than the saved MeshCentral address) are shown in the card after Save ("Saved, with a warning: ..."), once, `role="alert"`.
- **Certificate pin.** Help text: leave empty when the server certificate is issued by a public CA such as Let's Encrypt (a certificate that renews every 60 to 90 days would break a pin); use a pin only for a private CA or a self-signed certificate. **Read it from the server** shows Core's `pin_probe.note` and, when `pin_probe.publicly_trusted` is true (a second, verified handshake succeeded), shows a "publicly trusted: no pin needed" panel and offers **no** way to put the hash in the pin field; for a self-signed certificate the proposal, the note and the button are as before.
- **Core's note under Advanced** now says what was checked against a real MeshCentral (the download address shape, agent id 4) and what was not (Linux agent ids, install arguments, node-id locations).
- **Installer payload and Linux command.** `mesh.meshid` and the Linux `--mesh-group-id` carry the URL form (verbatim as pasted, else derived from the hex); `install-linux.sh` quoting (`'...'`) is Core's, so the `$` in an id is safe.
- **Pin:** `rivet/rivet-core ^1.0.0-rc.15` (lock reference `b930c50063823d1ee324216b7612e518bbb87998`), vendored copy identical to `git archive` of the tag; `tests/wave1_integration.php` follows the pin and the new step (82 checks, was 79).
- **Tests:** `tests/rmm_ui.php` 1667 checks (was 1617; the Mesh fragment now covers the bare `meshid`, the whole pasted address for the saved host and for a different host (warning), hex, `mesh//`, whitespace trimming, 19 hostile or malformed values refused with nothing written, escaping of a hostile value put back, clearing, a row from before migration 0024, the new help texts, the self-signed probe note and the publicly-trusted panel, end to end through Core's verified handshake against a LOCAL CA (a second `php -S` started with `curl.cainfo` = that CA, plus the control that the same server is not trusted by the first web server; this proves the wiring, not Let's Encrypt itself; `tests/support/tls_stub_server.py` answers the HEAD that handshake sends)), `tests/rmm_installer_ui.php` 85 (payload decode shows the URL-form `meshid`, verbatim for a pasted address with `@` and `$`), `tests/endpoint_agent_module.php` 84 (the 2.6.89 to 2.6.90 step, idempotent, equals a fresh `db.sql` import), the Mesh browser smoke 19 checks (was 15: the same forms through the real page, the warning, hostile values, and the payload decoded from a real installer download), the other eight browser smokes, golden replay, real agent (12) and real P2/P3 agent (30) suites with an agent built from the rc.15 tag, phpunit (229 tests) and the regression list. Known results, identical on 26.10.17 (rc.14, checked by running the same suites on that tree in the same environment): `sla_pause` 2, `recovery_integration` 3 (the 600000-iteration drill archive), browser `rmm_p68_admin_smoke` check 14 (queue-worker guidance text, environment dependent), and `endpoint_agent_golden.php strict` reporting only the four intentional `01-disabled` differences (a disabled module answers 503 `module_disabled`; `golden` replay, which allows exactly those, is clean); `rmm_smoke` check 25 and a `HQ` substring in a CSRF token on the fields page of `rmm_ui_p23_a_policies.php` are intermittent. The real-agent proof (`tests/real_agent_proof/run.sh`, docker, a real systemd agent built from the rc.15 tag, a MOCK Mesh server) passes with 71 checks. The real-agent proof harness (`tests/real_agent_proof`) now uses a fresh random URL-form group id. Every group id in the tests is generated at run time or a fixed fake; no real MeshCentral address or id is in the repository.
- **Not verified:** only the Mesh download address shape and agent id 4 were checked against a real MeshCentral (by Core, not here); the Linux agent ids, the install and uninstall arguments, where the node id can be read, and everything on a Windows host are not. Nothing was installed from a real Mesh server and nothing was deployed.

## [26.10.17] RivetMSP — RivetCore 1.0.0-rc.14: the Alerting thresholds no longer fail on a check with empty params

Small patch release. Adopts RivetCore **1.0.0-rc.14**, which fixes `Cannot use object of type stdClass as array` from `AlertingActions::getThresholds`: on an install that never edited its global checks (the built-in list, where `pending_reboot` has empty params) the thresholds part of the Alerting tab of an agent-linked device failed, was logged, and showed the "declares no thresholds" note for every check. Inside Core a check definition's `params` is now always an array; `\stdClass` exists only on the wire (`ChecksValidator::toWire()`), so the stored `checks_json`, the signed check messages and the policy `version` hashes are byte for byte unchanged (the golden transcripts still replay identically, 10 files). No migration: **DB stays 2.6.89**.

**Deploy notes:** back up the database, pull this tag, `composer install --no-dev --no-plugins --no-scripts`. No `update_db` step, no new cron line, no nginx change, no agent change (an agent built from rc.13 keeps working; the fix is server side).

- **Edition code audited, no change needed.** Nothing in `includes/rmm_*.php`, `agent/rmm_*.php`, `agent/post/rmm_*.php`, `js/` or `src/Core/Adapter/Endpoint/` checks `instanceof \stdClass`, json-encodes a check list or reads `params` as an object; the thresholds editor and the policy viewer already treat `params` as an array, and the administration checks editor prints Core's wire-shaped settings summary (`{}`, asserted below).
- **Pin:** `rivet/rivet-core ^1.0.0-rc.14` (lock reference `fae6d98e63669294ed1bbf6719d082b1bedeb600`), vendored copy identical to `git archive` of the tag. `tests/wave1_integration.php` follows the pin (79 checks).
- **Regression test** in `tests/rmm_ui_p23_c_alerting.php`: with no `checks_json` stored, a device that reports `disk_c`, `pending_reboot`, `svc_eventlog` and `agent_update` loads the asset page Alerting tab; the technician thresholds route answers for each defined check, every default check lists in the thresholds part, the server log holds no `stdClass` / `Cannot use object` / fatal line, and the administration checks editor still shows `"params": {}`. Run against the rc.13 vendored Core the same checks fail (`pending_reboot` params is an object, thresholds answer 500, the error is logged), so the test is not vacuous.
- **Tests:** `tests/rmm_ui.php` 1617 checks (18 new), nine browser smokes, the RMM, golden (replay identical, strict identical), real agent and phpunit (229 tests) suites and the regression list all as on 26.10.16. Known failures, identical on 26.10.16 (rc.13 Core): `sla_pause` 2, `recovery_integration` 3 (the 600000-iteration drill archive), browser `rmm_p68_admin_smoke` check 14 (queue-worker guidance text, environment dependent); `rmm_smoke` check 25 (tag removal after reload) is intermittent. Nothing was deployed.

## [26.10.16] RivetMSP — RivetCore 1.0.0-rc.13: optional MeshCentral agent install and the RMM starter pack

Adopts RivetCore **1.0.0-rc.13** (rc.12: the optional MeshCentral agent install with automatic node-id mapping, migration 0023; rc.13: the RMM starter pack). Aligned with RivetIT 26.10.39 (same page name `agent/rmm_starter_pack.php`, same handler, same Mesh box rule: offered only once MeshCentral is on with an address). **DB 2.6.89**: one idempotent step gated on 2.6.88 that runs Core migration `0023_rmm_mesh_agent` through the Core runner (three new tables, nothing existing altered; `db.sql` carries it and a fresh import equals a migrated database). The starter pack has no migration. **Nothing is switched on by this update**: no installer asks for the Mesh agent until an administrator saves the Mesh install settings and ticks the box, and nothing from the starter pack is installed until an administrator installs it. Older agents are answered exactly as before (golden transcripts replay identically, strict mode included).

**Deploy notes:** back up the database, pull this tag, `composer install --no-dev --no-plugins --no-scripts`, `php scripts/update_cli.php --update_db` (2.6.88 to 2.6.89; safe before or right after the code). No new cron line, no nginx change. To use the Mesh install, roll out an agent built from RivetCore rc.13 (an older agent never reports Mesh and ignores the installer members).

- **Optional MeshCentral agent (off by default).** The Add device dialog and the Administration deployment form get **Also install the MeshCentral agent** (unticked every time, disabled with Core's own refusal sentence until Mesh is switched on with an https address and a device group id). Administration > MeshCentral gets the install settings: device group id (masked, it is a secret), certificate pin with **Read it from the server** (shows the SHA-384 and SHA-256 of the server's certificate and **never saves**; a button puts it in the field and you press Save), the overwrite switch, and JSON corrections of the agent-id and install-argument tables behind a warning that they are unverified. Core validates everything and writes nothing when any field is invalid. The device's Remote access card (and the Remote tab) shows the Mesh agent state: installed with its version or the install error, and what the server did with the node id (`mapped`; `conflict`, a visible alert that names the other device only when it belongs to the same client; `kept_manual`, a visible hint). The node id itself is shown to administrators only. Events `rmm.mesh.agent_installed` and `rmm.mesh.node_conflict` are in the shared picker.
- **RMM starter pack.** Endpoints > **Starter pack** (administrators, `scripts` sub-switch): browse and filter 73 scripts (41 Windows, 32 Linux; 57 read only, 10 change the system, 6 disruptive), 3 check sets and 3 policy drafts (created switched off and unassigned), tick what you want, **Preview**, **Install selected**, **Uninstall selected** (preview first, then an in-page confirmation), a settings form per draft (time server, SSH unit, certificate paths, backup service...), Core's signing-key blocker shown first. An empty selection is never sent to Core (Core reads it as the whole pack). The script library marks starter scripts (**Starter** badge, risk chip, **Update waiting** for a parked newer version, with a link that compares it with yours); policies show a Starter badge and never print their marker, and saving a draft keeps it. Event `rmm.starter.installed`.
- **Tests:** `tests/rmm_ui.php` 1599 checks (new fragments `rmm_ui_p1213_mesh.php`, `rmm_ui_p1213_starter.php`), `tests/rmm_installer_ui.php` 81 (payload decode: the Mesh members only when ticked, token-gated download, Linux flags), the pin probe against a local TLS server, auto-mapping from real check-ins (conflict, kept manual, overwrite), two browser smokes (Mesh 15 checks, Starter 16; desktop and phone, light and dark), the real Linux agent proof (71 checks, was 39) extended with a MOCK Mesh server and the starter pack through the API.
- **Not verified:** anything that talks to a real MeshCentral server (download address and flags, agent ids, install and uninstall arguments, where the node id can be read), the Windows code on a Windows host, and all 41 Windows starter scripts (parse-checked by Core, never run). Nothing was deployed.

## [26.10.15] RivetMSP — RivetCore 1.0.0-rc.11: remote tools, file transfer, log collection, RMM scale

Ports RivetIT's released adoption (26.10.38) of RivetCore **1.0.0-rc.11** (RMM Phase 6 remote tools, Phase 8 log collection, scale items S3 to S8). **DB 2.6.88** (one idempotent step gated on 2.6.87 that runs Core migrations `0021_rmm_scale_storage` and `0022_rmm_remote_and_logs` through the Core runner: ten new tables, nothing existing altered; plus `settings.config_rmm_remote_manage_lvl3` and the four `config_rmm_extstore_*` columns, all off; `db.sql` carries it and a fresh import equals a migrated database). **Nothing is switched on by this update**: the `remote_tools`, `filetransfer` and `logs` sub-switches, partitioning, herd control and the external metric store are all off until an administrator turns them on, and older agents are answered exactly as before (golden transcripts replay identically).

**Deploy notes:** back up the database, pull this tag, `composer install --no-dev --no-plugins --no-scripts`, `php scripts/update_cli.php --update_db` (2.6.87 to 2.6.88; safe before or right after the code). No new cron line. The live nginx rules are not changed by this release (the shipped templates already deny `backups/`, where file transfers are stored). To use remote tools or logs, roll out an agent built from RivetCore rc.11 first.

- **Device bridges:** `api/v1/agent_transfer.php` and `api/v1/agent_logs.php`, routed above the bearer parsing and documented in `openapi.yaml`; `api/v1/rmm_gate.php` is Core's rc.11 template (answers both while the module is off and enforces the warm start before any database work). File transfers live in `backups/endpoint-transfer` (`EA_TRANSFER_DIR` overrides).
- **Remote tab on the asset page** (services, processes, files, MeshCentral desktop/terminal/files) in its own handler `agent/post/rmm_remote_tools.php`; `agent/post/rmm_remote.php` (Remote Connect) is untouched and now pinned by a test.
- **Logs and Log rules** (Endpoints menu, shown only to users with the ability; asset Logs tab), client scoped by Core.
- **Administration:** cards for Remote tools, File transfer, Log collection, Scale settings, Metric storage and partitioning and the External metric store. RivetMSP writes metrics through Core's `DatabaseMetricSink`, so Core's **daily rollup and partitions apply to RivetMSP's own tables**: the storage card lists `rmm_metric_hourly`, `rmm_metric_daily` and `endpoint_agent_check_history`, shows the rollup status, and Enable converts every table the probe allows (naming any it leaves plain); the Performance and capacity panel shows the autoscaling hint, cache, herd control and the three tiers.
- **Permissions:** new ability `rmm.remote.manage` (administrators; a role with scripts level 3 and remote access only while the new Administration setting is on); sending a file to a device and the remote-tools settings stay administrator-only. Events `rmm.file.transferred` and `rmm.log.rule_matched` appear in the picker (read from Core's catalog).
- **Operations:** `cron/integration_worker.php --worker=N` runs several queue workers; with Redis the RMM read cache and token buckets use it from every entry point; housekeeping (already called by `cron/cron.php`) now also builds the daily rollup, prunes logs, expires transfers and flushes the external buffer. The external store's three settings are registered for key rotation and a schema scan fails when a secret-looking column is missing from the registry.
- **Tests:** `tests/rmm_ui.php` with new fragments (remote, logs, admin incl. the rollup and partitioning of all three tables on MariaDB 11.8), three browser smokes (17, 15 and 18 checks, desktop and phone, light and dark), `tests/real_agent_proof/run.sh` (39 checks with the real Linux agent built from rc.11 under systemd), policy conformance cases, registry scan. Not verified: any Windows code path, a real MeshCentral server, Core's S9 24-hour 10,000-device run, and live systems (nothing was deployed).

## [26.10.14] RivetMSP — Encryption on the RivetCore envelope: key file, rotation, rewrap tool, vault v3 (off)

Ports RivetIT's released adoption (26.10.35 plus the 26.10.37 secret-box fix) of RivetCore **1.0.0-rc.10** `RivetCore\Crypto` (ADR-011) for stored secrets. **DB 2.6.87** (one idempotent step gated on 2.6.86: widens the encrypted
columns so the new envelope fits, adds `settings.config_vault_v3_enabled` (0), `config_vault_dek_wrap`, `config_vault_v3_prepared_at` and `api_tokens.token_enc_dek`; no stored value changes; `db.sql` carries it and a fresh
import equals a migrated database). **Nothing changes on an existing install until an administrator creates the key file**; the credential vault stays on the legacy format until its flag is switched on.
Read [docs/KEY_MANAGEMENT.md](docs/KEY_MANAGEMENT.md) before deploying.

**Deploy notes** (in this order; take a database dump first):

1. Back up the database, pull this tag and run `composer install --no-dev --no-plugins --no-scripts` (nothing new is installed; the lock is unchanged).
2. `php scripts/update_cli.php --update_db` (2.6.86 to 2.6.87). Safe to run before anything else; the app keeps working on the `config.php` key exactly as before.
3. As root, create the key file: `sudo php scripts/keys_cli.php generate`. It creates `/etc/rivetmsp` **0755** (the web user must be able to traverse it) and `keys.json` **`root:www-data` 0640**, with the `config.php` key as kid `k1`. Check with `php scripts/keys_cli.php status`: it must not say "NOT REACHABLE".
4. **Take an offline copy of `/etc/rivetmsp/keys.json` now**, apart from the dump and the backup passphrase.
5. Dry run, then move everything: `php scripts/rewrap_cli.php --dry-run`, `php scripts/rewrap_cli.php` (repeats until a pass changes nothing; resumable). `php scripts/keys_cli.php status --inventory` shows the result.
6. Leave the credential vault flag **off** (`config_vault_v3_enabled` stays 0; `scripts/vault_v3_cli.php` is for a later, deliberate step).
7. Rollback: set `$config_crypto_v3_settings = false;` in `config.php` (new writes are `ENC2:` again; v3 values already written stay readable by this release). Downgrading the code needs the values moved back first, or a restore of the pre-upgrade dump.

- **Settings and two-factor seeds.** `encryptSetting()` / `decryptSetting()` (same signatures, an optional context) go through `RivetMSP\Crypto\SettingsCrypto` / RivetCore's `SettingsVault`. With a key file, new values are `v3:<kid>:...`; `ENC2:`, `ENC:` and cleartext keep reading and move to v3 lazily (the settings row) and with the tool. `users.user_token` seeds are v3 of the `totp` purpose bound to the user. A value that cannot be opened reads as empty (logged once, never the value); **an unknown or damaged envelope (`v4:`, `ENC3:`, a truncated `v3:`) now fails closed** instead of being handed back as if it were cleartext; true legacy cleartext still reads. Flags `$config_crypto_v3_settings`, `$config_crypto_v3_totp`.
- **Tooling.** `scripts/keys_cli.php` (status, generate, fingerprint, add-key, set-active, retire), `scripts/rewrap_cli.php` (one `RewrapSource` per encrypted column; Core's `Rewrapper`: dry run, resumable, compare-and-set, never truncates a too-narrow column, one audit row per column and run) and **Administration > Security > Encryption keys**. The registry (`src/Crypto/ColumnRegistry.php`) is derived from `secStragglerColumns()` and also covers the RMM module's secrets (signing key, Mesh login key, queued job secrets, secret custom fields), including a composite-key source.
- **The key file directory.** `generate` as root creates the directory 0755 and the file `root:www-data` 0640; the installer (`deploy/install.sh`) creates it as root after setup; status, the CLI and the panel say **KEY FILE NOT REACHABLE** (with the command that fixes it) when the web user cannot traverse the directory or read the file, instead of showing a plain legacy install.
- **Credential vault v3** (flag `config_vault_v3_enabled`, default off, `scripts/vault_v3_cli.php`): fields sealed with the vault data key (AAD `credential:<id>:<field>`), per-user `vw3:` wraps at the next password login, mobile tokens wrap the data key, writes (including a restore from an uploaded backup) finalised to v3. **Not enabled by this release.**
- **Recovery.** The backup manifests (in-app zip and `deploy/backup.sh`) keep the fingerprint formula and add the key ring by kid and fingerprint (no key material); `deploy/restore.sh` and `deploy/install.sh --restore-from` accept `--key-file` / `--restore-key-file`; the setup wizard restore names the missing keys; the restore drill reports "Key file for this backup" and opens v3 samples. The key file is never in an archive: escrow it separately.
- **Ciphertext prefix checks accept `v3:` everywhere** (a v3 value would otherwise be mistaken for cleartext): `secIsWrapped()`, the cleartext-row SQL, the webhook URL masking on the list and edit pages, the drill's secret check, `EndpointSecretBox` (already since 26.10.13). A cleartext vault key or TOTP seed is wrapped in its own context. `decryptSetting()` loads Composer's autoloader itself, so a cron script that never did cannot silently read v3 values as empty.
- Tests: `tests/crypto_settings.php`, `crypto_rewrap.php`, `crypto_vault.php`, `crypto_panel.php`, `tests/crypto_msp_integration.php` (prefix sites, fail-closed, key file access, TOTP login over HTTP, `cron/mail_queue.php` against a fake SMTP server with the password and an XOAUTH2 token, webhook queue delivery with the HMAC, every settings secret, provider credentials, all after a real rewrap), `tests/crypto_rmm_after_rewrap.php` (the RMM signing key signs a job over HTTP after a real rewrap; Mesh key, job and custom field secrets, Tactical / Level / Action1 / Sophos credentials; an unreadable key file), and RivetCore's `RewrapSourceConformanceTestCase` for the integer and string key sources plus a composite-key test.

## [26.10.13] RivetMSP — RMM Phase 2 and 3: policies, script library, schedules, approvals, custom fields, alerting

Adopts the rest of RivetCore **1.0.0-rc.10** (RMM Phase 2 and 3; `^1.0.0-rc.10`, the lock and the tracked `vendor/` agree, and `vendor/rivet/rivet-core` is exactly `git archive` of the tag). **DB 2.6.86** (one idempotent step gated on 2.6.85 that runs Core migrations `0019_rmm_policies_and_scripts` and `0020_rmm_alerting_maturity` through the Core runner: nineteen new tables, nothing existing altered, and two settings columns, `config_rmm_approve_scripts_lvl3` and `config_rmm_escalation_contact`). `db.sql` carries the new tables and a fresh import matches a migrated install (no `uca1400` collation). **Nothing is switched on by default**: Administration > Endpoint agent > "Policies, scripts and alerting" has the three sub-switches (`policies`, `scripts`, `alerting`), each with a plain-language cost note. Deploy order: database dump, `composer install --no-dev --no-plugins --no-scripts`, `php scripts/update_cli.php --update_db`; until the update has run, the new pages are off anyway because their switches are off. Roll out agents of this release before switching a part on: older agents keep working and simply do not use it.

- **Policies** (Endpoints > Policies): check-in and collection intervals inside the global limits, feature toggles, update ring and check templates (with warn/crit thresholds, flap detection, "ignore parent"), versions, assignment to everything, a client, a site, a group, a tag or one device with priority and enforce, and an "effective policy" viewer (also on the asset page, tab **Policy and fields**).
- **Script library** (Endpoints > Script library): PowerShell, bash and Python scripts with immutable signed versions, a version diff, parameter definitions (string, int, bool, choice, secret), retire/restore, re-sign, runs on a device, group, tag, client, site or everything with an in-page confirmation, and a "Run a library script" card on the asset page Jobs tab.
- **Schedules** and **Approvals**: interval or cron schedules with result history; a second person approves risky runs (a script marked "requires approval", or more devices than the threshold), sees the script text and hash, and never decides their own request. Who approves: administrators, and roles with RMM scripts level 3 only if the new Administration setting is on.
- **Custom fields** (client, site or device, with a secret type); values on the asset page; `{{field.name}}` fills script parameters.
- **Agent alerts** (grouped by device or check, filters, flap state, acknowledge/resolve, create ticket), **Maintenance windows** (one-time or recurring, mute or suppress, scope picker, "open now" banner; a suppress window holds scheduled scripts back), **Escalation policies** (timed steps to people, roles or addresses), and on the asset page the tab **Alerting**: per-check threshold override editor, parent device, why a bad check has no alert.
- **Escalation delivery** (`EndpointEscalation`, Core's `RmmEscalationInterface`): in-app notification (the bell, live stream and mobile push) and email through the mail queue to the step's users, roles and addresses and to the ticket assignee, the configured escalation contact for steps that reach nobody, and a ticket for a critical alert through the existing `createTicketFromRmmAlert()` (it reuses an open ticket); acknowledge and severity changes are mirrored onto `rmm_alerts`. The RMM Alerts page's Acknowledge/Resolve now also tell Core, so an acknowledged alert stops escalating. The existing cron housekeeping call runs escalations and scheduled-script batches, and answers without a query while the module is off.
- **Abilities** in the role matrix (Administrator and Technician semantics unchanged): `rmm.job.approve` is for administrators, and for roles with RMM scripts level 3 only while "Technicians with RMM scripts level 3 may approve runs" is on (off by default); `rmm.alert.manage` is for administrators and roles with RMM write (module_rmm level 2 or more). Client-portal contacts, disabled accounts and technicians with no RMM role rows hold nothing; a technician limited to some clients sees and acts on those clients only (Core scopes every list and write). All new pages are gated three times (module, sub-switch, ability) and the Endpoints menu entries come from the module's state file, so with the module off nothing here queries the database. The ten new `rmm.*` events are in the event picker.
- **`EndpointSecretBox` accepts `v3:` values** (ported from RivetIT 26.10.37): the box that reads the RMM signing key and the Mesh login key now hands `v3:` (the RivetCore envelope RivetMSP's settings will move to), `ENC2:` and `ENC:` values to the decryptor and still refuses anything that is not a ciphertext. RivetMSP's own `decryptSetting()` does not know `v3:` yet and returns such text unchanged, so a decryptor that gives back its input now reads as "not available", never as a key.
- Tests: `tests/rmm_ui.php` (four new fragments), browser smokes (policies and fields, scripts/schedules/approvals, alerting; desktop and 390 px, light and dark), conformance cases for the escalation adapter, the two new abilities and the secret box, and a run of the real Linux agent (rc.10) against a scratch server: policy delivery, a threshold alert and escalation through housekeeping, a library script with a secret parameter through approval, and a maintenance window holding back a scheduled script. `wave1_integration` and `endpoint_agent_module` updated for rc.10 and 2.6.86 (2.6.84 is the no-op stub of 26.10.12, 2.6.85 its real step).
## [26.10.12] RivetMSP — platform wave 2: relationships, search, inventory sync and a tamper-evident audit trail

Ports RivetIT's wave 2 (RivetIT 26.10.33; gap analysis items 13, 14, 15 and 17; item 18, the Intune primary user, does not apply because RivetMSP has no Intune integration). **DB 2.6.85**: one idempotent step gated on 2.6.84 (`db.sql` carries it, a fresh import and a migrated install have identical schemas, checked column by column). 2.6.84 belongs to the RMM Phase 2/3 branch; a branch that does not carry it has a no-op 2.6.84 stub so the chain stays linear. Take a dump first. Nothing is switched on that was off: stale-asset auto-retire is off by default, the document reminder and the audit hash chain are on by default and only add rows or columns.

### Relationships (item 13)

- **Entity links.** A generic `entity_links` table (`link_id`, `client_id`, `src_type`/`src_id`, `dst_type`/`dst_id`, `link_type` depends_on / runs_on / supported_by / documented_by / related, `note`, `created_by`, `created_at`; unique on the five identity columns, indexed on the destination) and a `RivetMSP\Links\LinkService` over it. Thirteen record types: asset, software, vendor, document, KB article, service, network, domain, certificate, credential, contact, location, ticket.
- **Rules (by client).** Both ends must belong to the same client unless one is kept in no client (a global vendor or KB article, client 0); creating a link needs change access to the source record's module and view access to the target's; unlinking needs change access to either end. A **client-restricted user does not reach client 0 records** here, matching RivetMSP's vendor and KB lists (which join clients) and `enforceClientAccess()`; administrators and users with no client restriction do. An API key limited to one client reaches that client only. Every create and delete writes an audit event (`entity_link.create` / `entity_link.delete`).
- **Relationships card** (`includes/relationships_card.php`) on the asset, vendor, document, KB article and contact pages, and in a pop-up (row action "Relationships") for software, services, networks, domains, certificates, credentials, locations and the vendor details pop-up (RivetMSP has list pages, not detail pages, for domains and certificates). It shows the record's links, **Referenced by** (reverse lookups) and **Impact: what depends on this** (transitive over depends on / runs on / supported by, up to three levels, cycle safe, capped at 100 records; records the role cannot see are neither listed nor walked through), with an unlink action, and a shared **Link item** pop-up (type picker, live search scoped to the source record's client, relationship type, note). **KB articles link to assets** (and anything else) the same way.
- **Nothing is copied.** The older link tables (`asset_documents`, `software_assets`, `service_assets`, `service_*`, `contact_*`, `vendor_*`, `ticket_assets`, asset location / contact / interface network, certificate domain, credential asset / software / vendor / contact) are read at query time and shown on the card as read-only "from ..." rows.
- **Several vendors per asset and software title, each with a role** (support, reseller, manufacturer): `asset_vendors` / `software_vendors`. `asset_vendor_id` / `software_vendor_id` stay the primary and are mirrored as role support; the database step back-fills the mirror from the existing primaries. Add or remove vendors on the card.
- **API:** `GET/POST /api/v1/relationships`, `DELETE /api/v1/relationships/{id}` (flat per-resource routing like every other resource; documented in `openapi.yaml`).

### Search and document review (item 14)

- Global search (page, live dropdown and `/api/v1/search`) now covers **software, networks and services**, shows **vendor roles** (how many assets and software titles each vendor supports, resells or makes; searching a role word such as "reseller" finds those vendors) and a **Linked records** section ("Runbook documents Asset srv-core"). The new sections are limited to the role's modules and clients.
- **Document review date** (`documents.document_review_at`, set when adding or editing a document). On the date the team gets one notification and a `document.review_due` event; changing the date re-arms it. The cron does this once a day (`src/Platform/Nightly.php`).

### Inventory sync (item 15)

- **The asset's own fields are filled from the built-in agent and the vendor RMMs** (Tactical, Level, Action1 and Sophos Central share `RmmAssetMapper`; the agent goes through `EndpointBridge`): make, model, OS, CPU and RAM (new `assets.asset_cpu` / `asset_ram`) and the primary network adapter's IP and MAC. A field is written when it is blank or still holds exactly what the last sync wrote (`asset_sync_state`), so a hardware change flows through and **a human edit is never overwritten**. Unchanged reports cost one read.
- **Per-integration client mapping** (`integration_client_map`): a saved mapping is used before the exact-name match. A client or group name that matches no client is no longer skipped silently; it waits in a **needs-mapping queue** (Administration > Integrations > RMM) where it is mapped to a client or ignored, and the next sync uses the answer.
- **Auto-retire of stale RMM-linked assets** (off by default; Administration > Integrations > RMM > Stale assets): assets whose RMM links have been silent for N days are archived with status Retired and listed for review (Restore / Confirm); a device that reports in again is restored automatically.

### Audit (item 17)

- **Hash chain** on `audit_events` (`prev_hash`, `row_hash`; HMAC keyed from the settings key when the install has one), kept in the edition (`RivetMSP\Audit\AuditChain`, `ChainedAudit`) around RivetCore's `AuditService`: `CoreBridge` now hands out the chained service, so every audit writer is sealed as it is written; the cron seals anything else, and the **nightly verify** alerts on an edited, removed or reordered row. Retention of the old end is accepted. Administration > Audit trail shows the last result and has **Verify now**. RivetMSP's audit trail is still the Compliance "audit recording" switch: with it off, nothing is recorded (and nothing is chained).
- **New events:** role created and **permission changes with before/after** (`role.permissions_changed`), a user's role (`user.role_changed`) and client access (`user.client_access_changed`), API token revoke, backup downloads / saves / deletes, the master key download, the RMM / firewall / UniFi settings pages and **every export** (all mirrored from the activity log), and entity links.
- **Audit copy:** an optional JSON-lines file and/or syslog copy of every sealed event (Administration > Audit trail; off by default; the path must be outside the application folder).

### Fixes found while porting

- `agent/user/api_token_revoke.php` printed the page shell before its JSON (so the `Content-Type` header failed with "headers already sent" and the response was HTML followed by JSON); it now answers JSON only.
- `agent/post.php` and `admin/post.php` no longer raise an undefined-index warning for a POST without a Referer.

### Tests

`tests/platform_migration.php` (migration gated on 2.6.84, idempotent, back-fill, fresh import equals migrated schema), `tests/entity_links.php`, `tests/platform_sync.php`, `tests/platform_search.php`, `tests/audit_chain.php` (including the CoreBridge path) and the browser smoke `tests/browser/relationships_smoke.mjs` (seed: `relationships_seed.php`). Not carried over: the Intune cases and the IT event-catalog extension (RivetMSP's picker lists RivetCore's catalog; the three new events appear as "other" ids).

## [26.10.11] RivetMSP — RMM Phase 1: software inventory, tags, groups, check history and performance history

Adopts RivetCore **1.0.0-rc.9** (RMM Phase 1; `^1.0.0-rc.9`, the lock and the tracked `vendor/` agree). **DB 2.6.83** (one idempotent step gated on 2.6.82 that runs Core migration `0018_rmm_inventory_foundation` through the Core runner: eleven new tables, none of the ten `endpoint_agent_*` tables altered; `db.sql` carries them and a fresh import matches a migrated install). **Nothing is switched on by default.** Run the update before, or right after, the new code is live: until the tables exist a check-in that carries checks answers 500 and the agent retries by itself.

- **RivetMSP now keeps performance history.** RivetMSP has no metrics subsystem, so the module is given Core's `DatabaseMetricSink` (sink and reader): one summary row per metric per hour plus the newest reading (`rmm_metric_hourly`, `rmm_metric_latest`), 14 days, pruned by the daily housekeeping. The asset page's **Performance** section draws CPU, memory, disk and network charts for the last 24 hours (average per hour, the hour's maximum dashed) with the Chart.js the app already ships (no new library or CDN), each with its numbers in a table behind a disclosure. The gauges stay the latest check-in. The "no performance history in RivetMSP" explanation is gone.
- **Capacity.** Plan on about 12 rows per device per hour (roughly 290 a day, about 4,000 per device at steady state). Administration > Endpoint agent > Software inventory and history says so, with the number for the enrolled devices, and that the history is stored in the database. The 14 day window is fixed by RivetCore.
- **Software tab** on the asset page, behind the new `inventory_software` switch: current list with search, publisher, version, install date, "include removed" and paging, plus the change log (installed, upgraded, downgraded, removed). A technician can ask a device for a full list. Agents of this Core release announce the capability; nothing is collected until the switch is on, and switching it off stops agents within one check-in. Existing data is kept.
- **Device tags and groups** in the device strip: add (with the browser's autocomplete from the existing tags) and remove for administrators (`rmm.device.manage`), read-only chips for others; group membership is shown. Tags show in the fleet list.
- **Per-check history:** a 24 hour trend per check (an accessible sparkline whose height also encodes the status, availability in words, a history table behind a disclosure).
- **Network tile** shows receive and send as bars against the real 24 hour peak, replacing the "link speed not reported" empty state.
- **Fleet page:** filters by tag, group and installed software name, and an **Outdated software** card (name plus oldest acceptable version).
- **Events:** the nine `rmm.*` events (device enrolled/offline/online, check failed/recovered, job completed/failed, software installed/removed) go onto the event bus (`EndpointEvents`), so webhooks (`rmm.*` or `*`) and Event rules can use them. They are in the event picker (RivetMSP's picker reads RivetCore's catalog, which lists them in the `rmm` group).
- **Administration:** the `inventory_software` switch and the retention limits `check_history_days` and `software_history_days`.
- Everything is gated by the existing RMM abilities and the module switch; with the module off the new code asks the database nothing.
- Tests: `tests/rmm_ui.php` (see the release notes for counts), the browser smoke (desktop and 390 px, light and dark), conformance cases for the metric sink and reader (`DatabaseMetricSink`) and the events adapter, the real Linux agent (reports its software and metric history into the MSP tables), `wave1_integration`/`endpoint_agent_module` updated for rc.9 and 2.6.83.

## [26.10.10] RivetMSP — nightly security fixes integrated, database steps renumbered (2.6.79 to 2.6.82)

Reconciles 26.10.9 with the tree that is actually running. The live database was already at **2.6.78** (RivetCore 0017, MCP identity binary collation) from the nightly-fix line, so 26.10.9's chain collided with it: its 2.6.78 security step would have been skipped. **26.10.9 is superseded; do not deploy it.** Database chain on this release, linear after 2.6.77: **2.6.78** MCP identity collation (RivetCore 0017, rc.7), **2.6.79** security, **2.6.80** mail intake, **2.6.81** recovery, **2.6.82** RivetCore runner step (rc.8; idempotent, every earlier Core migration is recorded and skipped). `db.sql` is regenerated (version 2.6.82, no `utf8mb4_uca1400_ai_ci`), and a database migrated from the live 2.6.78 shape has the same schema as a fresh import.

Nightly security fixes carried in from the live tree:

- **MSP-1** add-to-invoice-from-ticket: the invoice must belong to the ticket's client and still be editable.
- **MSP-2** `tests/` is CLI-only and denied by the repo nginx templates.
- **MSP-3** client portal: non-primary contacts cannot rewrite the login of technical or billing contacts; the users UPDATE is limited to portal users.
- **MSP-4** API tokens are revoked when a user is disabled, their 2FA is reset or their role changes (merged with the password-policy and session work in `admin/post/users.php`: 2FA reset also deletes recovery codes).
- **MSP-5 / MSP-6** billing: bulk payment recomputes balances under row locks in cents, `delete_payment` resolves its client, credits need a positive amount, the Stripe fee expense is booked after the payment is recorded, cents status in portal and webhook, balances under 1.00 are payable online.
- **IT-7** share links carry the TOTP seed and compute the code in the browser; never-expires shares work. **IT-8** scheduled reports: staff-only recipients (re-checked at send time), write access and per-report module rights required. **IT-13 / IT-14** RMM remote logs `getIP()`; the RMM alerts client filter is scoped to the user's clients.
- **RivetCore 1.0.0-rc.7 and database step 2.6.78** (MCP identity `issuer`/`subject` to `utf8mb4_bin`), then rc.8 (above).

Deploy notes: dump the database first; make sure `$config_settings_enc_key` is in `config.php`; after the update run `php scripts/update_cli.php --rewrap_secrets` if the key was added late; set the backup passphrase (Maintenance > Backup, 16+ characters).

Installs that already ran 26.10.9's chain (the live site did not): 26.10.9 wrote 2.6.78 = security, 2.6.79 = intake, 2.6.80 = recovery, 2.6.81 = RivetCore 0017. On this release those numbers mean something else, so such a database simply continues from its number: at 2.6.81 only the 2.6.82 RivetCore runner step runs (idempotent; applies 0017 if missing); at 2.6.78, 2.6.79 or 2.6.80 the steps from that number on run again (security, intake, recovery are all idempotent: `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`, already-wrapped secrets left alone) and then the Core step. Nothing is lost or skipped. Verified on a database migrated by 26.10.9's own chain and then by this release (schema identical to a fresh import).


### Wave 1 detail (first shipped as 26.10.9)


- **Pins `rivet/rivet-core` 1.0.0-rc.8** (`^1.0.0-rc.8`; composer.json, lock and the tracked `vendor/` agree). rc.7/rc.8 bring CORE-1 (RMM enrollment is bound to the token's client: a machine identity that exists under another client creates a new pending device instead of reusing it), CORE-2, F8 (migration 0017) and F10. **Database step 2.6.82** (gated on 2.6.81, idempotent) runs the Core migration runner so `mcp_unlinked_identities.issuer/subject` become `utf8mb4_bin`; an edition applies new Core migrations through its own step, the package alone never does.
- **Mail OAuth secrets are wrapped.** `cron/mail_queue.php` reads `config_mail_oauth_client_secret`, `config_mail_oauth_refresh_token` and `config_mail_oauth_access_token` through `decryptSetting()` and stores refreshed tokens with `encryptSetting()`, so the three columns moved from `secDeferredColumns()` to `secStragglerColumns()` and the 2.6.79 step wraps them.
- **Sign-in codes are single use.** `TokenAuth6238::verifyOnce()` (ported from RivetIT): +/-1 step window, and a step that is not newer than the last accepted one for that secret is refused, so an observed code cannot be replayed. Used by `login.php` and the mobile `api/v1/auth.php`; the setup-time check in the profile still uses `verify()`. The state is a small per-secret file in the system temp directory (no schema change). Tests: `tests/totp_replay.php`, `tests/api_auth_hardening.php`, `tests/security_login_http.php`.
- **`deploy/install.sh` schedules the mail jobs.** `cron/cron.php` does not run `cron/mail_queue.php` or `cron/ticket_email_parser.php`, and the installer only scheduled `cron.php` (plus the restore drill), so a fresh install sent no mail and read no mailbox. `install_cron` now adds both every minute (as the existing installs do) with log files created up front, and `docker/supervisord.conf` runs them too.
- The Ticketing directory page no longer builds the Mailboxes tile inside the array literal (the navigation coverage test could not read it).
- Tests: `tests/wave1_integration.php` (step chain, db.sql, rc.8 pin, mail OAuth, fingerprint constant, cron, TOTP wiring) and `tests/recovery_integration.php` (the restore drill against backups made by the hardened in-app builder and by `deploy/backup.sh`, end to end).

### Wave 1 security (DB 2.6.79)

Ported from RivetIT 26.10.30, adapted to RivetMSP (clients, the RMM module, MSP's own login and session code). One idempotent database step, **2.6.79**, gated on 2.6.77: the security tables, `settings.config_backup_passphrase`, TEXT license keys, the session-length meaning, and the re-wrap of every plaintext secret (only when `$config_settings_enc_key` exists). `db.sql` matches; a fresh import and an install migrated from 2.6.76 end with identical schemas. 2.6.80 (mail intake) and 2.6.81 (recovery) follow it; see below.

**Deploy order and what to know**

- **Add `$config_settings_enc_key` to `config.php` before updating.** RivetMSP never generated one, so every "encrypted" setting (SMTP/IMAP passwords, API keys, webhook secrets, the credential-vault master key) was stored in plaintext and `encryptSetting()` silently stored cleartext. It now fails closed (it throws instead). `deploy/update.sh` adds a key to an older instance automatically; `setup` and `scripts/setup_cli.php` generate one on every new install (`--settings-enc-key` for a restore). Without a key an administrator banner says so on every admin page, the RMM module card keeps its switch disabled, and the update leaves secrets as they are. After adding it run `php scripts/update_cli.php --rewrap_secrets` (also safe to repeat). Back the key up with `config.php`: it cannot be recovered.
- **Settings cipher:** new values are AES-256-GCM (`ENC2:`); legacy `ENC:` (AES-128-CBC) and unprefixed plaintext stay readable. `EndpointSecretBox` (the RMM signing key) accepts both prefixes.
- **Mail OAuth secrets:** `settings.config_mail_oauth_client_secret`, `config_mail_oauth_refresh_token` and `config_mail_oauth_access_token` are wrapped by the 2.6.79 step like the other secrets, because `cron/mail_queue.php` (the last raw reader) now reads them through `decryptSetting()` and stores refreshed tokens with `encryptSetting()` (nothing is stored when the key is missing; the next send refreshes again). `secDeferredColumns()` is empty. No Slack bot token or whitelabel key reader exists in RivetMSP.

**Backups**

- The in-app zip had no manifest at all, so a restore onto a new server lost every secret. It now refuses to build without a **backup passphrase** (16+ characters, Maintenance > Backup) and carries `backup-manifest.json.enc` (`openssl enc -aes-256-cbc -pbkdf2 -iter 600000`) holding the settings key plus a fingerprint; the key is never written anywhere else in the zip. The `/setup` restore asks for the passphrase (or the original key, checked against the fingerprint) and applies the key. Cron and the buttons handle the refusal.
- `deploy/backup.sh`: `-iter 600000`, the settings key goes to a separate `0600` `<archive>.settings-key` file and the archive's manifest holds a fingerprint only. `deploy/restore.sh` tries 600000 iterations then the old default, reads `--settings-key-file` or the sidecar, and still restores archives made before this change.

**Accounts**

- **Staff passwords:** 12+ characters (Settings > Security > Sign-in policy), not equal to the name or email, optional Have I Been Pwned range check (off by default, fails open, URL vetted by RivetCore's `UrlPolicy`, only the 5-character SHA-1 prefix leaves the server), **Argon2id** (64 MiB, 3 passes) with a rehash at the next sign-in. A password change or administrator reset ends the user's other sessions and remember-me cookies.
- **MFA:** TOTP seeds are stored wrapped; 10 single-use recovery codes (hashed, shown once, accepted at the web and mobile sign-in, audited and e-mailed); MFA policy off / administrators / all agents with a grace period, plus the per-user flag; a required, unenrolled, out-of-grace agent can only open the account pages. MFA cannot be turned off while required. (RivetMSP has no departments: the policy is global, by role (administrators) and per user.)
- **Sessions:** the old *session lifetime* meant "minutes of inactivity" (default 480). It is now the **idle timeout** (default 8 hours; a customised old value is kept as the idle timeout by the update) and `settings.config_login_session_lifetime` is the **absolute maximum** (default 7 days, floor 60 minutes, cap 90 days). Strict session mode, a browser-session `SameSite=Strict` vault-key cookie, **Account > Security > Active sessions** with revoke and sign-out-everywhere, background polls do not extend idle, and **remember-me never replaces the second factor for an administrator or a user with vault access** (setting to allow it). The compliance "sessions are limited" check reads the absolute maximum.
- **Vault list:** `agent/credentials.php` no longer puts any username or password in the page. The eye and copy buttons ask `agent/credential_reveal.php`, which checks client access, asks for the password again after 15 idle minutes (SSO accounts exempt), limits each user (default 30 per 10 minutes, counted from the activity log so it works while the Core audit module is off), notifies the administrators and logs every reveal and copy.

**Other**

- `db.sql` no longer carries `utf8mb4_uca1400_ai_ci` on nine tables (not portable to MariaDB 10.x); `setup_cli.php` crashed on the vault key seed once the cipher failed closed and now writes the key before anything is stored.
- Tests: `tests/security_crypto.php`, `security_backup.php`, `security_passwords.php`, `security_mfa.php`, `security_sessions.php`, `security_vault_reveal.php`, `security_login_http.php` (scratch database only; `tests/support/security_lib.php`).

### Wave 1 mail intake and recovery (DB 2.6.80, 2.6.81)

### Mail intake reliability (DB 2.6.80, ported from RivetIT 2.6.152)

- **Auto-replies, out-of-office mail and loops never create tickets or replies.** The mailbox poller drops anything with `Auto-Submitted` (not `no`), `Precedence: bulk/junk/list/auto_reply`, `X-Auto-Response-Suppress: All/OOF/AutoReply`, `X-Autoreply`/`X-Autorespond`, an empty `Return-Path`, the loop header (`X-RivetMSP-Auto`, and RivetIT's `X-RivetIT-Auto`), or an auto-responder subject (English and common localised forms). They are logged as *Suppressed* in the Email Log. Delivery status notifications are recognised everywhere, not only for unknown senders (and their `message/delivery-status` fields are now actually read).
- **Per-sender hourly cap** (default 20): the excess is held as mail requests with reason *Rate limited* and one de-duplicated alert.
- **Message-ID dedupe and threading.** Inbound Message-IDs are stored on tickets, replies and mail requests; an already-imported message is skipped; replies thread by `In-Reply-To`/`References` against inbound ids and the ids RivetMSP puts on its own outbound mail, before the `[PREFIX-123]` subject token (kept as the fallback) and the fuzzy match. A sender-mismatch reply no longer adds a new mail request on every poll.
- **Poison-message quarantine.** A message that fails 3 times (setting) is moved to the `ITFlow-Quarantine` folder (or flagged and marked read in place) and listed under Admin > Mailboxes > Quarantined mail instead of being re-fetched forever.
- **Mailbox health and alerts.** Admin > Mailboxes shows last polled / last success / consecutive failures / last error and a needs-attention banner (also on the Ticketing tile). Admin notifications plus email, de-duplicated per 6 hours, for OAuth refresh failure (Google now logs the provider's reason like Microsoft), mailbox unreachable, poller silent over 15 minutes, outbound mail out of retries, and rate-limited senders.
- **Outbound mail** gets its own `Message-ID`, `Auto-Submitted: auto-generated`, `X-Auto-Response-Suppress: All` and a loop header; the mail queue reaps rows stuck in *sending* for over 10 minutes, retries with 5/15/60/240 minute back-off (five attempts, then one alert) instead of a fixed 30 minutes, and caps sending at 120 per minute (setting). `cron/mail_queue.php` now decrypts the stored SMTP and OAuth secrets (like the rest of the app) and stores refreshed OAuth tokens with `encryptSetting`.
- **Quoted history is stripped from email replies** (`On ... wrote:` and localised forms, `-----Original Message-----`, `From:/Sent:` blocks, `>` lines, Gmail/Outlook/Apple Mail HTML quotes), preferring the text part and never emptying a message; the reply-above marker still works.
- **Attachment limits:** 25 MB per file and 50 MB per message (settings), inline images over 1 MB become attachments, the ticket notes what was left out, and an optional ClamAV (`clamdscan`) scan.
- Database 2.6.80 (gated on 2.6.79, idempotent): `ticket_mail_message_id`, `ticket_reply_mail_message_id`, `mail_request_message_id`/`mail_request_reason`, mailbox health columns, `email_queue` message-id/ticket/auto/started/alerted columns, and the tables `mail_intake_state`, `mail_intake_settings`, `mail_alerts`. Runbook: `docs/MAIL_INTAKE.md`. Tests: `tests/mail_intake_parser.php` (golden `.eml`), `tests/mail_intake_db.php` (scratch database).
- Fixed: `createMailRequestFromInbound()` can no longer rename the `uploads/tmp` folder when it is given an empty `.eml` name. The `config/` ignore rule no longer risks hiding the webklex/php-imap default config (`plugins/vendor/webklex/php-imap/src/config/imap.php`).

### Recovery: backup status and alerts, restore drill, off-site copies (DB 2.6.81, ported from RivetIT 2.6.153/154)

- **Every backup run leaves a record** (`backup_runs`: kind, file, size, SHA-256, ok, error, off-site result), from the in-app builder and from `deploy/backup.sh`. A failed or interrupted run raises an in-app notification, an email (the Alert email on Admin > Backup, else every administrator) and the `backup.failed` event, once per 6 hours.
- **Stale and sync watch.** `cron/cron.php` alerts (`backup.stale`) when the newest good backup is over 26 hours old (setting) and when an RMM (Tactical, Level, Action1, Sophos Central) or UniFi sync has no run for three times its interval (`integration.sync_stale`) or errors several runs in a row (`integration.sync_failed`). The scheduled RMM sync now writes an `rmm_sync_log` row per run, like UniFi already did (a few bad devices do not count as a failed run; all devices erroring, or the vendor API failing, does).
- **Nightly restore drill** (`cron/restore_drill.php`, off until enabled on Admin > Backup > Restore drill): restores the newest backup into a scratch `drill_<date>` database with a dedicated `drill_%`-scoped DB account, checks archive and `db.sql`/`uploads.zip` checksums, schema version, table list, row counts against the new `table-snapshot.json` inside each backup (5% tolerance), a sample secret decrypt, the settings key and an uploads CRC sample, always drops the scratch database and temp files, and records the measured restore time. Handles the passphrase-encrypted key manifest, the older plain manifest, fingerprint-only manifests with a `.settings-key` file, and plain zips with no manifest. New Compliance check "A restore was proven in the last 35 days". Admin > Backup shows the recovery status, drill results and settings.
- **Off-site copy for `deploy/backup.sh`** (rclone, S3, SFTP or a mounted directory; `/etc/itflow/offsite.conf`, template `deploy/etc/offsite.conf.example`) with checksum files, size verification, retention and a never-delete-the-last-archive guard; `deploy/restore_drill.sh` drills the encrypted archives as root; `deploy/install.sh --install-backup-timers` installs the backup and drill systemd timers, and the instance cron file now runs `restore_drill.php` nightly (its log file is created up front: cron cannot create files in `/var/log`).
- Database 2.6.81 (gated on 2.6.80, idempotent): tables `backup_runs`, `restore_drill_log`, `recovery_alerts`, `recovery_settings`. Runbook with RTO/RPO template, recovery order, quarterly drill and key escrow: `docs/RECOVERY_RUNBOOK.md`. Tests: `tests/recovery_drill.php` (scratch database plus a `drill_%` account), `tests/recovery_offsite.php` (fake rclone/aws/sftp, no network).
- The events `backup.stale`, `restore_drill.failed`, `integration.sync_failed` and `integration.sync_stale` are emitted on the event bus (webhooks and event rules can subscribe by id) but are not yet in RivetCore's picker catalogue (`backup.failed` is).

## [26.10.8] RivetMSP — Optional RMM module (the built-in endpoint agent), off by default

Database migration 2.6.77 (RivetCore migrations 0014 to 0016 and `settings.config_core_rmm_enabled`). Pins `rivet/rivet-core` 1.0.0-rc.6. Nothing changes until an administrator switches the module on.

- **Add device / Download installer (easy installer flow):** Endpoints > Agent Fleet has a primary **Add device** button (also Endpoints menu > Add device, which opens the dialog, and a button in every client page's header, preselecting that client). The dialog takes the client (location optional), Windows or Linux, architecture (x64 default, ARM64), ring (stable default) and, under Advanced, token lifetime (24 h), one use or 25 ("multiple PCs") and a label; **Download installer** streams the stamped exe from `agent/post/rmm_installer.php` (POST, CSRF, `RmmAdmin::downloadInstaller()`, the same audited "Installer Created" path as the Administration card) with the next steps and the silent flag for deployment tools. The Linux tab shows the one-time install command with a copy button (`deploymentCommands()`, no binary). Shown only with `rmm.admin` / `rmm.token.manage` and the module on; without a current Windows agent the dialog says so and links to Administration > Endpoint agent > Agent binaries. That upload form is now a drop zone: both architectures at once, version and architecture read from the file names, "make current" on by default. Files: `js/rmm_installer.js`, `js/rmm_binary_upload.js`, `rivetRmmUiInstaller()` / `rivetRmmUiInstallerModal()`. Tests: `tests/rmm_installer_ui.php`, additions to `tests/browser/rmm_smoke.mjs`.
- **Asset page panel and Agent Fleet page (T10b, ported from RivetIT's T10a):** an agent-linked asset shows a status strip (actions disabled with their reason), live-health gauges from the latest check-in, alerts, checks, inventory, jobs with lazy output (`agent/rmm_job_output.php`) and in-page dialogs, and a Mesh remote card; it replaces the vendor-style card for the agent's own link. `agent/rmm_fleet.php` (Endpoints menu) shows status counts, approvals, offline devices, outdated agents (per architecture), job failures, capacity (administrators) and a filtered device list. RivetMSP keeps no metric history, so the Performance section is an explanation card. Reads go through RivetCore rc.6 read models (`deviceView()`, `job()`, `recentFailedJobs()`, `listDevices()`); module off renders nothing and asks the database nothing. The legacy device page redirects linked devices to the asset page. Styles: `css/itflow_rmm.css`, behaviour `js/rmm_panel.js` (linked only when the panel rendered). Tests: `tests/rmm_ui.php`, `tests/browser/rmm_smoke.mjs`.
- **Optional module, OFF by default:** the server side of the RivetIT endpoint agent (RivetCore RMM): enrollment, device inventory and checks, signed jobs, hosted updates, per-client installers, MeshCentral launch, technician REST API (`/api/v1/endpoint_devices`). Same URL space and wire protocol as RivetIT. Switch it on under Administration > Settings > Endpoint agent (the card sets `settings.config_core_rmm_enabled` and the module's master switch together). Needs `$config_settings_enc_key` in `config.php` (the signing key is sealed with it and is never stored in plaintext). RivetMSP has no metrics store: check-ins keep the latest values on the device and its RMM link.
- **Costs nothing while off:** the API gate (`api/v1/rmm_gate.php`) answers agents `503 module_disabled` with `Retry-After: 3600` from a state file, before `config.php` is loaded (no connection, no query); cron and pages skip the module. Switching off deletes nothing.
- **Tickets and alerts:** the module opens and resolves ordinary `rmm_alerts` rows. MSP's own RMM auto-ticketing creates the tickets; a cleared alert closes an untouched ticket through the existing conservative auto-close (`RmmAssetMapper::autoCloseAlertTicket` is now public). The agent's integration row (`type = rivetit_agent`) is excluded from the vendor RMM sync, lists and selectors.
- **Permissions:** no new keys. The nine `rmm.*` abilities use `module_rmm`, `module_rmm_scripts`, `module_rmm_remote_connect` and `role_is_admin`; client-portal contacts never hold any. The `module_rmm*` modules are not created by setup (add them under Administration > Access Modules to grant a technician role access); until then only administrators can use the module, as with the existing RMM pages. See `docs/RMM_MODULE.md`.
- **Tests:** Core's adapter conformance kit, the golden HTTP transcripts of the original agent code replayed against the MSP bridges, module switch (zero-query gate, fresh install off, real 2.6.76 to 2.6.77 updater), an end-to-end smoke (enroll, link, alert, ticket, signed job, update download, offline flip) and an optional run with a real Linux test agent.

## [Unreleased] RivetMSP — Redis authentication and TLS, API rate limit, Update checks

Database migrations 2.6.74, 2.6.75 and 2.6.76 (RivetCore migration 0013: retention indexes on audit_events, webhook_deliveries and integration_jobs).

- **Redis settings:** Administration > Redis now has an ACL username, a TLS switch, certificate verification and CA / client certificate / client key paths; the password stays encrypted and is never shown. Test connection says why it failed (password, TLS, unreachable, invalid field) with a fix. `RIVETMSP_REDIS_USERNAME`, `_TLS`, `_TLS_VERIFY`, `_TLS_CA_FILE` (and `_TLS_CERT_FILE`, `_TLS_KEY_FILE`) win over stored values, and can also be provided in `/etc/rivetmsp/redis.env`. See `docs/REDIS.md`.
- **API rate limit:** one Redis-backed limiter (RivetCore) per API key/token and per address; configurable on Administration > API Keys (default 120 requests a minute, 0 = off). Over the limit the API answers 429 with `Retry-After` and a JSON error, records one `api.rate_limited` audit event per key per minute, and fails open if Redis is down.
- **Update page:** a Checks panel (git, `.git` writable by the web user, update source, Composer files, database against code, libraries) with plain-English fixes, and Update App stops with the reason instead of failing half way. See `docs/UPDATING.md`.
- **Fixes:** a fresh install no longer shows every default saved ticket view twice (`db.sql` seeded them twice; migration 2.6.75 removes exact duplicates, keeping the lowest id, on installs made that way). The ticket list filter row no longer makes the page scroll sideways on a phone. The duplicate-name check in Add Client (and the e-mail check in Add Contact and the domain check in Add Domain) works again: inline `on*` handlers were blocked by the Content-Security-Policy and are now delegated from `js/app.js` (also the Share/Delete file menus, workflow and Redis confirmations, and the "Go Back" links). The RivetCore webhook dispatcher built by `CoreBridge::webhooks()` always vets URLs with the shared policy. Adapter conformance tests for RivetCore's kit in `tests/core/`.

## [26.10.7] RivetMSP — Webhooks and Event rules highlights, UI fixes

### Highlights: Webhooks and Event rules

- **Webhooks:** 24 ready-made platforms (n8n, Node-RED, Activepieces, Windmill, Huginn, Zapier, Make, Pipedream, IFTTT, Slack, Teams, Discord, Mattermost, Rocket.Chat, Matrix, Telegram, ntfy, Gotify, Apprise, Home Assistant, generic JSON/form/custom template), 13 payload formats, bearer/basic/custom-header/signed auth, signed timestamps with verification snippets in 5 languages, retries, a guided four-step setup with a live address check, Send test and Preview, and a Guides hub.
- **Events:** 113 events in 14 groups (96 emitted today, 17 planned) in a searchable picker with group wildcards and quick chips; the same catalog drives Event rules.
- **Event rules:** a When, If, Then builder with 3 actions (create a ticket, send a webhook, notify technicians), 10 recipes, a no-side-effects test drawer and run history.

### Fixes

- Webhook wizard: the Continue button is now the same size as Back; recipe cards no longer show the link underline.

## [26.10.6] RivetMSP — Event rules builder, webhook guides and creation redesign, icon fixes

No database migration. Requires rivet-core 0.21.0 (already in `vendor/`).

### Event rules

- **A "When, If, Then" rule builder.** The list shows each rule as a card with an instant on/off switch, a plain-English summary ("When a ticket is created, and priority is Critical, then notify technicians"), the event group, the action, when it last fired and how many runs. Rows have Edit, Test, History, Duplicate and Delete; the page has search, filters and sort, a summary strip and an empty state with ten ready-made recipes.
- **Editor.** Four numbered cards (When, If, Then, Settings) with a live summary beside them: the searchable event picker with the event's fields shown, condition rows with smart value lists, action cards with clickable `{placeholder}` chips and a rendered preview, and inline validation that keeps what you typed.
- **Test drawer** (no side effects: nothing is created, sent or queued) shows whether the conditions match and what would run, on a sample event or a recent real one. **History drawer** lists each run from the audit trail.
- This edition's engine is simpler than RivetIT's: conditions are "field is value" only, there are no nested groups, priorities or rate limits, and Notify reaches every technician.

### Webhooks

- **Guides** is now a documentation hub: platform sidebar with search and category filters, an index of all 24 platforms, numbered steppers with remembered ticks, callouts, highlighted code blocks with copy buttons, language tabs for verifying our signature, an example payload per platform, a troubleshooting table and previous/next links.
- **Creating a webhook is a four-step flow** (Platform, Connect, Events, Review & test) with a progress bar, a sticky Back/Continue footer and a link per step. Only the fields a platform needs are shown; the rest sit under "Advanced options". The address is checked live as you type (no request is sent), quick event chips (Tickets, Critical only, SLA problems, Security...) sit above the picker, and Review shows a masked summary, a payload preview and an inline Send test with a plain-English hint for common failures. The setup guide opens in a slide-over.
- **Editing** uses tabs (Connection, Events, Payload & advanced, Deliveries) with an enable switch, Duplicate and Delete. The list shows the last delivery result and an inline switch.

### Fixes

- Icons that Font Awesome 5.15 does not have (for example the Job queue icon) are replaced; `tests/fa_icons.php` guards it.

## [26.10.5] RivetMSP — webhook platforms and event picker, internal-network webhooks, shared date-range picker, icon picker, RivetCore 0.21

Database migrations 2.6.70 to 2.6.73 apply with **Update Database**. Requires rivet-core 0.21.0 (already in `vendor/`).

### Date ranges

- **One date-range picker on every filtered page**: grouped presets (Today, Yesterday, Last 7/14/30/90 days, this and last week, month, quarter, year, last 12 months, upcoming) and a two-month calendar for custom ranges. Existing `canned_date` / `dtf` / `dtt` links keep working.
- **Service Desk:** the date control is in the main filter row of the ticket list and kanban, with a **Date field** choice (Created, Updated, Resolved, Closed, Due, SLA resolution due). Saved ticket views keep rolling presets such as "Last 7 days"; older saved views with fixed dates still work.

### Icon picker

- Saved ticket views, tags and custom links choose their icon from a searchable catalog (621 icons in 14 categories, with a custom-class field) instead of typing a Font Awesome class.

### Webhooks to internal networks

- Administration > Webhooks > **Internal network access**: webhooks may reach private addresses only inside networks an admin lists (loopback, link-local and cloud-metadata addresses are never allowed). "Use this server's network" suggests the server's own subnet. Migration 2.6.72.

### Webhook platforms (RivetCore 0.21.0, migration 2.6.73)

- **Administration > Webhooks has a guided Add flow.** Pick one of 24 platforms (n8n, Node-RED, Activepieces, Windmill, Huginn, Zapier, Make, Pipedream, IFTTT, Home Assistant, Apprise, ntfy, Gotify, Discord, Mattermost, Rocket.Chat, Slack, Teams, Matrix, Telegram, Generic JSON/form, Custom template) from a searchable card grid; the form is built from the platform (address shape, allowed authentication, extra fields such as the ntfy topic or Telegram chat id, body format) with its setup guide beside it. Everything is validated with RivetCore; the address, signing secret and outgoing authentication are stored encrypted and never shown again (blank keeps them on edit).
- **Send test** and **Preview payload** (in the form and per webhook): a sample event goes through the real delivery path; the preview shows the exact body and headers with secrets hidden. Deliveries have a **Payload** view (secret-looking fields hidden).
- **Guides** page: every platform's guide, signature-verification snippets (Node, Python, PHP, Bash, n8n) with copy buttons and a "Receiving in n8n" walk-through.
- **Searchable events picker** (also on Event rules): search, expandable groups, descriptions, per-group select-all, chips. Subscriptions may be patterns such as `ticket.*` or `*`.
- Database: `webhooks` gains destination, format, method, template, auth mode/auth (encrypted) and extra columns; the URL and event list columns are widened. Existing webhooks are backfilled as Generic JSON and deliver exactly as before.

## [26.10.4] RivetMSP — same shell as RivetIT, RivetCore 0.17.1, Update fixes, installer hardening

### Installer and updater

- `deploy/install.sh` runs the database update loop after setup or restore and stops with a clear message when RivetCore cannot be downloaded (the committed `vendor/` copy is used if present). `deploy/update.sh` installs dependencies before running migrations, as RivetIT does.
- A single database update now applies every pending migration (it used to apply one step per run).
- Update App resets Composer's generated `vendor/composer` files first, so git no longer refuses to switch branch or pull.

### RivetCore 0.17.1

- Updates the shared library to RivetCore 0.17.1 (job heartbeat, webhook signed timestamp, PSR-3 logging, audit reader, retention horizons). **Migration 2.6.69** adds `integration_jobs.heartbeat_at` through Core's own migration runner. The Update page now shows the RivetCore version; the library updates with the app (the pin is in `composer.json`/`composer.lock`), no separate button is needed.

### Same shell as RivetIT

- Administration now uses RivetIT's layout: consolidated sidebar (Access, Configuration), a Settings directory with search, and Maintenance, Ticketing, Templates and Tags & Categories areas with breadcrumbs. Every existing admin page is still reachable (checked by `tests/nav_coverage.py`).
- Top bar, footer, page titles, toasts and shell JS/CSS aligned with RivetIT; accessibility labels added.

## [26.10.3] RivetMSP — Core parity: event bus, Redis, Problems & Changes, Workflows, Cron Manager, KB import, Audit trail

### Problems, Changes and client Onboarding/Offboarding workflows (rivet-core ITSM + Workflow modules)

- **Problems and Changes** (Service Desk): root-cause problems and lightweight change records with their status flows, linking tickets to problems (from the problem page or a new Problem card on the ticket page) and a change to the problem it fixes. Searchable from global search. Audit events: `problem.*`, `change.*`.
- **Onboarding & Offboarding workflows** (Organization): templates managed under Administration > Templates > Client Workflows (ordered tasks, optional tasks), runs started for a whole client or for one of its contacts, ticked off / skipped / reopened task by task, with audit events `workflow.onboarding_started`, `workflow.offboarding_started`, `workflow.completed`, `workflow.cancelled` and a `workflow.task_completed` event for webhooks and event rules.
- Both are behind their Core module switches (`core.itsm.enabled`, `core.workflow.enabled`); with a switch off the pages say so and nothing is touched.
- Database 2.6.68: `workflow_runs.client_id` (backfilled from the contact on existing runs).

## [26.10.2] RivetMSP — fix: blank Update page before the database update

Fixes a blank **Administration > Update** page on a server that had pulled 26.10.1 but not yet run **Update Database**: the new release-channel setting did not exist yet and its lookup raised an error. The page now renders, shows the channel from the checked-out branch, and saving the channel before the database update says to run Update Database first. No database change.

## [26.10.1] RivetMSP — Release channel (Production / Beta), with everything in 26.10

This is the first tagged release since the 26.10 work below. Apply database migrations 2.6.58 to 2.6.65 with **Update Database** (take a backup first); it requires rivet-core 0.14.0 (already bundled in `vendor/`).

### Release channel: Production or Beta

- **Administration > Update has a Release channel switch.** Production follows the `master` branch (tested releases); Beta follows `beta` (early access, changes more often). Each server chooses its own. The update check, **Update App**, the force update and `scripts/update_cli.php` (and so `deploy/update.sh`) all follow the chosen channel and move the server onto its branch. This replaces the old fixed `$repo_branch` value in `config.php`, which a server could be left pointing at a retired branch.
- **A server never goes backwards.** Switching to a channel whose latest release is older than the code already running is refused with an explanation, because the database may already be newer than that code can read. A switch that would overwrite hand-edited files is refused and leaves them untouched.
- Existing servers keep following the branch they are on today (a server on `beta` starts as Beta, everything else as Production), so nothing moves by itself. Database migration 2.6.65 adds the setting; a fresh install gets it from `db.sql`.


## [26.10] RivetMSP — SLA pause, resolution time rules, compliance status, saved-view filters and RivetCore

Consolidates the entries that were listed as Unreleased after 26.05. Apply database migrations with **Update Database**.

### Customisable ticket view filters

- **Edit the filters a saved view uses.** The view's Edit dialog (and Save Current View) now has a filter form instead of a fixed query: show Open, Closed or All tickets or pick specific statuses (new statuses you create appear automatically), assigned to anyone / me / unassigned / a named agent, priority, on-site or remote, overdue or due today, board, category and tags. The dialog also describes in plain words what the view currently does. Renaming a view without touching the form leaves its filters alone.
- Only validated choices are stored (unknown ids and junk values are dropped), a view with several statuses or tags now highlights correctly when active, and shared views can still only be edited by those allowed to. No database change.

### SLA no longer breaches while waiting on someone

- **Waiting statuses pause the SLA clock.** A ticket set to Waiting on Customer, Employee or Vendor (or On Hold) is no longer shown as breached because of the wait. While paused the clock is frozen: it never drifts into "Breached", the time spent paused is added back to the due date when the ticket moves on, and a ticket that was already past due before it was paused stays breached.
- **You choose which statuses pause it.** Administration > Ticket Statuses has a new **Pauses the SLA clock** option on every status, in the create dialog and the edit dialog, and an SLA clock column in the list. A status created later can opt in the same way, so the setting follows your own statuses. Saving a status updates the open tickets already in it. An SLA policy's own pause list still works and adds to this.
- **Every way of changing status is covered.** Agent status change, kanban drag, the API (including agent replies that move a ticket to Waiting on Customer), automation rules, and a customer reply that ends the wait all start or stop the pause. Anything that slips through is repaired when the ticket is opened and by the scheduled job. Ticket list, dashboard, ticket page and escalation rules all agree.
- Contract-hour SLAs (no policy) now also add paused time back. Database migration 2.6.64 adds the option (fresh installs get it from `db.sql`) and turns it on for On Hold and every status starting with "Waiting", then pauses open tickets already waiting.

### Resolution time rules

- **Only resolved tickets count toward average resolution time.** An open ticket, one in an "Unresolved" status, or one that was reopened and not yet resolved again is left out of every average (dashboard tile, ticket reports, per-technician figures and the API).
- **Reopening restarts the clock.** The time to resolve a reopened ticket is measured from the moment it was reopened to when it is resolved again, not from the original creation date. Creation dates are never changed. Every way of reopening is covered: agent status change, kanban, the department portal and guest link, the CSAT low-rating auto-reopen, automation and scheduled reopen, and the API. Moving or re-saving a ticket that is already open does not reset anything.
- Resolution time now ends at the resolved date (older tickets closed without one use the close date), instead of the close date, so a ticket that sits resolved before auto-closing is not penalised. SLA targets and SLA breach tracking are unchanged.
- Database migration 2.6.63 adds `ticket_resolution_started_at`; a fresh install gets it from `db.sql`. Existing tickets keep a blank start, so their time still runs from creation.

### Compliance status

- **Administration > Compliance status.** A live view of how this installation measures up against common security controls, tagged to ISO/IEC 27001, SOC 2, PCI DSS and HIPAA, with a score per framework. Automatic checks cover multi-factor authentication (agents and administrators), administrator count, session lifetime, HTTPS-only, vault key, backups, API-key expiry, dormant agents, schema currency, audit recording and retention against the chosen preset.
- **Manual checklist with sign-off.** Thirteen things software cannot see (policy review, risk assessment, access review, restore test, incident-response test and so on). Record who reviewed, when, the next due date and a note; items show as current, due soon or overdue. Every review is kept (append-only).
- **Snapshots and auditor export.** Save a snapshot at any time (one is also saved automatically each month, and kept). Download a CSV or a printable report for the whole position or a single framework, from the live view or from any snapshot. Saving, recording and exporting are written to the audit trail.
- **Share a report on the portal.** On a saved snapshot, choose Share to publish it, with an optional message, for every client who can sign in to the portal. They see it under a Security menu item: framework scores, the title and result of each check, and the status and last-review date of each checklist item. Counts, account names, reviewer names, notes and settings values are never shared. Nothing is shown until an administrator publishes, a newer snapshot does not replace what is shared, and Stop sharing removes it. Publishing and unpublishing are audited. Database migration 2.6.60 adds the table; rivet-core 0.10.0.
- **NIST SP 800-171 Rev 2 / CMMC Level 2** is a fifth framework: every check and checklist item that maps to it carries requirement numbers (indicative; CMMC Level 2 corresponds to the 110 requirements). Included in migration 2.6.61. rivet-core 0.12.0.
- **Compliance for customers.** Each client gets a Compliance tab: choose the standards that apply to them (ISO 27001, SOC 2, PCI DSS, HIPAA, NIST 800-171/CMMC), work through a 23-item checklist with sign-off, save snapshots, export CSV or a printable report, and share a snapshot with the client's portal users under Security (reduced view: scores and each item's title, status and last-review date; never reviewer names, notes or evidence). Clients only ever see their own report. An overview page (Organization > Client compliance) lists every client's score and overdue items. Migration 2.6.61 adds `subject_id` to the compliance tables and a `compliance_subjects` table.
- **NIST 800-171 / CMMC retention preset** (Settings > Compliance): a 365-day minimum. 800-171 requires audit logs to be retained but sets no number, so this is the common choice to define in your policy. rivet-core 0.14.0.
- Fixed: the "Application and database are up to date" check showed "Version information is unavailable" because the page does not load the version file; it now reads it itself.
- Security hardening: the ajax modal builds its shell with DOM APIs instead of HTML strings, the bulk-action link rewrite accepts only a plain same-origin path, the bundled Markdown converter escapes backslashes in links, and the plugins' symfony/http-foundation is updated to 7.4.x (advisory fix).
- Database migration 2.6.62 adds the responsibilities table (used by RivetIT; harmless here).
- This is a self-assessment aid, not a certification or an audit opinion; control references are indicative and should be confirmed against the current text of each standard.
- Database migration 2.6.59 adds the two tables (created by rivet-core's own migration runner); a fresh install gets them from `db.sql`. Upgraded to rivet-core 0.9.0.

### Compliance settings

- **Settings > Compliance.** Pick a retention preset (ISO/IEC 27001, SOC 2, PCI DSS or HIPAA) and set how long the audit trail and the activity logs are kept. A preset is a **minimum**: records are never deleted younger than it (365 days, or 6 years for HIPAA), even if a number is set lower, and the hourly cleanup enforces it, not just the form. 0 keeps records forever and is always allowed. A preset helps meet a retention requirement; it does not make an organization compliant on its own.
- **Audit recording switch.** The page also holds the switch for the audit trail; any preset keeps it on. Saving the page is recorded in the audit trail with the before and after values, including the change that turns auditing off.
- The audit trail has its own retention, separate from the activity logs, webhook delivery log and finished background jobs (default 365 days, which keeps more than before). Security > Log retention honours a preset's minimum too.
- Database migration 2.6.58 adds the two settings; a fresh install gets them from `db.sql`. Upgraded to rivet-core 0.8.0.

### security follow-ups, log retention, complete fresh installs

- **Request ids are server-assigned.** The audit trail used to store whatever `X-Request-ID` header a client sent; it now always records a server-generated id.
- **Log retention.** The nightly-ish `cron/cron.php` now also prunes RivetCore's tables (audit trail, webhook delivery log, finished integration jobs) at the existing "log retention" horizon, and a retention of 0 now means **keep everything**: before this, 0 deleted every log older than today.
- **A fresh install is now complete.** `db.sql` was a very old snapshot and was missing 39 tables that migrations create, plus their default SLA policy and saved views, so a fresh install lacked mailboxes, push endpoints and more. It is regenerated from a clean install plus every migration, and a fresh import is identical to an upgraded install. Migration 2.6.57 also adds `ticket_schedules` and `ticket_techs` (appointments and Outlook sync used them, but no migration ever created them; they only existed on servers where they had been added by hand, so this is a no-op there).
- Upgraded to rivet-core 0.7.1, which fixes a crash on a job's fifth failed attempt.

### RivetCore foundation (off by default)

RivetMSP now has a `src/` directory with PSR-4 autoloading (`RivetMSP\`) and consumes **RivetCore**
(`rivet/rivet-core`, a tagged shared package also used by RivetIT). First module: the structured audit trail. Login
events (success, failed, blocked, MFA failed) can be written to a new append-only `audit_events` table, **only when
`settings.config_core_audit_enabled = 1`; it defaults to 0**, and a Composer update never turns it on. It cannot break
sign-in: any failure is swallowed. Database migration 2.6.55 adds the flag and runs RivetCore's own migration runner
(state in `rivet_core_migrations`, independent of this database version), and is skipped, to retry later, if the package
is not installed yet. Storage goes through a Core-owned `DatabaseInterface`; RivetMSP's implementation reuses the
existing mysqli connection. Tests are in `tests/core/`. MySQL/MariaDB is unchanged.

More RivetCore modules are wired, every one **off by default** with its own switch in `settings` (`config_core_<module>_enabled`):
Redis (locks, rate limiter, cron guard), Jobs (queue), ITSM (problems and changes), Webhooks (synchronous delivery with signed bodies;
the `X-ITFlow-*` and `X-RivetMSP-*` headers), Workflow, Automation (rule matching) and a health endpoint (`health/live.php` always on,
`health/ready.php` returns 404 until its switch is on). Nothing calls them yet, and a Composer update never turns one on. Migration 2.6.56
adds the switches, RivetCore's tables (created by Core's own runner) and one additive nullable column, `tickets.ticket_problem_id`, which links
incidents to problems. `db.sql` carries the same objects, and a fresh install and an upgraded install were compared table by table and are identical.
Remote MCP is not wired here: it needs agent single sign-on columns RivetMSP does not have yet.

## [26.05] Stable Release
### Bug Fixes
- Stripe Payment: Fix adding saved cards on client portal.
- Various client and module enforments fixes. 
- Projects: Fix slow load by using an optimized query to count tickets and tasks.
- Show correct currency for the account balance when adding payment to invoice.
- Expire all Password reset tokens nightly with cron.
- Shared Items via secure link: Do not delete shared items that have not been viewed before cron runs.
- Client: Fix Client Abbreviation being converted to an int on edit.

### New Features & Updates 
- Bump TinyMCE from 8.4.0 to 8.5.0.
- Bump TCPDF from 6.11.2 to 6.11.3.
- DeBump stripe-php from 20.0.0 to 19.4.1.

## [26.04] Stable Release
### Bug Fixes
- Racks: Fix Device Removal.
- Table Lists: replace class table-responsive-sm with just table-reponsive was causing ui issues with certain screen sizes.
- Client: Fix Edit erroring on certain characters.
- Category: Fix Add/Edit due to missing CSRF fields.
- Category: Fix Restore function and Icon and text color.
- Invoice: Do not apply late fee on first overdue reminder (1 day).
- Ticket: Fix issue with contact not being added with Add contact modal v1.
- Quote: Fix Copy was missing client.
- API: Don't set client ID from POST - this is properly done via require_post_method instead only if it's an all-clients key.
- API: Prevent error 500s when existing data can't be cleanly re-inserted to database.
- API: Add more helpful errors.
- API: Fix asset read uri_2 field.
- API: Various other field fixes.

### New Features & Updates 
- Categories: Add Description Field.
- Categories: Add DB Field for order.
- Categories: Move Asset Status and Network Interface Type to categories so custom ones can be created and edited.
- Categories: Moved note type, software type, rack type to be creatable/editable Categories with common defaults and descriptions
- Files: Allow .swb file for MikroTik Backup Files.
- Software: Added additonal License Types including Perpetual, Site, etc.
- API: Invoice Items: Add read endpoint.
- Networks: Added Import.
- Bump TinyMCE from 8.3.2 to 8.4.0.
- Bump stripe-php from 19.4.1 to 20.0.0.

## [26.03] Stable Release
### Bug Fixes
- Ticket Templates: Fix Task Sortinhahahg.
- Ticket: Lower autoclose setting minimum value from 48 to 24 Hours.
- Ticket: Fix Task Approval.
- Recurring Ticket: add empty value placeholder for Ticket Frequency.
- Documents/Files: Fix redirect after File Upload to redirect to files instead of the non existent documents.
- Setup: Fix base url tacking on /setup when not installing via script.

### New Features & Updates 
- Clients: Net Terms: Added common 45 and 15 Days, removed 14 Days not as common.
- Clients: Bulk Action Set Net Terms Added.
- Clients: Swapped location and contact column, add PopOver with Details such as created, abbreviation, DB ID instead of taking up space underneath client, rounded tag pills and increased padding, removed info badges and added one info badge that displays a popover with details.
- Clients: Added New Ticket to Client Top Header Menu.
- Clients: Client Overview: UI Sprucing.
- Invoice: Send reminder 1 day after due date.
- Invoices/Quotes/Recurring Invoices: Split Items tables into their own POST logic and Modal UIs and tables (quote_items, invoice_items, recurring_items).
- Tickets: New Ticket Parsing - Anyone CC'ed onto the original email that created the ticket is added as a ticket watcher.
- Ticket/Quotes: Quotes can now be associated with a ticket.
- Networks: Removed Subnet Mask Field, Use CIDR instead.
- Networks: Rearranged fields, Updated placeholders, Add/Edit/list for better flow.
- Networks: Renamed DHCP to IP Range to allow for you use of both DHCP and or Usable IPs.
- Assets: Rearranged fields, Updated placeholders, Add/Edit/list for better flow.
- Assets: Added IPv6 if available under IP, Make and Model are now one line with Serial Underneath. Added OS under Type. use pill for status.
- Calendar: Event thats are cut off can now be viewed as a tooltip on hover.
- Calendar: Renamed System Calendars to built-in calendars and added the names and color dot for reference.
- Calendar: You can now delete a custom calendar.
- Report: Client Ticket Time Detail Audit: Selectable Billing Time Increment, will later be avauilable globally.
- Roles/Permissions: Now complete and is out of beta all permission roles are strictly enforced, except for in Trips and Calendar, new enforce modules will be added for these at a later date.
- Project Templates: Ticket Template order can now be dragged and dropped.
- Global: Introduced new checkbox class to all Checkbox select columns to keep consistency and reduce space and enhance ui.
- Global: CSRF Checks everywhere instead of just deletion calls.
- Global: Renamed the rest of the unarchive post and label calls to restore.
- Files: Allow upload of .unifi extension.
- Bump Libraries:
  - stripe-php from 19.0.0 to 19.4.1.
  - fullcalendar from 6.1.19 to 6.1.20
  - TCPDF from 6.10.1 to 6.11.2

## [26.02.1] Maint Release
### Bug Fixes
- Credentials: Fix Password Generator.
- Calendar: Restrict Events for client restricted agents. 
- Ticket Merge: Fix.
- Asset Transfer: Fix.
- Ticket Listing: Restrict Tickets presented in ticket list view from client restricted agents.
- Ticket Details: Deny access to client restricted agents to view tickets without client_id in uri.
- Tickets: Allow agents with restricted client access to view and edit tickets without a client.
- Ticket Change client: Limit selection for agents with restricted client access.
- Ticket Details: Don't display updated at when null.

### New Features & Updates 
- Report: Added Client Detail Auditing.
- API: Added Endpoint to retrieve time worked by agent.
- ajax-modal: Revert to previous JS implementation before 26.02 release.
- Ticket: Move Subject from Ticket main ticket header to ticket details card header.

## [26.02] Stable Release
### Bug Fixes
- Mail Parser - Do not automatically send new ticket notifications to noreply/donotreply addresses.
- Ticket: removed newline \n on Parsed emails.
- Show Trips for everyone if accounting module is enabled.
- Fix Invoice Exporting.
- Fix Billable Column not sorting correctly in tickets.
- Fix Login flow where user agent and client user exists and agent has MFA but will not let them continue.
- Fix passing missing user_id var in client portal.
- Fix Ticket Templates not auto filling when selected.
- Fix Invoices not being sent to all billings contacts when manaully sent.
- Fix Documents and Files not able to be bulk deleted.
- Fix Role Archiving, can be archived as long as no users are assigned to the role.
- Fix showing Powered By ITFlow visibility on the login screen when Whitelabel is enabled.
- Missing username in audit log on successful login due to missing passed user_id to logging.
- API: Fix updating all documents instead of the intended document.
- Documents: Fix Document created at not showing the correct creation date of the master document.
- Ticket: Fixed Using edit ticket modal agent was not able to be set.
- Always check if a user is archived and or disabled instead of just during login.
- Report: Fix Collected tax report not totalling all tax categories.

### New Features & Updates 
- Task Approval System for ticket tasks: Once an approval is requested, the task cannot be marked as complete until approved. Internal Approvals Any other technician, or Specific technician, Client Approvals Anyone (usually the requestor) Tech contacts Billing contacts.
- Printable Invoice Packing Slips now available.
- Drastic Performance Bump: Up to 50% faster queries accross the board and reduced server memory usage by 40% by switching Database Query method from mysqli_fetch_array to mysqli_fetch_assoc.
- Added Connect to Microsoft 365 Button to mail settings.
- OAUTH2 support for Microsoft 365 and Google Workspaces is now considered stable and working.
- Favorites: Assets and Credentials now can be favorited singly or by Bulk action. Favorited items appear in the client overview now.
- Files/Documents: Collapsable folders feature, collapsed by default with a button to expand all.
- URL Keys and such are now set to a more manageable 32 Characters by default.
- Various UI/UX Updates throughout the app, with focus oin ticket details, contact details modal etc.
- Added Show Archived files and documents to the files section.
- Added Bulk Archive and restore options to files and documents.
- Rewrite of the Kanban Ticket view to match our procedural style of coding.
- All options are available in TinyMCE now in Mobile mode.
- Agent names appear now in Invoice History section.
- Mail Parser: Support flowed text.
- Assets: Keep Purchase reference when copying.
- Assets: Add basic tracking history: Archiving, restoring, name changes, transferimg to new clients.
- Mail Parser: NDR Parsing.
- Allow SVG files in mail attachments.
- Tickets: Use a more friendly time worked instead of 02:41:00 translates to 2h 41m.
- Update wording on ticket to invoice item details.
- Merge Tickets: Now wth a ticket merge dropdown list of tickets instead of a text field.
- Role Permissions can now be set during role creation, update Permission UI to use radio buttons instead of select boxes.
- Bump TinyMCE 8.2.2 to 8.3.2.
- Bump PHPMailer from 7.0.1 to 7.0.2.
- Bump Datatables from 2.3.4 to 2.3.7.

## [25.12.1] Maint Release

### Major Changes
- Unified the Client/Agent Login and process (Note only Client Users can Reset passwords from the login page, does not apply to agent users).

### Bug Fixes
- Fix Payment Provider not adding an account.
- Fix New ticket button in contact details in the related tickets section.

### New Features & Updates
- You can now Set Payment Provider income/expense account, expense vendor and expense category upond creation or editing.
- Moved Saved Payment Provider Methods away from admin side nav to the count link within Payment Providers page.
- Moved AI Models from the admin side nav to the model count link within AI Providers.
- Add Favicon Reset.

## [25.12] Stable Release

### Breaking Changes ###
- For Existing installs: **php-xml** extension needs to be installed for document creation and editing, new install script does this for you as of Dec 6th 2025. To install php-xml: `sudo apt install php-xml`

### Major Changes
- Consolidated "Files" and "Documents" into a single section called **Files**.

### Bug Fixes
- Resolved issue with updating asset notes in asset details.
- Fixed problem with bulk ticket merging.
- Corrected issue where decimal inputs (e.g., price, cost) weren’t displaying on iPhones in certain forms.
- Added CSV escaping to the sample export data in areas where a sample CSV template is provided.
- Fix a race condition where dupe tickets, invoices, recurring invoices, recurring tickets, quotes will be created using the same number if created in parallel espcecially when using the API.

### New Features & Updates
- Introduced automatic subject-based ticket merging/reply detection. Now, if an email comes from a known contact or domain and the subject matches 95% of a ticket opened in the last 7 days, it will be merged automatically.
- Added `cleanInput` function to sanitize data before inserting it into the database when using MySQLi prepared statements.
- Migrated client post functionality to use MySQLi prepared statements.
- Updated payment method post functionality to use MySQLi prepared statements.
- Implemented `saveBase64Images()` to convert base64-encoded `<img>` tags into actual image files stored under `/uploads/<module>/<id>/` with secure filenames. Added wrapper functions, and updated document creation to use processed image paths.
- For new documents and document templates, images are now stored in `/uploads/documents/$document_id` instead of being stored as base64 in the database, using the `saveBase64Images()` function.
- UI/UX improvements made to the document details page.
- Removed sidebar quick-add options.
- Created new folders in the uploads directory: `documents`, `document_templates`, and `recurring_tickets`.
- Reworked the bulk action function to pass the name arrays, instead of a generic `selected_ids` array. This allows multiple bulk name arrays to be passed at once, currently used for the new file-document merge.
- Big task: Converted the remaining modals to use the new `ajax-modal` system, enabling more flexible flow expansion going forward.
- Mail queue: Added a `--no-mx-validation` flag to bypass recipient domain MX validation.
- Bump PHPMailer from 7.0.0 to 7.0.1.
- Bump stripe-php from 18.1.0 to 19.0.0.
- Bump TCPDF from 6.10.0 to 6.10.1.
- Bump TinyMCE from 8.2.0 to 8.2.2.

## [25.11.1] Maint Release

### Fixes
- Fix broken edit Payment Method.
- Fix unable to delete Vendor Template.
- Fix Mail Queue link in flash alert for testing email and sending a quote.
- Add Show Category Type select if not defined.
- Add Show Product Type select if not defined.
- Fix add ticket watcher.
- Fix if Client isn't assigned to a ticket dont show client view.
- Fix missing session client id check when paying an invoice from client portal.
- Update Composer Webklex-IMAP library dependency symfony/http-foundation from 7.3.3 to 7.3.7 to fix security related issues.
- Add back delete Payment provider the database will handle cascade deletes to saved cards, recurring payments and client payment provider reference.
- Don't show Client Tickets Breadcrumb if no client is assigned to a ticket.
- Don't Show Contact or Assignment Tab in edit ticket if no Client is Assigned.
- Don't Show add contact, asset, vendor, watcher if not client is assigned to a ticket.
- Don't Show Public Comment & Email if contact email doesn't exist.
- Fixed IMAP Test whicn now uses RAW TCP Connection instead of the depracated php-imap extension.
- Fix Broken Link in Ticket Updates via Client Portal to agent.

### Added / Changed
- [Feature] Added Asset Tags.
- [Feature] Added Quick Add Links to most side bar navs example quickly add a client from sidebar.
- Migrate ticket template add to ajax modal.
- Add TOTP secret to Client Export PDF in Credential section.
- Add UserID on hover in users listing.
- Merge ticket now redirects to the new ticket details page.
- [Feature] Add Pay via saved card under invoice Listings.
- Ticket Related Side Items UI Cleanup to use btn-tool class. 

## [25.11] Stable

### Deprecation Notice:
- **Outdated CRON Scripts**: The following scripts are removed.
  - `/scripts/cron_mail_queue.php`
  - `/scripts/cron_ticket_email_parser.php`
  - `/scripts/cron.php`
  - `/scripts/cron_domain_refresher.php`
  - `/scripts/cron_certificate_refresher.php`
  
  **Action Required**: Transition to the new versions:
  - `/cron/mail_queue.php`
  - `/cron/ticket_email_parser.php`
  - `/cron/cron.php`
  - `/cron/domain_refresher.php`
  - `/cron/certificate_refresher.php`

- PHP Extensions php-imap and php-mime-mail-parser are no longer required.
---

### Fixes
- **Ticket Listing**: Resolved issue where the “Check All” checkbox was visible even when ticket status wasn’t set. Now hidden for closed tickets only.
- **Timer Auto-Start**: Show H/M/S placeholders when timer auto-start is disabled.
- **Ticket Guest URL**: Fixed email not including the ticket guest URL key.
- **EML Generation**: Resolved issue with EML not being generated in the new ticket parser.
- **New Ticket Mail Notification**: Included message when notifying the tech of a reply in the new ticket mail parser.
- **Advanced Filter Collapse**: Added clause to prevent collapse of advanced filters when the “from” date is set to the default (1970-01-01).
- **Recurring Invoice**: Fixed issue where email was marked as sent but not actually sent when forcing a recurring invoice to an invoice.
- **CSRF Token**: Fixed issue with deleting recurring ticket from asset details page due to missing CSRF check token.
- **Vendor Website Link**: Fixed missing `https://` prefix in the vendor website link on the vendor details modal.
- **Agent Select Box**: Resolved issue where agents sometimes didn’t appear in the agent select boxes.
- **TinyMCE**: Fixed TinyMCE editor issue on Bulk Create Ticket in Assets.
- **Ticket Timer**: Fixed ticket timer initialization after reload and when the tab is put to sleep (background tab).
- **Client Deletion**: Fixed issue with client deletion.
- **Domain Records**: Added flag for missing SOA record when adding a domain (prevents subdomain creation).
- **Domain Fetching**: Quits domain record fetching if no SOA record exists (prevents subdomains).
- **Domain Expiry**: Only show time to expiry when there’s an expiry date set; otherwise, display a dash.
- **Certificates**: Improved handling of empty date in the agent UI.
- **Certificates API**: Fixed bug with missing JS to fetch certificate details.
- **API Updates**:
  - Clients API: Added support for archiving/un-archiving clients, updating client data, and abbreviation support.
  - Contacts API: Added archiving/un-archiving and restriction to only allow one primary contact per client.
  - Mail Queue: Added recipient domain MX validation before sending emails.

---

### Added / Changed
- **Backup / Restore**: Improved backup and restore by streaming data to disk (to prevent memory issues), setting unlimited timeouts, checking for bad backup contents, and using PHP for DB import instead of shell exec. Added `.htaccess` to prevent PHP execution in `/uploads/` directory.
- **Ajax Modals**: Migrated all Add and Bulk modals to the new Ajax Modal for improved performance.
- **Recurring Ticket Sorting**: Default sorting of recurring tickets by `RunDate` instead of subject.
- **Recurring Ticket Enhancements**:
  - Added Billable column.
  - Added bulk actions for setting priority, agent, billable status, and next run dates.
  - Added filters for category, assigned agent, and billable status.
  - Added new frequency options: 3-day and biweekly.
- **Asset Select**: Updated asset select dropdown to separate asset types using opt groups (planned for wider use).
- **Expiring Domains & Certificates**: Added "30 Day" warning for expiring domains and certificates in the dashboard.
- **Ticket Search**: Allowed search using both ticket prefix and number.
- **Recurring Invoice**: Cancel recurring invoices when the associated client is archived.
- **Credentials Import/Export**: Now includes TOTP secrets when importing/exporting credentials.
- **Asset Notes Import**: Allowed importing of asset notes.
- **Ticket View**: Added a "View HTML Code" button in all ticket views for TinyMCE.
- **Date Range Picker**: Updated all date filters to use the improved DateRangePicker JS.
- **Bulk Ticket Creation**: Added bulk ticket creation for clients.
- **Sidebar Updates**: Updated all sidebars to use absolute paths for easier integration with custom code.
- **Document Actions**: Added Archive and Delete buttons to the Document Details view with improved redirect behavior.
- **Ticket Template Sorting**: Allowed sorting by task count in ticket templates.
- **Contact Modal UI**: Updated contact details modal to display contact information at the top.
- **API & Code Updates**: 
  - Separated out post files for recurring tickets, invoices, expenses, and payments.
  - Removed unused budget code.
- **Invoice Product Autocomplete**: Now allows searching for product codes as well as names.
- **Client Duplicate Check**: Flags duplicate clients or leads when using the client add modal.
- **Recurring Invoice Reference**: Added a column to invoices indicating if they were created from a recurring invoice.
- **Global Search Enhancements**: 
  - Allowed ticket details to be searchable in global search.
  - Allowed searching for quotes in global search.
- **UI/UX Improvements**:
  - Spruced up the ticket details page UI.
  - Added contact email validation to flag duplicates or invalid addresses.
- **API Debugging**: Log API endpoint/URL path for authentication failures to aid in debugging.
- **Image Upload Optimization**: Removed image optimization from uploads (this will be handled by a cron job in the future).
- **View Behavior Change**: Updated ticket/invoice/quote views to always be in the Client section, showing client-side navigation and top info bar.

---

### Library Updates:
- **DataTable**: Bumped from 2.3.3 to 2.3.4.
- **TinyMCE**: Bumped from 8.0.2 to 8.2.0.
- **Stripe-PHP**: Bumped from 17.6.0 to 18.1.0.
- **PHPMailer**: Bumped from 6.10.0 to 7.0.0.
- **Chart.js**: Bumped from 4.5.0 to 4.5.1.




## [25.10.1]
- Deprecation Notice: `/scripts/cron_mail_queue.php` , `/scripts/cron_ticket_email_parser.php` , `/scripts/cron.php` `/scripts/cron_domain_refresher.php`, `/scripts/cron_certificate_refresher.php` are being phased out. Please transition to `/cron/mail_queue.php` , `/cron/ticket_email_parser.php`, `/cron/cron.php`, `/cron/domain_refresher.php`, `/cron/certificate_refresher.php` These older scripts will be removed in the November 25.11 release—update accordingly. 25.10.1 installs have the script already configured.

### Fixes
- Fix regression missing custom Favicon.
- Update SMTP and IMAP provider to allow for empty strings, empty means disabled.
- Fix Client portal Microsoft SSO Logins.
- Fix regression in Vendor Templates.
- Fix refression in some broken links from user to agent.
- Fix Project edit.
- Prevent open redirects upon agent login.
- Fix regression on switching to Webklex IMAP to allow for no SSL/TLS in IMAP.
- Fix Setup Redirect not behaving properly when setup hasnt been performed.
- Added Server Document Root Var to several includes, headers, footers files to allow includes from deeper directory strutures such as the new custom directories.
- Fix edit contact in contact details.
- Add .htaccess to /cron/.

### Added / Changed
- Support for HTML Signatures.
- Add Edit Project Functionality in a ticket.
- Added more custom locations: /cron/custom/, /scripts/custom/, /api/v1/custom/, /setup/custom/.
- Copied `/scripts/cron.php` `/scripts/cron_domain_refresher.php`, `/scripts/cron_certificate_refresher.php` to `/cron/cron.php`, `/cron/domain_refresher.php`, `/cron/certificate_refresher.php`. See Above!
- Signatures is now handled in post ticket reply on Public Comments only.

## [25.10]

### Breaking Changes
- Renamed `/user/` directory to `/agent/`.
- Deprecation Notice: `/scripts/cron_mail_queue.php` and `/scripts/cron_ticket_email_parser.php` are being phased out. Please transition to `/cron/mail_queue.php` and `/cron/ticket_email_parser.php`. These older scripts will be removed in the November release—update accordingly. New Installs via the script will have this already configured.
- Custom is working now. Custom code should be placed in /admin/custom/ , /agent/custom/ , /client/custom/ /guest/custom/
We will provide example code with directory structure for each custom directory a week after this release.

### Fixes
- Resolved issue with "Restore from Setup" not functioning correctly.
- Corrected asset name display in logs and flash messages when editing an asset in a ticket.
- Fixed Payment Provider Threshold not being applied.
- Fixed issue where Threshold setting was not saving properly.
- Various minor fixes for Payment Provider issues.
- Removed leads from the client selection list in the "New Ticket" modal.
- Fixed issues with the MFA modal.
- Resolved MFA enforcement bugs.
- Fixed KeepAlive functionality to maintain user sessions longer.
- Fixed multiple broken links caused by the `/user/` to `/agent/` path migration.
- Fixed Custom code directories.

### Added / Changed
- Removed "ACH" as a payment method; added "Bank Transfer" instead.
- Replaced relative paths with absolute paths for web assets.
- Tickets can now be resolved via the API.
- Added a filter for Archived Users and an option to restore them.
- Introduced a modal when archiving users, allowing reassignment of open and recurring tickets to another agent.
- Improved logic for determining the index/root page.
- Added "Assigned Agent" column for recurring tickets.
- Introduced "Additional Assets" option when editing assets in tickets; modal now uses the updated AJAX method.
- Added Gibraltar to the list of supported countries.
- Added Custom Link Option for the Admin Nav.
- Added Custom Link Option for the Reports Nav.

### Other notes
- Major releases will happen on the first week of every Month.


## [25.09.2]

### Fixes
- Fix Payment Method Select box in Revenue.
- Remove Extra Feeback Wording When Invoice Sends.
- Updated all CSV exports to use escape parameters.
- Fix Missing First row on Asset interface export.
- Fix Edit User not working due to incorrect modal footer path.
- Fix Add Certificate breaking due spelling on function.
- Update all CSV Exports to include company name or client name depending on when its being exported from.
- Introduced new function sanitize_filename and implmented it in all exports.
- Spruced up UI/UX Saved Paymented section in Client Portal.
- Fix add Payment Link in client portal recurring invoice section.
- Better Logic handling for default page redirect.

### Features
- Introduced new Beta mail parser cron using webklex imap library instead of php-imap as this is deprecated --Not Enabled on existing installs, only new installs.
- Introduced Beta support for OAUTH2 Authentication for Microsoft 365 and Google Workspaces for both incoming ticket parsing and outgoing email but must use new mail parser and mail queue for this to work, and requires changing the cron jobs: scripts/cron_mail_queue.php to cron/mail_queue.php and scripts/cron_ticket_email_parser.php to cron/ticket_email_parser.php.

---

## [25.09.1]

### Fixes
- **Web Installer**: Resolved issue with broken installer caused by incorrect database schema file name.
- Hide the "Add Credit" button as the feature is not fully implemented yet.
- Corrected long invoice/quote notes that were overlapping with the footer in PDF exports.
- Fixed AI settings not appearing in the Admin Menu when the Billing module was disabled.
- Enabled wrapping of client tags when they are too long.
- Fixed an issue where AI was not functioning correctly.
- Removed extra spacing between the contact name and icon in the Ticket Details contact card.

### Features
- Redesigned **AI Ticket Summary**, now divided into 3 sections: Main Issue, Actions Taken, and Resolution/Next Steps.
- Updated the **AI Ticket Summary** prompt to include ticket status, reply author, source, category, and priority.

---

## [25.09]

***BACK UP*** before updating.

---

### Breaking Changes and Notes
- We strongly recommend updating from the command line, however if performed via the webui and after performed it will return a 404. thats normal as the directory structure has changed, just close your browser then log back in then go back to update to perform the many database updates. 
- This is a major release with significant changes. While the community has done a great job identifying bugs, some may still remain — continued testing is encouraged.
- All AI settings will be **reset** and must be reconfigured using the new AI provider backend.
- The `xcustom` directory has been renamed to `custom`. All custom libraries and post-processing scripts should now be placed here.

---

### Added / Changed
- Numerous UI improvements and refinements across the application.
- Enhanced visual clarity by thickening the left border on ticket comments to help identify comment types.
- Ticket details UI redesigned to use less space at the top of the screen.
- Introduced tracking for the **first response date/time** on tickets.
- New reporting feature: **Average time to first response** on tickets.
- Stripe integration rebuilt using the new **payment provider backend**.
- Clients can now save and manage **multiple payment methods**.
- Support for selecting saved cards for **recurring invoices** in both the client and agent portals.
- Initial database structure and logic added for **credit management** (feature not yet enabled).
- Major **backend directory restructuring**.
- Introduced **stock/inventory management**, including a stock ledger backend.
- Stock quantities now update automatically when invoice items are added or removed.
- Invoice autocomplete now includes: **name, description, price, tax, stock levels**, and links `product_id` to `item_id`.
- Added a **category filter** to invoices.
- Linked stock to related expenses.
- New product fields: **location, code, and type**.
- Products now separated into two types: **Service** and **Product**.
- **Dark mode** introduced.
- Projects: Now support linking **closed tickets**.
- Clients: Added bulk actions for tags, referral source, industry, hourly rate, email, archive, and restore.
- Invoices: Bulk action added to **assign categories**.
- Assets: New `client_uri` field, visible in both the agent and client portals.
- Client Portal: Clients can now **select an asset** during ticket creation.
- Client Portal: Company logo now **displays in the header**.
- Client Portal: Dashboard cards are now **clickable** for more detail.
- Assets: Option added to include **MAC Address** in additional columns.
- Asset Interface: Bulk actions added — set DHCP, network type, and delete.
- API:
  - Added `/location` endpoint.
  - Ticket content now supports **HTML formatting**.
- New option to filter and display **500 records per page** in the footer.
- Payment methods are now treated as a **separate entity** instead of being grouped under categories.
- Updated libraries:
  - **TinyMCE**
  - **Chart.js** (major upgrade)
  - **DataTables**
  - **Bootstrap**
  - **FullCalendar**
  - **php-stripe**

---

### Fixed
- Several security vulnerabilities patched (with thanks to www.helx.io).
- Ticket status is no longer updated when scheduling.
- Client Portal: Tech contacts can no longer edit their own details.
- Fixed overlapping logo issue in Invoice/Quote PDF exports.
- Refactored `check_login.php` into multiple files for modular login functionality.
- Removed redundant logging comments for redirects.
- Renamed `get_settings.php` to `load_global_settings.php`.
- Simplified syntax for `ajax-modal` and updated usage throughout the app.
- Fixed issue where primary contact text wasn’t displaying.
- Corrected client **Net Terms** display.
- Fixed logic for recurring expense **next run date**.
- Resolved broken **IMAP test button**.
- Archived clients can no longer log into the portal.
- Searching closed tickets no longer reverts to open tickets.
- Fixed project search filter not showing completed projects.
- Fixed issue where company logo was not being removed correctly.
- Resolved API bugs:
  - Default rate and net terms.
  - Contact location.
  - Document endpoint.

---

### Developer Updates
- Replaced legacy code with newer functions like `redirect()`, `getFieldById()`, and `flash_alert()`.
- Significantly improved performance of queries used for filter selection boxes.


## [25.06.1]

### Fixed
- Fixed a regression in setup causing it to crash and never complete, due to missing default for currency.

## [25.06]

### Breaking CHANGES
- Old Document Verions will be deleted due to the major backend rewrite how document versions work.

### Added / Changed
- Improved function for retrieving remote IP address for logging purposes.
- Ticket categories are now sorted alphabetically.
- Visiting a deleted invoice or recurring invoice now redirects to the listing page; delete option added to invoice details page.
- Added "Mark as Sent" and "Make Payment" actions directly on the invoice listing page.
- Introduced Ticket Category UI for recurring tickets.
- In Project Details, bulk actions and sorting are now available for tickets.
- Updated ticket details UI to use full card stacks with edit icons for stackable items (e.g., asset, watchers, contact).
- Added a new setting to toggle AutoStart Timer in ticket details (disabled by default).
- Applied gray accent theme in the client section to visually distinguish from the global view.
- Introduced Ticket Due Date functionality (currently supports add/edit only; more updates coming next release).
- Added settings option to display Company Tax ID on invoices.
- Client overview now displays badge counts for all entities.
- Overhauled UI for Invoice, Quote, and Recurring Invoice details; switched PDF generation to TCPDF PHP from PDFMake JS.
- Document versioning has been moved to a separate backend table to resolve permanent link issues -- SEE Breaking CHANGES.
- Migrated Document Templates, Vendor Templates, and Software/License Templates to dedicated tables.
- Added functionality to mark all tasks in a ticket as complete or incomplete.
- Asset CSV import now supports a purchase date field.
- Recurring Payments have been restructured to auto-charge on the invoice due date instead of at generation time.
- Added "Base Template" label for vendor templates when available.
- Backup and restore processes now use a temporary directory; files are cleaned up automatically if operations fail.
- Added confirmation prompt when accepting or declining a quote.
- Other minor code UI/UX cleanups and refactoring throughout the app.

### Fixed
- Resolved issue with enabling MFA.
- Fixed UI regression where ticket listing columns would misalign.
- Non-billable invoices are no longer included in calculations.
- Addressed multiple minor reported security vulnerabilities.
- Tickets with open tasks are no longer resolved in bulk; a warning is shown along with a count of affected tickets.


## [25.05.1]

### Added / Changed
- Added Domain Expiring Card to Client Portal Dashboard for Primary and Technical Users.
- Added Balance and Monthly Recurring Amount to Client Portal Dahboard for Primary and Technical Users.
- Added Archive Searching to network and certificates also added unarchive capabilities to them as well.

### Fixed
- Add Payment not showing in Invoice.
- Updated Client Overview Entities to not show archived client's Entities even though the entity may not be archived.


## [25.05]

### Added / Changed
- Expanded file upload allow-list to include .bat and .stk file types.
- Added full backup/restore functionality. Backup downloads a zip that includes the SQL dump and uploads folder, setup now has option to restore from zip backup.
- Migrated Asset and Contact Links to modals to resolve variable overlap issue.
- Added Pagination to Notification Modal.
- Removed 500 Records Per Page option.
- Removed unused old DB checks in the top nav.
- Clients can now use the portal to setup Stripe automatic payments themselves for recurring invoices
- Automatic payments are now disabled for all recurring invoices if the saved payment method is removed
- Added Card Details and Payment added to Client Stripe.
- UI / UX updates to guest pay Make use of cards.
- Don't show Checkbox columns when ticket is closed, compact ticket list now matches round pills for status and priority.
- Ticket UI/UX update allow the ticket toolbar to be a little more mobile-friendly
- UI / UX Updates to Expenses - Combine Category and Description into 1 column.
- Country information is now displayed in Invoices, Quotes, Recurring Invoices, Clients, Locations, and the client top header.
- Added country-based search filters in Locations and Clients sections.
- Changed the settings name from Integrations to Identity Providers to make room for future iDPs (e.g. Google).
- Bump FullCalendar from 6.1.15 to 6.1.17.
- Bump DataTables from 2.2.2 to 2.3.1.
- Bump TCPDF from 6.8.2 to 6.9.4.
- Bump tinyMCE from 7.7.1 to 7.9.0.
- Bump phpMailer from 6.9.2 to 6.10.0.
- Bump stripe-php from 16.4.0 to 17.2.1.


### Fixed
- "None" option for SMTP encryption now functions correctly.
- Debug table row counts now reflect actual counts instead of relying on SHOW TABLE STATUS.
- Archived Categories now display properly.
- Stripe saved payment methods are now limited to credit/debit cards only.

## [25.03.6]

### Fixed
- Set default to date to 2035-12-31 as 9999-12-31 and 2999-12-31 broke certain browsers.
- Update Client PDF Export, add header added company logo.
- Present Larger clearer Warning about updates on update page.
- Allow to search by project reference.

## [25.03.5]

### Fixed
- Fixed the user listing issue when copying a trip.
- Corrected the display of recurring invoice amounts on the dashboard.
- Fixed the linking of entities with assets and contacts.
- Resolved the issue with displaying the correct mobile country code in the contact listing.
- Set the default date to `9999-12-31` to ensure future items (like invoices) are displayed by default.
- Fixed the display issue where file folders were not showing properly during document creation.
- Migrated from Dragula to SortableJS for a more modern, mobile-friendly solution.
- Added Handlebars icons for drag-and-drop items.
- Changed behavior to open Contact and Asset Details pages directly instead of using a modal.

## [25.03.4]

### Fixed
- Ability to remove additional assets from the ticket details screen.
- Fix the ability to remove assets from edit ticket not working when only 1 asset exists.
- Fix Database Backup corruption.
- Client Portal - show ticket number instead of ticket id in ticket listing.
- Add Purchase Reference to copy asset.
- Add Link to asset details from the global search.
- Fix Bulk assign ticket only showing contacts instead of ITFlow users.


## [25.03.3]

### Fixed
- Fix adding ITFlow user.
- Do not alert on inactive recurring invoices.
- Fix ticket user assignment including bulk assignment.
- Fix adding a location phone extension.
- Do not default to +1 Country code, instead default to null.
- Do not format numbers unless a country code is entered.
- Fix editing network location.
- Fix ticket redaction on client replies.
- Remove more from user activity as it requires admin privledges.
- Fix MFA Enforcement page.

## [25.03.2]

### Fixed
- Revert DB.sql change

## [25.03.1]

### Fixed
- Phone number missing in various sections.
- Match Database.
- Client Export Only display licenses users and assets from the selected client only.

## [25.03]

### Fixed
- Resolved missing attachments in ticket replies processed via the email parser.
- Fixed issue where the top half of portrait image uploads appeared cut off at the bottom.
- Ensured all tables and fields use `CHARACTER SET utf8mb4` and `COLLATE utf8mb4_general_ci` for updates and new installations.
- Converted `service_domains` table to use InnoDB instead of MyISAM.
- Fixed the initials function to properly handle UTF-8 characters, preventing contact-related issues.
- Interfaces can now start with `0`.
- Adjusted AI prompt handling to focus solely on content, avoiding unnecessary additions.

### Added / Changed
- Introduced bulk delete functionality for assets.
- Added the ability to redact ticket replies after a ticket is closed.
- Added support for redacting specific text while a ticket is open.
- Switched file upload hashing from SHA256 to MD5 to significantly improve performance.
- Enabled assigning multiple assets to a single ticket.
- Updated all many-to-many tables to support cascading deletes using foreign key associations, improving efficiency, performance, and data integrity.
- Enabled caching for AJAX modals to reduce repeated reloads and enhance browser performance.
- Upgraded DataTables from 2.2.1 to 2.2.2.
- Upgraded TinyMCE from 7.6.1 to 7.7.1, providing a significant performance boost.
- Added “Copy Credentials to Clipboard” button in AJAX asset and contact views.
- Renamed and reorganized several tables.
- Improved theme color organization by grouping primary colors and their related shades.
- Displayed a user icon next to contacts who have user accounts.
- New image uploads are now converted to optimized `.webp` format by default; original files are no longer saved. Existing images remain unchanged.
- Added international phone number support throughout the system.
- Introduced user signatures in preferences, which are now appended to all ticket replies.
- Optimized search filters to only display defined tags.
- Added “Projects” to the client-side navigation.
- Enabled “Create New Ticket” from within project details.
- Reintroduced batch payment functionality in client invoices.
- Included client abbreviations in both client and global search options.
- Added assigned software license details (User/Asset) to the client PDF export.
- Replaced client-side `pdfMake` with the PHP-based `TCPDF` library for generating client export runbooks.
- Introduced the ability to download documents as PDFs.
- Added a “Reference” field to tickets and invoices generated from recurring templates (not yet in active use).

### Breaking Changes
> **Important:** To update to this version, you **must** run the following commands from the command line from the scripts directory:
>
> ```bash
> php update_cli.php
> php update_cli.php --db_update
> ```
>
> Repeat `--db_update` until no further updates are found.
>
> **Back up your system before upgrading.**  
> This version includes numerous backend changes critical for future development.

## [25.02.4]

### Fixed
- Resolved issue preventing the addition or editing of licenses when no vendor was selected.
- Fixed several undeclared variables in AJAX contact details.
- Corrected the contact ticket count display.
- Addressed an issue where clicking "More Details" in AJAX contact/asset details failed to include the `client_id` in the URL.
- Fixed an issue with recurring invoices in the client URL: clicking "Inactive" or "Active" would unexpectedly navigate away from the client section.
- Added new php function getFieldById() to return a record using just an id and sanitized as well.

## [25.02.3]

### Fixed
- Fixed notifications being reversed as dismissed notifications.

## [25.02.2]

### Fixed
- Corrected some edit modals not showing notes correctly.
- Bugfix: When exporting to CSV, the first asset wasn't being shown.
- Fix broken create / edit credentials.
- Fixed missing Notificatons link.
- Fixed a few dead links.
- Fixed Overdue count also counting Non-Billable Invoices.
- Fix Edit Client Notes.

### Added / Changed
- Implemented SSL certificate history tracking.
- Added Inactive / Active Filter to Recurring Invoices.
- Merged Dismissed notifications and notification in one.
- Added Link Button to addd / edit Document WYSIWYG.
- Added Physical location to the asset export / import.

## [25.02.1]
### Fixed
- Resolved broken links in the client overview, project and client listings, and rack details.
- Corrected asset transfer functionality to clients.
- Fixed the ticket scheduling redirect.
- Corrected the ticket link in the Scheduled Ticket Agent Notification email.
- Addressed issues with credentials and ticket actions in the Contact Detail Modal.
- Fixed text wrapping in notifications.
- Adjusted notifications so that they are sorted with the newest first.
- Fixed drag-and-drop functionality for tickets in the Kanban view on mobile devices.
- Resolved a weird issue with TinyMCE that prevented using links referencing your ITFlow instance url.
- Corrected image orientation issues during upload and the preview optimization process.

### Added / Changed
- Introduced entity link indicator icons and counts in the contacts and credentials section.
- Implemented a fade animation for the new AJAX modal.
- Removed the Client Overview Expire Day Select and replaced it with simplified 1, 7, or 45-day options.
- Added the ability to link and unlink entities within asset details.
- Introduced quick tag/category creation across the app.
- Added a Vendor Quick Details Modal.
- Enabled vendor linking and added a License Purchase Reference in the Software Licenses section.
- Added download original, optimized and thumbnail option for images.
- Added Paid status to the top corner of Invoice PDFs.

## [25.02]
### Fixed
- Migrated several reports to the new permissions/roles system.
- Resolved issue with empty task box showing for closed/resolved tickets.
- Corrected ticket priority sorting.
- Cloned asset interfaces when transferring assets between clients.

### Added / Changed
- Restored max number of records per page option back to 500 since we dont have repeating modals.
- Bulk Categorize Tickets feature.
- Renamed "Interface port" to "Interface Description." "Interface Name" should now refer to port name and/or number.
- Changed "Transfer Asset to Client" from a single action to a bulk action.
- Updated Filter Footer UI to show "Showing x to x of x records" instead of just the total records.
- Added Client Overview section to view client assets, contacts, licenses, credentials, etc.
- Introduced Quick Peek for asset details, contact information, and document viewing throughout the ITFlow App, all made possible by AJAX.
- Enabled Simple Drag-and-Drop Ordering for Invoices, Recurring Invoices, Quotes, Ticket Tasks, and Ticket Template Tasks.
- Added new Ticket View options: Kanban and Simple View.
- Migrated all repeating modals to the new AJAX modal function for faster loading times and quicker development.
- Allowed clients to upload PDF documents to accepted quotes.
- Client Portal now shows ticket category.
- Custom links can now be added to the Client Portal navbar.
- Lots of little tweaks to UI, performance, bugs, etc.

### Breaking Changes
- Cron scripts have officially been moved to the /scripts folder and are no longer in the root directory; they must be updated to function properly.

## [25.01.3]
### Fixed
- Fixed ticket assignment modal showing client contacts.

## [25.01.2]
### Fixed
- Fixed app version.

## [25.01.1]

### Added / Changed
- Redesigned the Multi-Factor Authentication (MFA) Setup and Enforcement Flow UI/UX for a more intuitive user experience.
- Added a "Member" column in the user roles listing for improved visibility.
- General UI/UX improvements, along with minor performance optimizations and cleanups.

### Fixed
- Fixed an issue where Stripe was not appearing as a recurring payment option.
- Corrected inaccurate Quarter 2 Expense results in the Profit & Loss Report.
- Resolved TOTP code not displaying correctly on hover in the Contact or Asset Details sections.
- Archived contacts no longer appear in the Bulk Mail section.
- Fixed an issue where the Ticket Assign Modal was showing both ITFlow and client users.
- Fixed issue with login key redirecting to legacy client portal page.

## [25.01]

### Added / Changed
- Added support for saving cards in Stripe for automatic invoice payments.
- Page titles now display detailed information (e.g., page name, client selection, company name, ticket and invoice info) for easier multi-tab navigation.
- Reintroduced the new admin role-check for admin pages.
- Admin roles can now be archived.
- Debug mode now shows the current Git branch.
- The auto-acknowledgment email for email-parsed tickets now includes a guest link.
- Recurring tickets no longer require a contact.
- Stripe online payment setup now prompts you to set the income/expense account.
- New cron/CLI scripts have been moved to the `/scripts` subfolder — remember to update your cron configurations!
- Moved modal includes to `/modals` to tidy up the root directory.
- Moved most include files to `/includes` to improve directory structure.
- Moved guest pages to `/guest` for better organization.
- Renamed the include file `pagination.php` to `filter_footer.php`, as it is used in conjunction with `filter_header.php` for page filtering.
- Guest ticket feedback now shows the ticket prefix and number, not just the ID.
- Individual POST handler logic pages are no longer directly accessible.
- Added the ability to delete payments on the Payments and Client Payments pages.
- Implemented domain history tracking.
- Added Asset Interface Linking/Connections to show what interface is connected to which interface port of another asset.
- Added Force Recurring Ticket option in more locations, not just for recurring tickets.
- Implemented row spanning and centered devices that occupy multiple units in a rack.
- Added tooltips to main navigation badge counts to clarify what is being counted.
- Reduced max records per page from 500 to 100 to prevent performance issues.
- Updated several plugins:
  - `stripe-php` from 10.5.0 to 16.4.0
  - `Inputmask` from 5.0.8 to 5.0.9
  - `DataTables` from 2.1.8 to 2.2.1
  - `pdfmake` from 0.2.8 to 0.2.18
  - `php-mime-mail-parser` to 9.0.1
  - `TinyMCE` from 7.5.1 to 7.6.1
- Removed unused libraries from the vendor folder and moved Stripe to the plugins folder, eliminating the vendor folder.
- Merged the MFA TOTP functionality files `base32static.php` and `rfc6238.php` into a single file (`totp`) and moved it to the plugins folder.
- No longer need to pass the DB connection (`$mysqli`) to the `addToMailQueue` function.
- Disabled HTML Purifier caching.
- Replaced the `nullable_htmlentities` function with `htmlspecialchars`.
- Updated filter variable naming.
- Implemented other minor UI updates, performance optimizations, and directory cleanups.

### Fixed
- Fixed an issue where the ticket edit modal didn't show multi-client or no-client projects.
- Fixed asset interface losing DHCP settings.
- Fixed a 500 error when creating or editing recurring expenses due to an incorrect variable name.
- Fixed tickets created via the portal/email not being marked as billable.
- Fixed issues with editing recurring expenses.
- Resolved a regression where the TinyMCE editor didn’t display when adding or editing ticket templates.
- Fixed a TinyMCE license issue.

### Removed / Deprecated
- Deprecated the cron scripts in the root directory. Cron jobs should now use the ones in the `/scripts` subfolder, which no longer require a cron key and must be run via CLI.

### BREAKING CHANGES
- The client portal has been moved from `/portal` to `/client`:
  - Links in previous emails will be broken.
  - The Azure Entra ID SSO Redirect URI needs to be updated to `/client`.
  - You may need to update other links (e.g., website, support page).
- Guest links have been moved from `/` to `/guest`. Previous links will be broken.

## [24.12]

### Added / Changed
- Introduced versioned releases for the first time!
