#!/usr/bin/env bash
set -euo pipefail

# RivetMSP — restore an encrypted deploy/backup.sh archive.
#
# The counterpart backup.sh never had: decrypts a backup-*.tar.gz.enc
# produced by deploy/backup.sh, then overwrites the target instance's
# database and uploads/ with what's inside. This is the "new server, only
# have my offsite encrypted backup" disaster-recovery path — run
# deploy/install.sh first to stand up a fresh, empty instance (packages, db,
# vhost, first-run setup), then point this script at the backup to restore
# into it.
#
# Usage:
#   restore.sh --app-dir=<path> --backup=<path> --passphrase-file=<path> --confirm-restore [options]
#   restore.sh --help
#
# Required:
#   --app-dir=<path>            Webroot of the ALREADY-INSTALLED instance to
#                               restore into (must contain config.php — run
#                               deploy/install.sh first on a fresh box).
#   --backup=<path>             Path to the backup-*.tar.gz.enc file to
#                               restore (produced by deploy/backup.sh).
#   --passphrase-file=<path>    600-permission file holding the passphrase
#                               that encrypted --backup. Also used, unless
#                               --no-pre-restore-backup-confirmed is passed,
#                               to take a safety backup of --app-dir's
#                               CURRENT state before it's overwritten.
#   --confirm-restore            Required, no-value acknowledgment that this
#                               REPLACES every table in the target database
#                               and everything under --app-dir/uploads with
#                               what's in --backup. Refuses to run without it
#                               — there is no interactive y/n prompt anywhere
#                               in this tooling to accidentally click through.
#
# Options:
#   --no-pre-restore-backup-confirmed
#                               Explicit opt-out of the pre-restore safety
#                               backup. Only use this if --app-dir has
#                               nothing worth keeping (e.g. it was just
#                               created by install.sh and never used) or the
#                               box is critically low on disk space.
#   --unattended                 Cron-friendly: output goes to
#                               /var/log/itflow-restore.log only. Without
#                               this flag, output goes to both the terminal
#                               and the logfile.
#   --help                       Show this help and exit.
#
# Must be run as root — needs to read config.php's db credentials, write
# root-owned temp files, and re-chown uploads/ back to www-data afterward.
#
# What it does NOT do: stop php-fpm, put the site in any kind of maintenance
# mode, or touch cron. mysqldump's own dumps are taken --single-transaction,
# so importing one is consistent even against a live schema, but a ticket
# automation rule or a user editing a record mid-restore can still race the
# import. For a real disaster-recovery drill this is moot (nothing is live
# yet); for a "restore over a running instance" scenario, stop traffic to it
# first if you can.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

LOG_FILE="/var/log/itflow-restore.log"

APP_DIR=""
BACKUP_FILE=""
PASSPHRASE_FILE=""
SETTINGS_KEY_FILE=""
# Core crypto key file (RivetCore\Crypto, docs/KEY_MANAGEMENT.md): the offline copy of /etc/rivetmsp/keys.json, from --key-file; KEY_FILE_DEST is
# where it is installed (--key-file-dest). The archive never carries it: without it the v3 secrets in a restored database do not open.
KEY_FILE_RESTORE=""
KEY_FILE_DEST="/etc/rivetmsp/keys.json"
CONFIRM_RESTORE=0
NO_PRE_RESTORE_BACKUP_CONFIRMED=0
UNATTENDED=0

# Populated later by read_app_config() / decrypt_and_extract() / read_manifest().
DB_HOST=""
DB_USER=""
DB_PASS=""
DB_NAME=""
INSTALLATION_ID=""
SETTINGS_ENC_KEY=""
EXTRACT_DIR=""
SQL_FILE=""
DECRYPTED_WITH=""
MANIFEST_INSTALLATION_ID=""
MANIFEST_SETTINGS_ENC_KEY=""
MANIFEST_SETTINGS_ENC_KEY_FINGERPRINT=""

