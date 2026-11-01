# RivetMSP Security Remediation Report

Companion to `MSP_SECURITY_AUDIT.md` (read that first for the full finding list). This document is the change log: what was actually
changed, where, and - for anything that still needs Blake's own action - exactly what and why it wasn't done automatically.

## What changed in this pass (branch `msp-security-audit-docs`, off `beta` tip `af8f4c395`)

### Commit `a1b0c6a87` - GitHub Actions template-injection fix, RMM tenant-isolation test, test-harness guard gap

1. **`.github/workflows/core-conformance.yml`** - the `workflow_dispatch` input `core_ref` was interpolated directly into a `run:` shell
   block (`REF="${{ github.event.inputs.core_ref }}"`). Changed to pass it through `env: CORE_REF_INPUT: ${{ github.event.inputs.core_ref }}`
   and reference `$CORE_REF_INPUT` in the shell. This is the standard GitHub-recommended fix for the workflow template-injection class -
   the untrusted expression is substituted into an environment variable's *value* (which the shell then reads literally) rather than
   directly into the script text (which the shell re-parses as syntax).
   - All 4 workflow files (`core-conformance.yml`, `dbsql-lint.yml`, `first-interaction.yml`, `php-lint.yml`) were grepped for every
     `${{ github.event` / `${{ github.head_ref` style expression; this was the only one that reached a `run:` step. No other change
     was needed to the `permissions:` blocks - they were already minimal (see the audit doc, finding H2).

2. **`tests/rmm_tenant_isolation.php` (new file)** - the explicit cross-tenant RMM-agent-impersonation test requested this pass. 23
   assertions, all passing on two consecutive runs. See the audit doc's "Informational" section for what it proves and how.

3. **`tests/support/rmm_agent_build_part.php`** - added the one-line CLI-only HTTP guard every other `tests/*.php` file in the suite
   carries. Found as a side effect of re-running `tests/nginx_private_paths.php` (which itself asserts every test file has this guard)
   during this pass's regression check. Confirmed with `php -l` that the file is still syntactically valid, and the guard is a pure
   no-op when the file is included under CLI SAPI (which is how every test that uses it runs), so this carries no behavior-change risk
   to the tests that include it.

No other first-party code changed this pass. The broader JS XSS sweep (innerHTML/outerHTML/insertAdjacentHTML/document.write/eval/
new Function/location sinks/jQuery `$()` confusion, across every first-party `.js` file and the 6 JS files shared with RivetIT via
`vendor/rivet/rivet-core`) found nothing new to fix - see audit finding H5 for exactly what was checked and why each site already
escapes or validates correctly.

## What was verified (not changed) from the prior pass

Re-run fresh against a brand-new, schema-only scratch database (`rivetmsp_scratch_secaudit`, built via
`php scripts/setup_cli.php --non-interactive`, never copied from any live install) and a throwaway shared-host Redis instance
(port 16379, not 6379/6380):

| Test | Result |
|---|---|
| `tests/security_sqli.php` | 25/25 pass |
| `tests/no_unsafe_shell.php` | PASS (35 shell calls, 138 files, 5 reviewed exceptions) |
| `tests/no_unsafe_navigation.php` | PASS (200 files, 11 reviewed sites) |
| `tests/security_identity_provider.php` | 11/11 pass |
| `tests/security_vault_reveal.php` | 71/71 pass (reproducible on rerun) |
| `tests/security_backup.php` | 42/42 pass |
| `tests/security_mfa.php` | 52/52 pass |
| `tests/security_sessions.php` | 59/59 pass |
| `tests/security_passwords.php` | 44/44 pass |
| `tests/security_login_http.php` | 72/73 pass (1 environment-only failure, see below) |
| `tests/totp_replay.php` | ALL PASS |
| `tests/tls_verification.php` | ALL PASS |
| `tests/api_auth_hardening.php` | ALL PASS |
| `tests/net_guards.php` | ALL PASS |
| `tests/nginx_private_paths.php` | 163/163 pass (after the one fix above; was 162/163 before it) |
| `composer audit --no-interaction` | No security vulnerability advisories found |

This confirms findings C1, C2, C3, H1, H3, H4, H5 from the audit doc are all still correctly fixed in current `beta` - nothing
regressed or was reverted. Full assertion-by-assertion detail is in `MSP_TEST_RESULTS.md`.

### A pre-existing environment quirk found while verifying (not a code regression)

