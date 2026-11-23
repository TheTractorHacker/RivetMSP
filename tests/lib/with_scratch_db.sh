#!/usr/bin/env bash
# Usage: tests/lib/with_scratch_db.sh <command...>
# Creates a throwaway schema-only DB (name contains "scratch") from db.sql plus a throwaway user, exports RIVETMSP_TEST_DB* for the
# command, then drops both. Never touches a live database.
set -u
root="$(cd "$(dirname "$0")/../.." && pwd)"
sfx="$(head -c4 /dev/urandom | od -An -tx1 | tr -d ' \n')"
db="rivetmsp_scratch_$sfx"; usr="scr_$sfx"; pw="$(head -c12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
M="sudo -n mysql"
$M -e "CREATE DATABASE \`$db\`; CREATE USER '$usr'@'localhost' IDENTIFIED BY '$pw'; GRANT ALL ON \`$db\`.* TO '$usr'@'localhost';" 2>&1 | grep -v -i "requiretty\|\^~\|sudo:" 
# SCRATCH_TABLES="history invoices" loads only those CREATE TABLE statements (fast on a busy server); default is the whole schema.
if [[ -n "${SCRATCH_TABLES:-}" ]]; then
    for t in ${SCRATCH_TABLES}; do
        awk -v t="CREATE TABLE \`$t\` (" 'index($0,t)==1{p=1} p{print} p&&/^\) ENGINE/{p=0}' "$root/db.sql"
    done | $M "$db" 2>&1 | grep -v -i "requiretty\|\^~\|sudo:"
else
    $M "$db" < "$root/db.sql" 2>&1 | grep -v -i "requiretty\|\^~\|sudo:"
fi
RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME="$db" RIVETMSP_TEST_DB_USER="$usr" RIVETMSP_TEST_DB_PASS="$pw" "$@"
rc=$?
$M -e "DROP DATABASE \`$db\`; DROP USER '$usr'@'localhost';" 2>&1 | grep -v -i "requiretty\|\^~\|sudo:"
exit $rc
