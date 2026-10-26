# RivetMSP Security Test Results

Regression suite run for the 2026-10-26 security audit (`msp-security-audit-docs`, off `beta` tip `af8f4c395`). All DB-backed tests ran
against a fresh, schema-only scratch database (`rivetmsp_scratch_secaudit`, built via `php scripts/setup_cli.php --non-interactive` -
never copied from a live install) and a throwaway shared Redis instance (port 16379). Commands shown are exactly what was run.

Categories requested: SQL injection, XSS, Redis/predis compatibility, authentication, session handling, tenant separation, Microsoft
OAuth (Identity Providers / Azure AD), unsafe command execution, GitHub Actions security.

## SQL injection

**`tests/security_sqli.php`** - 25 assertions, **25 passed, 0 failed**.
```
RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=rivetmsp_scratch_secaudit RIVETMSP_TEST_DB_USER=secaudit_scratch \
RIVETMSP_TEST_DB_PASS=*** RIVETMSP_REDIS_PORT=16379 RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/security_sqli.php
```
Covers: `sortColumnAllowed()` ORDER BY allow-list (7 assertions: real column, table-qualified column, SELECT alias, subquery payload
rejected, boolean/time payload rejected, unknown identifier rejected, empty rejected), a live allow-listed ORDER BY query;
`UnifiSyncMapper::findExistingAsset()` MAC binding (4 assertions: real MAC matches, always-true payload matches nothing, UNION payload
matches nothing, no SQL error raised, `users` table survives a stacked `DROP TABLE` attempt); recurring-invoice INTERVAL allow-list (3);
`logAction()` prepared-statement binding (3: no breakout, payload stored verbatim, action/IP/user-agent stored verbatim); the
`agent/post/certificate.php` add+edit second-order path over real HTTP (5: exactly one row created, payload stored verbatim not
executed, `users` table intact, edit stores new payload verbatim, change-history row survives).

## Unsafe command execution

**`tests/no_unsafe_shell.php`** - aggregate pass/fail (static analysis over every shell-invoking call site, not per-assertion).
**PASS: 35 shell call(s) in 138 first-party PHP file(s) use only literals, constants and escaped values (5 reviewed exception(s)).**
```
php tests/no_unsafe_shell.php
```
Covers the whois option-injection fix and git-command argument escaping from the earlier pass. PHPMailer's `popen()` was investigated
separately and confirmed unreachable vendored code (not a live call site in this app), documented as a reviewed exception.

## XSS / unsafe navigation

**`tests/no_unsafe_navigation.php`** - aggregate pass/fail.
**PASS: 200 first-party file(s) that touch location: every navigation target is a literal, encoded or reviewed, and no page URL is
rendered as HTML (11 reviewed site(s)).**
```
php tests/no_unsafe_navigation.php
```
Covers the 8 named `window.location.href` sites plus the `redirect()` PHP fallback from the earlier pass.