print_help() {
    cat <<'EOF'
RivetMSP — restore an encrypted deploy/backup.sh archive

Usage:
  sudo deploy/restore.sh --app-dir=<path> --backup=<path> \
      --passphrase-file=<path> --confirm-restore [options]

Required:
  --app-dir=<path>            Webroot of the already-installed instance to
                              restore into (must contain config.php).
  --backup=<path>             Path to the backup-*.tar.gz.enc file to restore.
  --passphrase-file=<path>    600-permission file holding the decryption
                              passphrase. Also used for the pre-restore
                              safety backup unless opted out (see below).
  --confirm-restore            Required acknowledgment that this overwrites
                              the target database and uploads/ entirely.

Options:
  --key-file=<path>           Offline copy of the Core crypto key file (keys.json). Installed at
                              --key-file-dest (default /etc/rivetmsp/keys.json), 0640. The archive never
                              contains it: keep it apart from the dump and the passphrase.
  --settings-key-file=<path>  File holding the original config_settings_enc_key
                              (backup.sh writes <archive>.settings-key next to
                              each archive; that sidecar is picked up automatically
                              when it sits next to --backup). Archives made before
                              the key moved out of the archive still carry it in
                              their manifest and need no file.
  --no-pre-restore-backup-confirmed
                              Skip taking a safety backup of --app-dir's
                              current state before overwriting it.
  --unattended                 Cron-friendly: log file only, no terminal echo.
  --help                       Show this help and exit.

Steps performed, in order: pre-restore safety backup (or confirmed skip) ->
decrypt + extract --backup -> import its database dump -> replace
--app-dir/uploads with its uploads/ -> restore ownership/permissions ->
apply the backup's settings-encryption key to config.php, if its manifest
has one, or from --settings-key-file / the archive's .settings-key sidecar
(see backup.sh; backups taken before the manifest existed print a warning
instead - SMTP/IMAP/RMM/webhook secrets, TOTP seeds and the vault master key
need the original key).

To test a backup without touching a real instance, point --app-dir at a
disposable one instead (a second deploy/install.sh instance with a
throw-away --domain/--db-name), then delete it afterward.
EOF
}

parse_args() {
    local arg
    for arg in "$@"; do
        case "${arg}" in
            --app-dir=*)              APP_DIR="${arg#*=}" ;;
            --backup=*)                BACKUP_FILE="${arg#*=}" ;;
            --passphrase-file=*)      PASSPHRASE_FILE="${arg#*=}" ;;
            --settings-key-file=*)    SETTINGS_KEY_FILE="${arg#*=}" ;;
            --key-file=*)             KEY_FILE_RESTORE="${arg#*=}" ;;
            --key-file-dest=*)        KEY_FILE_DEST="${arg#*=}" ;;
            --confirm-restore)         CONFIRM_RESTORE=1 ;;
            --no-pre-restore-backup-confirmed) NO_PRE_RESTORE_BACKUP_CONFIRMED=1 ;;
            --unattended)               UNATTENDED=1 ;;
            --help|-h)
                print_help
                exit 0
                ;;
            *)
                print_help
                die "Unknown option: ${arg}"
                ;;
        esac
    done
}

