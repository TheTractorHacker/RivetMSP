#!/usr/bin/env bash
# Entry scripts ignore test-hook variables from the environment; the sudoers rule install-web-update.sh writes forbids env passing and is
# valid for the sudoers parser on this host (sudo-rs rejects the old `--branch=*` wildcard rule). Run: bash tests/msp_ds_review_entry_hooks_and_sudoers.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail=0; ok(){ echo "ok   $1"; }; bad(){ echo "FAIL $1"; fail=1; }
for f in web-update.sh update.sh backup.sh restore.sh restore_drill.sh; do
    snippet="$(sed -n '/^for _rv_v in/,/^unset _rv_v/p' "$ROOT/deploy/$f")"
    [[ -n "$snippet" ]] || { bad "$f has no hook-unset block"; continue; }
    out="$(RIVETMSP_TRUST_TEST_NONROOT=1 RIVETMSP_TRUST_UID=1 RIVETMSP_TRUST_STOP=/ RIVETMSP_TEST_PRIV_SKIP=1 RIVETMSP_TEST_AS_ROOT=1 RIVETMSP_ETC_DIR=/tmp/evil RIVETMSP_ALLOW_UNTRUSTED_TOOLING=1 RIVETMSP_ALLOW_UNTRUSTED_GIT=1 RIVETMSP_WEB_UPDATE_CONF=/tmp/evil.conf \
        bash -c "$snippet"'; env | grep -E "^RIVETMSP_" | sort')"
    if [[ "$f" == web-update.sh ]]; then
        [[ -z "$out" ]] && ok "$f drops every RIVETMSP_ test/override variable (incl. ALLOW_UNTRUSTED_*, WEB_UPDATE_CONF)" || bad "$f left: $out"
    else
        [[ "$out" == *RIVETMSP_ALLOW_UNTRUSTED_TOOLING=1* && "$out" != *TRUST_* && "$out" != *TEST_* && "$out" != *ETC_DIR* ]] && ok "$f drops TRUST_/TEST_/ETC_DIR hooks (operator ALLOW_UNTRUSTED_* overrides stay)" || bad "$f: $out"
    fi
    first="$(grep -n 'for _rv_v in' "$ROOT/deploy/$f" | head -1 | cut -d: -f1)"; src="$(grep -n 'source "\${.*}/lib/common.sh"' "$ROOT/deploy/$f" | head -1 | cut -d: -f1)"
    [[ -n "$src" && "$first" -lt "$src" ]] && ok "$f unsets the hooks before sourcing common.sh" || bad "$f order ($first/$src)"
done
# sudoers line as the installer writes it
line="$(grep -n "^printf 'Defaults!" "$ROOT/deploy/install-web-update.sh" | head -1 | cut -d: -f2-)"
DEST=/usr/local/lib/rivetmsp-deploy; WEB_USER=www-data; TMP="$(mktemp)"
line="$(printf '%s' "$line" | sed 's/ > "\${TMP}"$//')"
eval "$line" > "$TMP"
[[ "$(wc -l < "$TMP")" == 2 ]] && grep -q "^Defaults!$DEST/web-update.sh env_reset, !setenv$" "$TMP" && ok "Defaults line forces env_reset and disables setenv for the helper" || bad "defaults line: $(cat "$TMP")"
! grep -qiE 'SETENV:|env_keep|env_check|\*' "$TMP" && ok "rule has no SETENV tag, no env_keep/env_check and no wildcard" || bad "rule weakens env handling: $(cat "$TMP")"
grep -q "^www-data ALL=(root) NOPASSWD: $DEST/web-update.sh$" "$TMP" && ok "one command, the root-owned helper only" || bad "command line"
if sudo -n true 2>/dev/null && command -v visudo >/dev/null; then sudo -n visudo -cf "$TMP" >/dev/null 2>&1 && ok "visudo (this host's sudo) parses the generated rule" || bad "visudo rejects the generated rule: $(cat "$TMP")"; else echo "skip visudo check (no passwordless sudo here)"; fi
rm -f "$TMP"
exit $fail
