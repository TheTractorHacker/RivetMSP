#!/usr/bin/env bash
# Review R11 (MSP-DS-19/34): backup.sh stages files in a root-only 0700 directory the web user cannot enter, so the table snapshot
# (written by app code that run_app_php runs AS the web user) must travel over STDOUT and be redirected by root; handing the web user a
# path inside the stage made the snapshot silently vanish from every archive. Run: bash tests/msp_ds_review_R11_table_snapshot_stage.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
fail=0; ok(){ echo "ok   $1"; }; bad(){ echo "FAIL $1"; fail=1; }
mkdir -p "$T/app/vendor" "$T/stage"; chmod 700 "$T/stage"
echo '<?php $mysqli = new stdClass;' > "$T/app/config.php"
cat > "$T/app/vendor/autoload.php" <<'P'
<?php
namespace RivetMSP\Recovery { class TableSnapshot { static function build($m) { if (getenv('SNAP_FAIL')) { throw new \RuntimeException('db down'); } return ['tables' => ['clients' => 3]]; } } }
P
cat > "$T/run.sh" <<EOS
set -uo pipefail
APP_DIR="$T/app"; SCRIPT_DIR="$ROOT/deploy"; DEST="$T/stage"; LOG_FILE=/dev/null
source "$ROOT/deploy/lib/common.sh"
source "$ROOT/deploy/lib/backup_extras.sh"
# run_app_php stand-in: records every argument, and refuses (like a web user would) any path inside the root-only stage directory
run_app_php() { local a; shift; for a in "\$@"; do case "\$a" in "$T/stage"*) echo "WEB-USER-GIVEN-STAGE-PATH \$a" >&2; return 1;; esac; done; ( cd / && php "\$@" ); }
if backup_make_snapshot "$T/stage/table-snapshot.json"; then echo RC=0; else echo RC=1; fi
EOS
res="$(bash "$T/run.sh" 2>&1)"
[[ "$res" == *RC=0* && "$res" != *WEB-USER-GIVEN* ]] && ok "snapshot function succeeds without giving the web user a stage path" || bad "snapshot: $res"
[[ -s "$T/stage/table-snapshot.json" ]] && php -r 'exit(json_decode(file_get_contents($argv[1]), true)["tables"]["clients"] === 3 ? 0 : 1);' "$T/stage/table-snapshot.json" && ok "snapshot JSON landed in the stage file (root redirected STDOUT)" || bad "snapshot file empty or wrong"
[[ "$(stat -c %a "$T/stage/table-snapshot.json")" == 600 ]] && ok "snapshot file mode 600" || bad "mode"
res="$(SNAP_FAIL=1 bash "$T/run.sh" 2>&1)"
[[ "$res" == *RC=1* && "$res" == *"No table snapshot was produced"* ]] && ok "a failed snapshot is a visible warning and a non-zero return, not silence" || bad "failure path: $res"
exit $fail
