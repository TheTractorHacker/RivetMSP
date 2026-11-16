#!/usr/bin/env bash
# RivetMSP deployment tooling — shared helper library.
#
# Sourced (never executed directly) by deploy/install.sh and by the other
# deploy/*.sh scripts in this directory (e.g. deploy/harden.sh,
# deploy/update.sh, deploy/backup.sh, deploy/restore.sh). Function names/
# signatures here are a shared interface across those scripts — keep them
# stable and generic rather than tailoring them to any one script's needs.
#
# Usage from a caller:
#   SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#   # shellcheck source=./lib/common.sh
#   source "${SCRIPT_DIR}/lib/common.sh"

set -euo pipefail

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------
# Colored only when stderr is an actual terminal that claims 8+ colors —
# never emit raw escape codes into a redirected log file (e.g. install.sh's
# `exec > >(tee -a /var/log/itflow-install.log)`), where they'd just show up
# as literal "\033[..." noise.
if [[ -t 2 ]] && command -v tput >/dev/null 2>&1 && [[ "$(tput colors 2>/dev/null || echo 0)" -ge 8 ]]; then
    C_RED=$'\033[0;31m'
    C_GREEN=$'\033[0;32m'
    C_YELLOW=$'\033[0;33m'
    C_BLUE=$'\033[0;34m'
    C_BOLD=$'\033[1m'
    C_RESET=$'\033[0m'
else
    C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""; C_BOLD=""; C_RESET=""
fi

_ts() { date '+%Y-%m-%d %H:%M:%S'; }

# log(): plain, uncolored step marker. Used for routine narration that other
# tooling (e.g. a CI log scraper) might grep for without ANSI codes in the way.
log() {
    printf '%s [%s]\n' "$(_ts)" "$*"
}

# info()/warn()/error(): colored, leveled output. warn/error go to stderr so
# a caller can separate them from stdout even when both are also being
# tee'd to the same logfile.
info() {
    printf '%s %s[INFO]%s %s\n' "$(_ts)" "${C_BLUE}" "${C_RESET}" "$*"
}

success() {
    printf '%s %s[ OK ]%s %s\n' "$(_ts)" "${C_GREEN}" "${C_RESET}" "$*"
}

warn() {
    printf '%s %s[WARN]%s %s\n' "$(_ts)" "${C_YELLOW}" "${C_RESET}" "$*" >&2
}

error() {
    printf '%s %s[FAIL]%s %s\n' "$(_ts)" "${C_RED}" "${C_RESET}" "$*" >&2
}

# die(): print an error and exit non-zero. The one and only way scripts
# using this library should abort on a fatal condition.
die() {
    error "$*"
    exit 1
}

# announce(): for invasive/hard-to-reverse steps (service restarts, ufw
# enable, cert issuance, destructive file writes). Prints what is ABOUT to
# happen, distinctly from routine info(), before the caller does it.
announce() {
    printf '%s %s%s[ACTION]%s %s\n' "$(_ts)" "${C_BOLD}" "${C_YELLOW}" "${C_RESET}" "$*"
}

# ---------------------------------------------------------------------------
# Environment / privilege checks
# ---------------------------------------------------------------------------

# require_root(): dies with a clear, actionable message if not running as
# EUID 0. Every script in deploy/ that touches system packages, /etc, or
# service state should call this before doing anything else.
require_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        die "This script must be run as root. Re-run with: sudo $0 $*"
    fi
}

# detect_os(): dies cleanly on anything that isn't a Debian/Ubuntu apt-based
# system — that's the only target this tooling supports in v1. On success,
# exports OS_ID / OS_VERSION_ID / OS_CODENAME / OS_PRETTY_NAME for callers
# that want to branch on specifics (e.g. Ubuntu 24.04 "noble").
detect_os() {
    if [[ ! -r /etc/os-release ]]; then
        die "Cannot detect the operating system (/etc/os-release missing). This installer only supports Debian/Ubuntu (apt-based) systems."
    fi

    # shellcheck disable=SC1091
    . /etc/os-release

    local id="${ID:-}"
    local id_like="${ID_LIKE:-}"
    if [[ "${id}" != "ubuntu" && "${id}" != "debian" && "${id_like}" != *debian* && "${id_like}" != *ubuntu* ]]; then
        die "Unsupported OS '${PRETTY_NAME:-$id}'. This installer only supports Debian/Ubuntu (apt-based) systems."
    fi

    if ! command -v apt-get >/dev/null 2>&1; then
        die "apt-get not found. This installer requires an apt-based system."
    fi

    OS_ID="${id}"
    OS_VERSION_ID="${VERSION_ID:-}"
    OS_CODENAME="${VERSION_CODENAME:-}"
    OS_PRETTY_NAME="${PRETTY_NAME:-$id}"
    export OS_ID OS_VERSION_ID OS_CODENAME OS_PRETTY_NAME

    info "Detected OS: ${OS_PRETTY_NAME}"
}

