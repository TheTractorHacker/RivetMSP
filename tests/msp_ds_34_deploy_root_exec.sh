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
# MSP-DS-19: intermediates are staged in a root-only dir, not in the web-writable backups/ directory
grep -q 'local stage' deploy/backup.sh && grep -q 'local sql_file="${stage}/' deploy/backup.sh && grep -q 'local manifest_file="${stage}/' deploy/backup.sh && grep -q 'local rmm_pg_dump="${stage}/' deploy/backup.sh; ok $? "backup.sh stages dump, manifest, snapshot and RMM dump outside DEST"
grep -q 'mv -fT "${encrypted}" "${final_encrypted}"' deploy/backup.sh; ok $? "finished archive is renamed into DEST (a planted symlink is replaced, not followed)"
grep -q 'mv -fT "${tmp_sum}"' deploy/lib/backup_extras.sh; ok $? "checksum file is renamed into place"
! grep -q 'chmod o+r "${KEY_FILE}"' deploy/restore_drill.sh; ok $? "restore drill never loosens the key file's mode"
# behaviour: the planted-symlink scenario with the exact publish step
d=$(mktemp -d); mkdir "$d/dest" "$d/stage"; echo victim > "$d/victim"; ln -s "$d/victim" "$d/dest/backup-x.tar.gz.enc"; echo data > "$d/stage/backup-x.tar.gz.enc"
mv -fT "$d/stage/backup-x.tar.gz.enc" "$d/dest/backup-x.tar.gz.enc"
[[ "$(cat "$d/victim")" == victim && "$(cat "$d/dest/backup-x.tar.gz.enc")" == data && ! -L "$d/dest/backup-x.tar.gz.enc" ]]; ok $? "mv -fT over a planted symlink leaves the victim file untouched"
rm -rf "$d"
echo; echo "$pass passed, $fail failed"; [[ $fail == 0 ]]
