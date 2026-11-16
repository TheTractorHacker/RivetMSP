#!/usr/bin/env bash
# MSP-DS-34: root deploy scripts must not eval or require web-writable PHP. Run: bash tests/msp_ds_34_deploy_root_exec.sh
set -u
cd "$(dirname "$0")/.."
pass=0; fail=0
ok() { if [[ "$1" == 0 ]]; then pass=$((pass+1)); echo "  ok   $2"; else fail=$((fail+1)); echo "  FAIL $2"; fi; }
die() { echo "DIE: $*" >&2; return 1; }
# shellcheck disable=SC1091
source <(sed -n '/^parse_rmm_pg_env() {/,/^}/p' deploy/lib/common.sh)
unset RIVETCORE_PG_DATABASE PGPASSFILE
out="export RIVETCORE_PG_DATABASE='rivet db'
export RIVETCORE_PG_USER='o'\\''brien'
export PGPASSFILE='/tmp/x'"
( parse_rmm_pg_env "$out" && [[ "${RIVETCORE_PG_DATABASE}" == "rivet db" && "${RIVETCORE_PG_USER}" == "o'brien" ]] ); ok $? "well-formed output is parsed literally (quotes handled)"
touch /tmp/ds34_marker_should_not_exist; rm -f /tmp/ds34_marker_should_not_exist
( parse_rmm_pg_env "export RIVETCORE_PG_DATABASE='x'; touch /tmp/ds34_pwned" 2>/dev/null ); r=$?
[[ $r != 0 && ! -e /tmp/ds34_pwned ]]; ok $? "injected command is refused and not run"
( parse_rmm_pg_env "touch /tmp/ds34_pwned2" 2>/dev/null ); [[ ! -e /tmp/ds34_pwned2 ]]; ok $? "non-export line is refused"
( parse_rmm_pg_env "export PATH='/tmp/evil'" 2>/dev/null ); r=$?; [[ $r != 0 ]]; ok $? "variable names outside the allow-list are refused"
! grep -nE '^\s*eval ' deploy/backup.sh deploy/restore.sh | grep -q rmm_pg; ok $? "no eval of rmm_postgres_backup_env output"
! grep -nE '(^|[^_a-z])php "\$\{(APP_DIR|SCRIPT_DIR)\}' deploy/backup.sh deploy/restore.sh deploy/lib/backup_extras.sh deploy/restore_drill.sh | grep -v run_app_php | grep -q .; ok $? "no direct php of app files left in the root scripts"
grep -q 'core.hooksPath=/dev/null' deploy/update.sh; ok $? "update.sh git checkout runs with hooks and fsmonitor disabled"
echo; echo "$pass passed, $fail failed"; [[ $fail == 0 ]]
