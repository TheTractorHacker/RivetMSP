#!/usr/bin/env bash
# RivetMSP -- "RMM Agent Builder" setup: build the Windows agent (.exe) and installer (.msi) on THIS server from the web UI.
#
# Installs a pull-model build host next to the application: Docker, a locked-down system user, the RivetCore build-host script (fetched from a PINNED
# RivetCore commit and checked against a pinned SHA-256; the script refuses on any mismatch), a systemd timer that polls the application every minute, and
# a build host + token created through the application's own code. Safe to run again at any time (idempotent). One-line setup, also shown on
# Administration > Endpoint agent > Agent updates:
#
#   sudo deploy/setup-build-host.sh            (add --yes to skip the question, --check to see what it would do)
#
# Options:
#   --check, --dry-run     show what would be done; change nothing (needs no root; still downloads and verifies the pinned files)
#   --yes                  do not ask for confirmation (the Docker-group warning is still printed)
#   --uninstall            remove the timer, units, script, config and the system user; deletes the build host in the application
#   --purge-docker         with --uninstall: also remove the docker.io package (and with it every image and container on this machine)
#   --keep-host            with --uninstall: leave the build host entry in the application
#   --keep-workdir         with --uninstall: keep /var/lib/rivetit-build-host (the build cache, about 5 GB)
#   --no-register          skip the final "--register" check against the server
#   --app-dir=PATH         the application directory (default: the one this script is in)
#   --name=NAME            the build host's name in the application (default: build-<hostname>)
#   --site-url=URL         the public https:// address of the site (default: the service URL set in the application)
#   --core-repo=URL|PATH   where the build host reads the RivetCore source (default https://github.com/TheTractorHacker/rivet-core.git)
#   --allowed-refs="a b"   source refs this machine will build (default: main v* agent-v*)
#   --workdir=PATH         build cache (default /var/lib/rivetit-build-host)
#   --web-user=USER        the account PHP runs as (default www-data)
#   --php-bin=PATH         php binary (default php)
#   --fetch-base=URL       TESTS ONLY: fetch the three pinned files from this base URL (file:// allowed) instead of GitHub; the SHA-256 pins still apply
#   --help
#
# SECURITY: membership of the "docker" group is root-equivalent on this machine. Only the dedicated rivetit-build account gets it, never the web server
# user (this script refuses to continue if the web user is already in that group). The build token goes straight from the application into
# /etc/rivetit/build-host.conf (mode 600, owned by rivetit-build); it is never printed, logged, or placed on a command line.
set -euo pipefail
umask 022

# ---- pins: the RivetCore commit the files are fetched from and their SHA-256. Bump all four together (see docs/RMM_MODULE.md, "Bumping the pin"). ----
CORE_PIN_COMMIT="9d2003165e29d98ebc1ec8c85ff451d889703b5f"
CORE_RAW_BASE="https://raw.githubusercontent.com/TheTractorHacker/rivet-core/${CORE_PIN_COMMIT}/endpoint-agent/scripts"
PIN_SHA_SCRIPT="a5ac6e95e2e8e2817c2eb32254632b7c811c154f65957e775908b054f630a594"
PIN_SHA_SERVICE="4d73f42d88876003ab6804b0f3ebffe6e8fb85c7e91bfd9734706eaf64b1a703"
PIN_SHA_TIMER="6cba55037c010ffba258913b265033791c786e6fcf9880805313a3b28b7cd5ba"

BH_USER="rivetit-build"
BH_OPT_DIR="${BH_OPT_DIR:-/opt/rivetit-build-host}"
BH_CONF_DIR="${BH_CONF_DIR:-/etc/rivetit}"
BH_CONF="${BH_CONF_DIR}/build-host.conf"
UNIT_DIR="${UNIT_DIR:-/etc/systemd/system}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
CHECK=0 YES=0 UNINSTALL=0 PURGE_DOCKER=0 KEEP_HOST=0 KEEP_WORKDIR=0 NO_REGISTER=0
HOST_NAME="" SITE_URL="" CORE_REPO="https://github.com/TheTractorHacker/rivet-core.git" ALLOWED_REFS="main v* agent-v*"
WORKDIR="/var/lib/rivetit-build-host" WEB_USER="www-data" PHP_BIN="php" FETCH_BASE=""
TMP=""

