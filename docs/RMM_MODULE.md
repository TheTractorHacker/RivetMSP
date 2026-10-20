# Optional RMM module (the built-in endpoint agent) in RivetMSP

Status: since DB 2.6.77 RivetMSP can run the **RMM module of RivetCore** (`rivet/rivet-core` >= 1.0.0-rc.5, namespace `RivetCore\Rmm`): the server side of the
RivetIT endpoint agent (enrollment, signed jobs, hosted updates, per-client installers, MeshCentral launch). It is an **optional module and it is OFF by
default**. RivetMSP installs never ran the endpoint agent, so there is no data and no older code; switching it on is the first time anything exists. This page
covers what is RivetMSP's own: how it is wired, the switch, permissions, operations and tests. The module itself, the wire protocol and the capacity guide are in
the rivet-core repository:

| Topic | Document (rivet-core) |
| --- | --- |
| What the module is, its contracts, key classes, behaviour | `docs/modules/rmm.md` |
| Wire protocol, credential formats, signing, error codes | `docs/rmm/PROTOCOL.md`, `docs/rmm/openapi-device.yaml` |
| Module switch, state file, load shedding, capacity | `docs/rmm/CAPACITY.md` |
| Building and releasing the agent | `docs/rmm/AGENT_BUILD.md`, `endpoint-agent/README.md` |
| Design and decisions | `docs/design/endpoint-module-extraction.md`, `docs/architecture/ADR-010-endpoint-agent-module.md` |

The URL space and behaviour are the same as RivetIT's (`docs/ENDPOINT_AGENT.md` there): an agent does not know which edition it talks to.

## 1. How RivetMSP wires it

| Piece | File |
| --- | --- |
| Composition root: builds `RivetCore\Rmm\RmmModule` from the adapters below | `includes/rmm_bootstrap.php` (`rivetRmmModule()`, `rivetRmmEnabled()`, `rivetRmmSyncState()`, `rivetRmmHousekeeping()`, `rivetRmmRequest()`) |
| Adapters for the Core contracts | `src/Core/Adapter/Endpoint/`: `EndpointTenancy` (clients, locations, client scope), `EndpointAssets` (`assets`, `asset_interfaces`; also `RmmAssetNamesInterface`, so the device list carries `asset_name` from one batched query), `EndpointBridge` (`rmm_integrations`, `asset_rmm_links`, `rmm_alerts`, `rmm_scripts`, `rmm_remote_sessions`), `EndpointSecretBox` (`encryptSetting()`), `EndpointAudit` (`logAction()`), `EndpointModuleState` (the kill switch), `EndpointAccessPolicy` (the nine `rmm.*` abilities). Metrics use Core's `NullRmmMetricSink` |
| Device REST bridges | `api/v1/agent_enroll.php`, `agent_checkin.php`, `agent_jobs.php`, `agent_update.php`, `agent_installer.php` and (RivetCore 1.0.0-rc.11) `agent_transfer.php` and `agent_logs.php` via `api/v1/includes/agent_device_api.php`; routed in `api/v1/index.php` above the Bearer parsing |
| Technician REST API (user API token; the legacy shared key is refused) | `api/v1/endpoint_devices.php`, `case 'endpoint_devices'` in `api/v1/index.php` |
| Pre-bootstrap gate | `api/v1/rmm_gate.php`, the first include of `api/v1/index.php` |
| Administration page and its POST handler | `admin/settings_endpoint_agent.php` (Settings > Connections & data > Endpoint agent), `admin/post/settings_endpoint_agent.php` |
| Device page and its JSON actions | `agent/rmm_agent_device.php`, `agent/post/rmm_agent.php`, the endpoint-agent branch of `agent/post/rmm_remote.php`, the "Agent device" button on the asset page |
| Add device / Download installer | Dialog on `agent/rmm_fleet.php` (button, and `?add=1` from the Endpoints menu) and in the client page header (`agent/includes/inc_client_top_head.php`, that client preselected). View-model `rivetRmmUiInstaller()` (null without `rmm.admin` / `rmm.token.manage` or with the module off, so nothing renders), markup `rivetRmmUiInstallerModal()`, behaviour `js/rmm_installer.js` (linked only when rendered). Posts to `agent/post/rmm_installer.php`: POST + CSRF only, Windows streams `RmmAdmin::downloadInstaller()` (stamped exe, exact length, audit "Installer Created", token revoked if the file cannot be served), Linux returns `deploymentCommands()['linux']` as JSON. Defaults: x64, stable, 24 h, 1 use (25 with "multiple PCs"). Agent binaries upload (`admin/settings_endpoint_agent.php`, `js/rmm_binary_upload.js`, handler `admin/post/settings_endpoint_agent.php`): several files at once, version / architecture from the file name when not given, no remote fetching. Tests: `tests/rmm_installer_ui.php`, `tests/browser/rmm_smoke.mjs` |
| Housekeeping | the "built-in endpoint agent" block of `cron/cron.php`; queued-ingest handlers through `rivetRegisterJobHandlers()` |
| Publishing a binary from the shell | `scripts/endpoint_agent_publish.php` |

Core ships read models and validated operations; RivetMSP renders its own pages and keeps CSRF, session, flash messages and navigation.

## 2. The module switch (default OFF)

Two switches, both must be on:

| Switch | Where | Default |
| --- | --- | --- |
| Install switch (edition kill switch) | `settings.config_core_rmm_enabled`, the `config_core_<module>_enabled` convention of the other Core modules; added by the 2.6.77 step and by `db.sql` | **0** |
| Service switch (master) | `endpoint_agent_settings.enabled` | **0** |

Administration > Settings > Endpoint agent has an **RMM module** card that sets **both together** (the first switch-on mints the instance signing key and the
`rmm_integrations` row of type `rivetit_agent`, and offers a feature preset). Switching off sets both off and deletes nothing. Setting
`config_core_rmm_enabled = 0` by hand is the emergency kill switch: the module is off whatever the master says.

What "off" costs:

* Device endpoints answer `503 {"code":"module_disabled"}` with `Retry-After: 3600` from `api/v1/rmm_gate.php`, **before `config.php` is loaded**: no database
  connection, no query, no class loaded (a read of `backups/rmm-state/rmm_state.json`). `endpoint_devices` authenticates first and then answers `404 disabled`.
  If there is no valid state file yet (a fresh install; the updater and the first cron tick write it) the request takes the normal path and `DeviceApi` answers the same 503 after a couple of primary-key SELECTs.
* Cron: the housekeeping block does two primary-key SELECTs and loads nothing. Queued work waits.
* Pages: the device page shows a notice, the asset page hides the "Agent device" button. The settings tile is always listed (the switch lives there).
* The Metrics feature has nothing to write to: RivetMSP has no metrics subsystem, so the module uses the null sink. Check-ins keep the **latest** values on the
  device row and the link health columns; there is no history.