`tests/security_crypto.php` and `tests/security_login_http.php` each have one assertion that checks behavior **with no encryption
key available at all** (`secSettingsKeyAvailable()` must return `false`, a warning banner must show, etc.). On this specific shared
host there is a real, root-owned `/etc/rivetmsp/keys.json` (owned `root:www-data`) - a *global, filesystem-wide* key-ring file that
`RivetMSP\Crypto\KeyStore::load()` checks unconditionally at a hardcoded absolute path, with no per-worktree override. Every scratch
environment built on this box therefore sees "a key is available" even when a test blanks its own `$config_settings_enc_key`, because
the v3 key ring is still reachable through that global file. This caused `tests/security_crypto.php` to fail 14 of 51 assertions (all
of them the "simulate zero key material anywhere" scenarios) and `tests/security_login_http.php` to fail 1 of 73 (the "no key ->
warning banner" case) on this host specifically.

This is **not a defect in the audited code** - it is a test-isolation limitation of sharing this one physical/VM host across many
scratch worktrees, none of which can independently simulate "no key exists" without either touching `/etc/rivetmsp/keys.json` (which
may be a live install's real key material - out of scope and explicitly not touched) or `KeyStore` gaining a per-process override for
that path (a real, if minor, product improvement - not something this pass's scope covers). Every other assertion in both files -
including the full legacy/v3 cipher round trip, every named straggler-settings column, the TOTP seed wrapping, the DB-update step,
and (with `config_https_only` correctly set for loopback testing) the entire sign-in/MFA/session/remember-me/password-policy flow -
passed cleanly.

## What still needs Blake's own action

1. **TinyMCE upgrade (8.5.0 → 8.5.1, audit finding C4). RESOLVED - this was a follow-up pass with network access.** Three
   stored-XSS CVEs (CVE-2026-47759, -47761, -47762) are fixed upstream in 8.5.1. The original remediation pass above deliberately
   deferred this (see the now-superseded text this replaces) because it needed network access and careful provenance verification,
   not hand-patching minified files. A later pass, run in an environment **with** outbound network access, completed it:

   - **Local-modification check (before touching anything):** `git log --follow -- plugins/tinymce/` shows every prior version bump
     (6.4.2 through 8.5.0) as a clean, atomic "Bump TinyMCE from X to Y" commit touching only the expected vendor files - no commit
     layers custom skin/CSS edits on top. `git show --stat` on the last bump (`f5da94a27`) confirmed the same pattern. No local
     modifications were found anywhere under `plugins/tinymce/`, so none needed to be preserved/reapplied.
   - **Target version:** checked TinyMCE's own changelog (`https://www.tiny.cloud/docs/tinymce/latest/changelog/`) - **8.5.1**
     (2026-05-20) is the only 8.5.x patch release after 8.5.0, and its Security section lists exactly three stored-XSS fixes
     matching this finding: two core-serializer fixes (`data-mce-` prefixed attributes, forged `mce:protected` comments) plus a
     `media` plugin `data-mce-object` injection fix. (TinyMCE's current latest overall is 8.10.1 - deliberately *not* adopted here;
     the task was a security patch within the 8.5.x line, not a feature-version bump.)
   - **Provenance verification:** tiny.cloud's own self-hosted download page (`https://www.tiny.cloud/get-tiny/self-hosted/`) offers
     no checksum or signature at all. The npm registry does: fetched `https://registry.npmjs.org/tinymce/8.5.1` and confirmed (a)
     the package is published by Tiny Technologies' own npm account (`tinymce`/`ephox`, `engineering-inbox@tiny.cloud`), matching the
     copyright holder in the vendored `license.md`; (b) `repository.url` is `github.com/tinymce/tinymce`, the same upstream already
     recorded in this repo; (c) after downloading the tarball over HTTPS, its SHA-1 (`2783ea3a666081b3e8ac38cb4d0cdab589782544`) and
     SHA-512 (base64, `Wd4OrokDEwlXppSR+ijXngLa2+s3v8NZax9WCvAHeQhqvFsj9tGt19PmrnAdGLiQFkBBYzdOLW23tsBTPMiJYw==`) both matched the
     registry's own recorded `dist.shasum`/`dist.integrity` byte-for-byte. Extracted and confirmed `tinymce.min.js` reports
     `majorVersion:"8"`, `minorVersion:"5.1"` and `package.json` reports `"version":"8.5.1"`.
   - **Swap:** replaced `plugins/tinymce/{icons,models,plugins,skins,themes,tinymce.d.ts,tinymce.min.js,license.md,notices.txt}` with
     the verified 8.5.1 contents, pruning the npm package's extra unminified source companions (`plugin.js`/`index.js` per plugin,
     `.ts`/`.css` source files under `skins/`) that this repo has never vendored, so the resulting file-path set is byte-for-byte
     identical to the pre-upgrade tree (confirmed via `diff` of `git ls-tree` vs. the new file list) - only 3 files actually changed
     content: `tinymce.min.js`, `plugins/media/plugin.min.js` (the media-plugin fix), and `license.md` (upstream's own 2026 copyright/
     license-text update). `langs/` (a stock placeholder `README.md` only, no actual language files) was left untouched - the npm
     package doesn't ship it at all.
   - **Smoke test:** stood up a schema-only scratch database (`db.sql` imported fresh) with a throwaway admin user via
     `scripts/setup_cli.php`, served the worktree with PHP's built-in server, and drove a real headless Chromium (via
     `puppeteer-core`, pointed at a locally cached Chrome build - the project's usual MCP browser tools weren't available in this
     environment) through: (1) `admin/settings_mail_templates.php` - both `tinymceMailTemplate` editors render with toolbars
     (`tox-toolbar`), zero console errors, zero failed network requests under the page's CSP; typed content round-tripped through
     `editor.getContent()`/the underlying `<textarea>`, and a full server round trip (submit → reload) persisted the typed marker
     correctly; (2) the `.tinymceTicket` editor reached via the real "Add Ticket" ajax-modal flow on `agent/tickets.php` - this is
     the one that loads the `image` plugin, the plugin family containing the actual CVE-fixed code - toolbar rendered, typing
     worked, zero console errors/failed requests. `client/includes/footer.php`'s editor was not separately browser-driven (no
     client-portal ticket existed in the fresh scratch DB) but uses the identical vendored core/skin/theme bundle and an equivalent
     plugin set to the ticket editor already exercised above. Confirmed all `tinymce.init()` call sites via grep:
     `js/app.js` (7 calls), `admin/settings_mail_templates.php` (2 calls), `client/includes/footer.php` (1 call) - no others exist.
   - **Tests:** no PHP files were touched by this change (only files under `plugins/tinymce/` and these docs); `php -l` has nothing
     new to check. No existing automated test file references `tinymce` (grepped `tests/`).
   - Scratch DB, scratch user, and the worktree's local `config.php` were dropped/removed after testing; no live data was touched.

