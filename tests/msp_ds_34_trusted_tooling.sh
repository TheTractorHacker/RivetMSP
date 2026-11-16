#!/usr/bin/env bash
# MSP-DS-34: privileged tooling refuses a web-writable tree; installer makes a read-only copy; pg env is parsed; root builds its own pgpass.
# Runs as a normal user in a temp dir using the test hooks (no root, no host changes). Run: bash tests/msp_ds_34_trusted_tooling.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
fail=0; ok(){ echo "ok   $1"; }; bad(){ echo "FAIL $1"; fail=1; }
ME="$(id -u)"
export RIVETMSP_TRUST_TEST_NONROOT=1 RIVETMSP_TRUST_UID="$ME" RIVETMSP_TRUST_STOP="$T"
mkdir -p "$T/trusted/deploy/lib" "$T/open/deploy/lib"
cp "$ROOT"/deploy/lib/common.sh "$T/trusted/deploy/lib/"; cp "$ROOT"/deploy/lib/common.sh "$T/open/deploy/lib/"
echo '#!/bin/bash' > "$T/trusted/deploy/backup.sh"; cp "$T/trusted/deploy/backup.sh" "$T/open/deploy/backup.sh"
chmod 644 "$T/trusted/deploy/lib/common.sh"; chmod 755 "$T/trusted/deploy/backup.sh" "$T/trusted" "$T/trusted/deploy" "$T/trusted/deploy/lib"; chmod 775 "$T/open/deploy/lib" "$T/open/deploy" "$T/open"
chk(){ bash -c "source '$1/deploy/lib/common.sh'; require_trusted_tooling '$1/deploy/backup.sh'; echo RC=\$?" 2>&1; }
out="$(chk "$T/trusted")"; [[ "$out" == *RC=0* ]] && ok "root-protected tree accepted" || bad "trusted tree rejected: $out"
out="$(chk "$T/open")"; [[ "$out" == *"Refusing to run as root"* && "$out" != *RC=0* ]] && ok "web-writable tree refused (non-zero, message)" || bad "writable tree not refused: $out"
out="$(RIVETMSP_ALLOW_UNTRUSTED_TOOLING=1 chk "$T/open")"; [[ "$out" == *"Running although"* ]] && ok "explicit override works (and warns)" || bad "override"
bash "$ROOT/deploy/install-trusted-tooling.sh" --dest="$T/dest/rivetmsp-deploy" >/dev/null 2>&1
[[ -x "$T/dest/rivetmsp-deploy/backup.sh" && "$(stat -c %a "$T/dest/rivetmsp-deploy/lib")" == 755 && "$(stat -c %a "$T/dest/rivetmsp-deploy/lib/common.sh")" == 755 ]] && ok "installer copied the tooling without group/other write" || bad "installer"
src="$(sed -n '/^parse_rmm_pg_env() {/,/^}/p' "$ROOT/deploy/lib/common.sh")"
parse(){ bash -c 'die(){ echo "DIE $*"; exit 3; }; '"$src"'; parse_rmm_pg_env "$1" && echo OK:"$RIVETCORE_PG_USER"' _ "$1" 2>&1; }
[[ "$(parse "export RIVETCORE_PG_USER='o'\\''b'")" == "OK:o'b" ]] && ok "normal line parsed literally" || bad "normal line"
[[ "$(parse "export RIVETCORE_PG_USER='x'; touch $T/pwn")" == DIE* && ! -e "$T/pwn" ]] && ok "injected command rejected and not run" || bad "injection"
[[ "$(parse "export PGPASSFILE='/etc/shadow'")" == DIE* ]] && ok "PGPASSFILE from app output is refused (root makes its own)" || bad "pgpassfile accepted"
grep -q 'export PGPASSFILE="${own_pass}"' "$ROOT/deploy/lib/common.sh" && ok "root exports its own PGPASSFILE" || bad "own pgpass"
! grep -nE '^\s*eval ' "$ROOT"/deploy/backup.sh "$ROOT"/deploy/restore.sh | grep -q . && ok "no eval in backup.sh/restore.sh" || bad "eval present"
for f in backup.sh restore.sh restore_drill.sh update.sh; do grep -q 'require_trusted_tooling' "$ROOT/deploy/$f" && ok "$f calls require_trusted_tooling" || bad "$f does not"; done
grep -q 'rivetmsp-deploy/backup.sh' "$ROOT/deploy/templates/itflow-backup.service" && grep -q 'rivetmsp-deploy/restore_drill.sh' "$ROOT/deploy/templates/itflow-restore-drill.service" && ok "systemd units run the root-owned copy" || bad "unit paths"
exit $fail
