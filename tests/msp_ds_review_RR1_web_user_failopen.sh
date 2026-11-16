#!/usr/bin/env bash
# Re-review RR1 (MSP-DS-34): as root, run_app_exec / run_app_php / run_rmm_pg_script / rmm_pg_prepare must NEVER run app code as the caller.
# The web user comes from a root-controlled source (/etc/rivetmsp/web-user.conf, else the owner of APP_DIR itself); a missing, renamed or
# root-owned config.php changes nothing; no usable web user = die. Exercises the REAL functions: only the `runuser` binary is a PATH shim
# (real runuser needs root) and the existing RIVETMSP_TRUST_TEST_NONROOT hooks (+ RIVETMSP_TEST_AS_ROOT=1) put the code in root mode.
# Run: bash tests/msp_ds_review_RR1_web_user_failopen.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
fail=0; ok(){ echo "ok   $1"; }; bad(){ echo "FAIL $1"; fail=1; }
ME="$(id -u)"
mkdir -p "$T/bin" "$T/etc" "$T/app/cron"
cat > "$T/bin/runuser" <<S
#!/usr/bin/env bash
[[ "\$1" == -u && "\$3" == -- ]] || exit 9
echo "RUNUSER \$2" >> "$T/runuser.log"; shift 3; exec "\$@"
S
chmod +x "$T/bin/runuser"
cat > "$T/payload.sh" <<S
#!/usr/bin/env bash
touch "$T/ran-as-caller"
S
chmod +x "$T/payload.sh"
export RIVETMSP_TRUST_TEST_NONROOT=1 RIVETMSP_TRUST_UID="$ME" RIVETMSP_ETC_DIR="$T/etc" RIVETMSP_TEST_AS_ROOT=1 PATH="$T/bin:$PATH"
# run FN ARGS... in a fresh shell that sourced the real common.sh; prints RC=n
call() { bash -c 'source "$1/deploy/lib/common.sh"; shift; "$@"; echo "RC=$?"' _ "$ROOT" "$@" 2>&1; }
reset() { rm -f "$T/ran-as-caller" "$T/runuser.log" "$T/etc/"*; }

# 1. conf names a real non-root account: runs through runuser as that account, even with NO config.php anywhere (the RR1 scenario)
reset; echo 'WEB_USER=nobody' > "$T/etc/web-user.conf"; chmod 644 "$T/etc/web-user.conf"
rm -f "$T/app/config.php"
r="$(call run_app_exec "$T/app" "$T/payload.sh")"
[[ -e "$T/ran-as-caller" && "$(cat "$T/runuser.log")" == "RUNUSER nobody" ]] && ok "root mode + conf: command runs via runuser as the configured web user although config.php is missing" || bad "conf case: $r $(cat "$T/runuser.log" 2>/dev/null)"
# 2. the web user renames a root-owned file over config.php / makes it a symlink: nothing changes (config.php is never consulted)
reset; echo 'WEB_USER=nobody' > "$T/etc/web-user.conf"; chmod 644 "$T/etc/web-user.conf"; ln -s /etc/shadow "$T/app/config.php"
r="$(call run_app_exec "$T/app" "$T/payload.sh")"; rm -f "$T/app/config.php"
[[ "$(cat "$T/runuser.log")" == "RUNUSER nobody" ]] && ok "a symlinked/replaced config.php does not change the account" || bad "config swap: $r"

