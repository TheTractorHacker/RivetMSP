#!/usr/bin/env bash
# Review R4 (MSP-DS-34): the REAL output of scripts/rmm_postgres_backup_env.php (six RIVETCORE_PG_* lines plus export PGPASSFILE=...)
# must be accepted by rmm_pg_prepare / parse_rmm_pg_env, and the PGPASSFILE path from app output must never be applied.
# The output section of the real script is executed here with a stub connection config, so a change to what the script prints
# is exercised too. Run: bash tests/msp_ds_review_R4_pg_env_real_output.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
fail=0; ok(){ echo "ok   $1"; }; bad(){ echo "FAIL $1"; fail=1; }

# 1. reproduce the script's real stdout: everything from the `$shellQuote =` line to the end, with a stub $config and $passfile.
start="$(grep -n '^\$shellQuote = ' "$ROOT/scripts/rmm_postgres_backup_env.php" | cut -d: -f1)"
[[ -n "$start" ]] || { bad "cannot find the output section of rmm_postgres_backup_env.php"; exit 1; }
{ echo '<?php $config = (object)["database"=>"rivet db","user"=>"o'"'"'brien","host"=>"db.example","port"=>5432,"sslMode"=>"require","schema"=>"rmm"]; $passfile = $argv[1];'
  tail -n +"$start" "$ROOT/scripts/rmm_postgres_backup_env.php"; } > "$T/emit.php"
php "$T/emit.php" "$T/scratch-pgpass" > "$T/real.out" || { bad "stub run of the real output section failed"; exit 1; }
grep -q '^export PGPASSFILE=' "$T/real.out" && [[ "$(wc -l < "$T/real.out")" -eq 7 ]] && ok "real output has 7 lines incl. export PGPASSFILE (premise of the finding)" || bad "real output shape changed: $(cat "$T/real.out")"

# 2. parse_rmm_pg_env accepts it and does not apply the PGPASSFILE value
src="$(sed -n '/^parse_rmm_pg_env() {/,/^}/p' "$ROOT/deploy/lib/common.sh")"
res="$(PGPASSFILE=/root/own bash -c 'die(){ echo "DIE $*"; exit 3; }; '"$src"'; parse_rmm_pg_env "$(cat "$1")" && echo "OK|$RIVETCORE_PG_DATABASE|$RIVETCORE_PG_USER|$RIVETCORE_PG_PORT|$PGPASSFILE"' _ "$T/real.out" 2>&1)"
[[ "$res" == "OK|rivet db|o'brien|5432|/root/own" ]] && ok "parse_rmm_pg_env accepts the real output; PGPASSFILE untouched" || bad "parse of real output: $res"

# 3. end to end through rmm_pg_prepare with run_app_php replaced by the real output; root's own file is used, not the app's path
cat > "$T/prep.sh" <<EOS
set -euo pipefail
source "$ROOT/deploy/lib/common.sh"
run_app_php() { local a; for a in "\$@"; do [[ "\$a" == --passfile=* ]] && pf="\${a#--passfile=}"; done; echo 'h:5432:db:u:s3cret' > "\$pf"; php "$T/emit.php" "\$pf"; }
app_owner_user() { :; }
rmm_pg_prepare "$T"
echo "READY=\${RMM_PG_READY} PASS=\${PGPASSFILE} DB=\${RIVETCORE_PG_DATABASE}"
[[ "\$(cat "\$PGPASSFILE")" == *s3cret* ]] && echo COPIED
[[ "\$(stat -c %a "\$PGPASSFILE")" == 600 ]] && echo MODE600
EOS
res="$(bash "$T/prep.sh" 2>&1)"
[[ "$res" == *READY=1* && "$res" == *"DB=rivet db"* && "$res" == *COPIED* && "$res" == *MODE600* && "$res" != *scratch* ]] && ok "rmm_pg_prepare succeeds on the real output with its own 0600 password file" || bad "rmm_pg_prepare: $res"

# 4. hostile lines are still refused
for bad_line in "export PGPASSFILE='/etc/shadow'; touch $T/pwn" "export PGPASSFILE=/etc/shadow" "export PATH='/tmp/x'"; do
    r="$(bash -c 'die(){ echo "DIE $*"; exit 3; }; '"$src"'; parse_rmm_pg_env "$1"' _ "$bad_line" 2>&1)"
    [[ "$r" == DIE* && ! -e "$T/pwn" ]] && ok "still refused: ${bad_line:0:40}" || bad "accepted: $bad_line => $r"
done
exit $fail
