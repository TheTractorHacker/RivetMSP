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
| Device REST bridges | `api/v1/agent_enroll.php`, `agent_checkin.php`, `agent_jobs.php`, `agent_update.php`, `agent_installer.php` via `api/v1/includes/agent_device_api.php`; routed in `api/v1/index.php` above the Bearer parsing |
| Technician REST API (user API token; the legacy shared key is refused) | `api/v1/endpoint_devices.php`, `case 'endpoint_devices'` in `api/v1/index.php` |
| Pre-bootstrap gate | `api/v1/rmm_gate.php`, the first include of `api/v1/index.php` |
| Administration page and its POST handler | `admin/settings_endpoint_agent.php` (Settings > Connections & data > Endpoint agent), `admin/post/settings_endpoint_agent.php` |
| Device page and its JSON actions | `agent/rmm_agent_device.php`, `agent/post/rmm_agent.php`, the endpoint-agent branch of `agent/post/rmm_remote.php`, the "Agent device" button on the asset page |
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
it; `encryptSetting()` would otherwise store the key in plaintext, so `EndpointSecretBox` refuses and the page keeps the switch disabled with the reason. A
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

No new permission keys. The nine Core abilities map onto the existing RMM module grants (`user_role_permissions` joined to `modules`, `role_is_admin`) in
`EndpointAccessPolicy`, the same matrix as RivetIT minus module-only logins (which RivetMSP does not have):

| Ability | Needs |
| --- | --- |
| `rmm.device.view` | `module_rmm` >= 1, and the device's client in scope |
| `rmm.job.run_saved`, `rmm.job.reboot` | view, `module_rmm_scripts` >= 2 |
| `rmm.job.run_script` | view, `module_rmm_scripts` >= 3 |
| `rmm.remote.launch` | view, `module_rmm_remote_connect` >= 1 |
| `rmm.device.manage`, `rmm.token.manage`, `rmm.binary.publish`, `rmm.admin` | administrator (`role_is_admin`) |

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
* **config.php constants** (all optional): `EA_ALLOW_INSECURE_HTTP` (loopback test servers only), `EA_BINARY_DIR`, `EA_BINARY_MAX_BYTES`, `EA_ALLOW_NON_WINDOWS` (the Linux test agent, never production), `RMM_STATE_DIR`.
* **nginx / PHP-FPM.** `client_max_body_size` at least 1m for `/api/v1/agent_checkin`; PHP-FPM must pass `HTTPS` (or the proxy sets `X-Forwarded-Proto: https`). The live nginx rules are not changed by this module.
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
```

The scratch `config.php` must define `EA_ALLOW_INSECURE_HTTP` (the golden runner starts a second server with `EA_TEST_NO_INSECURE=1` for the 426 transcripts),
`$config_settings_enc_key`, `$config_enable_setup = 0`, and may honour `RMM_TEST_STATE_DIR` / `RMM_TEST_NO_ENC_KEY` / `RMM_TEST_DATABASE` / `EA_TEST_LINUX` (see the
headers of the test files). The golden runner records this install into a temporary directory and compares it with Core's transcripts (recorded from the original RivetIT code): everything is identical except the four "disabled" exchanges of `01-disabled.json`, the one intentional wire change (a disabled module answers 503 `module_disabled` with `Retry-After: 3600`, not 403 after a database lookup; RivetMSP has no enrolled agents to stay compatible with). `php tests/endpoint_agent_golden.php strict` runs the driver's own replay instead, which reports exactly that difference.

## 7. Not verified

A real MeshCentral server (only Core's mock), the Windows agent and installer on a Windows host, and a production-sized fleet (see Core's `docs/rmm/CAPACITY.md`).
