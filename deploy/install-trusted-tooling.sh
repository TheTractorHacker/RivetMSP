#!/usr/bin/env bash
# RivetMSP: install a root-owned copy of the privileged deploy tooling (MSP-DS-34; same design as RivetIT IT-DS-28).
#
# backup.sh, restore*.sh, restore_drill.sh and update.sh run as root. Run from the application tree (owned by the web user) they would
# execute code the web user can rewrite, so they now refuse to. This copies deploy/ to /usr/local/lib/rivetmsp-deploy as root:root,
# read-only for everyone else (the same directory install-web-update.sh uses); run the tools, and point the systemd units, from there.
# Re-run after every update of deploy/.
#
#   sudo deploy/install-trusted-tooling.sh [--dest=/usr/local/lib/rivetmsp-deploy] [--web-user=www-data]
# --web-user records the account application code is dropped to (root-owned /etc/rivetmsp/web-user.conf); without it an existing file is kept.
set -euo pipefail
DEST="/usr/local/lib/rivetmsp-deploy"; WEB_USER=""
for a in "$@"; do case "$a" in --dest=*) DEST="${a#*=}" ;; --web-user=*) WEB_USER="${a#*=}" ;; -h|--help) sed -n 2,10p "$0"; exit 0 ;; *) echo "unknown option: $a" >&2; exit 2 ;; esac; done
[[ "${EUID}" -eq 0 || "${RIVETMSP_TRUST_TEST_NONROOT:-}" == "1" ]] || { echo "Run as root." >&2; exit 1; }
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[[ ! -L "${SRC}" ]] || { echo "refusing: source is a symlink" >&2; exit 1; }
PARENT="$(dirname "${DEST}")"
install -d -m 0755 "${PARENT}"
rm -rf "${DEST}.new"
install -d -m 0755 "${DEST}.new"
( cd "${SRC}" && find . -type f ! -path './stress/*' ! -name '*.md' -print0 | while IFS= read -r -d '' f; do
    install -d -m 0755 "${DEST}.new/$(dirname "$f")"
    case "$f" in *.sh|*.py) m=0755 ;; *) m=0644 ;; esac
    install -m "$m" -- "$f" "${DEST}.new/$f"
  done )
if [[ "${EUID}" -eq 0 ]]; then chown -R root:root "${DEST}.new"; fi
rm -rf "${DEST}.old"; [[ -d "${DEST}" ]] && mv "${DEST}" "${DEST}.old"
mv "${DEST}.new" "${DEST}"; rm -rf "${DEST}.old"
if [[ -n "${WEB_USER}" ]]; then
    [[ "${WEB_USER}" =~ ^[a-z_][a-z0-9_-]{0,31}$ && "${WEB_USER}" != "root" ]] || { echo "refusing: --web-user is not a usable account name" >&2; exit 1; }
    ETC="${RIVETMSP_ETC_DIR:-/etc/rivetmsp}"
    install -d -m 0755 "${ETC}"
    printf 'WEB_USER=%s\n' "${WEB_USER}" > "${ETC}/web-user.conf.new"; chmod 0644 "${ETC}/web-user.conf.new"
    if [[ "${EUID}" -eq 0 ]]; then chown root:root "${ETC}/web-user.conf.new"; fi
    mv -fT "${ETC}/web-user.conf.new" "${ETC}/web-user.conf"
fi
echo "Installed root-owned tooling in ${DEST}. Run e.g. sudo ${DEST}/backup.sh --app-dir=<app> ..."