2. **Historical backup-dump SQL files in git history (audit finding M1).** This was explicitly decided earlier in this session: the
   files are confirmed removed from the current tree and `.gitignore` now blocks recurrence, but **git history was deliberately not
   rewritten/scrubbed** without the user's explicit authorization. This decision was not revisited this pass per the task's own
   instruction. **What Blake needs to decide:** whether the historical dumps ever contained real production data and, if so, whether
   the exposure is acceptable to leave in history (private repo, limited clone access) or warrants a `git filter-repo`/BFG history
   rewrite - which is a disruptive, coordinated operation (force-push, every clone must be re-fetched) that this agent will not perform
   without that explicit go-ahead.

3. **Azure AD client secret rotation.** Not determined necessary this pass - the fix (H1) stops the secret from ever reaching the
   browser going forward, but if the secret was exposed to anyone before that fix landed, only Blake can assess who had access to that
   admin page in that window and decide whether rotating the Azure-side secret is warranted. No evidence was found this pass that the
   secret was actually viewed by anyone untrusted; this is a precautionary note, not a confirmed compromise.

4. **GitHub Actions `permissions:` / repo-settings review.** The workflow YAML itself is already least-privilege (see above); the one
   remaining repo-settings-level item outside this pass's scope is confirming (in GitHub's own repo settings, not a file this agent
   can edit) that the default `GITHUB_TOKEN` permissions for the repository are set to "Read repository contents and packages
   permissions" (read-only) rather than the legacy "read and write" default, so a workflow that omits a `permissions:` block entirely
   doesn't silently get write access. This is a one-time checkbox in GitHub's web UI (Settings > Actions > General > Workflow
   permissions), not something expressible in a workflow file.

## Secrets handling note

No secret value was printed, logged, or committed anywhere in this pass. Where a credential was generated for testing (the scratch
database's throwaway MySQL user/password, the scratch admin account's password), it exists only in this worktree's git-ignored
`config.php` and the scratch MySQL user `secaudit_scratch`, confined to the scratch database `rivetmsp_scratch_secaudit` with no
privileges elsewhere - never copied from, or capable of reaching, any live database or live `config.php`.
