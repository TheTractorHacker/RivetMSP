#!/usr/bin/env bash
set -euo pipefail

# RivetMSP - one-time setup so the Update page can update the app safely (see deploy/web-update.sh). Run as root.
#
#   sudo deploy/install-web-update.sh --app-dir=/var/www/itflow.example.com --web-user=www-data \
#        (--passphrase-file=/root/backup.pass | --no-backup-confirmed)
#
# Copies deploy/ to /usr/local/lib/rivetmsp-deploy (root-owned, so the web user cannot edit what it is allowed to run), writes
# /etc/rivetmsp/web-update.conf, and installs ONE sudo rule: the web user may run web-update.sh and nothing else.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"
require_root

APP_DIR=""; WEB_USER="www-data"; PASSPHRASE_FILE=""; NO_BACKUP=0
for arg in "$@"; do
    case "${arg}" in
        --app-dir=*) APP_DIR="${arg#*=}" ;;
        --web-user=*) WEB_USER="${arg#*=}" ;;
        --passphrase-file=*) PASSPHRASE_FILE="${arg#*=}" ;;
        --no-backup-confirmed) NO_BACKUP=1 ;;
        *) die "Unknown option: ${arg}" ;;
    esac
done
[[ "${APP_DIR}" == /* && -f "${APP_DIR}/scripts/update_cli.php" ]] || die "--app-dir must be an installed RivetMSP folder."
[[ "${WEB_USER}" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || die "--web-user is not a valid user name."
id "${WEB_USER}" >/dev/null 2>&1 || die "User ${WEB_USER} does not exist."
if [[ -n "${PASSPHRASE_FILE}" ]]; then [[ -f "${PASSPHRASE_FILE}" ]] || die "Passphrase file not found."
elif [[ "${NO_BACKUP}" -ne 1 ]]; then die "Pass --passphrase-file=<path> or --no-backup-confirmed."; fi

DEST=/usr/local/lib/rivetmsp-deploy
install -d -m 0755 -o root -g root "${DEST}" /etc/rivetmsp
rm -rf "${DEST}.new"; cp -a "${SCRIPT_DIR}" "${DEST}.new"
chown -R root:root "${DEST}.new"; chmod -R go-w "${DEST}.new"
rm -rf "${DEST}"; mv "${DEST}.new" "${DEST}"

umask 022
{
    echo "APP_DIR=${APP_DIR}"
    [[ -n "${PASSPHRASE_FILE}" ]] && echo "PASSPHRASE_FILE=${PASSPHRASE_FILE}"
    [[ "${NO_BACKUP}" -eq 1 ]] && echo "NO_BACKUP_CONFIRMED=1"
} > /etc/rivetmsp/web-update.conf
chown root:root /etc/rivetmsp/web-update.conf; chmod 0644 /etc/rivetmsp/web-update.conf

RULE="/etc/sudoers.d/rivetmsp-web-update"
TMP="$(mktemp)"
printf '%s ALL=(root) NOPASSWD: %s/web-update.sh, %s/web-update.sh --branch=*\n' "${WEB_USER}" "${DEST}" "${DEST}" > "${TMP}"
visudo -cf "${TMP}" >/dev/null || { rm -f "${TMP}"; die "The generated sudoers rule did not validate; nothing installed."; }
install -m 0440 -o root -g root "${TMP}" "${RULE}"; rm -f "${TMP}"
success "Installed ${DEST}, /etc/rivetmsp/web-update.conf and ${RULE}. Administration > Update > Update App now runs through it."
