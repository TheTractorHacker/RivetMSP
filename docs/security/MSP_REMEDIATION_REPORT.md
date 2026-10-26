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

1. **TinyMCE upgrade (8.5.0 → 8.5.1+, audit finding C4).** Three stored-XSS CVEs (CVE-2026-47759, -47761, -47762) are fixed upstream.
   This was **not hand-patched** in this pass because `plugins/tinymce/` is a large vendored bundle (core, multiple themes, models,
   plugins, icon packs, language files) distributed as a single self-hosted zip from tiny.cloud (or via `npm install tinymce`, which
   this repo does not use - there is no `package.json`). Safely upgrading it means downloading the **official** 8.5.1+ self-hosted
   release and replacing the entire `plugins/tinymce/` tree atomically, verifying the new bundle's checksum/provenance - not editing
   a handful of minified files by hand, which risks silently breaking the editor or (worse) introducing a worse supply-chain problem
   than the one being fixed. This needs either network access to fetch the official release artifact and a verification step this
   environment isn't set up to do safely, or Blake's own download-and-swap during a normal maintenance window.
   - **What Blake needs to do:** download the TinyMCE 8.5.1 (or later) self-hosted package from `https://www.tiny.cloud/get-tiny/self-hosted/`,
     replace `plugins/tinymce/` with it (keeping any custom skin/CSS overrides this project layers on top - check `git diff` against the
     current `plugins/tinymce/` tree for local modifications before overwriting), smoke-test every `tinymce.init()` call site
     (`js/app.js`, `admin/settings_mail_templates.php`, `client/includes/footer.php`), and commit.

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
