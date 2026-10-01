#!/usr/bin/env bash
set -euo pipefail

# RivetMSP — Docker Compose entrypoint. Runs as root (the
# container's default user); its job is everything that needs root before
# handing off to supervisord, which then runs php-fpm/nginx as www-data.
#
# App code at /var/www/html is a BIND MOUNT of the git checkout you cloned
# (see ../docker-compose.yml) — not baked into the image — so config.php,
# uploads/, backups/, and vendor/ all live on the host and survive
# `docker compose down` / image rebuilds. That also means this container's
# www-data has to be able to write into a directory it doesn't own by
# default: see the DOCKER_UID/DOCKER_GID handling below.

APP_DIR="/var/www/html"
UPLOAD_SUBDIRS=(contracts clients custom documents document_templates expenses recurring_tickets settings users tmp tickets ticket_templates)

remap_www_data() {
    local target_uid="${DOCKER_UID:-33}"
    local target_gid="${DOCKER_GID:-33}"
    local current_uid current_gid
    current_uid="$(id -u www-data)"
    current_gid="$(id -g www-data)"

    if [[ "${current_uid}" != "${target_uid}" ]]; then
        usermod -u "${target_uid}" www-data
    fi
    if [[ "${current_gid}" != "${target_gid}" ]]; then
        groupmod -g "${target_gid}" www-data
    fi

    if [[ "${target_uid}" == "33" && "${target_gid}" == "33" ]]; then
        echo "entrypoint: www-data left at the image default (uid/gid 33). If ${APP_DIR} is owned by your own host user, set DOCKER_UID/DOCKER_GID in .env to 'id -u'/'id -g' so the setup wizard can write config.php." >&2
    fi
}

wait_for_db() {
    local host="${DB_HOST:-db}" port="${DB_PORT:-3306}" tries=30
    echo "entrypoint: waiting for database at ${host}:${port}..."
    until (exec 3<>"/dev/tcp/${host}/${port}") 2>/dev/null; do
        tries=$((tries - 1))
        if [[ "${tries}" -le 0 ]]; then
            echo "entrypoint: database still not reachable after 60s; starting anyway — the app will show a connection error until it's up." >&2
            return 0
        fi
        sleep 2
    done
    exec 3<&- 3>&- 2>/dev/null || true
    echo "entrypoint: database is reachable."
}

ensure_upload_dirs() {
    local d
    for d in "${UPLOAD_SUBDIRS[@]}"; do
        mkdir -p "${APP_DIR}/uploads/${d}"
        [[ -f "${APP_DIR}/uploads/${d}/index.php" ]] || : > "${APP_DIR}/uploads/${d}/index.php"
    done
    mkdir -p "${APP_DIR}/backups"
    chown -R www-data:www-data "${APP_DIR}/uploads" "${APP_DIR}/backups"
}

install_composer_deps() {
    if [[ -f "${APP_DIR}/composer.json" && ! -d "${APP_DIR}/vendor" ]]; then
        echo "entrypoint: vendor/ missing, running composer install (first boot against this checkout only — persists to the bind mount)..."
        ( cd "${APP_DIR}" && composer install --no-dev --optimize-autoloader --no-interaction )
        chown -R www-data:www-data "${APP_DIR}/vendor"
    fi
}

# restore_from_backup(): the container-first-boot counterpart to
# deploy/install.sh's --restore-from/run_restore_setup(). Only runs when
# config.php doesn't exist yet AND RESTORE_FROM is set (see
# ../docker-compose.yml's ./restore:/var/www/restore:ro mount +
# .env.example) — otherwise a brand new instance just falls through to the
# browser /setup wizard, unchanged from the original design here.
#
# Placeholder company/admin values mirror install.sh's run_restore_setup()
# exactly (including the @example.com placeholder email — @localhost fails
# PHP's FILTER_VALIDATE_EMAIL, confirmed live during this tooling's own
# testing) — they exist for the few seconds before deploy/restore.sh
# overwrites the whole database with the real backup.
#
# Verifies success by checking config.php for the trailing
# `$config_enable_setup = 0;` line, NOT setup_cli.php's exit code —
# confirmed live that every die() in that script exits 0 (PHP's
# die()/exit() with a string argument always does), so the shell exit code
# cannot be trusted to detect a failed run.
restore_from_backup() {
    if [[ -f "${APP_DIR}/config.php" ]]; then
        return 0
    fi
    if [[ -z "${RESTORE_FROM:-}" ]]; then
        return 0
    fi
    if [[ -z "${RESTORE_PASSPHRASE_FILE:-}" ]]; then
        echo "entrypoint: RESTORE_FROM is set but RESTORE_PASSPHRASE_FILE is not — cannot decrypt it. Falling through to the browser /setup wizard instead." >&2
        return 0
    fi

    echo "entrypoint: RESTORE_FROM=${RESTORE_FROM} — writing a placeholder config.php via setup_cli.php, then restoring..."
    local placeholder_password
    placeholder_password="$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | head -c 32)"
    if ! ( cd "${APP_DIR}/scripts" && sudo -u www-data env ITFLOW_DB_PASSWORD="${DB_PASSWORD}" ITFLOW_ADMIN_PASSWORD="${placeholder_password}" php setup_cli.php \
        --host="${DB_HOST}" --username="${DB_USER}" --database="${DB_NAME}" --base-url="${APP_BASE_URL:-localhost}" \
        --locale=en_US --timezone=UTC --currency=USD --company-name="Restoring" --country="United States" \
        --address="" --city="" --state="" --zip="" --phone="" --company-email="" --website="" \
        --user-name="Restore Placeholder" --user-email="restore-placeholder@example.com" \
        --non-interactive < /dev/null ); then
        echo "entrypoint: setup_cli.php exited non-zero — leaving config.php absent so the browser /setup wizard is still reachable as a fallback." >&2
        return 0
    fi
    if [[ ! -f "${APP_DIR}/config.php" ]] || ! grep -qE '^\$config_enable_setup\s*=\s*0\s*;' "${APP_DIR}/config.php"; then
        echo "entrypoint: setup_cli.php did not complete (see its output above) — leaving config.php absent/incomplete so the browser /setup wizard is still reachable as a fallback." >&2
        rm -f "${APP_DIR}/config.php"
        return 0
    fi
    chmod 640 "${APP_DIR}/config.php"
    chown www-data:www-data "${APP_DIR}/config.php"

    if ! "${APP_DIR}/deploy/restore.sh" --app-dir="${APP_DIR}" --backup="${RESTORE_FROM}" \
        --passphrase-file="${RESTORE_PASSPHRASE_FILE}" --confirm-restore \
        --no-pre-restore-backup-confirmed --unattended; then
        echo "entrypoint: deploy/restore.sh failed (see /var/log/itflow-restore.log inside the container). config.php exists but config_enable_setup was left enabled, so /setup is still reachable to retry or fall back to a fresh install." >&2
        return 0
    fi
    echo "entrypoint: restore complete — ready to log in with the restored data."
}

remap_www_data
wait_for_db
ensure_upload_dirs
install_composer_deps
restore_from_backup

exec "$@"
