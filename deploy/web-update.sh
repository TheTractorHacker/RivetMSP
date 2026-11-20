#!/usr/bin/env bash
set -euo pipefail

# RivetMSP - the root helper behind Administration > Update > "Update App" (issue rivet-core#39).
#
# The Update page used to run `git pull` as the web server user against a checkout owned by someone else, which fails or leaves a
# mixed-ownership tree. Now the page runs ONLY this script (through one sudo rule), and this script runs deploy/update.sh: backup first,
# git pull as the owner of the files, composer, migrations, PHP-FPM reload (php<full version>-fpm), failing loudly.
#
# Security: this file and the deploy/ scripts next to it are a root-owned copy (deploy/install-web-update.sh puts them in
# /usr/local/lib/rivetmsp-deploy), so the web user can run them but never change them. It takes no free-form input: one optional
# --branch=<name> that must match a strict pattern and be a branch the remote already has, forward-only. Everything else comes from
# /etc/rivetmsp/web-update.conf (root-owned): APP_DIR, PASSPHRASE_FILE or NO_BACKUP_CONFIRMED=1, optional RELEASE_REMOTE.

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./lib/common.sh
source "${HERE}/lib/common.sh"

CONF="${RIVETMSP_WEB_UPDATE_CONF:-/etc/rivetmsp/web-update.conf}"
BRANCH=""
for arg in "$@"; do
    case "${arg}" in
        --branch=*) BRANCH="${arg#*=}" ;;
        *) die "Unknown option." ;;
    esac
done
if [[ -n "${BRANCH}" ]]; then
    [[ "${BRANCH}" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]{0,60}$ && "${BRANCH}" != *..* ]] || die "Not a valid branch name."
fi

require_root
[[ -f "${CONF}" && ! -L "${CONF}" ]] || die "${CONF} is missing: run deploy/install-web-update.sh as root first."
[[ "$(stat -c '%U' "${CONF}")" == "root" ]] || die "${CONF} must be owned by root."
[[ "$(stat -c '%a' "${CONF}" | tail -c 3)" =~ ^[0145][0145]$ ]] || die "${CONF} must not be writable by group or others."

# KEY=VALUE lines only; never sourced.
cfg() { sed -n -E "s/^$1=(.*)$/\\1/p" "${CONF}" | head -n 1 | sed -E "s/^['\"]//; s/['\"]\$//"; }
APP_DIR="$(cfg APP_DIR)"
PASSPHRASE_FILE="$(cfg PASSPHRASE_FILE)"
NO_BACKUP="$(cfg NO_BACKUP_CONFIRMED)"
REMOTE="$(cfg RELEASE_REMOTE)"; REMOTE="${REMOTE:-fork}"
[[ "${APP_DIR}" == /* && -f "${APP_DIR}/scripts/update_cli.php" ]] || die "APP_DIR in ${CONF} is not an installed instance."

OWNER="$(stat -c '%U' "${APP_DIR}/scripts/update_cli.php")"
g() { sudo -u "${OWNER}" git -C "${APP_DIR}" "$@"; }

if [[ -n "${BRANCH}" ]]; then
    current="$(g rev-parse --abbrev-ref HEAD)"
    if [[ "${current}" != "${BRANCH}" ]]; then
        announce "Release channel change: ${current} -> ${BRANCH}"
        timeout 60 sudo -u "${OWNER}" git -C "${APP_DIR}" fetch "${REMOTE}" || die "git fetch ${REMOTE} failed."
        g rev-parse --verify --quiet "refs/remotes/${REMOTE}/${BRANCH}" >/dev/null || die "${REMOTE}/${BRANCH} does not exist."
        # forward only: never install older code
        g merge-base --is-ancestor HEAD "refs/remotes/${REMOTE}/${BRANCH}" || die "${REMOTE}/${BRANCH} does not contain the code this server runs; refusing to move to older code."
        g checkout -B "${BRANCH}" --track "${REMOTE}/${BRANCH}" || die "Could not switch to ${BRANCH}."
    fi
fi

args=(--app-dir="${APP_DIR}")
if [[ -n "${PASSPHRASE_FILE}" ]]; then args+=(--passphrase-file="${PASSPHRASE_FILE}")
elif [[ "${NO_BACKUP}" == "1" ]]; then args+=(--no-backup-confirmed)
else die "${CONF} sets neither PASSPHRASE_FILE nor NO_BACKUP_CONFIRMED=1; refusing to update without a backup decision."
fi
exec "${HERE}/update.sh" "${args[@]}"
