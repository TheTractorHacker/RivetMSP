#!/usr/bin/env bash
# Review R2 (MSP-DS-34): the <archive>.settings-key sidecar sits in a web-writable directory. Root must never copy a symlink target (or a
# hard link, or a file the web user owns) into a file the web user can read. Behavioural test of copy_trusted_sidecar with the test hooks
# (no root needed), plus checks that restore_drill.sh and restore.sh use it. Run: bash tests/msp_ds_review_R2_sidecar_symlink.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
fail=0; ok(){ echo "ok   $1"; }; bad(){ echo "FAIL $1"; fail=1; }
ME="$(id -u)"
export RIVETMSP_TRUST_TEST_NONROOT=1 RIVETMSP_TRUST_UID="$ME"
run() { bash -c 'source "$1/deploy/lib/common.sh"; if copy_trusted_sidecar "$2" "$3"; then echo RC=0; else echo "RC=$?"; fi' _ "$ROOT" "$@" 2>&1; }
mkdir "$T/dest"
echo "SECRET-ROOT-FILE" > "$T/victim"; chmod 600 "$T/victim"          # same owner and mode as a legitimate sidecar: only the symlink check can stop it
echo "0123456789abcdef0123456789abcdef" > "$T/dest/good.settings-key"; chmod 600 "$T/dest/good.settings-key"
r="$(run "$T/dest/good.settings-key" "$T/out1")"
[[ "$r" == *RC=0* && "$(cat "$T/out1")" == 0123456789abcdef0123456789abcdef && "$(stat -c %a "$T/out1")" == 600 ]] && ok "a root-owned 0600 regular sidecar is copied (0600)" || bad "good sidecar: $r"
ln -s "$T/victim" "$T/dest/planted.settings-key"
r="$(run "$T/dest/planted.settings-key" "$T/out2")"
[[ "$r" == *RC=1* && "$r" == *"symbolic links"* && ! -s "$T/out2" ]] && ok "a planted symlink is refused and nothing is copied" || bad "symlink: $r $(cat "$T/out2" 2>/dev/null)"
ln "$T/victim" "$T/dest/hard.settings-key"
r="$(run "$T/dest/hard.settings-key" "$T/out3")"
[[ "$r" == *RC=1* && "$r" == *"hard links"* && ! -s "$T/out3" ]] && ok "a hard link to another file is refused" || bad "hardlink: $r"
rm -f "$T/dest/hard.settings-key"
echo x > "$T/dest/open.settings-key"; chmod 644 "$T/dest/open.settings-key"
r="$(run "$T/dest/open.settings-key" "$T/out4")"
[[ "$r" == *RC=1* && "$r" == *"mode 644"* ]] && ok "a group/world-readable file is refused" || bad "mode: $r"
r="$(RIVETMSP_TRUST_UID=$((ME+1)) run "$T/dest/good.settings-key" "$T/out5")"
[[ "$r" == *RC=1* && "$r" == *"not root"* && ! -s "$T/out5" ]] && ok "a file owned by someone else (the web user) is refused" || bad "owner: $r"
r="$(run "$T/dest/none.settings-key" "$T/out6")"
[[ "$r" == *RC=2* ]] && ok "an absent sidecar is reported as absent (drill goes on without a key)" || bad "absent: $r"
# the scripts use it, and the old '-f is enough' discovery is gone
grep -q 'copy_trusted_sidecar' "$ROOT/deploy/restore_drill.sh" && grep -q 'refuse_untrusted_sidecar' "$ROOT/deploy/restore_drill.sh" && ok "restore_drill.sh discovers the sidecar through copy_trusted_sidecar" || bad "restore_drill.sh"
! grep -qE '\[\[ -f "\$\{cand\}" \]\] && KEY_FILE=' "$ROOT/deploy/restore_drill.sh" && ok "restore_drill.sh no longer trusts [[ -f ]]" || bad "old -f discovery present"
grep -q 'copy_trusted_sidecar' "$ROOT/deploy/restore.sh" && ! grep -q '\-f "${BACKUP_FILE%.tar.gz.enc}.settings-key" \]\]' "$ROOT/deploy/restore.sh" && ok "restore.sh discovers the sidecar through copy_trusted_sidecar" || bad "restore.sh"
exit $fail