# ---------------------------------------------------------------------------
# Secrets
# ---------------------------------------------------------------------------

# gen_secret([length]): a random secret safe to drop into shell command
# lines, my.cnf/ini files, or URLs without quoting gymnastics — alphanumeric
# only, so '/', '+', '=' (base64's non-alnum characters) can never land in
# it. Loops rather than a single truncated call so the result always has the
# requested length even after stripping. Default length is 32.
gen_secret() {
    local length="${1:-32}"
    local secret=""
    while [[ "${#secret}" -lt "${length}" ]]; do
        secret+="$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9')"
    done
    printf '%s' "${secret:0:${length}}"
}

# ---------------------------------------------------------------------------
# Package / service state
# ---------------------------------------------------------------------------

# package_installed(name): true if a .deb package is installed (any state
# short of fully-installed, e.g. "half-configured", counts as NOT installed
# so callers re-attempt installation rather than trusting a broken package).
package_installed() {
    local status
    status="$(dpkg-query -W -f='${Status}' "$1" 2>/dev/null || true)"
    [[ "${status}" == "install ok installed" ]]
}

# service_is_active(name): true if a systemd unit is currently active.
service_is_active() {
    systemctl is-active --quiet "$1" 2>/dev/null
}

# command_exists(name): thin wrapper kept for readability at call sites.
# php_fpm_unit(): print the name of the active PHP-FPM systemd unit, or nothing (exit 1) when none is active. A source-built PHP
# (this project's servers run /opt/php-8.5.12) installs php<full version>-fpm, apt installs php<major.minor>-fpm, so try the full
# version first. PHP_FPM_UNIT_CANDIDATES (space separated) overrides the list; tests use it with a stubbed systemctl.
php_fpm_unit() {
    local cands u
    if [[ -n "${PHP_FPM_UNIT_CANDIDATES:-}" ]]; then
        cands="${PHP_FPM_UNIT_CANDIDATES}"
    else
        local full mm
        full="$(php -r 'echo PHP_VERSION;' 2>/dev/null | sed -E 's/^([0-9]+\.[0-9]+\.[0-9]+).*/\1/')" || full=""
        mm="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)" || mm=""
        cands=""
        [[ -n "${full}" ]] && cands="php${full}-fpm"
        [[ -n "${mm}" ]] && cands="${cands} php${mm}-fpm"
    fi
    for u in ${cands}; do
        if service_is_active "${u}"; then
            printf '%s\n' "${u}"
            return 0
        fi
    done
    return 1
}

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# ---------------------------------------------------------------------------
# Application config (config.php)
# ---------------------------------------------------------------------------

# read_app_config(app_dir): populates DB_HOST/DB_USER/DB_PASS/DB_NAME/
# INSTALLATION_ID/SETTINGS_ENC_KEY by shelling out to `php -r` with a small
# trusted snippet that requires app_dir/config.php and echoes the values it
# defines. Shared by backup.sh (to capture INSTALLATION_ID/SETTINGS_ENC_KEY
# into its backup manifest) and restore.sh (to find the target database and
# re-apply the encryption key). SETTINGS_ENC_KEY is empty only on an
# instance set up before the key existed: setup (web and scripts/setup_cli.php)
# now generates $config_settings_enc_key, deploy/update.sh adds one to an older
# instance, and encryptSetting() fails closed without it (it no longer stores a
# secret in cleartext). Captured and restored so a restore onto a fresh box
# keeps every wrapped secret readable. Deliberately NOT parsed out of config.php with
# grep/sed (its values go through var_export(), so they can contain escaped
# quotes, unicode, etc. — a text-munging parse would be fragile) and
# deliberately NOT eval'd as arbitrary PHP from an untrusted source —
# config.php is a trusted local file this same install already wrote, so
# requiring it here carries no more risk than the app's own every-request
# bootstrap already does.
# ---------------------------------------------------------------------------
# Root must not run code the web user can rewrite (MSP-DS-34; same design as RivetIT IT-DS-28)
# ---------------------------------------------------------------------------
# rivetmsp_path_trusted PATH: succeeds only when PATH, what it resolves to and every parent directory are owned by root and not writable
# by group or others. Prints why on failure. (Test hook: RIVETMSP_TRUST_TEST_NONROOT=1 lets a non-root test name another trusted owner via
# RIVETMSP_TRUST_UID and a stop directory via RIVETMSP_TRUST_STOP.)
rivetmsp_path_trusted() {
    local p="$1" want=0 stop="/" q d u m
    if [[ "${RIVETMSP_TRUST_TEST_NONROOT:-}" == "1" && "${EUID}" -ne 0 ]]; then want="${RIVETMSP_TRUST_UID:-0}"; stop="${RIVETMSP_TRUST_STOP:-/}"; fi
    [[ "${p}" == /* ]] || { echo "not an absolute path: ${p}"; return 1; }
    for q in "${p}" "$(readlink -f -- "${p}" 2>/dev/null || echo "${p}")"; do
        [[ -e "${q}" ]] || { echo "missing: ${q}"; return 1; }
        d="${q}"
        while :; do
            read -r u m < <(stat -c '%u %a' -- "${d}")
            [[ "${u}" == "${want}" ]] || { echo "${d} is not owned by root"; return 1; }
            if (( (8#${m} & 8#022) != 0 )); then echo "${d} is writable by group or others (mode ${m})"; return 1; fi
            [[ "${d}" == "/" || "${d}" == "${stop}" ]] && break
            d="$(dirname -- "${d}")"
        done
    done
    return 0
}

# require_trusted_tooling <calling-script>: refuse to run privileged tooling (backup, restore, restore drill, update) from a tree the web
# user can write, because that user could replace common.sh or the script itself and become root at the next timer run. The refusal is
# loud: non-zero exit, a syslog entry, and (best effort, as the web user) a failed-backup report the application shows to administrators.
# Install the root-owned copy with deploy/install-trusted-tooling.sh and run (and point the systemd units at) that copy.
# RIVETMSP_ALLOW_UNTRUSTED_TOOLING=1 overrides, for an operator who reviewed the tree; the shipped units never set it.
require_trusted_tooling() {
    local caller="${1:-}" lib why f msg
    lib="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    for f in "${lib}/common.sh" "${caller}"; do
        [[ -n "${f}" ]] || continue
        [[ "${f}" == /* ]] || f="$(cd "$(dirname "${f}")" && pwd)/$(basename "${f}")"
        if ! why="$(rivetmsp_path_trusted "${f}")"; then
            if [[ "${RIVETMSP_ALLOW_UNTRUSTED_TOOLING:-}" == "1" ]]; then
                warn "Running although the tooling is not root-protected (${why}); RIVETMSP_ALLOW_UNTRUSTED_TOOLING=1 was set."
                return 0
            fi
            msg="Refusing to run as root from a tree the web user can modify (${why}). Run: sudo deploy/install-trusted-tooling.sh, then use /usr/local/lib/rivetmsp-deploy/$(basename "${caller:-backup.sh}") and point the systemd units there."
            logger -t rivetmsp-deploy -p user.crit "${msg}" 2>/dev/null || true
            if [[ -n "${APP_DIR:-}" && -f "${APP_DIR}/deploy/lib/backup_status.php" ]]; then
                run_app_php "${APP_DIR}" "${APP_DIR}/deploy/lib/backup_status.php" --app-dir="${APP_DIR}" --ok=0 --started="$(date +%s)" --error="${msg}" >/dev/null 2>&1 || true
            fi
            die "${msg}"
        fi
    done
    return 0
}

# copy_trusted_sidecar SRC DEST: MSP-DS-34 (review R2). A file found NEXT TO a backup archive (the <archive>.settings-key sidecar) lives in
# a directory the web user can write, so root must not follow or trust whatever it finds there: a symlink to /etc/shadow, a root SSH key or
# the backup passphrase would be copied into a file the web user can read. SRC is opened once (O_RDONLY, following nothing we have not then
# checked), and the OPEN file is verified: a regular file (not reached through a symlink: lstat of the path must be the same inode),
# owned by root, mode 0600 or stricter, a single hard link. Only then is it copied to DEST (0600) from that same descriptor, so a swap after
# the check changes nothing. Returns 0 on success, 1 (reason on stderr) when SRC is not safe, 2 when SRC does not exist.
# (Test hook: with RIVETMSP_TRUST_TEST_NONROOT=1 and a non-root caller, RIVETMSP_TRUST_UID names the expected owner.)
copy_trusted_sidecar() {
    local src="$1" dest="$2" want=0 fd ftype fuid fmode fnlink fid ltype lid
    if [[ "${RIVETMSP_TRUST_TEST_NONROOT:-}" == "1" && "${EUID}" -ne 0 ]]; then want="${RIVETMSP_TRUST_UID:-0}"; fi
    [[ -e "${src}" || -L "${src}" ]] || return 2
    { exec {fd}< "${src}"; } 2>/dev/null || { echo "${src} cannot be opened" >&2; return 1; }
    read -r fuid fmode fnlink fid < <(stat -L -c '%u %a %h %d:%i' "/proc/self/fd/${fd}" 2>/dev/null) || fuid=""
    ftype="$(stat -L -c '%F' "/proc/self/fd/${fd}" 2>/dev/null || true)"
    ltype="$(stat -c '%F' -- "${src}" 2>/dev/null || true)"
    lid="$(stat -c '%d:%i' -- "${src}" 2>/dev/null || true)"
    local why=""
    if [[ "${ltype}" != "regular file" ]]; then why="${src} is ${ltype:-not a regular file} (symbolic links are never followed)"
    elif [[ "${ftype}" != "regular file" || "${fid}" != "${lid}" ]]; then why="${src} changed while it was being checked"
    elif [[ "${fuid}" != "${want}" ]]; then why="${src} is owned by uid ${fuid}, not root"
    elif (( (8#${fmode} & 8#077) != 0 )); then why="${src} has mode ${fmode}; a settings key must be 0600"
    elif [[ "${fnlink}" != "1" ]]; then why="${src} has ${fnlink} hard links"
    fi
    if [[ -n "${why}" ]]; then exec {fd}<&-; echo "${why}" >&2; return 1; fi
    ( umask 077; cat <&"${fd}" > "${dest}" ) || { exec {fd}<&-; return 1; }
    exec {fd}<&-
    chmod 600 "${dest}"
    return 0
}

# refuse_untrusted_sidecar WHY: loud refusal (syslog + non-zero exit) for copy_trusted_sidecar failures.
refuse_untrusted_sidecar() {
    local msg="Refusing to use a settings-key file next to the archive: $1. The backup directory is writable by the web user, so only a root-owned regular 0600 file is accepted. Pass --settings-key-file=<path> explicitly to use another file."
    logger -t rivetmsp-deploy -p user.crit "${msg}" 2>/dev/null || true
    die "${msg}"
}

# rmm_pg_prepare APP_DIR: MSP-DS-34. Runs scripts/rmm_postgres_backup_env.php as the web user and sets RIVETCORE_PG_* from its
# output (validated, never eval'd). The script writes the connection's PGPASSFILE into a directory only that user can reach; root
# then reads that file (never through a symlink) and writes its OWN root-owned 0600 password file, and PGPASSFILE is set to that:
# a path taken from app output is never trusted. Returns 0 with RMM_PG_READY=1 when a PostgreSQL store is configured, 0 with
# RMM_PG_READY=0 when it is not, non-zero when the script failed.
rmm_pg_prepare() {
    local app_dir="$1" owner scratch out rc=0 own_pass
    RMM_PG_READY=0
    RMM_PG_USER_PASSFILE=""
    owner="$(app_owner_user "${app_dir}")"
    scratch="$(mktemp -d)"; register_tmpfile "${scratch}"; chmod 700 "${scratch}"
    [[ -n "${owner}" ]] && chown "${owner}" "${scratch}"
    out="$(run_app_php "${app_dir}" "${app_dir}/scripts/rmm_postgres_backup_env.php" --app-dir="${app_dir}" --passfile="${scratch}/pgpass")" || rc=$?
    [[ "${rc}" -eq 0 ]] || return "${rc}"
    [[ -n "${out}" ]] || return 0
    parse_rmm_pg_env "${out}"
    own_pass="$(mktemp)"; chmod 600 "${own_pass}"; register_tmpfile "${own_pass}"
    if [[ -f "${scratch}/pgpass" && ! -L "${scratch}/pgpass" ]]; then
        cat -- "${scratch}/pgpass" > "${own_pass}"
    fi
    export PGPASSFILE="${own_pass}"
    # The web user's scratch copy (that user's own 0600 file in a directory only it can enter) is what run_rmm_pg_script hands to the
    # vendor scripts, which run as that user; root never executes them.
    [[ -f "${scratch}/pgpass" && ! -L "${scratch}/pgpass" ]] && RMM_PG_USER_PASSFILE="${scratch}/pgpass"
    RMM_PG_READY=1
    return 0
}

# _app_exec_user <app_dir>: the account application code must run as ("" = no drop needed). When running as root it is the non-root owner
# of config.php (the web user); as a normal user, or when config.php is root-owned, it is empty. (Tests override this function.)
_app_exec_user() {
    [[ "${EUID}" -eq 0 ]] && app_owner_user "$1"
    return 0
}

# run_app_php <app_dir> <php args...>: MSP-DS-34. Application PHP (config.php, scripts/*.php, the app's own bootstrap, vendor/) is writable by
# the web user, so root must never execute it. When running as root, drop to the account that owns config.php (the web user) first; as a
# normal user (or when config.php is root-owned) it simply runs php. Callers get only the output, which they treat as data.
run_app_php() {
    local app_dir="$1"; shift
    run_app_exec "${app_dir}" "${PHP_BIN:-php}" "$@"
}

# run_app_exec <app_dir> <command> [args...]: run any command that lives in, or loads code from, the application tree (php, or an
# executable under vendor/) as the web user when this is root. Review R3: a root process must never execute a file the web user can write.
run_app_exec() {
    local app_dir="$1"; shift
    local owner; owner="$(_app_exec_user "${app_dir}")"
    if [[ -n "${owner}" && "${owner}" != "root" ]]; then
        ( cd / && runuser -u "${owner}" -- "$@" )
    else
        ( cd / && "$@" )
    fi
}

# run_rmm_pg_script <app_dir> <script> [args...]: MSP-DS-34 (review R3). rivet-core's rmm-postgres-backup.sh / rmm-postgres-restore.sh live in
# vendor/ (web-writable), so they are NOT executed by root: they run as the web user, which already holds the RMM PostgreSQL credentials,
# with the RIVETCORE_PG_* values root validated and the password file the web user's own scratch copy (from rmm_pg_prepare). STDIN/STDOUT
# are inherited, so root keeps opening the dump file (backup: `> dump`, restore: `< dump`) in its root-only staging directory. Nothing the
# script prints is trusted, and the dump is only data. When no drop is needed (non-root caller, or a root-owned config.php) it runs directly
# with root's own password file.
run_rmm_pg_script() {
    local app_dir="$1" script="$2"; shift 2
    local owner; owner="$(_app_exec_user "${app_dir}")"
    if [[ -n "${owner}" && "${owner}" != "root" ]]; then
        [[ -n "${RMM_PG_USER_PASSFILE:-}" && -f "${RMM_PG_USER_PASSFILE}" && ! -L "${RMM_PG_USER_PASSFILE}" ]] || die "No password file prepared for the RMM PostgreSQL step."
        ( cd / && PGPASSFILE="${RMM_PG_USER_PASSFILE}" runuser -u "${owner}" -- "${script}" "$@" )
    else
        ( cd / && "${script}" "$@" )
    fi
}

# app_owner_user <app_dir>: the non-root account that owns config.php ("" when root-owned or unknown).
app_owner_user() {
    local o; o="$(stat -c '%U' "$1/config.php" 2>/dev/null || true)"
    [[ -n "${o}" && "${o}" != "root" ]] && printf '%s' "${o}"
    return 0
}

# parse_rmm_pg_env <text>: MSP-DS-34. scripts/rmm_postgres_backup_env.php prints `export NAME='value'` lines. It is app code the web
# user can change, so its output is never eval'd: only exactly these variable names with single-quoted values are accepted, and the
# value is taken literally (no expansion). The one extra line the script prints, `export PGPASSFILE='...'`, is ignored, never applied.
parse_rmm_pg_env() {
    local line name val
    while IFS= read -r line; do
        [[ -z "${line}" ]] && continue
        # scripts/rmm_postgres_backup_env.php always prints `export PGPASSFILE='<its scratch file>'` after the six connection lines. That
        # path is app output and is never trusted: discard the line (rmm_pg_prepare makes root's own password file and sets PGPASSFILE).
        [[ "${line}" =~ ^export\ PGPASSFILE=\'.*\'$ ]] && continue
        if [[ "${line}" =~ ^export\ (RIVETCORE_PG_DATABASE|RIVETCORE_PG_USER|RIVETCORE_PG_HOST|RIVETCORE_PG_PORT|RIVETCORE_PG_SSLMODE|RIVETCORE_PG_SCHEMA)=\'(.*)\'$ ]]; then
            name="${BASH_REMATCH[1]}"; val="${BASH_REMATCH[2]}"
            # A value is one single-quoted word: the only quote allowed inside is the shell idiom '\'' (close, escaped quote, reopen).
            local rest="${val//\'\\\'\'/}"
            [[ "${rest}" == *"'"* ]] && die "Unexpected output from scripts/rmm_postgres_backup_env.php; refusing to use it."
            val="${val//\'\\\'\'/\'}"
            printf -v "${name}" '%s' "${val}"; export "${name}"
        else
            die "Unexpected output from scripts/rmm_postgres_backup_env.php; refusing to use it."
        fi
    done <<< "$1"
}

read_app_config() {
    local app_dir="$1"
    info "Reading configuration from ${app_dir}/config.php..."
    local raw
    if ! raw="$(run_app_php "${app_dir}" -r '
        require $argv[1];
        echo $dbhost . "\n" . $dbusername . "\n" . $dbpassword . "\n" . $database . "\n";
        echo ($installation_id ?? "") . "\n";
        echo ($config_settings_enc_key ?? "") . "\n";
    ' -- "${app_dir}/config.php")"; then
        die "Failed to read configuration from ${app_dir}/config.php via 'php -r' (see PHP's error output above). config.php connects to MySQL as a side effect of being require()'d — this usually means the database is unreachable, not just a bad config file."
    fi

    local -a cfg_lines
    mapfile -t cfg_lines <<< "${raw}"
    DB_HOST="${cfg_lines[0]:-}"
    DB_USER="${cfg_lines[1]:-}"
    DB_PASS="${cfg_lines[2]:-}"
    DB_NAME="${cfg_lines[3]:-}"
    INSTALLATION_ID="${cfg_lines[4]:-}"
    SETTINGS_ENC_KEY="${cfg_lines[5]:-}"

    if [[ -z "${DB_HOST}" || -z "${DB_USER}" || -z "${DB_NAME}" ]]; then
        die "config.php did not yield usable database settings (host='${DB_HOST}' user='${DB_USER}' database='${DB_NAME}'). Refusing to proceed with an incomplete target."
    fi
}

# ---------------------------------------------------------------------------
# Misc
# ---------------------------------------------------------------------------

# backup_if_exists(path): if `path` already exists, copy it aside to
# path.bak-<timestamp> before a caller overwrites it. Keeps re-running an
# install/update idempotent-but-non-destructive of local edits, per this
# tooling's general rule of never silently clobbering an existing file.
backup_if_exists() {
    local target="$1"
    if [[ -e "${target}" ]]; then
        local backup
        backup="${target}.bak-$(date +%Y%m%d%H%M%S)"
        cp -a "${target}" "${backup}"
        warn "Existing file backed up: ${target} -> ${backup}"
    fi
}

# detect_ssh_port(): best-effort read of the configured sshd port from
# /etc/ssh/sshd_config AND /etc/ssh/sshd_config.d/*.conf (Ubuntu 24.04's
# default layout drops a machine-generated port override into the latter,
# not the former — checking only sshd_config misses it), falling back to
# the IANA default (22) when no directive is found or nothing is readable.
# Used before any `ufw enable` so the firewall never locks out the very
# session running the installer.
detect_ssh_port() {
    local port=""
    port="$( { [[ -f /etc/ssh/sshd_config ]] && grep -E '^[[:space:]]*Port[[:space:]]+[0-9]+' /etc/ssh/sshd_config;
               compgen -G '/etc/ssh/sshd_config.d/*.conf' >/dev/null && grep -hE '^[[:space:]]*Port[[:space:]]+[0-9]+' /etc/ssh/sshd_config.d/*.conf; } \
             2>/dev/null | awk '{print $2; exit}' || true )"
    printf '%s' "${port:-22}"
}

# ---------------------------------------------------------------------------
# Temp-file cleanup (secrets)
# ---------------------------------------------------------------------------
# Any script that writes a secret to a temp file (a generated DB password, a
# `mysql --defaults-extra-file`) OR extracts one into a temp directory (e.g.
# a decrypted backup archive) should register it here with
# register_tmpfile() rather than rolling its own trap. This is the ONE EXIT
# trap for the whole process — bash keeps only a single handler per signal,
# so a script that later called `trap ... EXIT` again would silently replace
# this one and skip secret cleanup. Add cleanup work by calling
# register_tmpfile(), never by setting a second EXIT trap.
declare -a _ITFLOW_TMPFILES=()

register_tmpfile() {
    _ITFLOW_TMPFILES+=("$1")
}

# register_exit_hook <function>: run <function> <exit-code> from the same single EXIT trap, before temp files are shredded. Used by
# backup.sh to report a failed run (die() exits through here). Hooks must not fail the script: errors are ignored.
declare -a _ITFLOW_EXIT_HOOKS=()
register_exit_hook() {
    _ITFLOW_EXIT_HOOKS+=("$1")
}

_cleanup_tmpfiles() {
    local _rc=$? h f
    for h in "${_ITFLOW_EXIT_HOOKS[@]:-}"; do
        [[ -n "${h}" ]] && { "${h}" "${_rc}" || true; }
    done
    for f in "${_ITFLOW_TMPFILES[@]:-}"; do
        [[ -n "${f}" && -e "${f}" ]] || continue
        if [[ -d "${f}" ]]; then
            # A registered directory (e.g. restore.sh's decrypted-archive
            # extraction dir) may hold sensitive files of its own — shred
            # each one individually before removing the tree, same intent
            # as the plain-file branch below, just recursive.
            find "${f}" -type f -exec shred -u {} + 2>/dev/null
            rm -rf "${f}"
        else
            shred -u "${f}" 2>/dev/null || rm -f "${f}"
        fi
    done
}

# ensure_updater_remote APP_DIR [OWNER]: RivetMSP's updater (includes/release_channel.php RELEASE_REMOTE) pulls from a git
# remote named "fork", but an install cloned or copied from a checkout only has "origin". Without it `git pull fork ...`
# fails and update_cli.php still prints "Update successful", so nothing ever updates. Add it as an alias of origin when
# missing. Idempotent; a no-op when the app does not name a remote other than origin.
ensure_updater_remote() {
    local app="${1:?app dir}" owner="${2:-}" rf name url
    rf="${app}/includes/release_channel.php"
    [[ -f "${rf}" && -d "${app}/.git" ]] || return 0
    name="$(sed -n "s/.*define('RELEASE_REMOTE', *'\([^']*\)').*/\1/p" "${rf}" | head -1)"
    [[ -n "${name}" && "${name}" != "origin" ]] || return 0
    local -a g=(git -C "${app}")
    [[ -n "${owner}" ]] && g=(sudo -u "${owner}" git -C "${app}")
    if "${g[@]}" remote get-url "${name}" >/dev/null 2>&1; then
        return 0
    fi
    url="$("${g[@]}" remote get-url origin 2>/dev/null || true)"
    if [[ -z "${url}" ]]; then
        warn "No git remote '${name}' (used by the updater) and no 'origin' to copy it from; add it by hand: git -C ${app} remote add ${name} <repository url>"
        return 0
    fi
    if "${g[@]}" remote add "${name}" "${url}"; then
        info "Added git remote '${name}' -> ${url} (the remote the updater pulls from)."
    else
        warn "Could not add git remote '${name}'; updates will fail until it exists."
    fi
}
# ignore_git_filemode APP_DIR [OWNER]: install.sh's set_file_permissions rewrites every file to 640/750, which git sees as
# mode changes on the tracked executables (cron/*.php, scripts/*.php, deploy/*.sh). A later `git pull` that touches any of
# them aborts with "Your local changes would be overwritten", leaving the instance un-updatable. Mode bits are not content,
# so tell this checkout to ignore them. Idempotent, never fatal.
ignore_git_filemode() {
    local app="${1:?app dir}" owner="${2:-}"
    [[ -d "${app}/.git" ]] || return 0
    local -a g=(git -C "${app}")
    [[ -n "${owner}" ]] && g=(sudo -u "${owner}" git -C "${app}")
    [[ "$("${g[@]}" config --get core.fileMode 2>/dev/null || true)" == "false" ]] && return 0
    "${g[@]}" config core.fileMode false || warn "Could not set core.fileMode=false in ${app}; a later update may be blocked by file-mode differences."
}

trap _cleanup_tmpfiles EXIT