validate_args() {
    [[ -n "${APP_DIR}" ]] || { print_help; die "--app-dir is required."; }
    [[ "${APP_DIR}" == /* ]] || die "--app-dir must be an absolute path (got: ${APP_DIR})"
    [[ -d "${APP_DIR}" ]] || die "--app-dir '${APP_DIR}' does not exist or is not a directory."
    [[ -f "${APP_DIR}/config.php" ]] || die "No config.php found under ${APP_DIR} — this restore script overwrites an EXISTING instance's data, it does not create one. Run deploy/install.sh first, then re-run this against the resulting --app-dir."

    [[ -n "${BACKUP_FILE}" ]] || { print_help; die "--backup is required."; }
    [[ -f "${BACKUP_FILE}" ]] || die "--backup '${BACKUP_FILE}' does not exist."

    [[ -n "${PASSPHRASE_FILE}" ]] || { print_help; die "--passphrase-file is required."; }
    [[ -f "${PASSPHRASE_FILE}" ]] || die "--passphrase-file '${PASSPHRASE_FILE}' does not exist."
    local pf_perm
    pf_perm="$(stat -c '%a' "${PASSPHRASE_FILE}")"
    if [[ "${pf_perm: -2}" != "00" ]]; then
        die "--passphrase-file '${PASSPHRASE_FILE}' has permissions ${pf_perm} (group/other can access it). Expected 600, owner-only. Fix with: chmod 600 '${PASSPHRASE_FILE}'"
    fi

    if [[ "${CONFIRM_RESTORE}" -ne 1 ]]; then
        print_help
        die "Refusing to run without --confirm-restore. This REPLACES every table in ${APP_DIR}'s database and everything under ${APP_DIR}/uploads with the contents of ${BACKUP_FILE}. Re-run with --confirm-restore once you're sure."
    fi

    command_exists openssl || die "openssl is required to decrypt --backup but is not on PATH."
    command_exists mysql || die "The mysql client is required to import the database dump but is not on PATH."
    command_exists rsync || die "rsync is required to restore uploads/ but is not on PATH. Install it: apt-get install rsync"
}

setup_logging() {
    touch "${LOG_FILE}"
    chmod 640 "${LOG_FILE}"
    chown root:root "${LOG_FILE}"
    if [[ "${UNATTENDED}" -eq 1 ]]; then
        exec >> "${LOG_FILE}" 2>&1
    else
        exec > >(tee -a "${LOG_FILE}") 2>&1
    fi
}

# run_pre_restore_backup(): same mandatory-safety-net idiom update.sh uses
# for updates — this is the same kind of destructive step, so it gets the
# same "back up first, or make the operator explicitly opt out" gate.
run_pre_restore_backup() {
    if [[ "${NO_PRE_RESTORE_BACKUP_CONFIRMED}" -eq 1 ]]; then
        warn "Proceeding WITHOUT a pre-restore safety backup (--no-pre-restore-backup-confirmed was passed). ${APP_DIR}'s current data is about to be overwritten with no way back except --backup itself."
        return 0
    fi

    announce "Taking a safety backup of ${APP_DIR}'s CURRENT state via deploy/backup.sh before overwriting anything."
    if ! "${SCRIPT_DIR}/backup.sh" --app-dir="${APP_DIR}" --passphrase-file="${PASSPHRASE_FILE}"; then
        die "Pre-restore safety backup failed (see deploy/backup.sh's output above). Aborting the restore entirely; ${APP_DIR} was NOT touched. Fix the backup failure, or re-run with --no-pre-restore-backup-confirmed if you accept the risk, and try again."
    fi
    success "Pre-restore safety backup completed successfully."
}

decrypt_and_extract() {
    announce "Decrypting ${BACKUP_FILE}..."
    local combined
    combined="$(mktemp)"
    chmod 600 "${combined}"
    register_tmpfile "${combined}"

    # Archives written by this version use 600000 PBKDF2 iterations; archives from before that used OpenSSL's
    # default (10000). Try the new count first, then the old one. A wrong count or passphrase fails the openssl
    # padding check (or, rarely, yields garbage that fails gzip -t), so both are checked before accepting a result.
    local iter_label
    DECRYPTED_WITH=""
    for iter_label in 600000 default; do
        local -a iter_args=()
        [[ "${iter_label}" == "default" ]] || iter_args=(-iter "${iter_label}")
        : > "${combined}"
        if openssl enc -d -aes-256-cbc -pbkdf2 "${iter_args[@]}" \
            -in "${BACKUP_FILE}" -out "${combined}" \
            -pass file:"${PASSPHRASE_FILE}" 2>/dev/null && gzip -t "${combined}" 2>/dev/null; then
            DECRYPTED_WITH="${iter_label}"
            break
        fi
    done
    if [[ -z "${DECRYPTED_WITH}" ]]; then
        die "Decryption failed. Either --passphrase-file doesn't match the passphrase ${BACKUP_FILE} was encrypted with, or the file is corrupt."
    fi
    success "Decrypted OK (PBKDF2 iterations: ${DECRYPTED_WITH})."

    EXTRACT_DIR="$(mktemp -d)"
    register_tmpfile "${EXTRACT_DIR}"

    info "Extracting archive..."
    if ! tar -xzf "${combined}" -C "${EXTRACT_DIR}"; then
        die "tar extraction failed. ${BACKUP_FILE} may be corrupt, or --passphrase-file decrypted it into garbage (wrong passphrase can produce a file that 'looks' decrypted but isn't valid gzip)."
    fi

    local -a sql_matches
    mapfile -t sql_matches < <(find "${EXTRACT_DIR}" -maxdepth 1 -name '*.sql')
    if [[ "${#sql_matches[@]}" -eq 0 ]]; then
        die "No .sql file found inside ${BACKUP_FILE} after extraction — this doesn't look like a deploy/backup.sh archive."
    fi
    if [[ "${#sql_matches[@]}" -gt 1 ]]; then
        die "Multiple .sql files found inside ${BACKUP_FILE} (${sql_matches[*]}) — expected exactly one. Refusing to guess which one to import."
    fi
    SQL_FILE="${sql_matches[0]}"
    success "Extracted OK ($(basename "${SQL_FILE}"))."
}

# read_manifest(): recovers installation_id/settings_enc_key from the
# backup-manifest.json backup.sh bundles alongside the SQL dump (see its own
# comment for why). Older backups predate this file entirely — warn and
# move on rather than failing the whole restore over it.
read_manifest() {
    local manifest="${EXTRACT_DIR}/backup-manifest.json"
    if [[ ! -f "${manifest}" ]]; then
        warn "No backup-manifest.json inside ${BACKUP_FILE} — this backup predates that feature. If the source instance had \$config_settings_enc_key set by hand in its own config.php, copy that same value into ${APP_DIR}/config.php manually, or any settings it encrypted (SMTP/IMAP/RMM/webhook secrets) will not decrypt correctly after this restore."
        return 0
    fi

    local raw
    if ! raw="$(php -r '
        $data = json_decode(file_get_contents($argv[1]), true);
        echo ($data["installation_id"] ?? "") . "\n";
        echo ($data["settings_enc_key"] ?? "") . "\n";
        echo ($data["settings_enc_key_fingerprint"] ?? "") . "\n";
    ' -- "${manifest}")"; then
        warn "Failed to parse ${manifest} — proceeding without it."
        return 0
    fi
    local -a lines
    mapfile -t lines <<< "${raw}"
    MANIFEST_INSTALLATION_ID="${lines[0]:-}"
    MANIFEST_SETTINGS_ENC_KEY="${lines[1]:-}"
    MANIFEST_SETTINGS_ENC_KEY_FINGERPRINT="${lines[2]:-}"

    if [[ -z "${MANIFEST_SETTINGS_ENC_KEY}" ]]; then
        # Manifests written since the key moved out of the archive hold a fingerprint only. The operator supplies the key.
        if [[ -n "${SETTINGS_KEY_FILE}" ]]; then
            load_settings_key_file "${SETTINGS_KEY_FILE}" "${MANIFEST_SETTINGS_ENC_KEY_FINGERPRINT}"
        elif [[ -n "${MANIFEST_SETTINGS_ENC_KEY_FINGERPRINT}" ]]; then
            warn "backup-manifest.json does not contain the settings-encryption key (key fingerprint ${MANIFEST_SETTINGS_ENC_KEY_FINGERPRINT}). Supply it with --settings-key-file=<path> (the <archive>.settings-key file backup.sh wrote, or a file holding the original config.php's \$config_settings_enc_key). Without it SMTP/IMAP passwords, RMM/webhook secrets, TOTP seeds and the credential vault will not decrypt after this restore unless this config.php already has that key."
        fi
    fi
}

# install_key_file(src, dest): validates the key file with the app's own loader (no key material is printed), keeps any file already at dest as
# <dest>.pre-restore-<ts>, and installs it 0640 root:www-data (when run as root and the group exists). The directory is created 0755: the web
# user must be able to traverse it to reach the file (a 0750 root:root directory makes a readable key file unreadable to PHP).
install_key_file() {
    local src="$1" dest="$2"
    [[ -f "${src}" ]] || die "--key-file '${src}' does not exist."
    local kids
    kids="$(php -r 'require $argv[1] . "/vendor/autoload.php"; try { $r = RivetCore\Crypto\KeyFile::ringFromJson((string) file_get_contents($argv[2])); echo implode(",", $r->kids()), " active=", $r->activeKid(); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }' -- "${APP_DIR}" "${src}")" \
        || die "${src} is not a usable key file."
    if [[ -f "${dest}" ]] && ! cmp -s "${src}" "${dest}"; then
        local keep="${dest}.pre-restore-$(date +%Y%m%d%H%M%S)"
        cp -p "${dest}" "${keep}" && warn "A different key file was already at ${dest}; kept as ${keep}."
    fi
    install -d -m 0755 "$(dirname "${dest}")" || die "Cannot create $(dirname "${dest}")."
    if [[ "$(id -u)" -eq 0 ]] && getent group www-data >/dev/null 2>&1; then
        install -m 0640 -o root -g www-data "${src}" "${dest}"
    else
        install -m 0640 "${src}" "${dest}"
    fi
    success "Key file installed at ${dest} (keys: ${kids})."
}

# load_settings_key_file(path, [fingerprint]): reads the original config_settings_enc_key from `path`
# (the last line that is not a # comment; backup.sh writes <archive>.settings-key this way) into
# MANIFEST_SETTINGS_ENC_KEY. When the manifest gave a fingerprint, the key must match it or this dies:
# applying the wrong key would make every stored secret unreadable.
load_settings_key_file() {
    local path="$1" want_fp="${2:-}"
    [[ -f "${path}" ]] || die "--settings-key-file '${path}' does not exist."
    local perm
    perm="$(stat -c '%a' "${path}")"
    if [[ "${perm}" =~ [0-7][0-7][1-7]$ || "${perm}" =~ [0-7][1-7][0-7]$ ]]; then
        warn "${path} has permissions ${perm}; it holds a secret and should be 600."
    fi
    local key
    key="$(grep -v '^[[:space:]]*#' "${path}" | grep -v '^[[:space:]]*$' | tail -n 1 | tr -d '[:space:]')"
    [[ "${key}" =~ ^[0-9a-fA-F]{32,128}$ ]] || die "${path} does not contain a hex settings-encryption key."
    if [[ -n "${want_fp}" ]]; then
        local got_fp
        got_fp="$(php -r 'echo substr(hash("sha256", "rivetit-settings-key-fingerprint|v1|" . $argv[1]), 0, 16);' -- "${key}")"
        [[ "${got_fp}" == "${want_fp}" ]] || die "The key in ${path} (fingerprint ${got_fp}) does not match this backup (fingerprint ${want_fp}). Refusing to apply it."
    fi
    MANIFEST_SETTINGS_ENC_KEY="${key}"
    success "Settings-encryption key read from ${path}$( [[ -n "${want_fp}" ]] && printf ' (fingerprint matches the backup)' ) - will apply it to config.php after import."
}

import_database() {
    local defaults_file
    defaults_file="$(mktemp)"
    chmod 600 "${defaults_file}"
    register_tmpfile "${defaults_file}"
    cat > "${defaults_file}" <<EOF
[client]
host=${DB_HOST}
user=${DB_USER}
password=${DB_PASS}
EOF

    # DROP + CREATE the whole database first, rather than importing the dump
    # straight into whatever's already there. mysqldump's own per-table DROP
    # TABLE IF EXISTS only covers tables that exist IN THE DUMP — a table
    # that exists in the target but not in the backup (e.g. schema drift
    # between when the backup was taken and this target's current db.sql)
    # would otherwise survive the "restore" untouched. The app's own DB user
    # already holds CREATE/DROP on this one database (see install.sh's
    # provision_database), so this doesn't need any wider privilege.
    announce "Dropping and recreating database '${DB_NAME}' before importing $(basename "${SQL_FILE}") — this removes ANY existing table, not just ones the dump also contains."
    if ! mysql --defaults-extra-file="${defaults_file}" -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\`;"; then
        die "Failed to drop/recreate database '${DB_NAME}' (see mysql's output above). Nothing was imported — ${APP_DIR}'s previous database is untouched (or recoverable from the safety backup taken above)."
    fi

    announce "Importing $(basename "${SQL_FILE}") into the now-empty database '${DB_NAME}'."
    if ! mysql --defaults-extra-file="${defaults_file}" "${DB_NAME}" < "${SQL_FILE}"; then
        if [[ "${NO_PRE_RESTORE_BACKUP_CONFIRMED}" -eq 1 ]]; then
            die "Database import failed partway through (see mysql's output above). '${DB_NAME}' is now empty or partially restored, and no pre-restore safety backup was taken. Investigate manually before running the app."
        else
            die "Database import failed partway through (see mysql's output above). '${DB_NAME}' is now empty or partially restored — restore the safety backup taken at the start of this run (via this same script, or by hand) before running the app."
        fi
    fi
    success "Database import complete."
}

restore_uploads() {
    local src_uploads="${EXTRACT_DIR}/uploads"
    if [[ ! -d "${src_uploads}" ]]; then
        warn "${BACKUP_FILE} contains no uploads/ directory; leaving ${APP_DIR}/uploads untouched."
        return 0
    fi

    announce "Replacing ${APP_DIR}/uploads with the backup's uploads/ (rsync --delete — anything added since the backup that isn't in it will be removed)."
    mkdir -p "${APP_DIR}/uploads"
    if ! rsync -a --delete "${src_uploads}/" "${APP_DIR}/uploads/"; then
        die "rsync failed while restoring uploads/. The database has already been imported at this point — investigate the uploads/ mismatch manually."
    fi

    info "Restoring ownership (www-data:www-data) and permissions under ${APP_DIR}/uploads..."
    chown -R www-data:www-data "${APP_DIR}/uploads"
    find "${APP_DIR}/uploads" -type d -exec chmod 750 {} +
    find "${APP_DIR}/uploads" -type f -exec chmod 640 {} +
    # Same widening install.sh applies: uploads/ needs to stay writable by
    # the www-data group for the app to save new files into it.
    chmod -R u+rwX,g+rwX "${APP_DIR}/uploads"
    success "uploads/ restored."
}

# apply_settings_enc_key(): rewrites (or appends) config.php's
# $config_settings_enc_key so it matches whatever the manifest recovered —
# without this, every setting functions.php's encryptSettingsValue()
# protected under the SOURCE instance's key would decrypt to garbage under
# the TARGET's own (different, freshly-generated-or-absent) key. Uses a
# small trusted PHP snippet (var_export, not string interpolation) to edit
# config.php rather than sed, so the key's own content never has to survive
# shell/sed escaping.
apply_settings_enc_key() {
    if [[ -z "${MANIFEST_SETTINGS_ENC_KEY}" ]]; then
        info "No settings_enc_key was recovered (empty in the backup, or only a fingerprint and no --settings-key-file) — config.php's key is left as it is."
        return 0
    fi
    if [[ "${SETTINGS_ENC_KEY}" == "${MANIFEST_SETTINGS_ENC_KEY}" ]]; then
        info "config.php's \$config_settings_enc_key already matches the backup's — nothing to change."
        return 0
    fi

    announce "Updating ${APP_DIR}/config.php's \$config_settings_enc_key to match ${BACKUP_FILE} so restored encrypted settings decrypt correctly."
    if ! php -r '
        $path = $argv[1];
        $key  = $argv[2];
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $line = "\$config_settings_enc_key = " . var_export($key, true) . ";";
        $found = false;
        foreach ($lines as $i => $l) {
            if (preg_match("/^\\\$config_settings_enc_key\\s*=/", $l)) {
                $lines[$i] = $line;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $lines[] = $line;
        }
        if (file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            exit(1);
        }
    ' -- "${APP_DIR}/config.php" "${MANIFEST_SETTINGS_ENC_KEY}"; then
        die "Failed to update \$config_settings_enc_key in ${APP_DIR}/config.php. The database and uploads/ have already been restored — fix config.php by hand (see backup-manifest.json's settings_enc_key inside the extracted archive, already shredded now — re-run with the same --backup to recover it again if needed)."
    fi
    chmod 640 "${APP_DIR}/config.php"
    chown www-data:www-data "${APP_DIR}/config.php"
    success "config_settings_enc_key updated."
}

main() {
    parse_args "$@"
    require_root "$@"
    validate_args
    setup_logging

    info "=== RivetMSP restore starting: ${BACKUP_FILE} -> ${APP_DIR} ==="

    read_app_config "${APP_DIR}"
    # The key file backup.sh writes next to the archive is used automatically unless one was named.
    if [[ -z "${SETTINGS_KEY_FILE}" && -f "${BACKUP_FILE%.tar.gz.enc}.settings-key" ]]; then
        SETTINGS_KEY_FILE="${BACKUP_FILE%.tar.gz.enc}.settings-key"
        info "Found the settings-key file next to the archive: ${SETTINGS_KEY_FILE}"
    fi
    run_pre_restore_backup
    decrypt_and_extract
    read_manifest
    import_database
    restore_uploads
    apply_settings_enc_key
    if [[ -n "${KEY_FILE_RESTORE}" ]]; then
        install_key_file "${KEY_FILE_RESTORE}" "${KEY_FILE_DEST}"
    else
        warn "No --key-file given: if this install used the Core key file (/etc/rivetmsp/keys.json), put the offline copy back, or v3 secrets stay unreadable."
    fi

    success "=== Restore complete: ${APP_DIR} now reflects ${BACKUP_FILE} ==="
    log "RESTORE OK app_dir=${APP_DIR} backup=${BACKUP_FILE} database=${DB_NAME}"
    info "Reminder: credential vault data decrypts with each user's own password-derived key, not a separate secret in the dump. If the admin password changed after this backup was taken, that vault data will need the account's password reset to match, or recovery via whatever vault-recovery path the app provides."
}

# Run only when executed, not when sourced (tests source this file to exercise its functions).
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi
