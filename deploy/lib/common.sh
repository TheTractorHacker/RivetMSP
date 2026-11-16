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
                # subshell: with no resolvable web user run_app_php dies, and that must not pre-empt the refusal below
                ( run_app_php "${APP_DIR}" "${APP_DIR}/deploy/lib/backup_status.php" --app-dir="${APP_DIR}" --ok=0 --started="$(date +%s)" --error="${msg}" ) >/dev/null 2>&1 || true
            fi
            die "${msg}"
        fi
    done
    return 0
}

# _rivetmsp_safe_read SRC MODE [WANT_UID]: MSP-DS-34 (re-review RR3). Writes the content of one file from a web-writable directory to stdout,
# or refuses. The order matters: lstat the PATH first and refuse everything that is not a plain regular file (symlink, FIFO, device node,
# directory, socket) BEFORE any open(), so a planted FIFO can never block root and a symlink can never make root open /dev/watchdog or a
# secret. Only then open with O_RDONLY|O_NOFOLLOW|O_NONBLOCK (a file swapped for a symlink fails, one swapped for a FIFO opens without
# blocking and fails the next check), fstat the DESCRIPTOR and require the same device:inode, a regular file and a single hard link
# (re-checked on the open file, so a swap to a hard link after the first look is caught), and read at most 64 KiB from that descriptor.
# MODE strict: owner uid WANT_UID and no group/other permission bits (the settings-key sidecar). MODE any: any owner or mode (the web
# user's own scratch file whose content root copies to its own file). Exit 0 content written; 1 refused (reason on stderr); 2 absent.
# Needs perl (perl-base is Essential on Debian/Ubuntu).
_rivetmsp_safe_read() {
    command -v perl >/dev/null 2>&1 || { echo "perl is required to read a web-writable path safely" >&2; return 1; }
    perl -e '
        use strict; use Fcntl qw(:DEFAULT :mode);
        my ($src, $mode, $want) = @ARGV;
        sub refuse { print STDERR "$_[0]\n"; exit 1 }
        my @l = lstat($src);
        unless (@l) { exit 2 if $!{ENOENT} || $!{ENOTDIR}; refuse("$src cannot be examined ($!)") }
        my $m = $l[2];
        unless (S_ISREG($m)) {
            my $t = S_ISLNK($m) ? "a symbolic link" : S_ISFIFO($m) ? "a FIFO" : S_ISDIR($m) ? "a directory" : (S_ISCHR($m) || S_ISBLK($m)) ? "a device node" : "not a regular file";
            refuse("$src is $t (only a plain regular file is accepted; symbolic links are never followed)");
        }
        sysopen(my $fh, $src, O_RDONLY | O_NOFOLLOW | O_NONBLOCK) or refuse("$src cannot be opened ($!)");
        my @f = stat($fh);
        refuse("$src changed while it was being checked") unless @f && S_ISREG($f[2]) && $f[0] == $l[0] && $f[1] == $l[1];
        refuse(sprintf("%s has %d hard links", $src, $f[3])) unless $f[3] == 1;
        if ($mode eq "strict") {
            refuse("$src is owned by uid $f[4], not root") unless $f[4] == $want;
            refuse(sprintf("%s has mode %o; a settings key must be 0600", $src, $f[2] & 07777)) if ($f[2] & 077) != 0;
        }
        refuse("$src is larger than 64 KiB") if $f[7] > 65536;
        my ($buf, $n, $out) = ("", 0, "");
        while (($n = sysread($fh, $buf, 65536)) > 0) { $out .= $buf; refuse("$src is larger than 64 KiB") if length($out) > 65536 }
        defined $n or refuse("$src cannot be read ($!)");
        print $out;
    ' -- "$@"
}

# copy_trusted_sidecar SRC DEST: MSP-DS-34 (review R2, re-review RR3). A file found NEXT TO a backup archive (the <archive>.settings-key
# sidecar) lives in a directory the web user can write, so root must not follow or trust whatever it finds there: a symlink to /etc/shadow,
# a FIFO (which would hang root forever), a device node, a root SSH key or the backup passphrase. _rivetmsp_safe_read lstats first, opens
# O_NOFOLLOW|O_NONBLOCK, verifies the open descriptor (regular, same inode, one link, root-owned, 0600 or stricter) and DEST (0600) is written
# from those bytes only. Returns 0 on success, 1 (reason on stderr) when SRC is not safe, 2 when SRC does not exist.
# (Test hook: with RIVETMSP_TRUST_TEST_NONROOT=1 and a non-root caller, RIVETMSP_TRUST_UID names the expected owner.)
copy_trusted_sidecar() {
    local src="$1" dest="$2" want=0 rc=0
    if [[ "${RIVETMSP_TRUST_TEST_NONROOT:-}" == "1" && "${EUID}" -ne 0 ]]; then want="${RIVETMSP_TRUST_UID:-0}"; fi
    ( umask 077; _rivetmsp_safe_read "${src}" strict "${want}" > "${dest}" ) || rc=$?
    if [[ "${rc}" -ne 0 ]]; then : > "${dest}"; return "${rc}"; fi
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
    owner="$(_app_exec_user "${app_dir}")" || die "Refusing to run application code as root."
    scratch="$(mktemp -d)"; register_tmpfile "${scratch}"; chmod 700 "${scratch}"
    [[ -n "${owner}" ]] && chown "${owner}" "${scratch}"
    out="$(run_app_php "${app_dir}" "${app_dir}/scripts/rmm_postgres_backup_env.php" --app-dir="${app_dir}" --passfile="${scratch}/pgpass")" || rc=$?
    [[ "${rc}" -eq 0 ]] || return "${rc}"
    [[ -n "${out}" ]] || return 0
    parse_rmm_pg_env "${out}"
    own_pass="$(mktemp)"; chmod 600 "${own_pass}"; register_tmpfile "${own_pass}"
    # The scratch directory is the web user's: never open its pgpass before an lstat (a FIFO swapped in would hang the root backup).
    _rivetmsp_safe_read "${scratch}/pgpass" any > "${own_pass}" || { [[ $? -eq 2 ]] || warn "Not using ${scratch}/pgpass: it is not a plain regular file."; : > "${own_pass}"; }
    export PGPASSFILE="${own_pass}"
    # The web user's scratch copy (that user's own 0600 file in a directory only it can enter) is what run_rmm_pg_script hands to the
    # vendor scripts, which run as that user; root never executes them.
    [[ -f "${scratch}/pgpass" && ! -L "${scratch}/pgpass" ]] && RMM_PG_USER_PASSFILE="${scratch}/pgpass"
    RMM_PG_READY=1
    return 0
}

# _rivetmsp_root_mode: true when this process is root (or, in tests only, pretends to be: RIVETMSP_TRUST_TEST_NONROOT=1 plus
# RIVETMSP_TEST_AS_ROOT=1 from a non-root caller, so the real root-only code paths run with `runuser` replaced on PATH).
_rivetmsp_root_mode() {
    [[ "${EUID}" -eq 0 ]] && return 0
    [[ "${RIVETMSP_TRUST_TEST_NONROOT:-}" == "1" && "${RIVETMSP_TEST_AS_ROOT:-}" == "1" ]]
}

