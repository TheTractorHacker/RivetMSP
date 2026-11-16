#!/usr/bin/env bash
# Review R3 (MSP-DS-34): root must not execute anything under ${APP_DIR}/vendor (web-writable). Design chosen: the vendor
# rmm-postgres-backup.sh / rmm-postgres-restore.sh and the KeyFile validation run AS THE WEB USER (runuser) via run_rmm_pg_script /
# run_app_php; root opens the dump files and keeps its own password file. Run: bash tests/msp_ds_review_R3_vendor_scripts_not_root.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
fail=0; ok(){ echo "ok   $1"; }; bad(){ echo "FAIL $1"; fail=1; }

# 1. static: no root script runs an executable from the app tree except through run_app_php / run_app_exec / run_rmm_pg_script / sudo -u OWNER
hits="$(grep -nE '(^|[;&|(!]|then|do|\bif)[[:space:]]*(\w+=\S+[[:space:]]+)*"\$\{APP_DIR\}/[^"]*(\.sh|vendor/)[^"]*"' "$ROOT"/deploy/backup.sh "$ROOT"/deploy/restore.sh "$ROOT"/deploy/restore_drill.sh "$ROOT"/deploy/update.sh "$ROOT"/deploy/web-update.sh "$ROOT"/deploy/lib/*.sh | grep -vE 'run_app_php|run_app_exec|run_rmm_pg_script|sudo -u|^\S+:[0-9]+:\s*#|\[\[ ' || true)"
[[ -z "$hits" ]] && ok "no \${APP_DIR}/... executable is invoked directly by a root script" || { bad "root runs app-tree code directly:"; echo "$hits"; }
phprq="$(grep -nE "php -r" "$ROOT"/deploy/backup.sh "$ROOT"/deploy/restore.sh "$ROOT"/deploy/restore_drill.sh "$ROOT"/deploy/lib/*.sh | grep -vE 'run_app_php' | while IFS=: read -r f n _; do sed -n "${n},$((n+14))p" "$f" | grep -q 'autoload.php' && echo "$f:$n"; done)"
[[ -z "$phprq" ]] && ok "no bare 'php -r' (root) requires vendor/autoload.php" || { bad "bare php -r loads vendor/autoload.php: $phprq"; }
grep -q 'run_rmm_pg_script "${APP_DIR}" "${APP_DIR}/vendor/rivet/rivet-core/scripts/rmm-postgres-backup.sh"' "$ROOT/deploy/backup.sh" && ok "backup.sh uses run_rmm_pg_script for the dump" || bad "backup.sh"
grep -q 'run_rmm_pg_script "${APP_DIR}" "${APP_DIR}/vendor/rivet/rivet-core/scripts/rmm-postgres-restore.sh"' "$ROOT/deploy/restore.sh" && ok "restore.sh uses run_rmm_pg_script for the restore" || bad "restore.sh"

# 2. behaviour with a stand-in runuser and a pretend web user
mkdir -p "$T/app/vendor/rivet/rivet-core/scripts" "$T/stage"
cat > "$T/app/vendor/rivet/rivet-core/scripts/rmm-postgres-backup.sh" <<'S'
#!/usr/bin/env bash
echo "DUMP user_env=${RIVETCORE_PG_DATABASE} passfile=${PGPASSFILE}"
S
cat > "$T/app/vendor/rivet/rivet-core/scripts/rmm-postgres-restore.sh" <<'S'
#!/usr/bin/env bash
echo "RESTORE clean=${RMM_PG_RESTORE_CLEAN} got=$(cat) passfile=${PGPASSFILE}" > "$RESULT_OUT"
S
chmod +x "$T"/app/vendor/rivet/rivet-core/scripts/*.sh
echo '<?php' > "$T/app/config.php"
cat > "$T/run.sh" <<EOS
set -uo pipefail
source "$ROOT/deploy/lib/common.sh"
_app_exec_user() { echo webuser; }
runuser() { [[ "\$1" == -u && "\$3" == -- ]] || exit 9; echo "RUNUSER \$2" >> "$T/runuser.log"; shift 3; "\$@"; }
RMM_PG_USER_PASSFILE="$T/web-pgpass"; : > "\$RMM_PG_USER_PASSFILE"
export PGPASSFILE="/root/own-pgpass" RIVETCORE_PG_DATABASE=rmmdb
run_rmm_pg_script "$T/app" "$T/app/vendor/rivet/rivet-core/scripts/rmm-postgres-backup.sh" > "$T/dump.out"
echo dumpdata > "$T/dump.in"; export RESULT_OUT="$T/restore.out"
RMM_PG_RESTORE_CLEAN=1 run_rmm_pg_script "$T/app" "$T/app/vendor/rivet/rivet-core/scripts/rmm-postgres-restore.sh" < "$T/dump.in"
EOS
bash "$T/run.sh" 2>&1
[[ "$(grep -c 'RUNUSER webuser' "$T/runuser.log" 2>/dev/null)" == 2 ]] && ok "both vendor scripts were started through runuser as the web user" || bad "vendor scripts not run via runuser: $(cat "$T/runuser.log" 2>/dev/null)"
[[ "$(cat "$T/dump.out" 2>/dev/null)" == "DUMP user_env=rmmdb passfile=$T/web-pgpass" ]] && ok "dump is written to root's redirect; web user gets its own password file, not root's" || bad "dump env: $(cat "$T/dump.out" 2>/dev/null)"
[[ "$(cat "$T/restore.out" 2>/dev/null)" == "RESTORE clean=1 got=dumpdata passfile=$T/web-pgpass" ]] && ok "restore gets the dump on stdin and RMM_PG_RESTORE_CLEAN through the drop" || bad "restore: $(cat "$T/restore.out" 2>/dev/null)"
exit $fail
