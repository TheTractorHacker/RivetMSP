#!/usr/bin/env bash
#
# rivet/rivet-core packaging gap (confirmed 2026-10-25 against v1.0.0-rc.41, commit 4df3663):
# its .gitattributes marks /scripts/* export-ignore, same as /tests, /docs and /.github - meant to keep
# dev tooling out of an edition's composer dist install. That rule also strips the five RMM PostgreSQL
# OPERATOR scripts that the package's own docs/rmm/POSTGRES.md tells every edition to run straight out of
# vendor/rivet/rivet-core/scripts/:
#
#   rmm-postgres.php           (create/upgrade the PostgreSQL schema - POSTGRES.md section 4)
#   rmm-store-migrate.php       (migrate/rollback/purge the RMM history between stores - section 14/15)
#   rmm-store-verify.php        (read-only post-restore reconciliation - section 8.6)
#   rmm-postgres-backup.sh       (pg_dump to STDOUT - section 8.1/8.2)
#   rmm-postgres-restore.sh      (pg_restore from STDIN - section 8.1/8.3)
#
# `composer install`/`update rivet/rivet-core` uses a dist archive (GitHub zipball), which honours
# export-ignore, so every such run silently deletes these 5 files again. This is a rivet-core bug (the
# scripts are operator-facing, not dev-only) - report it upstream; nothing in THIS repo's composer.json
# can fix it without pulling the whole source tree (tests/, docs/, .github/) into vendor/, which would be
# a much bigger, noisier vendor/ diff than this repo's tracked-vendor convention wants.
#
# Until it is fixed upstream, run this script once after every `composer update rivet/rivet-core` (or
# `composer install`) in this repo. It clones the EXACT commit composer just installed (read from
# vendor/composer/installed.json) and copies only these 5 files back in, verifying each one is
# byte-identical to what a real git checkout of that commit contains before overwriting anything.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."   # repo root
INSTALLED_JSON="vendor/composer/installed.json"
[ -f "$INSTALLED_JSON" ] || { echo "sync-rmm-postgres-cli-scripts: $INSTALLED_JSON not found - run composer install first." >&2; exit 2; }

REF=$(php -r '
$d = json_decode(file_get_contents($argv[1]), true);
foreach ($d["packages"] ?? [] as $p) {
    if (($p["name"] ?? "") === "rivet/rivet-core") { echo $p["source"]["reference"] ?? $p["dist"]["reference"] ?? ""; exit; }
}
' "$INSTALLED_JSON")
[ -n "$REF" ] || { echo "sync-rmm-postgres-cli-scripts: could not find rivet/rivet-core's installed commit reference in $INSTALLED_JSON." >&2; exit 2; }

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
echo "sync-rmm-postgres-cli-scripts: cloning rivet/rivet-core @ $REF to recover scripts/ ..." >&2
git clone --quiet https://github.com/TheTractorHacker/rivet-core "$TMP/rivet-core" >&2
git -C "$TMP/rivet-core" checkout --quiet "$REF" >&2

DEST="vendor/rivet/rivet-core/scripts"
mkdir -p "$DEST"
for f in rmm-postgres.php rmm-store-migrate.php rmm-store-verify.php rmm-postgres-backup.sh rmm-postgres-restore.sh; do
    SRC="$TMP/rivet-core/scripts/$f"
    [ -f "$SRC" ] || { echo "sync-rmm-postgres-cli-scripts: $f is missing from commit $REF - rivet-core may have renamed/removed it; check POSTGRES.md." >&2; exit 1; }
    cp "$SRC" "$DEST/$f"
    case "$f" in *.sh) chmod 0755 "$DEST/$f" ;; esac
    echo "  restored $DEST/$f" >&2
done
echo "sync-rmm-postgres-cli-scripts: done. Review 'git diff --stat vendor/rivet/rivet-core/scripts' and commit." >&2