if [[ -t 2 ]]; then C_Y=$'\033[0;33m' C_R=$'\033[0;31m' C_G=$'\033[0;32m' C_0=$'\033[0m'; else C_Y="" C_R="" C_G="" C_0=""; fi
say() { printf '%s\n' "$*"; }
step() { printf '\n== %s\n' "$*"; }
warn() { printf '%sWARNING:%s %s\n' "${C_Y}" "${C_0}" "$*" >&2; }
die() { printf '%sERROR:%s %s\n' "${C_R}" "${C_0}" "$*" >&2; exit 1; }
# act: run a command, or only say so in --check mode
act() {
    if [[ "${CHECK}" -eq 1 ]]; then
        say "  would run: $*"
    else
        "$@"
    fi
}
cleanup() { [[ -n "${TMP}" && -d "${TMP}" ]] && rm -rf "${TMP}"; return 0; }
trap cleanup EXIT

usage() { sed -n '2,/^set -euo/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'; }

for arg in "$@"; do
    case "${arg}" in
        --check | --dry-run) CHECK=1 ;;
        --yes | -y) YES=1 ;;
        --uninstall) UNINSTALL=1 ;;
        --purge-docker) PURGE_DOCKER=1 ;;
        --keep-host) KEEP_HOST=1 ;;
        --keep-workdir) KEEP_WORKDIR=1 ;;
        --no-register) NO_REGISTER=1 ;;
        --app-dir=*) APP_DIR="${arg#*=}" ;;
        --name=*) HOST_NAME="${arg#*=}" ;;
        --site-url=*) SITE_URL="${arg#*=}" ;;
        --core-repo=*) CORE_REPO="${arg#*=}" ;;
        --allowed-refs=*) ALLOWED_REFS="${arg#*=}" ;;
        --workdir=*) WORKDIR="${arg#*=}" ;;
        --web-user=*) WEB_USER="${arg#*=}" ;;
        --php-bin=*) PHP_BIN="${arg#*=}" ;;
        --fetch-base=*) FETCH_BASE="${arg#*=}" ;;
        --help | -h) usage; exit 0 ;;
        *) usage >&2; die "unknown option: ${arg}" ;;
    esac
done

if [[ "${PURGE_DOCKER}" -eq 1 && "${UNINSTALL}" -ne 1 ]]; then die "--purge-docker only makes sense with --uninstall"; fi
if [[ "${EUID}" -ne 0 && "${CHECK}" -ne 1 ]]; then die "this script must run as root (try: sudo $0 ...). Use --check to preview without root."; fi

# ---- values that end up in a file bash will source: strict allowlists (no quotes, $, `, ;, newlines) ----
[[ -n "${HOST_NAME}" ]] || HOST_NAME="build-$(hostname -s 2>/dev/null | tr -c 'A-Za-z0-9.-' '-' | cut -c1-60)"
[[ "${HOST_NAME}" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,78}$ ]] || die "--name may only contain letters, numbers, dots, dashes and underscores (got: ${HOST_NAME})"
[[ "${ALLOWED_REFS}" =~ ^[A-Za-z0-9._*/\ -]{1,200}$ ]] || die "--allowed-refs contains characters that are not allowed"
[[ "${CORE_REPO}" =~ ^(https://[A-Za-z0-9._~/:@-]{1,300}|/[A-Za-z0-9._/-]{1,300})$ ]] || die "--core-repo must be an https:// URL or an absolute path without special characters"
[[ "${WORKDIR}" =~ ^/[A-Za-z0-9._/-]{1,200}$ ]] || die "--workdir must be an absolute path without special characters"
[[ "${WEB_USER}" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || die "--web-user is not a valid account name"
[[ "${APP_DIR}" == /* ]] || die "--app-dir must be an absolute path"
if [[ -n "${SITE_URL}" ]]; then
    [[ "${SITE_URL}" =~ ^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~/-]*)?$ ]] || die "--site-url must be a plain https:// address"
    SITE_URL="${SITE_URL%/}"
fi
if [[ -n "${FETCH_BASE}" ]]; then
    [[ "${FETCH_BASE}" =~ ^(file:///|https://)[A-Za-z0-9._~/:@-]+$ ]] || die "--fetch-base must be a file:/// or https:// URL"
    warn "--fetch-base is for tests: the files are still checked against the pinned SHA-256 values."
fi

as_user() { local u="$1"; shift; runuser -u "${u}" -- "$@"; }
web_php() { as_user "${WEB_USER}" "${PHP_BIN}" "${APP_DIR}/scripts/build_host_setup.php" "$@"; }

confirm() {
    [[ "${YES}" -eq 1 || "${CHECK}" -eq 1 ]] && return 0
    [[ -t 0 ]] || die "not a terminal: pass --yes to proceed without the question"
    local a
    read -r -p "$1 [y/N] " a
    [[ "${a}" =~ ^[Yy]([Ee][Ss])?$ ]]
}

# ---------------------------------------------------------------------------------------------------------------- uninstall
do_uninstall() {
    step "Removing the RMM Agent Builder from this server"
    confirm "Remove the build host timer, script, config and the ${BH_USER} account?" || die "cancelled"
    if [[ "${KEEP_HOST}" -ne 1 && -f "${APP_DIR}/scripts/build_host_setup.php" ]]; then
        if [[ "${CHECK}" -eq 1 ]]; then say "  would delete the build host \"${HOST_NAME}\" in the application"; else
            web_php delete --name "${HOST_NAME}" || warn "could not delete the build host in the application (do it under Agent updates > Local build)"
        fi
    fi
    if command -v systemctl >/dev/null 2>&1; then
        act systemctl disable --now build-host.timer 2>/dev/null || true
        act systemctl stop build-host.service 2>/dev/null || true
    fi
    act rm -f "${UNIT_DIR}/build-host.service" "${UNIT_DIR}/build-host.timer"
    command -v systemctl >/dev/null 2>&1 && act systemctl daemon-reload
    act rm -rf "${BH_OPT_DIR}"
    act rm -f "${BH_CONF}"
    act rmdir "${BH_CONF_DIR}" 2>/dev/null || true
    if id "${BH_USER}" >/dev/null 2>&1; then
        act userdel "${BH_USER}" || warn "could not remove the ${BH_USER} account"
    fi
    if [[ "${KEEP_WORKDIR}" -ne 1 ]]; then act rm -rf "${WORKDIR}"; else say "  kept ${WORKDIR}"; fi
    if [[ "${PURGE_DOCKER}" -eq 1 ]]; then
        warn "--purge-docker removes docker.io and EVERY image, container and volume on this machine."
        confirm "Really purge Docker?" || die "cancelled"
        act apt-get purge -y docker.io
        act apt-get autoremove -y
    else
        say "  Docker itself was left installed (add --purge-docker to remove it)."
    fi
    say; say "Done: the RMM Agent Builder is removed."
}

# ---------------------------------------------------------------------------------------------------------------- fetch + verify
fetch_verified() { # name expected_sha dest mode
    local name="$1" want="$2" dest="$3" got
    local url="${FETCH_BASE:-${CORE_RAW_BASE}}/${name}"
    if ! curl --proto '=https,file' --tlsv1.2 -fsS --max-time 60 --max-filesize 1048576 -o "${dest}" "${url}"; then
        die "could not download ${name} from ${url}"
    fi
    got="$(sha256sum "${dest}" | cut -d' ' -f1)"
    if [[ "${got}" != "${want}" ]]; then
        rm -f "${dest}"
        die "SHA-256 mismatch for ${name} (expected ${want}, got ${got}). Refusing to install it."
    fi
    say "  verified ${name} (${want:0:12}...)"
}

install_if_changed() { # src dest mode
    if [[ -f "$2" ]] && cmp -s "$1" "$2" && [[ "$(stat -c '%U:%G %a' "$2")" == "root:root $3" ]]; then
        say "  unchanged: $2"
    else
        act install -o root -g root -m "$3" "$1" "$2"
        [[ "${CHECK}" -eq 1 ]] || say "  installed: $2"
    fi
}

# ---------------------------------------------------------------------------------------------------------------- install
do_install() {
    step "RMM Agent Builder for ${APP_DIR}"
    [[ "${CHECK}" -eq 1 ]] && say "(check mode: nothing will be changed)"
    [[ -f "${APP_DIR}/scripts/build_host_setup.php" && -f "${APP_DIR}/config.php" ]] || die "${APP_DIR} does not look like this application (scripts/build_host_setup.php or config.php is missing)"
    for c in curl sha256sum runuser install; do command -v "${c}" >/dev/null 2>&1 || die "required command missing: ${c}"; done
    command -v "${PHP_BIN}" >/dev/null 2>&1 || die "php not found (${PHP_BIN})"
    id "${WEB_USER}" >/dev/null 2>&1 || die "the web user ${WEB_USER} does not exist (use --web-user)"
    if id -nG "${WEB_USER}" 2>/dev/null | tr ' ' '\n' | grep -qx docker; then
        die "${WEB_USER} is a member of the docker group, which is root-equivalent for the web application. Remove it first (gpasswd -d ${WEB_USER} docker); this script never adds it."
    fi
    cat <<EOF

This will:
  - install Docker (docker.io) if it is not there, plus git and python3 which the build script needs
  - create a system account '${BH_USER}' (home ${WORKDIR}, no login) in the 'docker' group
  - install the RivetCore build script into ${BH_OPT_DIR} (pinned to RivetCore ${CORE_PIN_COMMIT:0:12}, SHA-256 checked)
  - create the build host "${HOST_NAME}" in the application and store its token in ${BH_CONF} (mode 600)
  - enable a systemd timer that asks this site for a build job once a minute

EOF
    warn "Membership of the docker group is root-equivalent on this machine (anyone who can start containers can mount the whole disk). Only the ${BH_USER} account gets it, and it cannot log in."
    confirm "Would you like to install the RMM Agent Builder on this server?" || die "cancelled"

    # 1. packages
    step "1/7 Docker and tools"
    local need=()
    command -v docker >/dev/null 2>&1 || need+=(docker.io)
    command -v git >/dev/null 2>&1 || need+=(git)
    command -v python3 >/dev/null 2>&1 || need+=(python3)
    command -v flock >/dev/null 2>&1 || need+=(util-linux)
    if [[ "${#need[@]}" -gt 0 ]]; then
        command -v apt-get >/dev/null 2>&1 || die "apt-get not found; install these yourself and run again: ${need[*]}"
        act env DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${need[@]}"
    else
        say "  docker, git and python3 are already installed"
    fi
    if command -v systemctl >/dev/null 2>&1; then act systemctl enable --now docker 2>/dev/null || warn "could not start the docker service (start it, then run this script again)"; fi

    # 2. user
    step "2/7 The ${BH_USER} account"
    if id "${BH_USER}" >/dev/null 2>&1; then
        say "  ${BH_USER} exists"
    else
        act useradd --system --home-dir "${WORKDIR}" --create-home --shell /usr/sbin/nologin "${BH_USER}"
    fi
    if [[ "${CHECK}" -eq 1 ]] && ! getent group docker >/dev/null 2>&1; then
        say "  would add ${BH_USER} to the docker group (created by the docker.io package)"
    else
        getent group docker >/dev/null 2>&1 || die "no 'docker' group (is Docker installed?)"
        id -nG "${BH_USER}" 2>/dev/null | tr ' ' '\n' | grep -qx docker || act usermod -aG docker "${BH_USER}"
    fi
    if [[ "${CHECK}" -ne 1 ]]; then
        install -d -o "${BH_USER}" -g "${BH_USER}" -m 750 "${WORKDIR}"
    fi

    # 3. files
    step "3/7 The build script (pinned and verified)"
    TMP="$(mktemp -d)"
    chmod 700 "${TMP}"
    fetch_verified build-host.sh "${PIN_SHA_SCRIPT}" "${TMP}/build-host.sh"
    fetch_verified build-host.service "${PIN_SHA_SERVICE}" "${TMP}/build-host.service"
    fetch_verified build-host.timer "${PIN_SHA_TIMER}" "${TMP}/build-host.timer"
    act install -d -o root -g root -m 755 "${BH_OPT_DIR}"
    install_if_changed "${TMP}/build-host.sh" "${BH_OPT_DIR}/build-host.sh" 755
    install_if_changed "${TMP}/build-host.service" "${UNIT_DIR}/build-host.service" 644
    install_if_changed "${TMP}/build-host.timer" "${UNIT_DIR}/build-host.timer" 644

    # 4. host + token
    step "4/7 The build host in the application"
    local info host_state site_url ready
    if ! info="$(web_php info --name "${HOST_NAME}" 2>/dev/null)"; then info="$(web_php info --name "${HOST_NAME}" 2>&1 || true)"; fi
    ready="$(sed -n 's/^ready=//p' <<<"${info}" | head -n1)"
    host_state="$(sed -n 's/^host=//p' <<<"${info}" | head -n1)"
    site_url="$(sed -n 's/^site_url=//p' <<<"${info}" | head -n1)"
    [[ "${ready}" == "1" ]] || die "the application is not ready for build hosts: run the database update first (Administration > Updates, or: sudo -u ${WEB_USER} php ${APP_DIR}/scripts/update_cli.php --update_db)"
    [[ -n "${SITE_URL}" ]] && site_url="${SITE_URL}"
    [[ -n "${site_url}" ]] || die "the application's public https:// address is not set. Set the service URL under Administration > Endpoint agent, or pass --site-url=https://..."
    local server_url="${site_url}/api/v1/agent_build_host"
    say "  server: ${server_url}"
    local have_good_conf=0
    if [[ -f "${BH_CONF}" && -x "${BH_OPT_DIR}/build-host.sh" && "${CHECK}" -ne 1 ]]; then
        if as_user "${BH_USER}" "${BH_OPT_DIR}/build-host.sh" --config "${BH_CONF}" --register >/dev/null 2>&1; then have_good_conf=1; fi
    fi
    if [[ "${have_good_conf}" -eq 1 ]]; then
        say "  ${BH_CONF} already holds a token the server accepts: keeping it"
    elif [[ "${CHECK}" -eq 1 ]]; then
        say "  would ${host_state/none/create}${host_state/exists/replace the token of}${host_state/disabled/re-enable and replace the token of} the build host \"${HOST_NAME}\" (host state: ${host_state}) and write ${BH_CONF} (mode 600, owner ${BH_USER}); the token is never printed"
    else
        local rot=()
        [[ "${host_state}" == "none" ]] || rot=(--rotate)
        install -d -o root -g root -m 755 "${BH_CONF_DIR}"
        local tmpconf token
        tmpconf="$(umask 077; mktemp "${BH_CONF_DIR}/.build-host.conf.XXXXXX")"
        # the token is captured in a variable (never on a command line, never echoed) and written straight into the 0600 file
        if ! token="$(web_php create --name "${HOST_NAME}" "${rot[@]}" 2>/dev/null)"; then
            rm -f "${tmpconf}"
            die "the application could not create the build host \"${HOST_NAME}\" (run: sudo -u ${WEB_USER} ${PHP_BIN} ${APP_DIR}/scripts/build_host_setup.php create --name '${HOST_NAME}' to see why; the token is printed by that command, so do it only on a private terminal)"
        fi
        [[ "${token}" =~ ^rbh_[0-9a-f]{16}_[0-9a-f]{48}$ ]] || { rm -f "${tmpconf}"; die "the application returned something that is not a build host token"; }
        {
            printf '# Written by deploy/setup-build-host.sh. Mode 600, owned by %s. Contains the build host token: never share it.\n' "${BH_USER}"
            printf 'SERVER_URL=%s\n' "${server_url}"
            printf 'TOKEN=%s\n' "${token}"
            printf 'CORE_REPO=%s\n' "${CORE_REPO}"
            printf 'ALLOWED_REFS="%s"\n' "${ALLOWED_REFS}"
            printf 'WORKDIR=%s\n' "${WORKDIR}"
            printf 'BUILD_CPUS=2\nBUILD_MEMORY=4g\nBUILD_TIMEOUT_S=1500\n'
        } >"${tmpconf}"
        token=""
        chown "${BH_USER}:${BH_USER}" "${tmpconf}"
        chmod 600 "${tmpconf}"
        mv -f "${tmpconf}" "${BH_CONF}"
        say "  wrote ${BH_CONF} (mode 600, owner ${BH_USER})"
    fi

    # 5. timer
    step "5/7 The timer"
    if command -v systemctl >/dev/null 2>&1; then
        act systemctl daemon-reload
        act systemctl enable --now build-host.timer
    else
        warn "systemctl not found: start ${BH_OPT_DIR}/build-host.sh --config ${BH_CONF} --once from cron every minute as ${BH_USER}"
    fi

    # 6. verify
    step "6/7 Checking the connection"
    if [[ "${NO_REGISTER}" -eq 1 || "${CHECK}" -eq 1 ]]; then
        say "  skipped"
    elif as_user "${BH_USER}" "${BH_OPT_DIR}/build-host.sh" --config "${BH_CONF}" --register; then
        say "  ${C_G}the server accepted this build host${C_0}"
    else
        warn "the server did not accept the check. Look at: ${BH_OPT_DIR}/build-host.sh --config ${BH_CONF} --register (as ${BH_USER}); common causes: the site is not reachable over https from here, or Docker is not running."
        exit 1
    fi

    step "7/7 Summary"
    cat <<EOF
  Build host:   ${HOST_NAME} -> ${server_url}
  Script:       ${BH_OPT_DIR}/build-host.sh (root-owned, RivetCore ${CORE_PIN_COMMIT:0:12})
  Config:       ${BH_CONF} (mode 600, ${BH_USER})
  Timer:        build-host.timer (every minute)   status: systemctl list-timers build-host.timer
  Builds from:  ${CORE_REPO} (refs: ${ALLOWED_REFS})

Next: Administration > Endpoint agent > Agent updates > Build a new version > "Build on my build host".
Remove it all again with: sudo deploy/setup-build-host.sh --uninstall
EOF
}

if [[ "${UNINSTALL}" -eq 1 ]]; then do_uninstall; else do_install; fi