**Prerequisite.** `$config_settings_enc_key` must be set in `config.php` (a long random string, kept with config.php's backups). The signing key is sealed with
it; `encryptSetting()` fails closed without a key (it no longer stores cleartext) and `EndpointSecretBox` refuses with a clear message, so the page keeps the switch disabled with the reason (and every admin page shows a banner while the key is missing). New installs generate the key and `deploy/update.sh` adds one to an older instance. A
RivetMSP `setup` does not generate this key today, **on purpose** (decision of the maintainer's default, 2026-10): the key protects every other encrypted setting too, a
silently generated one would be lost with a restored `config.php`, and a silent change of it makes sealed values unreadable. The fix on an install without one is a
single line in `config.php`, then reload the page:

```php
$config_settings_enc_key = '<output of: openssl rand -base64 32>';   // back it up with config.php; it cannot be recovered
```

Two guards: the page disables "Switch the RMM module on" and says why, and the POST handler refuses a forged request the same way. The handler's check stays even
though RivetCore 1.0.0-rc.5 makes `RmmAdmin::enable()` return a failed result (500 `secret_box_unavailable`, naming the key, nothing written) instead of throwing:
`enable()` only seals when it has to create the signing key, so with an already sealed key and no `$config_settings_enc_key` it would succeed and leave a module that cannot
read its own key. Core's catch covers the other admin paths (settings save that switches on, key rotation, MeshCentral login key).

## 3. Permissions

No new permission keys. The twelve Core abilities map onto the existing RMM module grants (`user_role_permissions` joined to `modules`, `role_is_admin`) in
`EndpointAccessPolicy`, the same matrix as RivetIT minus module-only logins (which RivetMSP does not have):

| Ability | Needs |
| --- | --- |
| `rmm.device.view` | `module_rmm` >= 1, and the device's client in scope |
| `rmm.job.run_saved`, `rmm.job.reboot` | view, `module_rmm_scripts` >= 2 |
| `rmm.job.run_script` | view, `module_rmm_scripts` >= 3 |
| `rmm.remote.launch` | view, `module_rmm_remote_connect` >= 1 |
| `rmm.device.manage`, `rmm.token.manage`, `rmm.binary.publish`, `rmm.admin` | administrator (`role_is_admin`) |
| `rmm.job.approve` (RMM Phase 2) | administrator; a role with `module_rmm_scripts` >= 3 only while Administration > Endpoint agent > "Technicians with RMM scripts level 3 may approve runs" is on (`settings.config_rmm_approve_scripts_lvl3`, off by default: an approval is a second person) |
| `rmm.alert.manage` (RMM Phase 3) | administrator, or `module_rmm` >= 2 |
| `rmm.remote.manage` (RMM Phase 6, rc.11: service and process managers, downloading a file from a device, the Mesh terminal and files views) | administrator; a role with `module_rmm_scripts` >= 3 **and** `module_rmm_remote_connect` >= 1 only while Administration > Endpoint agent > Remote tools has "Level 3 script users with remote access may use the remote tools" on (`settings.config_rmm_remote_manage_lvl3`, off by default); a client-scoped user only for the clients in scope. Sending a file **to** a device and the remote-tools settings stay `rmm.admin` (administrators only); reading collected logs needs `rmm.device.view` + `rmm.job.run_saved`; log rules need `rmm.alert.manage` |

Administrators hold everything. Only active **agents** (`users.user_type` 1) hold anything: client-portal contacts, disabled and archived accounts are denied every
ability. Client scope (`EndpointTenancy`): administrators and users with no `user_client_permissions` rows see every client, otherwise only the listed ones; a device
outside the caller's clients is a 404, indistinguishable from a missing one.

**The `module_rmm*` modules are not in a stock install.** `setup` and the migrations only create `module_client`, `module_support`, `module_credential`,
`module_sales`, `module_financial`, `module_reporting` and `module_kb`; the RMM pages have always checked `module_rmm`, `module_rmm_scripts`,
`module_rmm_remote_connect` etc. against rows an administrator adds under Administration > Access Modules. Until that is done, only administrators can use the
module (this matches the existing RMM pages); to give a technician role access, add those module names and set the role's levels (Roles).
**No `module_rmm*` rows are seeded**, on purpose (maintainer's default, 2026-10): creating them in a migration would change what every existing technician role can open, and
the existing RMM pages have always worked this way. Revisit only together with a decision about the existing RMM pages.

## 4. Alerts and tickets

The module opens and resolves ordinary `rmm_alerts` rows (status `new`, no ticket, alert key `agent:<device>:<check>:<episode>`). It never creates a ticket itself:

* **Create**: the existing RMM auto-ticketing (Administration > Integrations > RMM alert auto-ticketing severities, `cron/cron.php`, `createTicketFromRmmAlert()`)
  picks the rows up like any other alert, and so do the Alerts page and the manual "create ticket" action. This part of the cron is gated by the vendor RMM switch
  (`config_module_enable_rmm`), as before. This is deliberate (maintainer's default, 2026-10): with the vendor RMM switch off the module still records devices and
  alerts, but no ticket is created automatically (the Alerts page and the manual "create ticket" action work regardless). A separate switch for the built-in agent can be added later.
* **Close**: when a check recovers, the module resolves the alert and calls `RmmAssetMapper::autoCloseAlertTicket()` (made public for this): the same conservative close the
  vendor sync uses (honours `config_rmm_auto_close_on_clear`, closes only an untouched open ticket, otherwise leaves it open with a note).
* **Vendor sync**: the built-in agent's integration row (`type = 'rivetit_agent'`) is excluded from the vendor sync loop, the integration lists and selectors, and
  `getRmmClient()` refuses it.

## 5. Operations

* **Agent binaries.** Built and released from rivet-core (tags `agent-v*`). Upload them under Endpoint agent > Agent binaries, or
  `sudo -u www-data php scripts/endpoint_agent_publish.php rivetit-agent-windows-amd64.exe --version 1.2.0 --arch amd64 --activate [--release pilot --rollout 10]`.
  Files are stored under `backups/endpoint-agent/` (or `EA_BINARY_DIR`) with random names; `backups/.htaccess` and the shipped nginx rules deny that directory.
  The agent still carries the RivetIT name (service `RivetIT Agent`, installer `RivetIT-Agent-Setup-<client>-x64.exe`): a later neutral rebrand is a separate project.
* **State directory.** `backups/rmm-state/` (override `RMM_STATE_DIR` in `config.php`; set `RMM_GATE_STATE_DIR` in the web server environment too, since the gate cannot read `config.php`). It must be writable by the web user; the administration page warns when the file cannot be written.
* **config.php constants** (all optional): `EA_ALLOW_INSECURE_HTTP` (loopback test servers only), `EA_BINARY_DIR`, `EA_TRANSFER_DIR` (file transfers, default `backups/endpoint-transfer`), `EA_BINARY_MAX_BYTES`, `EA_ALLOW_NON_WINDOWS` (the Linux test agent, never production), `RMM_STATE_DIR`.
* **nginx / PHP-FPM.** `client_max_body_size` at least 1m for `/api/v1/agent_checkin`; PHP-FPM must pass `HTTPS` (or the proxy sets `X-Forwarded-Proto: https`). The live nginx rules are not changed by this module.
* **Transfer folder (rc.11).** Files copied from or to a device are stored in `backups/endpoint-transfer/` (or `EA_TRANSFER_DIR`): mode 0700, private to the web user, inside the `backups/` area that every shipped nginx template (`deploy/templates/itflow-locations.conf`, `docker/nginx.conf`) and `backups/.htaccess` deny (`tests/nginx_private_paths.php` pins that), named `xfer_<random>.bin`, deleted when the transfer expires (`transfer_ttl_s`, 24 hours by default) by the cron housekeeping. It is not part of the in-app backup or `deploy/backup.sh` (transient data). DB step 2.6.88 creates it when the process running the update owns `backups/`; otherwise the first transfer creates it (as the web user), or Administration > Endpoint agent > File transfer shows its state with a "Create folder" button. Give it a disk with room for `transfer_store_max_bytes` (1 GiB by default). The agent sends chunks of at most 1 MiB, so `client_max_body_size` must be at least 2m for `/api/v1/agent_transfer` (the shipped templates use 500M).
* **Several queue workers (rc.11, scale S6).** `RivetCore\Jobs\JobClaimer` makes claiming safe for any number of workers: `SELECT ... FOR UPDATE SKIP LOCKED` on MariaDB 10.6+ and MySQL 8.0.1+, a conditional-UPDATE lease on older servers (either way a job is never run twice), with an exact per-queue limit (`ingest_max_running`). `cron/integration_worker.php` still runs ONE worker by default (its cron guard); to run N, add N-1 more cron lines with their own number: `* * * * * www-data php /path/cron/integration_worker.php --worker=2` ... `--worker=N` (worker 1 is the one without the option; each number has its own `cron:integration_worker_<n>` lock). How many: the Performance and capacity panel (Endpoints > Agent Fleet) shows the autoscaling hint. It matters only when `ingest_mode` is `queued` (load shedding switches to it under load). Housekeeping takes back the jobs of a worker that died after a two-minute lease.
* **Cron (rc.11).** Nothing new to schedule: the "built-in endpoint agent" block of `cron/cron.php` calls `Housekeeping::run()`, which now also builds the **daily metric rollup** (`rmm_metric_daily`, from the finished days of `rmm_metric_hourly`; at most about 15 s a run), prunes collected logs (retention, per-device and fleet caps, in batches of 2000), recovers log-rule alerts, deletes expired transfers, maintains time partitions when partitioning is on, prunes the read cache table, reclaims dead workers' `rmm.ingest` jobs and flushes the external metric buffer when that is on. Keep the cron's PHP time limit above 30 seconds (the CLI default is unlimited).
* **Redis (rc.11).** When Redis answers, the read cache (fleet counters 15 s fresh / 5 min stale, the active releases) and the per-client token buckets use it, shared by every web worker and the cron (`GlobalRedisClientProvider` loads `includes/redis_functions.php` itself, so a CLI process agrees with the web); both fail open when it is down and then Core uses its own tables. A writer that changes `endpoint_agent_releases` or `endpoint_agent_devices` directly must call `rivetRmmModule()->updates()->releasesChanged()` / `->cache()->invalidate('fleet', true)` (Core's own writers do; RivetMSP has none of its own).
* **Updating.** `Update Database` (2.6.77) adds `settings.config_core_rmm_enabled` and runs Core's migration runner (0014 the ten tables, 0015 a no-op here, 0016 the switch columns). It switches nothing on. It waits and retries (the version is not advanced) until `rivet/rivet-core` with the module is installed.
* **Backups and restore.** The tables are ordinary tables. A restored database with a stale state file is corrected by the next cron tick (or any save of the switch).

## 6. Tests

All against a scratch database (a name containing `scratch`), a throwaway Redis (never 6379/6380), real HTTP through `php -S` (`tests/rmm_golden/router.php`). Set
`RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=... RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<port> RIVETMSP_REDIS_ENV_FILE=/dev/null`.

```
RIVETCORE_PHPUNIT_AUTOLOAD=/path/to/rivet-core/vendor/autoload.php RIVETCORE_TEST_DB_NAME=... phpunit -c tests/core/phpunit.xml   # Endpoint*ConformanceTest = Core's adapter kit
RIVET_CORE_DIR=/path/to/rivet-core php tests/endpoint_agent_golden.php replay      # golden HTTP transcripts of the original agent code, replayed against the MSP bridges
php tests/endpoint_agent_module.php        # switch, gate with zero queries, fail-safe state file, fresh install default OFF, 2.6.76 -> 2.6.77 by the real updater
php tests/endpoint_agent_smoke.php         # enroll -> link -> check-in -> alert -> MSP ticket -> auto-close -> signed job -> update download -> offline flip
RMM_AGENT_BIN=/path/to/rivetit-agent-linux-amd64 php tests/endpoint_agent_real_agent.php   # a real Linux test agent over TLS (optional)
php tests/rmm_ui.php                       # asset panel, fleet, Phase 2/3 and (tests/rmm_ui_p68_*.php, rmm_ui_p1213_*.php) the remote tools, logs, scale settings, Mesh agent and starter pack pages
node tests/browser/rmm_p68_{remote,logs,admin}_smoke.mjs   # real-browser smokes of the same (see the headers; RIVETMSP_WEBHOOK_ALLOW_PRIVATE=1 for the admin one)
node tests/browser/rmm_p1213_{mesh,starter}_smoke.mjs   # real-browser smokes of the Mesh install settings (RIVETMSP_WEBHOOK_ALLOW_PRIVATE=1) and the Starter pack page
node tests/browser/rmm_p16_smoke.mjs     # real-browser smoke of the pushed Mesh install, the bulk install, the Format selector and the MSI templates (rc.16)
php tests/rmm_installer_ui.php             # the Add device dialog and installer download, including the optional MeshCentral agent box and the payload Core stamps
RIVETMSP_SOCKET=... AGENT_BIN=.../rivetit-agent-linux-amd64 tests/real_agent_proof/run.sh   # the real Linux agent under systemd against RivetMSP's bridges (docker; the Mesh agent against a MOCK Mesh server and the starter pack through the API are part of it)
```

The scratch `config.php` must define `EA_ALLOW_INSECURE_HTTP` (the golden runner starts a second server with `EA_TEST_NO_INSECURE=1` for the 426 transcripts),
`$config_settings_enc_key`, `$config_enable_setup = 0`, and may honour `RMM_TEST_STATE_DIR` / `RMM_TEST_NO_ENC_KEY` / `RMM_TEST_DATABASE` / `EA_TEST_LINUX` (see the
headers of the test files). The golden runner records this install into a temporary directory and compares it with Core's transcripts (recorded from the original RivetIT code): everything is identical except the four "disabled" exchanges of `01-disabled.json`, the one intentional wire change (a disabled module answers 503 `module_disabled` with `Retry-After: 3600`, not 403 after a database lookup; RivetMSP has no enrolled agents to stay compatible with). `php tests/endpoint_agent_golden.php strict` runs the driver's own replay instead, which reports exactly that difference.

## 6a. Asset page panel and Agent Fleet (T10b)

`includes/rmm_ui.php` (view-models, read-only, first call is the state file), `includes/rmm_ui_render.php` (HTML), `css/itflow_rmm.css`, `js/rmm_panel.js`, `agent/rmm_fleet.php`, `agent/rmm_job_output.php`, and the panel inside `agent/asset_details.php`. Actions are unchanged (`agent/post/rmm_agent.php`). RivetMSP specifics: clients (not departments); no Metrics subsystem, so the Performance section explains that the latest check-in is all that is kept; the Endpoints menu shows Agent Fleet whenever the module is on, even with the vendor RMM integrations off. Tests: `php tests/rmm_ui.php` (needs `EA_TEST_LINUX=1`) and `tests/browser/rmm_seed.php` + `tests/browser/rmm_smoke.mjs`.

## 6b. Policies, scripts and alerting (RivetCore 1.0.0-rc.10, DB 2.6.84)

Three sub-switches, all off by default (Administration > Endpoint agent > "Policies, scripts and alerting"): `policies`, `scripts` (script library, schedules, approvals, custom fields)
and `alerting` (thresholds, flap detection, maintenance windows, escalation). The pages (`agent/rmm_policies.php`, `rmm_scripts.php` (Library tab; the older `rmm_script_library.php` address redirects), `rmm_schedules.php`, `rmm_approvals.php`,
`rmm_fields.php`, `rmm_agent_alerts.php`, `rmm_maintenance.php`, `rmm_escalations.php`) and the asset page tabs are rendered by `includes/rmm_automation.php` (shared layer),
`rmm_pol_ui.php`, `rmm_scr_ui.php` and `rmm_alr_ui.php`; their forms post to `agent/post/rmm_automation_{pol,scr,alr}.php`. Every read and write is an in-process call into RivetCore's
technician API as the signed-in user, so Core's 403/404 rules, client scoping and audit apply and a forged POST gets the REST API's answer. A page checks the module, then its sub-switch (both
from the module's state file: no query while off), then the ability. Escalation is delivered by `src/Core/Adapter/Endpoint/EndpointEscalation.php` (in-app and email to users, roles and addresses;
a critical alert opens or reuses a ticket through `createTicketFromRmmAlert()`; acknowledge and severity are mirrored onto `rmm_alerts`), and runs from the same `cron/cron.php` housekeeping call as
everything else. DB 2.6.84 runs Core migrations 0019 and 0020 and adds `config_rmm_approve_scripts_lvl3` and `config_rmm_escalation_contact` to `settings`. Tests: `tests/rmm_ui_p23_*.php` (run by
`tests/rmm_ui.php`), `tests/browser/rmm_p23_{a,b,c}_smoke.mjs`, `tests/core/EndpointEscalationConformanceTest.php`.

## 6c. Remote tools, file transfer and log collection (RivetCore 1.0.0-rc.11, RMM Phases 6 and 8, DB 2.6.88)

Everything here is **off by default** and dormant until an administrator turns it on under Administration > Settings > Endpoint agent (the cards **Remote tools**, **File transfer**, **Log collection**). The three sub-switches are Core's `remote_tools`, `filetransfer` and `logs`. An agent that does not announce the capabilities (`remote:services`, `remote:processes`, `remote:files`, `logs:collect`; older agents) is answered exactly as before, and the golden transcripts replay byte for byte. Operator guide: rivet-core `docs/rmm/REMOTE_AND_LOGS.md`.

| Piece | Where | Needs |
| --- | --- | --- |
| Device bridges | `api/v1/agent_transfer.php` (GET and POST, raw body of at most 1 MiB, header `X-Transfer-Token`), `api/v1/agent_logs.php` (POST, JSON, at most 200 events and 256 KiB); routed in `api/v1/index.php` above the bearer parsing; the pre-bootstrap gate (`api/v1/rmm_gate.php`, Core's rc.11 template) answers both while the module is off and enforces the warm start before any database work | device credential (+ the one-time transfer token) |
| Remote tab of the asset page | `includes/rmm_rem_ui.php`, `agent/post/rmm_remote_tools.php`, `js/rmm_remote.js`: **Services**, **Processes**, **Files** (allowed folders, download with the verified SHA-256, upload for administrators, the transfer list), **Remote access** (MeshCentral desktop, terminal and files) | module + `remote_tools` (+ `filetransfer`) + `rmm.remote.manage` |
| Logs | `agent/rmm_logs.php`, the **Logs** tab of the asset page, `includes/rmm_log_ui.php` | module + `logs` + `rmm.device.view` + `rmm.job.run_saved` |
| Log rules | `agent/rmm_log_rules.php`, `agent/post/rmm_logs.php`: phrase rules (never a regular expression); a match reports the check `log.rule_<id>` through the alert engine | module + `logs` + `rmm.alert.manage` |
| Administration cards | `admin/settings_endpoint_agent.php`, `admin/post/settings_endpoint_agent.php`, `includes/rmm_scale_admin.php`, `includes/rmm_scale_cards.php`, `js/rmm_scale.js` | administrator |

**`agent/post/rmm_remote.php` is NOT the Remote tab's handler.** It is the vendor-RMM "Remote Connect" handler (and carries the endpoint-agent Mesh branch); the Remote tab posts to `agent/post/rmm_remote_tools.php`. `tests/rmm_ui_p68_remote.php` pins both files. The Endpoints menu entries Logs and Log rules are drawn only for a user who has the ability (`rivetRmmAutoNav($userId)`). Every action is authorised on the server on every call (role, the device's client, the ability, the switch) by Core's `RemoteActions` / `LogActions`; another client's device is the same 404 as a missing one; every request, refusal and completion is audited, and `rmm.file.transferred` and `rmm.log.rule_matched` are on the event bus (RivetMSP's picker reads Core's `EventCatalog`, so both appear without an extension list).

## 6f. Patch management (RivetCore 1.0.0-rc.20, RMM Phase 4, migration 0028, DB 2.6.96)

Sub-switch `patching` (off by default; Administration > Settings > Endpoint agent > **Patch management**). The model, the safety defaults and what is not done are in RivetCore's `docs/rmm/PATCHING.md`; this edition adds the screens.

| Piece | Where | Needs |
| --- | --- | --- |
| Patches tab of the asset page | `includes/rmm_patch_ui.php` (`rivetRmmPatchPanelSection()`, hooked in `rivetRmmUiTabs()`), `agent/post/rmm_patch.php` | module + `patching` + `rmm.patch.view` for the device's client; scan, approve, decline, dry run need `rmm.patch.manage`; a real install `rmm.patch.install` (and `rmm.job.reboot` to ask for a restart) |
| Patch compliance | `agent/rmm_patch_compliance.php` | module + `patching` + `rmm.patch.view` |
| Patch approvals (missing updates, fleet-wide) | `agent/rmm_patch_approvals.php` | view to read, manage to decide |
| Patch policies, rings, approval rules | `agent/rmm_patch_policies.php` | view to read, manage to change; rings and policies also need access to every client (Core) |
| Second person's decision | the existing Approvals page (kind `patch`), `agent/post/rmm_automation_scr.php` | `rmm.job.approve` and `rmm.patch.manage`; works with the script library off, then only for patch requests |
| Administration card | `admin/settings_endpoint_agent.php`, `admin/post/settings_endpoint_agent.php` (`save_patching_settings`) | administrator |

**Roles** (`src/Core/Adapter/Endpoint/EndpointAccessPolicy.php`): `rmm.patch.view` = RMM module level 1; `rmm.patch.manage` = RMM scripts level 2; `rmm.patch.install` = RMM scripts level 3. Administrators hold all three. **Live installs** (`patch_live_install`, default off) is a separate switch with its own confirmation: until it is on, the scheduler queues dry runs only. **Agent:** 0.8.0 or later announces `patch:scan` and `patch:install`; publish it to the rings and rebuild the MSI template. Tests: `tests/rmm_ui_p20_patching.php`.

## 6g. Software deployment and reporting (RivetCore 1.0.0-rc.21, RMM Phase 5, migration 0029, DB 2.6.97)

Sub-switch `software_deploy` (off by default; Administration > Settings > Endpoint agent > **Software deployment**). The model, the safety defaults, the package source pinning and what is not done are in RivetCore's `docs/rmm/SOFTWARE_DEPLOY.md`; this edition adds the screens.

| Piece | Where | Needs |
| --- | --- | --- |
| Deployment tab of the asset page | `includes/rmm_software_ui.php` (`rivetRmmSoftwarePanelSection()`, hooked in `rivetRmmUiTabs()`; hash `#rmm-deploy`), `agent/post/rmm_software.php` | module + `software_deploy` + `rmm.software.view` for the device's client; Check now and Dry run need `rmm.software.manage`; a real install or removal `rmm.software.deploy` |
| Software catalog (packages, versions, hosts) | `agent/rmm_software_catalog.php` | view to read; manage to change packages and versions (every client: Core's rule); the host list and lowering the two-person flag need `rmm.admin` |
| Software assignments (with the ring delays) | `agent/rmm_software_assignments.php` | view to read, deploy to assign and remove; a restricted technician assigns only to their own clients and devices |
| Software report and CSV | `agent/rmm_software_report.php`, `agent/rmm_software_report_csv.php` | view; Core scopes every row and audits the export |
| Second person's decision | the existing Approvals page (kind `software`), `agent/post/rmm_automation_scr.php` | `rmm.job.approve` and `rmm.software.deploy`; works with the script library off, then only for patch and software requests |
| Administration card | `admin/settings_endpoint_agent.php`, `admin/post/settings_endpoint_agent.php` (`save_software_settings`) | administrator |

**Roles** (`src/Core/Adapter/Endpoint/EndpointAccessPolicy.php`): `rmm.software.view` = RMM module level 1; `rmm.software.manage` = RMM scripts level 2; `rmm.software.deploy` = RMM scripts level 3. Administrators hold all three. **Live installs** (`software_live_install`, default off) is a separate switch with its own acknowledgement: until it is on, the scheduler queues dry runs only. **Agent:** a build of RivetCore 1.0.0-rc.21 announces `software:detect` and `software:install`; publish it to the rings. **The Windows install paths (MSI, EXE, the pinned download) have never been run on a real machine:** add the installer host, run a dry run first, start with one machine in the pilot ring and a small package. The asset page's older **Software** tab (installed software from the inventory) is unchanged; the new tab is called **Deployment**. Tests: `tests/rmm_ui_p21_software.php`, `tests/browser/rmm_p21_software_smoke.mjs`.

## 6d. Scale settings, storage and the Performance and capacity panel (RivetCore 1.0.0-rc.11, S3 to S8)

Administration > Settings > Endpoint agent > the cards **Scale settings**, **Metric storage and partitioning** and **External metric store**, and the read-only **Performance and capacity** panel on the Agent Fleet page (autoscaling hint, read cache, warm start, per-client token buckets, shedding, the three storage tiers and the rollup status).

* **RivetMSP writes metrics through Core's `DatabaseMetricSink`**, so Core's tiers are live here (unlike RivetIT, which keeps its own metric tables): `rmm_metric_hourly`, the daily tier `rmm_metric_daily` that housekeeping builds from it (readings over 7 days are answered from it; `rivetRmmMetricReader()` and the module's reader are the same), and `endpoint_agent_check_history`. Retention: `metric_hourly_days` (14), `metric_daily_days` (400), `check_history_days` (7).
* **Partitioning (opt-in, `partitioning`):** the card runs Core's capability probe and says in words which of the THREE tables it can convert; **Enable** (with a confirmation, because the one-time copy blocks writers on a big table) converts each convertible table and names any it had to leave plain; housekeeping then maintains the partitions and drops expired ones (guarded by the rollup watermark). Checked on MariaDB 11.8 (`tests/rmm_ui_p68_admin.php`).
* **Herd control (off by default):** warm start after the module is switched on again or after a quiet gap (the gate reads `rmm_herd.json` in the state directory) and a token bucket per client for check-ins and enrollment.
* **External metric store (off by default, S4):** only for installs far beyond 10,000 devices. Switch, write URL, read URL and bearer token live in `settings.config_rmm_extstore_*`, sealed with `encryptSetting()` and registered for key rotation (`ColumnRegistry`; `tests/crypto_rewrap.php` fails when a secret-looking column is missing from the registry). **Test connection** vets the URLs with the webhook `UrlPolicy` (https, public addresses, no redirects); the module wires a `CompositeMetricSink` with the database sink FIRST, so Core's rollups and partitions keep applying. Plain http to a loopback fake server exists only in the automated tests (`EA_ALLOW_INSECURE_HTTP` and `RIVETMSP_WEBHOOK_ALLOW_PRIVATE=1`).
* **Not done:** Core's S9 validation (24 hours, 10,000 devices, reference hardware) has not been run; nothing here was load-tested beyond the scratch suites.

## 6e. Optional MeshCentral agent install and node-id mapping (RivetCore 1.0.0-rc.12, migration 0023, DB 2.6.89; the group id in MeshCentral's URL form: rc.15, migration 0024, DB 2.6.90)

**Optional and off by default.** Nothing changes until an administrator saves the Mesh install settings (Administration > Settings > Endpoint agent > MeshCentral) and ticks **Also install the MeshCentral agent** for an installer. The installer never bundles a Mesh binary (an agent belongs to one Mesh server and one device group): after the RivetMSP agent is in place it downloads the agent from the customer's **own** Mesh server over https, trusting the system roots or exactly a pinned certificate hash. A Mesh failure never fails the RivetMSP install, and uninstall leaves Mesh alone unless `--remove-meshagent` is passed. Operator guide: Core's `docs/rmm/REMOTE_AND_LOGS.md`, "Install the MeshCentral agent with the RivetIT agent" (the product is RivetMSP here).

| Piece | Where | Needs |
| --- | --- | --- |
| Tick-box in the Add device dialog and the Administration deployment form | `includes/rmm_ui_render.php` (`rivetRmmUiInstallerMeshBox()`), `js/rmm_installer.js`, `agent/post/rmm_installer.php`, `admin/post/settings_endpoint_agent.php` | `rmm.token.manage` for the client (as before). Off by default and unticked each time the dialog opens; disabled with **Core's own refusal sentence** while Mesh is not switched on, the address is not https or no group id is set (`MeshAgentService::installRefusal()`). A forged tick is refused by Core up front, creating nothing |
| Mesh install settings | `includes/rmm_mesh_admin.php`, the MeshCentral card of `admin/settings_endpoint_agent.php`, `js/rmm_mesh_admin.js` | `rmm.admin`. Device group id (masked: it is a secret, anyone holding it who can reach your Mesh server can download an agent that joins the group), certificate pin, **Read it from the server** (connects through the network policy, shows the SHA-384 and SHA-256 and **never saves**; a button puts it in the field and you press Save), the overwrite switch and, for experts, JSON corrections of the agent-id and install-argument tables behind a warning that they are unverified. Core validates everything and writes nothing when any field is invalid |
| Device state | `rivetRmmUiMeshAgentHtml()` in the Remote access card (overview) and the Mesh card of the Remote tab | whoever may view the device. Installed (version) or the install error, and what the server did with the node id: `mapped`, `conflict` (visible alert; the other device is named only when it belongs to the same client), `kept_manual` (visible hint; says how an administrator changes it). The node id itself is shown to administrators only |
| Events | Core's `EventCatalog` | `rmm.mesh.agent_installed`, `rmm.mesh.node_conflict` appear in the shared event picker |

Node-id mapping (Core): only while Mesh is enabled; unmapped means mapped (source `agent`); a node set by hand is kept unless the overwrite switch is on; a node already mapped to another **live** device is never taken over, whatever the switch says; a node of a retired or revoked device moves to the machine that now reports it. REST: `GET`/`PUT /api/v1/endpoint_devices/remote/mesh` (`rmm.admin`), documented in Core's `docs/rmm/openapi-device.yaml`. Tests: `tests/rmm_ui_p1213_mesh.php`, the Mesh section of `tests/rmm_installer_ui.php`, `tests/browser/rmm_p1213_mesh_smoke.mjs`, and the real agent proof (`tests/real_agent_proof/`).

**The device group id (RivetCore 1.0.0-rc.15, migration 0024).** MeshCentral spells the group id (`meshid`) as 64 characters of base64 in its URL spelling (`@` for `+`, `$` for `/`; 48 raw bytes). It is **not** hex and has no `mesh//` prefix. The field accepts, and sends to Core unchanged beyond trimming whitespace: the **whole download address** pasted from MeshCentral (device group, Add Agent, Windows 64-bit installer; only the `meshid` is taken out of it, nothing is fetched, and an address for a host other than the saved MeshCentral address is saved with a warning shown in the card), the bare 64-character `meshid`, `mesh//<64 characters>` and the old hex form. Anything with whitespace inside, quotes, `&`, `#`, a wrong length or another alphabet is refused with one sentence and nothing is written. Core stores the canonical hex (`mesh_group_id`) and keeps the URL form you gave (`endpoint_agent_mesh_settings.group_id_url`, added by migration 0024, DB 2.6.90); the field reads back `mesh_group_id_url` (rows from rc.12 to rc.14 have it derived from their hex), still masked with a show toggle. The installer payload's `mesh.meshid` and the Linux `--mesh-group-id` carry the URL form. An agent built from rc.12 to rc.14 cannot use it (it records a Mesh error and the RivetMSP install is unaffected), so roll out the rc.15 agent with the server.

**The certificate pin (rc.15).** Leave the pin **empty** when the Mesh server's certificate is issued by a public CA such as Let's Encrypt (the system roots verify it; such a certificate renews every 60 to 90 days and a pin of it would break at each renewal). Use a pin only for a private CA or a self-signed certificate. **Read it from the server** now also does one verified handshake: Core answers `pin_probe.publicly_trusted` and `pin_probe.note`, the card shows the note, and when the certificate is publicly trusted it offers **no** way to put the hash in the pin field.

**Verified (rc.15, against an owner's real MeshCentral):** only the download address shape, `https://<mesh>/meshagents?id=4&meshid=<64 characters>&installflags=0` (agent id 4 = Windows x64, HTTP 200, a 3.5 MB PE, over a valid public certificate). **Not verified:** the Linux agent ids, the install and uninstall arguments, where the node id can be read, and the Windows code on a Windows host; nothing was installed from a real server. Check them against your own Mesh version before a fleet roll-out; the Administration card says so next to the corrections.

## 6f. The RMM starter pack (RivetCore 1.0.0-rc.13, no migration)

A new install is useful on day one: Core ships a versioned, checksummed pack of **73 library scripts** (41 Windows PowerShell, 32 Linux bash; 57 read only, 10 change the system, 6 disruptive), **3 check template sets** and **3 policy drafts** (`Starter: Workstation baseline`, `Starter: Windows Server baseline`, `Starter: Linux Server baseline`: created switched off and assigned to nothing). Nothing is installed or run by itself.

| Piece | Where | Needs |
| --- | --- | --- |
| Starter pack page | `agent/rmm_starter_pack.php`, `agent/post/rmm_starter.php`, `includes/rmm_starter_ui.php`, `js/rmm_starter.js`, `css/itflow_rmm_starter.css`; Endpoints menu entry **Starter pack** | module + `scripts` sub-switch + `rmm.admin`. Browse and filter (platform, risk, tag, state, search), tick what you want, **Preview** (writes nothing), **Install selected**, **Uninstall selected** (a preview first, then an in-page confirmation). Each draft has its own settings form (time server, SSH unit, certificate paths, backup service...); typing in it ticks the draft. An empty selection is refused by the page and **never sent to Core** (Core reads an empty selection as the whole pack). With no instance signing key Core's blocker sentence is shown first and Install is disabled |
| Marks in the script library | `includes/rmm_scr_ui.php` | whoever may see the library. A **Starter** badge and the risk chip (read only, changes the system, disruptive) on scripts that carry the tags `starter` and `risk:*`; **Update waiting** on a script an administrator edited and for which the pack now has a newer text, which Core parks as a non-current version flagged `starter-updated` (the script keeps running what it ran). The script's page explains it and links the comparison of your version with the parked one |
| Policy drafts | `includes/rmm_pol_ui.php`, `includes/rmm_automation.php`, `agent/post/rmm_automation_pol.php` | A draft is recognised by a marker at the end of its description. The pages show a **Starter** badge and never print the marker; saving a draft through the editor puts the stored marker back, so editing does not turn it into "your own policy" |
| Event | Core's `EventCatalog` | `rmm.starter.installed` (one per install or uninstall that changed anything; never a script text) |

Idempotent by pack, item and content hash: installing twice changes nothing; an edited script is never overwritten; a retired script stays retired; uninstall retires or deletes only what is untouched (a draft that is switched on, assigned or edited is left alone). Scripts are signed with the instance signing key when they are published. REST (Core, `rmm.admin`): `GET starter/catalog`, `POST starter/plan|install|uninstall` under `/api/v1/endpoint_devices/`. Tests: `tests/rmm_ui_p1213_starter.php`, `tests/browser/rmm_p1213_starter_smoke.mjs`, and the real agent proof (a starter script run on a real Linux agent).

**Not verified:** all 41 Windows scripts were parse-checked and linted by Core and **never run on a Windows host**; treat the first run of each on a real machine as a test (they start as dry runs or read only). The 32 Linux scripts were run in a container by Core, with no systemd, journald or Docker inside it.

## 6g. Pushed MeshCentral agent install and the MSI installer (RivetCore 1.0.0-rc.16, migration 0025, DB 2.6.91)

**(A) Install or remove the MeshCentral agent on a device that is already enrolled.** The Mesh options (6e) live only in a stamped installer, so an agent that enrolled without them, or one that updated itself, never got a Mesh agent. Core's `mesh_install` and `mesh_uninstall` jobs push it; RivetMSP adds the controls.

* **Device page, Remote tab** (Remote access card). *Install MeshCentral agent* is offered while MeshCentral is on with an https address and a device group (Core's `mesh_install.available`) and the device does not report the agent; it is disabled, with "this device's agent is too old to take the install, update the agent first", when the agent never announced `job:mesh_install`. Once the device reports the agent the card offers *Remove MeshCentral agent* (an in-page confirmation dialog with a box, then a destructive job offered once and never retried) and *Run the install again* (force). The page follows the job result (the shared Remote tab poller). The failure reason of an install is the `mesh_agent.error` the device reports, shown by the existing Mesh agent line. Removal is also offered after MeshCentral was switched off (Core does not tie it to the settings). The group id is never rendered. Handler: `agent/post/rmm_remote_tools.php` actions `mesh_install` (`force`) and `mesh_uninstall` (`confirm`), `RemoteActions::installMeshAgent()` / `uninstallMeshAgent()`; the caller sends nothing else, the address, device group and pin are read from the saved settings by Core when the job is created. The tab and the controls need `rmm.remote.manage` (the same grant as the terminal and the service manager) and the remote-tools switch; Core checks both again, another client's device answers like a missing one.
* **Agent fleet page, bulk install.** A collapsed card *Install the MeshCentral agent on many devices*: pick every device you may manage, one client, one tag or one group, press *Count the devices* (Core answers with the number it would reach and queues nothing), tick the confirmation, press *Install*. The answer lists what was queued and what was skipped (already installed, already queued, agent too old, no permission for that client, error). It is safe to repeat. Core caps one request at `bulk_run_max` devices and **refuses** one above `approval_bulk_threshold` (`409 approval_required`: the two-person approval queue only knows script runs), so a large fleet is installed in batches by client or tag. Handler `agent/post/rmm_mesh_bulk.php` (`RemoteActions::installMeshAgentBulk()`); a user limited to some clients can only reach those.
* REST (Core, additive): `POST /api/v1/endpoint_devices/{id}/mesh/install` `{force?}`, `POST .../mesh/uninstall` `{confirm}`, `POST /api/v1/endpoint_devices/mesh/install` `{target, force?, confirm}` (see `docs/API.md` and Core's `docs/rmm/openapi-device.yaml`). Needs an rc.16 agent for the install (`409 unsupported` otherwise).

**(B) MSI installer format.** An MSI installer is a copy of the current **MSI template** for the architecture with the same per-client payload appended (the stored template is never changed), streamed with an exact length; named `RivetIT-Agent-<client>-x64.msi`.

* **Format selector** (`exe` default | `msi`): the Add device dialog (Agent fleet page and the client page) and Administration > Endpoint agent > Deployment. The msi option is disabled, with the reason, when no template is current for the chosen architecture (Core builds an x64 MSI only: an ARM64 PC uses the exe unless an ARM64 template built elsewhere is published). Without JavaScript the server refuses an MSI that cannot be made, in words, and leaves no token behind. The optional *Also install the MeshCentral agent* box works the same. Linux has no format.
* **Deployment commands** with format msi gain an MSI block: `msiexec` for the stamped file, the property form (`SERVER`, `ENROLL_TOKEN`), the silent PowerShell downloader for an RMM, Intune or GPO startup script, the uninstall (the Mesh agent stays unless `REMOVE_MESHAGENT=1`), and the detection data (product name and UpgradeCode).
* **MSI templates card** (Administration > Endpoint agent): upload (`RmmAdmin::uploadMsiTemplate()`: a Windows Installer / OLE compound file within the size limit, not already stamped, the platform read from the package summary, else the uploader's word, shown as *declared by uploader*), the list (version, size, SHA-256, platform source, state) and Make current / Deactivate / Reactivate (`msiTemplateAction()`, audited). CLI: `php scripts/endpoint_agent_publish.php rivetit-agent-windows-amd64.msi --msi --version 1.0.0 --arch amd64 --activate`. Build the template with RivetCore's `make msi` (release tag `agent-v*`, with `SHA256SUMS.msi`).
* **Unsigned and UNVERIFIED on Windows.** The package is built on Linux and table-checked; it has never run on Windows. Whether `msiexec` accepts a package with the payload appended after the end of the compound file, what repair and upgrade do, and the whole custom-action flow are unverified (RivetCore `docs/rmm/MSI_VERIFY.md` is the owner's checklist). SmartScreen and some policies warn about or block an unsigned installer. The card, the dialog and the deployment commands say so. The exe installer is unchanged and stays the default.
* Migration 0025 (DB 2.6.91) adds one table, `endpoint_agent_msi_templates`, through the Core runner; until it runs everything else works and the card says to run the database update.

Tests: `tests/rmm_ui_p16_mesh_push.php` (the Remote tab controls, the handler, the bulk card and handler, roles, another client's device, CSRF, the approval threshold, module off), the MSI section of `tests/rmm_installer_ui.php` (the Format selector, upload and its refusals, the download: OLE header, decodable trailer, stored template untouched, a real msitools package; the Administration cards; the CLI), `tests/browser/rmm_p16_smoke.mjs`, and the push section of the real agent proof (`tests/real_agent_proof/run.sh`: a real Linux agent enrolled without Mesh is pushed `mesh_install` through the technician API against the mock Mesh server).

## 6h. The Remote button opens the remote screen in a new tab (RivetCore 1.0.0-rc.17, migration 0026, DB 2.6.94)

**The URL.** `<mesh>/<domain>/?login=<token>&gotonode=<node>&viewmode=<11|12|13>&hide=<mesh_hide>`. `hide` is MeshCentral's own bitmask (read from MeshCentral's page source: 1 masthead, 2 top bar, 4 footer, 8 page titles, 16 left bar and device list, 32 back button); the default **31** leaves only the device view, as Tactical RMM's "Take control" does. **Administration > Endpoint agent > MeshCentral > Hide MeshCentral interface elements** sets it (0 to 255; 0 omits the parameter, which is the URL of earlier versions); an optional second value applies to Terminal and Files (empty = same; 0 allowed). Core migration `0026_rmm_mesh_hide` (two columns on `endpoint_agent_mesh_settings`); until it runs the default 31 is used and the fields are not drawn.

**The tab.** `js/rmm_launch.js` opens a blank tab synchronously in the click (a browser blocks a tab opened after an asynchronous request), the launch call runs, and the tab is sent to the URL when it answers; a failed launch closes the blank tab and shows the error; a refused tab shows an **Open remote session** link. Used by the asset panel, the Remote tab (Desktop, Terminal, Files), the legacy device page and the Tactical asset page. Every click opens a new tab.

**The node id (RivetCore 1.0.0-rc.18).** `gotonode` is the node's 64-character MeshCentral id **without** the `node//` prefix (what the device page's address bar shows after `gotonode=`), `@` and `$` literal, e.g. `...?login=<token>&gotonode=<64 characters>&viewmode=11&hide=31`. The agent reports the same 48 bytes as 96 hex characters, which MeshCentral does not find; every stored form is converted when the link is built and when an id is saved or reported, and migration `0027_rmm_mesh_node_ids` rewrites the stored ids to `node//<64 characters>`. In the Mesh node field paste the address bar of the device in MeshCentral, the node id, or the 64 characters. **Not verified against a live MeshCentral server** with this exact link.

**Connect.** MeshCentral does not connect by itself from a URL (its page only selects the device and the view): the tab shows the Desktop with MeshCentral's own **Connect** button. **Not verified on a live MeshCentral:** `tests/browser/rmm_rc17_remote_smoke.mjs` runs against a mock Mesh server, and the `hide` bits are from MeshCentral's source, not from a live server of every version.

## 6i. Agent updates and Build installer (26.10.28, DB 2.6.98)

* **Agent updates card** (Administration > Endpoint agent): `includes/rmm_agent_feed.php` (`RivetMsp\AgentFeed\Feed`, `FeedHttp`, `LinuxInstall`), `includes/rmm_agent_feed_ui.php`, handlers at the end of `admin/post/settings_endpoint_agent.php`. State lives in `endpoint_agent_feed_state` (keys `repo`, `badge`, `cache`, `checked_at`, `last_error`, `linux:<version>`).
* **Publish** = checksum lists, download and SHA-256 check of every Windows file, then `RmmAdmin::uploadBinary()` / `uploadMsiTemplate()`, then the Linux links. Nothing is stored unless every Windows file verified. The test seam `RIVET_AGENT_FEED_TEST_ORIGIN` (a `config.php` constant, loopback mock only) is never read from the database, a request or the environment by the code itself.
* **Build installer page** `agent/rmm_add_device.php` + `js/rmm_add_device.js`; handler `agent/post/rmm_installer.php` (modes `download` and `commands`, Windows and Linux).
* **Install now**: the live-install switches (`patch_live_install`, `software_live_install`) gate only the scheduler; a person with the install / deploy right installs on one device with confirmation and audit.
* Tests: `tests/rmm_agent_feed.php` (+ `tests/support/mock_github.py`, fragments in `tests/support/`), `tests/browser/rmm_agent_feed_smoke.mjs`.


## 6j. Build a new agent version (26.10.31, no DB change)

* Code: `includes/rmm_agent_build.php` (`RivetMsp\AgentFeed\Build`, `rivetAgentBuildPublisher()`), `includes/rmm_agent_build_ui.php` (the box inside the Agent updates card), `js/rmm_agent_build.js` (8 s poll while a build is running), `admin/post/agent_build_poll.php` (POST, CSRF, administrators; the poll also does the automatic publish), `cron/agent_build_check.php` (every 5 minutes, exits at once when nothing is running), handlers in `admin/post/settings_endpoint_agent.php`. `FeedHttp::get()` gained a POST body and the token rule.
* State: `endpoint_agent_feed_state` keys `gh_token` (encrypted with `encryptSetting()`, context `agent_feed_token`), `gh_token_test`, `build` (JSON: version, run id, state, auto-publish options, who started it).
* GitHub calls (all through `FeedHttp`, token only to api.github.com, no redirects for token requests): `GET .../workflows/endpoint-agent.yml` (Test token), `GET .../workflows/endpoint-agent.yml/runs`, `POST .../dispatches` `{"ref":"main","inputs":{"version":"X.Y.Z"}}`, `GET .../actions/runs/<id>`. The run created by the dispatch is the earliest run with an id higher than the newest one seen just before.
* Token: fine-grained, one repository, **Actions: Read and write**, nothing else. Test token proves read access only; the first build proves write access.
* Cron (existing server): `*/5 * * * * www-data /usr/bin/php <app>/cron/agent_build_check.php >> /var/log/itflow-agent-build-cron.log 2>&1` and create that log file (www-data writable).
* Tests: `php tests/rmm_agent_feed.php` (mock: `tests/support/mock_github.py` handles dispatch, runs, token check; part `tests/support/rmm_agent_build_part.php`), browser: `tests/browser/rmm_agent_build_smoke.mjs` with `tests/browser/rmm_agent_feed_extra.php <dir> <version>` adding a release to the mock.

## 6j. Plain options instead of JSON (26.10.29, no DB change)

* **Check schedule** (Administration > Endpoint agent > "What the agent checks"): `includes/rmm_checks_simple.php` (`RivetChecksSimple`: `decompose()` stored list -> options, `compose()` posted options -> list, then `ChecksValidator`), `includes/rmm_checks_simple_ui.php`, `js/rmm_checks_simple.js`, handler in `admin/post/settings_endpoint_agent.php` (`$_POST['cs']`; a posted `checks_json` is ignored). Presets, intervals, the disk limits, the services checklist and the script limits are constants of the class. Checks without an option stay as "other checks" and are copied from the stored list by key. A refused save keeps the options in `$_SESSION['ea_checks_draft']` for one page view (ten minutes).
* **Policy editor**: the original types use `RivetChecksSimple::legacyFields()` (service, disk, script, restart) rendered by the same field renderer as the newer types; `legacyProblems()` gives the plain-word refusals. **Mesh card**: `mesh_agent_id[<platform>]` and `mesh_install_arg[<platform>]` boxes (`includes/rmm_mesh_admin.php`), Core still validates. **Older check policies** (`agent/rmm_checks.php`): `RivetChecksSimple::policyParams()` builds the stored settings from `pp[...]`.
* Tests: `tests/rmm_checks_simple.php`, `tests/browser/rmm_checks_simple_smoke.mjs`.

## 7. Not verified

A real MeshCentral server (only Core's mock and our local TLS stand-in; this includes the optional Mesh agent install of 6e), the Windows agent and installer on a Windows host (including the MSI installer of 6g and the pushed Mesh install on Windows) and the Windows scripts of the starter pack (6f), and a production-sized fleet (see Core's `docs/rmm/CAPACITY.md`).

## 6k. Create installer entry and the Remote access (MeshCentral) setup wizard (26.10.30, no DB change)

* **Create installer entry** `includes/rmm_installer_entry.php` (`rivetRmmInstallerEntry()`): states `ok`, `module_off`, `no_permission` (names `rmm.token.manage`, "Create enrollment tokens"), `no_service`; plus notices `no_agent` / `no_msi` linking to `/admin/settings_endpoint_agent.php#agent-updates`. Used by `agent/rmm_dashboard.php` and `agent/rmm_fleet.php`; the Add device dialog links to `agent/rmm_add_device.php`. The builder page and handler still check the permission and CSRF themselves.
* **Wizard** `admin/rmm_mesh_wizard.php` (page), `admin/post/rmm_mesh_wizard.php` (handler, picked by the Referer like every admin handler, administrators only, CSRF), `includes/rmm_mesh_wizard.php` (`rivetRmmMeshWizardSteps()` decides the live status, `rivetRmmMeshWizardPage()` renders, `rivetRmmMeshWizardRead()` reads the forms). Each step posts only its own fields; the handler merges them onto the saved settings and calls `RmmAdmin::saveMesh()` (Core validates, authorizes, audits). Statuses come from the saved settings and device counts: 1 address + key + switch (and the last test of this session), 2 device group saved, 3 pin saved or the certificate read shows a public authority, 4 Core's own install readiness, 5 at least one live device mapped. "Read it from the server" only proposes a pin.
* Tests: `php tests/rmm_mesh_wizard.php`, `node tests/browser/rmm_mesh_wizard_smoke.mjs` (see the file header for the throwaway-install recipe). Not verified: against a real MeshCentral server (none available; "Save and test" is proven to refuse an unreachable one).

## 6m. Build on my build host (26.10.33, DB 2.6.99)

Agent updates > Build a new version can build on a machine you own instead of GitHub. The build host (RivetCore `endpoint-agent/scripts/build-host.sh`, spec `docs/rmm/BUILD_HOST_PROTOCOL.md`) polls this site; the server never connects to it.

- **Endpoints** (POST, JSON, `Authorization: Bearer rbh_...`): `/api/v1/agent_build_host/poll`, `/status`, `/upload`, `/complete`. The host's `SERVER_URL` is `https://<site>/api/v1/agent_build_host`. Errors are `{error, code}`: 401 `invalid_token`, 404 `no_job` (also for another host's job), 409 `cancelled`/`bad_state`/`conflict`, 410 `expired`, 413 `too_large`, 422 `bad_name`/`bad_chunk`/`bad_chunk_hash`/`bad_manifest`/`verify_failed`, 429 `rate_limited`.
- **Jobs**: queued, claimed (15 minute lease, renewed by status and upload; an unstarted claim returns to the queue), building, uploading, verifying, built, published, failed, expired (60 minutes without status), cancelled. One job per host; at most three open jobs; one open build per version; the version must be higher than the GitHub list and the hosted versions.
- **Upload**: 1 MiB chunks, fixed names (`rivetit-agent-windows-amd64.exe`, `-arm64.exe`, `rivetit-agent-windows-amd64.msi`, `SHA256SUMS`), a repeated identical chunk is a no-op, a different one is 409. Caps: the binary size limit per file (`EA_BINARY_MAX_BYTES`, default 64 MiB), 16 KiB for `SHA256SUMS`, three files plus sums per job.
- **Verification and publish**: every file is re-assembled and re-hashed; hashes must match the manifest (where listed) and `SHA256SUMS`; PE type and architecture and the MSI template go through the same checks as the upload form; an executable that embeds a different `RIVETIT_BUILD_VERSION=` marker is refused (the validators cannot read a version themselves). With "publish automatically" the job is published as the requesting administrator (ring, rollout and make-current from the request); otherwise it waits as **built** (7 days) and an administrator presses Publish. Linux files are not uploaded.
- **Quarantine folder**: `backups/endpoint-build/<job id>/` (override with `EA_BUILD_DIR`); emptied on publish, failure, cancel and expiry.
- **Rate limits** (Redis, as the other API limits): 300 requests a minute per address, 120 a minute per host (600 for uploads), 20 failed tokens a minute per address.

## 6n. Build progress and the RMM Agent Builder installer (26.10.34, DB 2.6.100)

**Build progress.** The build host script sends optional fields on its `status` call (RivetCore `docs/rmm/BUILD_HOST_PROTOCOL.md`): `stage` (`prepare`, `clone`, `build_windows_amd64`, `build_windows_arm64`, `msi`, `verify`, `upload`), `pct` (0-100 overall), `detail` (at most 120 printable ASCII characters) and `elapsed_s`. They are validated strictly: anything else (a stage off the list, a percent outside 0-100 or not a whole number, a longer or non-printable detail, a bad `elapsed_s`) is `422 bad_progress` and the whole call stores nothing. Integers may be JSON numbers or digit strings. A call that sends none of them behaves exactly as before. The percent never moves backwards for a job (`GREATEST` in SQL), a stage earlier than the recorded one is dropped, a terminal call (`failed`) ignores progress, and a job that returns to the queue forgets it. Every status call renews the lease, with or without progress. The final 100 arrives before `complete`; the page shows 99 until the server has verified the files. Stored in `endpoint_agent_build_jobs.progress_stage / progress_pct / progress_detail / progress_at` (updater step 2.6.100: four `ADD COLUMN IF NOT EXISTS`, no row touched; the section asks for the update until the columns exist).

The Local build list shows, per job: a progress bar with percent, the current stage in plain words, the detail line, the time since the build was requested, and a checklist of the stages (done, active, pending; failed for the stage a failed job stopped at; no MSI step when the job does not want the MSI). It polls every 5 seconds (`admin/post/agent_localbuild_poll.php`) and writes every host-supplied string as text. The GitHub provider shows the workflow run's steps (GitHub's set-up and post steps left out) as the same kind of checklist with a percent estimate (finished steps over all steps, at most 95 until the release is in).

**RMM Agent Builder installer.** `deploy/setup-build-host.sh` (also asked by `deploy/install.sh`: "Would you like to install the RMM Agent Builder (build the agent .exe/.msi on this server from the web UI)? [y/N]"; `--with-build-host` / `--no-build-host`; a `--non-interactive` install installs nothing unless told to) installs a pull-model build host on this server. One line, also shown on Agent updates > Local build:

```
sudo /var/www/<site>/deploy/setup-build-host.sh            # --check previews, --yes skips the question, --uninstall [--purge-docker] removes it
```

It is idempotent and does, in order: install `docker.io` (skipped when Docker is there), git and python3; create the system account `rivetit-build` (home `/var/lib/rivetit-build-host`, `nologin`, member of `docker`); download `build-host.sh`, `build-host.service` and `build-host.timer` from RivetCore at a **pinned commit** (the constants at the top of the script) and refuse unless each file matches its pinned SHA-256; install the script root-owned 755 into `/opt/rivetit-build-host/` and the units into `/etc/systemd/system/`; create the build host through `scripts/build_host_setup.php` as the web user (the same `LocalBuild::addHost` / `rotate` as the page's buttons) and write `/etc/rivetit/build-host.conf` (`SERVER_URL=<site>/api/v1/agent_build_host`, `CORE_REPO`, `ALLOWED_REFS`, `WORKDIR`, limits; mode 600, owned by `rivetit-build`); `enable --now build-host.timer`; run `build-host.sh --register`. A second run keeps a token the server still accepts, and replaces it otherwise.

- **Docker group is root-equivalent.** Only `rivetit-build` (which cannot log in) is added to it. The script warns, and **refuses to run if the web user is already in the `docker` group**; it never adds it.
- **The token** goes from the application into a shell variable and straight into a 0600 file; it is not printed, not placed on any command line, not in the application's audit log. `build_host_setup.php create` prints the token and nothing else on stdout (exit 0), so do not run it by hand on a shared terminal.
- **Config injection.** The conf is sourced by bash, so every value (`--name`, `--allowed-refs`, `--core-repo`, `--workdir`, `--site-url`) is checked against a strict allowlist; the site URL must be a plain `https://` address.
- **Root only.** A real run as non-root is refused; `--check` needs no root and changes nothing (it still downloads and verifies the pinned files).
- **Flags**: `--app-dir`, `--name` (default `build-<hostname>`), `--site-url`, `--core-repo`, `--allowed-refs`, `--workdir`, `--web-user`, `--php-bin`, `--no-register`, `--keep-host`, `--keep-workdir`, and `--fetch-base` (tests only: a `file:///` or https base for the three files; the SHA-256 pins still apply).
- **Bumping the pin.** Choose the RivetCore commit, take the SHA-256 of `endpoint-agent/scripts/build-host.sh`, `build-host.service` and `build-host.timer` at that commit (`git show <commit>:endpoint-agent/scripts/<file> | sha256sum`), change `CORE_PIN_COMMIT`, `PIN_SHA_SCRIPT`, `PIN_SHA_SERVICE`, `PIN_SHA_TIMER` together, and run the three test scripts below. The current pin is RivetCore `9d2003165e29` (build progress, protocol 1.1).
- **Not covered**: a real Docker build (the box that built 0.9.60 on 2026-10-19 proves the script); `apt-get install docker.io` itself (stubbed in the container test); a non-systemd machine (the script says to run it from cron).

Tests: `tests/rmm_agent_localbuild.php` (progress and updater, 2.6.98 to 2.6.100 and 2.6.99 to 2.6.100), `tests/build_host_setup.php` (the CLI), `tests/build_host_setup/run.sh` (shellcheck, validation, check mode, the SHA-256 pin and tamper refusal), `tests/build_host_setup/container.sh` (a real install, second run, tamper, uninstall as root in a throwaway container with stubbed Docker and systemd), `tests/build_host_setup/installer_flags.sh` (the install.sh question and flags), `tests/browser/rmm_agent_localbuild_smoke.mjs`.

## 6l. Administration > Endpoint agent as tabs (26.10.32, no DB change)

`admin/settings_endpoint_agent.php` prints each card into the bucket of a tab with `$eaTab('name')` (output buffering; cards, forms and field names untouched) and `rivetEaRenderTabs()` in `includes/rmm_ea_tabs.php` assembles them. Tabs: **overview** (module, settings, pending, devices), **updates** (agent updates and Build a new version, binaries, MSI, deployment, rings), **checks**, **remote** (remote tools, file transfer, MeshCentral), **inventory** (software inventory, log collection, scale, partitioning, external store), **patching**, **software**, **security** (certificate and policy, policies/scripts/alerting, signing key, tokens).

* Behaviour (`js/rmm_ea_tabs.js`, no inline handlers): tablist roles, arrows/Home/End, `#tab-<name>` or any card id in the hash opens the right tab (also on `hashchange`), an unknown hash opens Overview. Without the script every pane shows.
* Save returns to its tab: every post form in a pane gets a hidden `ea_tab` (PHP, so it works without JS); `admin/post/settings_endpoint_agent.php` turns a valid value into the Referer used by the plain `redirect()` calls. Handlers that redirect to `#card-id` keep working because that id resolves to its tab.
* The "Service and defaults" form is `#ea_settings_form`; the check editor and the CA / coexistence fields are linked to it with `form=` and have their own Save (`save_agent_settings`).
* Dots (`$eaDots` at the end of the page): service off / state mismatch / devices waiting; no agent published; Mesh not set up; no active token.
* Tests: `node tests/browser/rmm_eatabs_smoke.mjs`; `tests/rmm_ui*.php` assert the cards by id.

## 6o. Every time in the company's time zone (26.10.36, no DB change)

No RMM, Endpoint agent or admin screen shows "UTC" for a timestamp any more. Every date and time is shown in the **company time zone** (Administration > Settings > Localization; the signed-in session's `session_timezone`; the server's PHP zone only as fallback), DST-correct, with the short zone label (`2026-07-04 10:42 CDT`, `... 09:42 CST` in winter). Column headings say just "Uploaded", "Expires", "Last check-in"; the label sits in each cell (or the tooltip of a relative time). Durations (elapsed, "5 min ago") are unchanged.

**Code.** `includes/rivet_time.php` is the shared helper (the same file as in RivetIT: `rivetFormatTime($value, 'utc'|'local'|'epoch', $withZone, $format)`, `rivetTimeIso`, `rivetTimeEpoch`, `rivetTimeZoneLabel`). `includes/rmm_time.php` adds the RMM wrappers (`rivetRmmTime($v, $utc = true, ...)`, `rivetRmmTimeTag`, `rivetRmmTimeInput`, `rivetRmmTimeFromInput`). Output is plain text; the caller escapes. Null, empty, zero-date and invalid input give an empty marker.

**The storage trap.** Two kinds of column reach these pages. RivetCore and every `endpoint_agent_*` table store **UTC** (`UTC_TIMESTAMP()`, `gmdate`, RFC 3339 `Z`, epoch): converted on output (`$utc = true`). The edition's own legacy tables are written with `NOW()` under the company zone (the web session and `cron/cron.php` both run `inc_set_timezone.php`): they are **already company time**, so they are only formatted and labelled (`$utc = false`), never converted a second time.

| Page / fragment | Column | Storage | Output |
|---|---|---|---|
| Endpoint agent > Agent updates: agent binaries, MSI templates | `endpoint_agent_binaries.created_at`, `endpoint_agent_msi_templates.created_at` | UTC (Core `utcNow()`) | converted |
| Agent updates: public releases list | `published_at` (GitHub, `Z`), `recorded_at` (`gmdate`) | UTC | converted |
| Endpoint agent: enrollment tokens (expires, last used), new-token notice, failed attempts, signing key created | `endpoint_agent_enrollment_tokens.*`, `endpoint_agent_enroll_attempts.attempted_at`, settings | UTC | converted |
| Endpoint agent: devices list (last check-in, first seen) | `endpoint_agent_devices.*` | UTC | converted |
| Device page (`agent/rmm_agent_device.php`): check-in, collection, inventory, enrolled, checks reported, jobs created/finished | `endpoint_agent_*` | UTC | converted |
| Build on my build host: job requested, host last seen | `endpoint_agent_build_jobs.created_at`, `endpoint_agent_build_hosts.last_seen_at` | UTC (`gmdate`) | converted; durations (elapsed, idle) unchanged |
| Build a new version: token test time | session epoch | epoch | converted |
| Scale cards: partition probe time, partition bounds, oldest waiting metric, rollup watermark, mesh certificate expiry, warm-start end | `rmm_storage_state`, `rmm_metric_outbox`, Core probe | UTC | converted (a rollup day starts at 00:00 UTC; the card states that moment in company time) |
| RMM asset panel and fleet: last seen, checks, jobs, software, patches, remote tasks, "last boot" tooltip | Core read models | UTC | relative text; tooltip = company time |
| Performance charts (hour axis and tooltip) | `rmm_metric_hourly` epoch | UTC | converted in the browser with `Intl` in the company zone passed by the server |
| Logs (`rmm_logs.php`, asset Logs tab): entry times, filters From/To, range searched | `endpoint_agent_log_events.occurred_at` | UTC | converted; the custom range is typed in company time |
| Alerting: alert opened/acknowledged/resolved/next notice, maintenance windows | Core alert tables | UTC | converted; the window form shows the company-time equivalent when the window's own zone differs |
| Scripts, schedules, approvals, policies, software, patch | Core | UTC | converted (`rivetRmmAutoTime`, `rivetRmmUiTime`) |
| Install-command validity (Create installer, Add device) | Core token `expires_at` | UTC | converted |
| RMM dashboard, alerts list, asset RMM tab, script run, remote sessions, integrations sync log | `rmm_alerts`, `rmm_remote_sessions`, `rmm_script_runs`, `rmm_sync_log`, `asset_rmm_links.last_seen/last_sync` | company-local (`NOW()` / `date()`) | formatted + labelled, not converted |
| Audit trail rows | `audit_events.created_at` | company-local (`DEFAULT current_timestamp()` under the web session zone) | formatted + labelled |
| Audit trail "chain checked", Recovery panel "last sync" | `gmdate` / epoch | UTC | converted |

**Deliberately still UTC.** (1) Cron expressions in a schedule (`*/15 * * * *`) are evaluated by Core in UTC; the form says so and the next-run column shows the company time. (2) Zone pickers list `UTC` as a zone name. These are allow-listed, with the reason, in `tests/rmm_time_utc_allow.txt`. CSV exports, the audit JSONL sink and API payloads keep their machine formats.

**Known inconsistency (not changed here).** `src/Audit/AuditSink.php` labels `audit_events.created_at` as `Z`, but web-written rows are company-local when the company zone is not UTC. Fix separately if the SIEM export matters.

**Tests.** `tests/rmm_time.php` (helper: zones, DST transitions in America/Chicago, UTC vs local, null/zero/invalid; lint that fails on a new hard-coded "(UTC)"/" UTC" label in these pages), `tests/rmm_time_pages.php` (the real pages with a non-UTC company zone, three zones, summer and winter).

## 6p. Which category an alert ticket gets (26.10.37, DB 2.6.101)

Administration > Integrations > RMM > **Alert tickets**. The admin picks the ticket category tickets made from RMM alerts go into (a one-click button creates a "Maintenance" ticket category and selects it when none exists) and may add special cases: **when** a check family fails (disk space, service, patching, reboot needed, agent update, log rule, change monitor), **when** the alert comes from a given RMM, or **when** it has a given severity, use category X and optionally priority Y. A plain-language summary under the table says what will happen. RivetMSP has no ticket boards or queues, so there is no board choice (the route table keeps a `board_id` column, always NULL, for parity).

**Order, most specific first:** check family, then source, then severity, then the default category. Inside one kind the lowest `sort` wins; each "when" choice can be used once. A rule priority replaces the severity-derived priority; no rule priority keeps it. **Nothing configured is identical to before** (no category). An archived or deleted category is never used (the ticket gets none).

**Where it applies.** One function: `createTicketFromRmmAlert()` (`includes/rmm_functions.php`) asks `rmmAlertTicketPlacement()` (`includes/rmm_alert_routes.php`). That covers cron auto-ticketing, the "Create ticket" button, the `ticket_created` automation action and the endpoint agent's critical-alert escalation. Which alerts become tickets by themselves is still decided by the severities in "Automatic Ticket Creation". Comet backup failure tickets (`includes/comet.php`) and event-bus tickets are not RMM alerts and are unchanged.

**Check families** are prefixes of the endpoint agent's check key (`disk_c`, `disk.utilization`, `patch.compliance`, `log.rule_N`, `service.*`, `reboot.*`, `agent_update*`, `change.*`); Tactical/Level alerts carry no check key, so only source and severity rules apply to them. **Security:** admin only (the admin POST handler), CSRF token on both forms, every posted value checked against fixed lists and the existing active Ticket categories, parameterised by escaping and integer casts, audit log entries for every save and for creating Maintenance. Tests: `tests/rmm_alert_category.php`, `tests/browser/rmm_alert_category_smoke.mjs`.
