#!/usr/bin/env bash
# Re-review RR3 (MSP-DS-34): copy_trusted_sidecar opened the web-writable path before checking its type, so a planted FIFO hung the root
# restore drill forever and root open()ed any device node a symlink named. The type check is now an lstat BEFORE the open, and the open is
# O_NOFOLLOW|O_NONBLOCK. Every case runs under `timeout 8`: rc 124 = the hang. Real functions. Run: bash tests/msp_ds_review_RR3_sidecar_fifo_device.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
fail=0; ok(){ echo "ok   $1"; }; bad(){ echo "FAIL $1"; fail=1; }
ME="$(id -u)"
export RIVETMSP_TRUST_TEST_NONROOT=1 RIVETMSP_TRUST_UID="$ME"
run() { timeout 8 bash -c 'source "$1/deploy/lib/common.sh"; if copy_trusted_sidecar "$2" "$3"; then echo RC=0; else echo "RC=$?"; fi' _ "$ROOT" "$@" 2>&1; [[ ${PIPESTATUS[0]} -ne 124 ]] || echo "TIMEOUT-HANG"; }
mkdir "$T/dest"
echo "0123456789abcdef" > "$T/dest/good.settings-key"; chmod 600 "$T/dest/good.settings-key"
r="$(run "$T/dest/good.settings-key" "$T/o1")"
[[ "$r" == *RC=0* && "$(cat "$T/o1")" == 0123456789abcdef && "$(stat -c %a "$T/o1")" == 600 ]] && ok "good file: copied, 0600" || bad "good: $r"
mkfifo "$T/dest/fifo.settings-key"
r="$(run "$T/dest/fifo.settings-key" "$T/o2")"
[[ "$r" == *RC=1* && "$r" == *FIFO* && "$r" != *TIMEOUT-HANG* ]] && ok "a planted FIFO is refused without blocking (the root drill hang)" || bad "fifo: $r"
ln -s "$T/dest/fifo.settings-key" "$T/dest/linkfifo.settings-key"
r="$(run "$T/dest/linkfifo.settings-key" "$T/o3")"
[[ "$r" == *RC=1* && "$r" != *TIMEOUT-HANG* ]] && ok "a symlink to a FIFO is refused without blocking" || bad "symlink->fifo: $r"
r="$(run /dev/null "$T/o4")"
[[ "$r" == *RC=1* && "$r" == *"device node"* && ! -s "$T/o4" ]] && ok "a device node path (/dev/null) is refused before any open" || bad "device: $r"
ln -s /dev/null "$T/dest/dev.settings-key"
r="$(run "$T/dest/dev.settings-key" "$T/o5")"
[[ "$r" == *RC=1* && "$r" == *"symbolic link"* ]] && ok "a symlink to a device node is refused (root never opens /dev/watchdog-style targets)" || bad "symlink->dev: $r"
echo SECRET > "$T/victim"; chmod 600 "$T/victim"; ln -s "$T/victim" "$T/dest/sym.settings-key"
r="$(run "$T/dest/sym.settings-key" "$T/o6")"
[[ "$r" == *RC=1* && "$r" == *"symbolic link"* && ! -s "$T/o6" ]] && ok "a symlink to a root-only file is refused, nothing copied" || bad "symlink: $r"
ln "$T/victim" "$T/dest/hard.settings-key"
r="$(run "$T/dest/hard.settings-key" "$T/o7")"
[[ "$r" == *RC=1* && "$r" == *"hard links"* && ! -s "$T/o7" ]] && ok "a hard link to another file is refused (nlink checked on the open descriptor)" || bad "hardlink: $r"
mkdir "$T/dest/dir.settings-key"
r="$(run "$T/dest/dir.settings-key" "$T/o8")"; [[ "$r" == *RC=1* && "$r" == *directory* ]] && ok "a directory is refused" || bad "dir: $r"
r="$(run "$T/dest/absent.settings-key" "$T/o9")"; [[ "$r" == *RC=2* ]] && ok "an absent sidecar is still RC=2" || bad "absent: $r"
head -c 70000 /dev/zero > "$T/dest/big.settings-key"; chmod 600 "$T/dest/big.settings-key"
r="$(run "$T/dest/big.settings-key" "$T/o10")"; [[ "$r" == *RC=1* && "$r" == *"64 KiB"* ]] && ok "an oversized file is refused" || bad "big: $r"
# the other root read of a web-owned path, the RMM pgpass copy in rmm_pg_prepare, goes through the same helper
rd() { timeout 8 bash -c 'source "$1/deploy/lib/common.sh"; if _rivetmsp_safe_read "$2" any; then echo RC=0; else echo "RC=$?"; fi' _ "$ROOT" "$@" 2>&1; }
echo 'h:5432:db:u:pw' > "$T/pgpass"; r="$(rd "$T/pgpass")"; [[ "$r" == *"h:5432:db:u:pw"* && "$r" == *RC=0* ]] && ok "safe read of a plain scratch pgpass works" || bad "pgpass: $r"
mkfifo "$T/pgfifo"; r="$(rd "$T/pgfifo")"; [[ "$r" == *RC=1* && "$r" == *FIFO* ]] && ok "a FIFO swapped in for the scratch pgpass does not hang rmm_pg_prepare's read" || bad "pg fifo: $r"
! grep -nE 'cat -- "\$\{scratch\}/pgpass"|exec \{fd\}< ' "$ROOT/deploy/lib/common.sh" | grep -q . && ok "no open-before-check read of a web path is left in common.sh" || bad "old pattern present"
grep -q TimeoutStartSec "$ROOT/deploy/templates/itflow-restore-drill.service" && grep -q TimeoutStartSec "$ROOT/deploy/templates/itflow-backup.service" && ok "both units carry TimeoutStartSec" || bad "TimeoutStartSec missing"
exit $fail