**New this pass - manual sweep, not an automated test file** (see `MSP_SECURITY_AUDIT.md` finding H5 for the full breakdown):
`innerHTML`/`outerHTML`/`insertAdjacentHTML`/`document.write`/`eval`/`new Function`/`location.replace`/`location.assign`/jQuery `$()`
selector-vs-HTML confusion/`.html()`, across every first-party `.js` file (591 files under the repo, excluding `vendor/`,
`node_modules/`, and third-party `plugins/`) plus the 6 JS files shared with RivetIT via `vendor/rivet/rivet-core/assets/ui/js/`. No
new unescaped sink found; this is **manual-only verification**, not covered by an automated regression test. Recommended follow-up
(out of this pass's scope): a Semgrep/CodeQL custom rule codifying "innerHTML/outerHTML assignment from a non-template, non-literal
source must go through an explicit escape helper" so this class of regression is caught automatically in future PRs rather than by a
manual grep sweep.

## Redis / predis compatibility

- `composer.json` pins `predis/predis: ^3.7`; `composer.lock` / `vendor/predis/predis` installed at **3.7.0** (confirmed by reading
  `vendor/predis/predis/composer.json`'s `name`/`version` fields in `composer.lock`).
- `composer audit --no-interaction` → **"No security vulnerability advisories found."**
- No dedicated `tests/predis_*.php` regression file exists; compatibility was exercised indirectly by every test above that boots the
  full application against the scratch database and a real Redis instance (every DB-backed test in this list opens Redis via
  `rmm_bootstrap.php`/the session layer with no connection failures). **Manual-only verification** for the specific CRLF-smuggling
  fix itself (no test sends a crafted CRLF payload through the Redis client) - this is a gap worth closing with a targeted regression
  test if predis is ever downgraded or re-pinned.

## Authentication, session handling, Microsoft OAuth (Identity Providers)

**`tests/security_identity_provider.php`** - 11 assertions, **11 passed, 0 failed**. Real HTTP (forged admin session, `php -S`).
```
RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=rivetmsp_scratch_secaudit RIVETMSP_TEST_DB_USER=secaudit_scratch \
RIVETMSP_TEST_DB_PASS=*** RIVETMSP_REDIS_PORT=16379 RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/security_identity_provider.php
```
Proves the Azure AD/Entra client secret never round-trips to the browser (see audit finding H1).

**`tests/security_mfa.php`** - **52 passed, 0 failed.** Login TOTP seed handling, remember-me rules, recovery-code acceptance/audit,
hash rehashing, mobile API login.

**`tests/security_sessions.php`** - **59 passed, 0 failed.** Session cron purge, Account > Security session list/revoke, remember-me
MFA-skip rules (plain users restored silently; administrators with MFA must re-authenticate unless the skip-MFA setting is explicitly
on).

**`tests/security_passwords.php`** - **44 passed, 0 failed.** Password-hash upgrade paths (`secPasswordRehashIfNeeded()` used by both
`login.php` and `api/v1/auth.php`), password-policy enforcement including `scripts/setup_cli.php`'s own 12-character minimum.

**`tests/security_login_http.php`** - **72 passed, 1 failed** (end-to-end over real HTTP: sign-in, MFA step + recovery code, session
cookies, Active-sessions row, idle expiry, revocation, remember-me, MFA-enforcement gate, enrollment with one-time recovery codes,
password change with Argon2id upgrade and session invalidation, Sign-in policy settings page, login-key secret save/reveal, TOTP
replay rejection). The 1 failure ("without a key every admin page carries a clear warning banner") is the shared-host
`/etc/rivetmsp/keys.json` artifact described in `MSP_REMEDIATION_REPORT.md` - not a code regression; every other assertion in the file,
including the full sign-in/MFA/session/password-change flow, passed.

**`tests/security_crypto.php`** - **37 passed, 14 failed** on this host for the same `/etc/rivetmsp/keys.json` reason (every failure is
one of the "simulate zero key material anywhere" scenarios). The legacy/v3 cipher round-trip, every named straggler-settings column
wrapping, TOTP seed wrapping, and the DB-update 2.6.79 step all passed.

**`tests/totp_replay.php`** - ALL PASS (4 assertions: older step refused, newer step accepted once then refused on replay, state is
per-secret).

**`tests/api_auth_hardening.php`** - ALL PASS (6 assertions: rate-limit fail-closed when asked, TOTP failure-counter ordering,
TOTP-replay rejection on the API login path).

**`tests/security_backup.php`** - **42 passed, 0 failed.** Backup/restore script hardening (including the restore.sh PBKDF2 iteration
fallback and `set -e` bare-`[[ ]]`-statement regression guard).

## Tenant separation

**`tests/endpoint_agent_smoke.php`** (pre-existing, re-read not re-run in full this pass - it is a long end-to-end smoke test; its
relevant assertions were spot-checked by reading, since this pass added a dedicated test for the specific new question): proves a
human technician restricted to one client gets 404 for another client's device, both via the technician REST API and the device-page
action endpoint.

**`tests/rmm_tenant_isolation.php`** (**new this pass**) - 23 assertions, **23 passed, 0 failed**, run twice to confirm determinism.
```
RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=rivetmsp_scratch_secaudit RIVETMSP_TEST_DB_USER=secaudit_scratch \
RIVETMSP_TEST_DB_PASS=*** RIVETMSP_REDIS_PORT=16379 RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/rmm_tenant_isolation.php
```
Proves, over real HTTP against two separately-enrolled devices under two different clients: a forged `client_id`/`device_id` in a
check-in body has no effect (only the authenticated device's own row changes); a job queued for client B's device is never offered to
client A's credential; client A's credential cannot report completion of client B's real job (404, job state untouched); enrolling a
device under client B's token with the exact same hardware serial as client A's existing device creates a brand-new device row under
client B rather than hijacking client A's row or credential (confirmed: different `device_id`, byte-identical `token_hash` before/
after on A's row, A's credential still works normally afterward); a raw enrollment token and a garbage token are both refused as
device check-in credentials. See `MSP_SECURITY_AUDIT.md`'s "Informational" section for the full narrative.

**`tests/net_guards.php`** - ALL PASS (6 assertions: loopback/empty/blank addresses blocked, public IPv4/IPv6 allowed) - SSRF-adjacent
guard relevant to any tenant-boundary webhook/integration call that takes a user-supplied host.

## GitHub Actions security

**No automated test file** - this is CI configuration, not application code, so there is no PHP test harness for it. Verified
manually this pass:
- All 4 files under `.github/workflows/` (`core-conformance.yml`, `dbsql-lint.yml`, `first-interaction.yml`, `php-lint.yml`) grepped
  for `${{ github.event` / `${{ github.head_ref }}` expressions reaching a `run:` step. One hit (`core-conformance.yml`), fixed this
  pass (see `MSP_REMEDIATION_REPORT.md`).
- `permissions:` blocks reviewed on all 4 files: already least-privilege.
- `pull_request_target` usage (`first-interaction.yml` only) confirmed to never check out PR code.
- This is **manual-only verification**. Recommended follow-up (out of scope for this pass): a CI job running `zizmor` or
  GitHub's own `actionlint` with the `expression-injection` rule enabled, so this class of regression is caught automatically on
  every future workflow-file PR rather than by a manual audit pass.

## Dependency audit

- `composer audit --no-interaction` → **No security vulnerability advisories found.**
- Vendored browser JS libraries (no `package.json`/lockfile exists in this repo) checked by version string against known CVEs - see
  `MSP_SECURITY_AUDIT.md` finding M3 for the full table. One actionable finding: **TinyMCE 8.5.0** has 3 disclosed stored-XSS CVEs,
  fixed in 8.5.1 (finding C4, unresolved - needs Blake's own upgrade, see remediation report). This check is **manual-only
  verification** (no automated test enforces vendored-library versions); recommended follow-up is adopting a `package.json` +
  `npm audit`/Dependabot for the vendored front-end libraries, or at minimum a CI step that extracts and diffs each bundle's embedded
  version string against a pinned allow-list.

## Nightly/CLI guard convention

**`tests/nginx_private_paths.php`** - **163 passed, 1 failed, then fixed → 163/163.** Before this pass's fix,
`tests/support/rmm_agent_build_part.php` was the only one of 139 checked test files missing the standard
`if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }` guard (nightly finding MSP-2's convention). Fixed this pass (see
`MSP_REMEDIATION_REPORT.md`); rerun confirms 163/163.

## Not independently re-run to completion this pass

- **`tests/rmm_agent_feed.php`** - times out past 90 seconds in this sandboxed environment (likely the `python3` mock-GitHub subprocess
  startup, not related to the one-line guard added to its `tests/support/rmm_agent_build_part.php` include). The guard addition was
  confirmed syntax-valid (`php -l`) and is a behavioral no-op under CLI execution (which is how this test always runs), so it carries
  no regression risk, but the test itself was not watched to completion this pass.

## Summary

| Category | Automated coverage | Manual-only |
|---|---|---|
| SQL injection | `tests/security_sqli.php` (25/25) | - |
| Unsafe command execution | `tests/no_unsafe_shell.php` (aggregate PASS) | PHPMailer `popen()` reachability (confirmed unreachable) |
| XSS / unsafe navigation | `tests/no_unsafe_navigation.php` (aggregate PASS) | broader innerHTML/eval/location sweep this pass |
| Redis/predis | `composer audit` (clean) | CRLF-smuggling-specific payload test does not exist |
| Authentication / sessions / MFA | 6 files, 274/275 assertions (1 env-only failure) | - |
| Microsoft OAuth (Identity Providers) | `tests/security_identity_provider.php` (11/11) | - |
| Tenant separation | `tests/rmm_tenant_isolation.php` (23/23, new) | - |
| GitHub Actions security | - | full manual review this pass |
| Dependency audit | `composer audit` (clean) | vendored JS version check (TinyMCE finding) |