# rivetmsp_web_user <app_dir>: MSP-DS-34 (re-review RR1). The account application code must run as when this process is root. It comes from
# a ROOT-CONTROLLED source, never from a file the web user can rename or replace (config.php, anything inside APP_DIR):
#   1. WEB_USER= in /etc/rivetmsp/web-user.conf, then in /etc/rivetmsp/web-update.conf (written at install time by install.sh,
#      install-web-update.sh, install-trusted-tooling.sh); each must be a root-owned regular file, not a symlink, not group/other-writable;
#   2. else (installs from before those files) the owner of APP_DIR itself: the web user cannot change it (chown needs root, and the
#      parent directory is root's), provided it is not root and APP_DIR is a real directory, not a symlink.
# Prints the user name. Fails (non-zero, loud) when nothing yields a valid non-root account, so nothing ever falls through to running app
# code as root. (Test hooks, only with RIVETMSP_TRUST_TEST_NONROOT=1 and a non-root caller: RIVETMSP_ETC_DIR, RIVETMSP_TRUST_UID.)
# Call as: u="$(rivetmsp_web_user "${APP_DIR}")" || die ...   (a die inside $(...) ends only the subshell, so callers must check.)
rivetmsp_web_user() {
    local app_dir="$1" etc=/etc/rivetmsp want=0 f u="" src="" st
    if [[ "${RIVETMSP_TRUST_TEST_NONROOT:-}" == "1" && "${EUID}" -ne 0 ]]; then
        etc="${RIVETMSP_ETC_DIR:-/etc/rivetmsp}"; want="${RIVETMSP_TRUST_UID:-0}"
    fi
    for f in web-user.conf web-update.conf; do
        f="${etc}/${f}"
        [[ -f "${f}" && ! -L "${f}" ]] || continue
        st="$(stat -c '%u %a' -- "${f}" 2>/dev/null)" || continue
        [[ "${st%% *}" == "${want}" ]] || { echo "WARNING: ${f} is not owned by root; ignoring it." >&2; continue; }
        (( (8#${st##* } & 8#022) == 0 )) || { echo "WARNING: ${f} is group/other-writable; ignoring it." >&2; continue; }
        u="$(sed -n -E 's/^WEB_USER=(.*)$/\1/p' "${f}" | head -n 1 | sed -E "s/^['\"]//; s/['\"]\$//")"
        if [[ -n "${u}" ]]; then src="${f}"; break; fi
    done
    if [[ -z "${u}" && -n "${app_dir}" && -d "${app_dir}" && ! -L "${app_dir}" ]]; then
        u="$(stat -c '%U' -- "${app_dir}" 2>/dev/null || true)"; src="the owner of ${app_dir}"
    fi
    if ! [[ "${u}" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]]; then
        echo "ERROR: cannot determine the web user (no WEB_USER in ${etc}/web-user.conf and ${app_dir:-APP_DIR} is not owned by a normal account). Refusing to run application code as root. Run: sudo deploy/install-trusted-tooling.sh --web-user=www-data" >&2
        return 1
    fi
    if [[ "${u}" == "root" || "$(id -u "${u}" 2>/dev/null || echo 0)" == "0" ]]; then
        echo "ERROR: the web user from ${src} is root or does not exist. Refusing to run application code as root." >&2
        return 1
    fi
    printf '%s' "${u}"
}

# write_web_user_conf <user>: install-time half of rivetmsp_web_user (root only): /etc/rivetmsp/web-user.conf, root:root 0644.
write_web_user_conf() {
    local u="$1"
    [[ "${u}" =~ ^[a-z_][a-z0-9_-]{0,31}$ && "${u}" != "root" ]] || die "Not a usable web user name: '${u}'."
    install -d -m 0755 -o root -g root /etc/rivetmsp
    printf 'WEB_USER=%s\n' "${u}" > /etc/rivetmsp/web-user.conf.new
    chown root:root /etc/rivetmsp/web-user.conf.new; chmod 0644 /etc/rivetmsp/web-user.conf.new
    mv -fT /etc/rivetmsp/web-user.conf.new /etc/rivetmsp/web-user.conf
}

# _app_exec_user <app_dir>: the account application code must run as. Root: the web user from rivetmsp_web_user (never empty, never root;
# non-zero otherwise). A normal user: "" (it just runs the command as itself, which gains nothing). No path returns "" to a root caller.
_app_exec_user() {
    if _rivetmsp_root_mode; then rivetmsp_web_user "$1"; return; fi
    return 0
}

# run_app_php <app_dir> <php args...>: MSP-DS-34. Application PHP (config.php, scripts/*.php, the app's own bootstrap, vendor/) is writable by
# the web user, so root must never execute it. As root it always drops to the web user (rivetmsp_web_user; dies when there is none); as a
# normal user it simply runs php. Callers get only the output, which they treat as data.
run_app_php() {
    local app_dir="$1"; shift
    run_app_exec "${app_dir}" "${PHP_BIN:-php}" "$@"
}

# run_app_exec <app_dir> <command> [args...]: run any command that lives in, or loads code from, the application tree (php, or an
# executable under vendor/) as the web user when this is root. Review R3 / RR1: a root process must never execute a file the web user can
# write, so a root caller that cannot name a non-root web user dies instead of running the command.
run_app_exec() {
    local app_dir="$1"; shift
    local owner; owner="$(_app_exec_user "${app_dir}")" || die "Refusing to run application code as root."
    if _rivetmsp_root_mode; then
        [[ -n "${owner}" && "${owner}" != "root" ]] || die "Refusing to run application code as root (no web user)."
        ( cd / && runuser -u "${owner}" -- "$@" )
    else
        ( cd / && "$@" )
    fi
}

# run_rmm_pg_script <app_dir> <script> [args...]: MSP-DS-34 (review R3). rivet-core's rmm-postgres-backup.sh / rmm-postgres-restore.sh live in
# vendor/ (web-writable), so they are NOT executed by root: they run as the web user, which already holds the RMM PostgreSQL credentials,
# with the RIVETCORE_PG_* values root validated and the password file the web user's own scratch copy (from rmm_pg_prepare). STDIN/STDOUT
# are inherited, so root keeps opening the dump file (backup: `> dump`, restore: `< dump`) in its root-only staging directory. Nothing the
# script prints is trusted, and the dump is only data. A non-root caller runs it directly with its own password file; a root caller with no
# resolvable web user dies (RR1).
run_rmm_pg_script() {
    local app_dir="$1" script="$2"; shift 2
    local owner; owner="$(_app_exec_user "${app_dir}")" || die "Refusing to run application code as root."
    if _rivetmsp_root_mode; then
        [[ -n "${owner}" && "${owner}" != "root" ]] || die "Refusing to run application code as root (no web user)."
        [[ -n "${RMM_PG_USER_PASSFILE:-}" && -f "${RMM_PG_USER_PASSFILE}" && ! -L "${RMM_PG_USER_PASSFILE}" ]] || die "No password file prepared for the RMM PostgreSQL step."
        ( cd / && PGPASSFILE="${RMM_PG_USER_PASSFILE}" runuser -u "${owner}" -- "${script}" "$@" )
    else
        ( cd / && "${script}" "$@" )
    fi
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

# RIVETMSP_GIT_SAFE_ENV: MSP-DS-34 (review R12). Every git run as the checkout owner (directly, or through update_cli.php / composer / the
# vendor-fixup script) must not execute anything configured inside the repository, which the web user may be able to write: hooks
# (post-merge, reference-transaction, ...) and core.fsmonitor run arbitrary commands. GIT_CONFIG_COUNT/KEY/VALUE is "command" scope, wins over
# .git/config, and is inherited by every git the child starts. Use as: sudo -u "${OWNER}" env "${RIVETMSP_GIT_SAFE_ENV[@]}" git ...
RIVETMSP_GIT_SAFE_ENV=(GIT_CONFIG_COUNT=2 GIT_CONFIG_KEY_0=core.hooksPath GIT_CONFIG_VALUE_0=/dev/null GIT_CONFIG_KEY_1=core.fsmonitor GIT_CONFIG_VALUE_1=false)

# require_git_tree_trusted APP_DIR OWNER: MSP-DS-34 (re-review RR2). RIVETMSP_GIT_SAFE_ENV switches off hooks and fsmonitor, but a repository the web
# user can write can run code as OWNER through many more keys (core.sshCommand, remote.*.uploadpack, filter.*.smudge + info/attributes,
# credential.helper, include.path, protocol.ext.allow + an ext:: URL, or edited refs/index that make the pull check out attacker content).
# Listing every such key cannot be complete, so the design is: git runs as OWNER only when nothing the web user could have written is in
# the repository. APP_DIR/.git must be a real directory (not a symlink and not a `gitdir:` file) and EVERYTHING under it, plus APP_DIR itself
# (whose owner could otherwise rename .git away and put its own in place), must be owned by OWNER or root and not group/other-writable.
# A violation is a loud refusal (non-zero die, syslog) naming the first offending path and the fix. RIVETMSP_ALLOW_UNTRUSTED_GIT=1 overrides
# for an operator who reviewed the tree. The safe env stays as defence in depth. Not a no-op for OWNER == web user (same account: nothing to
# escalate to, and the check passes on a normally installed tree).
require_git_tree_trusted() {
    local app="${1:?app dir}" owner="${2:?owner}" g bad="" msg
    g="${app}/.git"
    [[ -e "${g}" || -L "${g}" ]] || return 0
    if [[ -L "${g}" || ! -d "${g}" ]]; then
        bad="${g} is not a plain directory (symlink or gitdir file)"
    else
        bad="$(find "${app}" -maxdepth 0 \( ! -user "${owner}" ! -user root -o -perm /022 \) -printf '%p (owner %u, mode %m)\n' 2>/dev/null | head -n 1)"
        [[ -n "${bad}" ]] || bad="$(find "${g}" \( -type l -a ! -user "${owner}" -a ! -user root \) -o \( ! -type l -a \( \( ! -user "${owner}" -a ! -user root \) -o -perm /022 \) \) -printf '%p (owner %u, mode %m)\n' 2>/dev/null | head -n 1)"
    fi
    [[ -n "${bad}" ]] || return 0
    msg="Refusing to run git/composer as ${owner}: ${bad} could be changed by another account, and a repository the web user can write can run code as ${owner} (core.sshCommand, remote.*.uploadpack, filter smudge, credential.helper, include.path). Fix: sudo chown -R ${owner} '${g}' && sudo chmod -R go-w '${g}' '${app}', then re-run. RIVETMSP_ALLOW_UNTRUSTED_GIT=1 overrides after you reviewed .git/config and .git/hooks."
    if [[ "${RIVETMSP_ALLOW_UNTRUSTED_GIT:-}" == "1" ]]; then warn "${msg}"; return 0; fi
    logger -t rivetmsp-deploy -p user.crit "${msg}" 2>/dev/null || true
    die "${msg}"
}

# ensure_updater_remote APP_DIR [OWNER]: RivetMSP's updater (includes/release_channel.php RELEASE_REMOTE) pulls from a git
# remote named "fork", but an install cloned or copied from a checkout only has "origin". Without it `git pull fork ...`
# fails and update_cli.php still prints "Update successful", so nothing ever updates. Add it as an alias of origin when
# missing. Idempotent; a no-op when the app does not name a remote other than origin.
ensure_updater_remote() {
    local app="${1:?app dir}" owner="${2:-}" rf name url
    rf="${app}/includes/release_channel.php"
    [[ -f "${rf}" && -d "${app}/.git" ]] || return 0
    [[ -n "${owner}" ]] && require_git_tree_trusted "${app}" "${owner}"
    name="$(sed -n "s/.*define('RELEASE_REMOTE', *'\([^']*\)').*/\1/p" "${rf}" | head -1)"
    [[ -n "${name}" && "${name}" != "origin" ]] || return 0
    local -a g=(git -C "${app}")
    [[ -n "${owner}" ]] && g=(sudo -u "${owner}" env "${RIVETMSP_GIT_SAFE_ENV[@]}" git -C "${app}")
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
    [[ -n "${owner}" ]] && require_git_tree_trusted "${app}" "${owner}"
    local -a g=(git -C "${app}")
    [[ -n "${owner}" ]] && g=(sudo -u "${owner}" env "${RIVETMSP_GIT_SAFE_ENV[@]}" git -C "${app}")
    [[ "$("${g[@]}" config --get core.fileMode 2>/dev/null || true)" == "false" ]] && return 0
    "${g[@]}" config core.fileMode false || warn "Could not set core.fileMode=false in ${app}; a later update may be blocked by file-mode differences."
}

trap _cleanup_tmpfiles EXIT