# 3. fail-closed matrix: every one of these must die and run NOTHING (the old code ran the command as the caller)
expect_refused() { # name, [setup done by caller]
    local r; r="$(call run_app_exec "$APP" "$T/payload.sh")"
    if [[ ! -e "$T/ran-as-caller" && "$r" != *RC=0* && "$r" == *"Refusing to run application code as root"* ]]; then ok "refused, nothing ran: $1"; else bad "$1: ran or not refused: $r"; fi
}
APP=/usr   # a root-owned directory: no owner fallback possible
reset; expect_refused "no conf, APP_DIR root-owned (config.php absent)"
reset; echo 'WEB_USER=root' > "$T/etc/web-user.conf"; chmod 644 "$T/etc/web-user.conf"; expect_refused "conf says WEB_USER=root"
reset; echo 'WEB_USER=no_such_user_zz' > "$T/etc/web-user.conf"; chmod 644 "$T/etc/web-user.conf"; expect_refused "conf names a nonexistent account"
reset; echo 'WEB_USER=nobody' > "$T/etc/web-user.conf"; chmod 666 "$T/etc/web-user.conf"; expect_refused "conf is group/other-writable (ignored, then no fallback)"
reset; echo 'WEB_USER=nobody' > "$T/etc/web-user.conf"; chmod 644 "$T/etc/web-user.conf"; RIVETMSP_TRUST_UID=$((ME+1)) APP=/usr expect_refused "conf not owned by root (ignored)"
reset; echo 'WEB_USER=nobody' > "$T/etc/real"; chmod 644 "$T/etc/real"; ln -s real "$T/etc/web-user.conf"; expect_refused "conf is a symlink (ignored)"
reset; echo 'WEB_USER=-oProxy' > "$T/etc/web-user.conf"; chmod 644 "$T/etc/web-user.conf"; expect_refused "conf holds a malformed user name"
APP="$T/missing-dir"; reset; expect_refused "APP_DIR does not exist"
ln -s /usr "$T/applink"; APP="$T/applink"; reset; expect_refused "APP_DIR is a symlink (owner fallback refused)"
APP=/usr

# 4. fallback for installs older than web-user.conf: owner of APP_DIR itself, when that is not root
reset; APP="$T/app"
r="$(call run_app_exec "$APP" "$T/payload.sh")"
[[ "$(cat "$T/runuser.log" 2>/dev/null)" == "RUNUSER $(id -un)" ]] && ok "no conf: falls back to the owner of APP_DIR (never root)" || bad "owner fallback: $r"

# 5. the other helpers share the rule
reset; mkdir -p "$T/rmm"; cp "$T/payload.sh" "$T/rmm/s.sh"
r="$(call run_rmm_pg_script /usr "$T/rmm/s.sh")"
[[ ! -e "$T/ran-as-caller" && "$r" == *"Refusing to run application code as root"* ]] && ok "run_rmm_pg_script refuses instead of running the vendor script as root" || bad "rmm_pg_script: $r"
r="$(call run_app_php /usr -r 'touch("'"$T"'/ran-as-caller");')"
[[ ! -e "$T/ran-as-caller" && "$r" == *"Refusing"* ]] && ok "run_app_php refuses" || bad "run_app_php: $r"
r="$(call rmm_pg_prepare /usr)"
[[ ! -e "$T/ran-as-caller" && "$r" == *"Refusing"* ]] && ok "rmm_pg_prepare refuses" || bad "rmm_pg_prepare: $r"
r="$(call require_trusted_tooling /nonexistent/caller.sh)"; [[ "$r" != *"Refusing to run application code"* ]] && ok "require_trusted_tooling's refusal path is not pre-empted by a missing web user" || bad "trusted tooling: $r"

# 6. a non-root caller (no root mode) is unaffected: runs directly, no runuser
reset; r="$(RIVETMSP_TEST_AS_ROOT=0 call run_app_exec /usr "$T/payload.sh")"
[[ -e "$T/ran-as-caller" && ! -e "$T/runuser.log" ]] && ok "non-root caller runs the command directly (no drop needed, nothing to gain)" || bad "non-root: $r"

# 7. restore_drill.sh resolves the web user before any app code can run (so drill_fail cannot reach run_app_php as root)
d="$ROOT/deploy/restore_drill.sh"
l1="$(grep -n 'rivetmsp_web_user' "$d" | head -1 | cut -d: -f1)"; l2="$(grep -n 'run_app_php' "$d" | head -1 | cut -d: -f1)"
[[ -n "$l1" && "$l1" -lt "$l2" ]] && ok "restore_drill.sh resolves the web user before the first run_app_php (drill_fail path included)" || bad "restore_drill.sh ordering ($l1 / $l2)"
! grep -q 'app_owner_user' "$ROOT"/deploy/*.sh "$ROOT"/deploy/lib/*.sh && ok "the config.php-owner lookup is gone from every script" || bad "app_owner_user still used"
exit $fail
