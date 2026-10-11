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
php tests/rmm_ui.php                       # asset panel, fleet, Phase 2/3 and (tests/rmm_ui_p68_*.php) the remote tools, logs and scale settings pages
node tests/browser/rmm_p68_{remote,logs,admin}_smoke.mjs   # real-browser smokes of the same (see the headers; RIVETMSP_WEBHOOK_ALLOW_PRIVATE=1 for the admin one)
RIVETMSP_SOCKET=... AGENT_BIN=.../rivetit-agent-linux-amd64 tests/real_agent_proof/run.sh   # the real Linux agent under systemd against RivetMSP's bridges (docker; 39 checks)
```

The scratch `config.php` must define `EA_ALLOW_INSECURE_HTTP` (the golden runner starts a second server with `EA_TEST_NO_INSECURE=1` for the 426 transcripts),
`$config_settings_enc_key`, `$config_enable_setup = 0`, and may honour `RMM_TEST_STATE_DIR` / `RMM_TEST_NO_ENC_KEY` / `RMM_TEST_DATABASE` / `EA_TEST_LINUX` (see the
headers of the test files). The golden runner records this install into a temporary directory and compares it with Core's transcripts (recorded from the original RivetIT code): everything is identical except the four "disabled" exchanges of `01-disabled.json`, the one intentional wire change (a disabled module answers 503 `module_disabled` with `Retry-After: 3600`, not 403 after a database lookup; RivetMSP has no enrolled agents to stay compatible with). `php tests/endpoint_agent_golden.php strict` runs the driver's own replay instead, which reports exactly that difference.

## 6a. Asset page panel and Agent Fleet (T10b)

`includes/rmm_ui.php` (view-models, read-only, first call is the state file), `includes/rmm_ui_render.php` (HTML), `css/itflow_rmm.css`, `js/rmm_panel.js`, `agent/rmm_fleet.php`, `agent/rmm_job_output.php`, and the panel inside `agent/asset_details.php`. Actions are unchanged (`agent/post/rmm_agent.php`). RivetMSP specifics: clients (not departments); no Metrics subsystem, so the Performance section explains that the latest check-in is all that is kept; the Endpoints menu shows Agent Fleet whenever the module is on, even with the vendor RMM integrations off. Tests: `php tests/rmm_ui.php` (needs `EA_TEST_LINUX=1`) and `tests/browser/rmm_seed.php` + `tests/browser/rmm_smoke.mjs`.

## 6b. Policies, scripts and alerting (RivetCore 1.0.0-rc.10, DB 2.6.84)

Three sub-switches, all off by default (Administration > Endpoint agent > "Policies, scripts and alerting"): `policies`, `scripts` (script library, schedules, approvals, custom fields)
and `alerting` (thresholds, flap detection, maintenance windows, escalation). The pages (`agent/rmm_policies.php`, `rmm_script_library.php`, `rmm_schedules.php`, `rmm_approvals.php`,
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

## 6d. Scale settings, storage and the Performance and capacity panel (RivetCore 1.0.0-rc.11, S3 to S8)

Administration > Settings > Endpoint agent > the cards **Scale settings**, **Metric storage and partitioning** and **External metric store**, and the read-only **Performance and capacity** panel on the Agent Fleet page (autoscaling hint, read cache, warm start, per-client token buckets, shedding, the three storage tiers and the rollup status).

* **RivetMSP writes metrics through Core's `DatabaseMetricSink`**, so Core's tiers are live here (unlike RivetIT, which keeps its own metric tables): `rmm_metric_hourly`, the daily tier `rmm_metric_daily` that housekeeping builds from it (readings over 7 days are answered from it; `rivetRmmMetricReader()` and the module's reader are the same), and `endpoint_agent_check_history`. Retention: `metric_hourly_days` (14), `metric_daily_days` (400), `check_history_days` (7).
* **Partitioning (opt-in, `partitioning`):** the card runs Core's capability probe and says in words which of the THREE tables it can convert; **Enable** (with a confirmation, because the one-time copy blocks writers on a big table) converts each convertible table and names any it had to leave plain; housekeeping then maintains the partitions and drops expired ones (guarded by the rollup watermark). Checked on MariaDB 11.8 (`tests/rmm_ui_p68_admin.php`).
* **Herd control (off by default):** warm start after the module is switched on again or after a quiet gap (the gate reads `rmm_herd.json` in the state directory) and a token bucket per client for check-ins and enrollment.
* **External metric store (off by default, S4):** only for installs far beyond 10,000 devices. Switch, write URL, read URL and bearer token live in `settings.config_rmm_extstore_*`, sealed with `encryptSetting()` and registered for key rotation (`ColumnRegistry`; `tests/crypto_rewrap.php` fails when a secret-looking column is missing from the registry). **Test connection** vets the URLs with the webhook `UrlPolicy` (https, public addresses, no redirects); the module wires a `CompositeMetricSink` with the database sink FIRST, so Core's rollups and partitions keep applying. Plain http to a loopback fake server exists only in the automated tests (`EA_ALLOW_INSECURE_HTTP` and `RIVETMSP_WEBHOOK_ALLOW_PRIVATE=1`).
* **Not done:** Core's S9 validation (24 hours, 10,000 devices, reference hardware) has not been run; nothing here was load-tested beyond the scratch suites.

## 7. Not verified

A real MeshCentral server (only Core's mock), the Windows agent and installer on a Windows host, and a production-sized fleet (see Core's `docs/rmm/CAPACITY.md`).
