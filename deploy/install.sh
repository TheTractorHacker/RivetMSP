#!/usr/bin/env bash
# RivetMSP — fresh company installer.
#
# Stands up a brand-new, standalone instance of this app on a fresh Ubuntu/
# Debian box, or adds ANOTHER company's independent instance (own vhost, own
# database, own database user) alongside one or more instances already
# running on the same box. This is NOT a multi-tenant installer — every
# company gets its own separate database and its own separate config.php.
#
# Usage:
#   sudo deploy/install.sh --domain=itflow.example.com [options]
#   sudo deploy/install.sh --help
#
# See print_help() below for the full flag reference.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------
REPO_URL="https://github.com/TheTractorHacker/RivetMSP.git"
REPO_BRANCH="master"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
TEMPLATES_DIR="${SCRIPT_DIR}/templates"
LOG_FILE="/var/log/itflow-install.log"
PHP_SOCK="/run/php/php8.4-fpm.sock"

# Base packages needed regardless of PHP version. gettext-base provides
# envsubst (used to render the nginx vhost template); rsync is used when
# install.sh is run from inside an already-cloned checkout (see
# provision_app_code). Both are near-universally preinstalled on Ubuntu, but
# are listed explicitly so a minimal/container base image still works.
REQUIRED_BASE_PACKAGES=(nginx mariadb-server redis-server certbot python3-certbot-nginx ufw fail2ban git composer openssl unattended-upgrades gettext-base rsync)

# php8.4-mysqli is not a real ondrej/php package name — mysqli/pdo_mysql
# ship together in php8.4-mysql. php8.4-sodium does not exist either:
# libsodium has been a PHP core (bundled) extension since PHP 7.2, so it
# comes for free with php8.4-common (a dependency of every package below).
REQUIRED_PHP_PACKAGES=(php8.4-fpm php8.4-cli php8.4-mysql php8.4-curl php8.4-gd php8.4-mbstring php8.4-intl php8.4-xml php8.4-zip php8.4-bcmath php8.4-opcache)

# Every uploads/* subdirectory the application writes to, all already
# tracked in git with a placeholder `index.php` (denies directory listing)
# except `contracts`, which .gitignore excludes entirely.
UPLOAD_SUBDIRS=(contracts clients custom documents document_templates expenses recurring_tickets settings users tmp tickets ticket_templates)

# ---------------------------------------------------------------------------
# Options (defaults — populated by parse_args)
# ---------------------------------------------------------------------------
DOMAIN=""
APP_DIR=""
DB_NAME=""
PROXY_MODE=0
DEFAULT_VHOST=0
SKIP_FIREWALL=0
SKIP_FAIL2BAN=0
SKIP_TLS=0
NON_INTERACTIVE=0
CERTBOT_EMAIL=""
ADMIN_NAME=""
ADMIN_EMAIL=""
ADMIN_PASSWORD=""
LOCALE=""
TIMEZONE=""
CURRENCY=""
COMPANY_NAME=""
COUNTRY=""
ADDRESS=""
CITY=""
STATE=""
ZIP=""
PHONE=""
COMPANY_EMAIL=""
WEBSITE=""
RESTORE_FROM=""
RESTORE_PASSPHRASE_FILE=""

# Populated later in main(), after DOMAIN/PROXY_MODE/SKIP_TLS are known.
NEED_CERTBOT=0
SSL_CERT_PATH=""
SSL_CERT_KEY_PATH=""
DB_PASSWORD=""

# ---------------------------------------------------------------------------
# Help / argument parsing
# ---------------------------------------------------------------------------
print_help() {
    cat <<'EOF'
RivetMSP — fresh company installer

Usage:
  sudo deploy/install.sh --domain=<fqdn> [options]

Required:
  --domain=<fqdn>          Public hostname for this instance, e.g.
                            itflow.example.com. Only [a-zA-Z0-9.-] allowed —
                            this value is interpolated into a generated
                            nginx config and shell commands.

Deployment options:
  --app-dir=<path>          Webroot for this instance (default: /var/www/<domain>)
  --db-name=<name>          MariaDB database + username (default: derived
                            from --domain, sanitized to [a-z0-9_])
  --proxy-mode              This box sits behind an existing external reverse
                            proxy that already terminates public TLS
                            elsewhere. Skips certbot; generates a self-signed
                            backend cert instead, and configures the vhost so
                            nginx's own redirects stay relative (no internal
                            hostname/port ever leaks to an end user).
  --default-vhost           Use this instance for requests sent to the server's
                            IP address. Set on only one vhost per nginx port.
  --skip-tls                 Skip certbot AND proxy-mode's redirect tweaks —
                            serve a self-signed cert directly and leave real
                            TLS for you to configure later (e.g. no public
                            DNS yet). Implies --email is not required.
  --skip-firewall            Do not touch ufw.
  --skip-fail2ban             Do not touch fail2ban.
  --non-interactive           Fail instead of prompting for anything missing.
                            Requires --admin-name, --admin-email,
                            --admin-password, --locale, --timezone,
                            --currency, --company-name, --country, and
                            --email (unless --proxy-mode, --skip-tls, or
                            --restore-from).

Certificates:
  --email=<address>          Contact email for Let's Encrypt registration.
                            Required unless --proxy-mode or --skip-tls.

First admin user (optional — omitted values are prompted for interactively
unless --non-interactive; ignored entirely with --restore-from):
  --admin-name=<name>
  --admin-email=<address>
  --admin-password=<password>   WARNING: exposed via `ps` while setup runs
                            UNLESS you leave this flag out — install.sh then
                            forwards nothing and setup_cli.php's own
                            interactive prompt never touches argv either.
                            When this flag IS given, install.sh still keeps
                            it off setup_cli.php's own argv (forwarded via
                            the ITFLOW_ADMIN_PASSWORD environment variable
                            instead) — but it IS visible on install.sh's
                            OWN command line and shell history. Prefer the
                            interactive prompt whenever you have a terminal.

Company / localization details (optional — same prompt-if-omitted rule,
ignored entirely with --restore-from):
  --locale=<locale>              e.g. en_US
  --timezone=<tz>                 e.g. America/New_York
  --currency=<code>                e.g. USD
  --company-name=<name>
  --country=<name>
  --address=<address>
  --city=<city>
  --state=<state>
  --zip=<zip>
  --phone=<phone>
  --company-email=<address>
  --website=<url>

Restore onto this new box instead of a fresh company setup (both required
together; every company/localization/admin-user option above is ignored):
  --restore-from=<path>            Path to a backup-*.tar.gz.enc produced by
                            deploy/backup.sh.
  --restore-passphrase-file=<path>  600-permission file holding the
                            passphrase that backup was encrypted with.

  --help                           Show this help and exit.

Adding another company to a box that already runs a RivetMSP instance:
re-run this exact script with a different --domain (and, if sharing the
box, a different --db-name). Already-installed packages and an
already-active firewall/fail2ban are detected and left alone.
EOF
}

parse_args() {
    local arg
    for arg in "$@"; do
        case "${arg}" in
            --domain=*)              DOMAIN="${arg#*=}" ;;
            --app-dir=*)              APP_DIR="${arg#*=}" ;;
            --db-name=*)               DB_NAME="${arg#*=}" ;;
            --proxy-mode)               PROXY_MODE=1 ;;
            --default-vhost)            DEFAULT_VHOST=1 ;;
            --skip-tls)                 SKIP_TLS=1 ;;
            --skip-firewall)             SKIP_FIREWALL=1 ;;
            --skip-fail2ban)              SKIP_FAIL2BAN=1 ;;
            --non-interactive)             NON_INTERACTIVE=1 ;;
            --email=*)                     CERTBOT_EMAIL="${arg#*=}" ;;
            --admin-name=*)                 ADMIN_NAME="${arg#*=}" ;;
            --admin-email=*)                 ADMIN_EMAIL="${arg#*=}" ;;
            --admin-password=*)               ADMIN_PASSWORD="${arg#*=}" ;;
            --locale=*)                        LOCALE="${arg#*=}" ;;
            --timezone=*)                       TIMEZONE="${arg#*=}" ;;
            --currency=*)                        CURRENCY="${arg#*=}" ;;
            --company-name=*)                     COMPANY_NAME="${arg#*=}" ;;
            --country=*)                            COUNTRY="${arg#*=}" ;;
            --address=*)                             ADDRESS="${arg#*=}" ;;
            --city=*)                                  CITY="${arg#*=}" ;;
            --state=*)                                  STATE="${arg#*=}" ;;
            --zip=*)                                     ZIP="${arg#*=}" ;;
            --phone=*)                                     PHONE="${arg#*=}" ;;
            --company-email=*)                              COMPANY_EMAIL="${arg#*=}" ;;
            --website=*)                                      WEBSITE="${arg#*=}" ;;
            --restore-from=*)                                   RESTORE_FROM="${arg#*=}" ;;
            --restore-passphrase-file=*)                          RESTORE_PASSPHRASE_FILE="${arg#*=}" ;;
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
    [[ -n "${DOMAIN}" ]] || { print_help; die "--domain is required."; }
    [[ "${DOMAIN}" =~ ^[a-zA-Z0-9.-]+$ ]] || die "--domain contains characters other than [a-zA-Z0-9.-]: '${DOMAIN}'"

    if [[ -z "${APP_DIR}" ]]; then
        APP_DIR="/var/www/${DOMAIN}"
    fi
    [[ "${APP_DIR}" == /* ]] || die "--app-dir must be an absolute path (got: ${APP_DIR})"

    if [[ -z "${DB_NAME}" ]]; then
        DB_NAME="$(printf '%s' "${DOMAIN}" | tr 'A-Z.-' 'a-z__' | tr -cd 'a-z0-9_')"
    fi
    [[ "${DB_NAME}" =~ ^[a-z0-9_]+$ ]] || die "--db-name resolved to '${DB_NAME}', which isn't [a-z0-9_]-only. Pass --db-name explicitly."

    if [[ -n "${RESTORE_FROM}" || -n "${RESTORE_PASSPHRASE_FILE}" ]]; then
        [[ -n "${RESTORE_FROM}" && -n "${RESTORE_PASSPHRASE_FILE}" ]] \
            || die "--restore-from and --restore-passphrase-file must be given together."
        [[ -f "${RESTORE_FROM}" ]] || die "--restore-from '${RESTORE_FROM}' does not exist."
        [[ -f "${RESTORE_PASSPHRASE_FILE}" ]] || die "--restore-passphrase-file '${RESTORE_PASSPHRASE_FILE}' does not exist."
        local pf_perm
        pf_perm="$(stat -c '%a' "${RESTORE_PASSPHRASE_FILE}")"
        [[ "${pf_perm: -2}" == "00" ]] || die "--restore-passphrase-file '${RESTORE_PASSPHRASE_FILE}' has permissions ${pf_perm} (group/other can access it). Expected 600. Fix with: chmod 600 '${RESTORE_PASSPHRASE_FILE}'"
    fi

    if [[ "${NON_INTERACTIVE}" -eq 1 && -z "${RESTORE_FROM}" ]]; then
        local -a missing=()
        [[ -n "${ADMIN_NAME}" ]]     || missing+=(--admin-name)
        [[ -n "${ADMIN_EMAIL}" ]]    || missing+=(--admin-email)
        [[ -n "${ADMIN_PASSWORD}" ]] || missing+=(--admin-password)
        [[ -n "${LOCALE}" ]]         || missing+=(--locale)
        [[ -n "${TIMEZONE}" ]]       || missing+=(--timezone)
        [[ -n "${CURRENCY}" ]]       || missing+=(--currency)
        [[ -n "${COMPANY_NAME}" ]]   || missing+=(--company-name)
        [[ -n "${COUNTRY}" ]]        || missing+=(--country)
        if [[ "${PROXY_MODE}" -eq 0 && "${SKIP_TLS}" -eq 0 ]]; then
            [[ -n "${CERTBOT_EMAIL}" ]] || missing+=(--email)
        fi
        [[ "${#missing[@]}" -eq 0 ]] || die "--non-interactive requires: ${missing[*]}"
    fi

    if [[ "${PROXY_MODE}" -eq 0 && "${SKIP_TLS}" -eq 0 && -z "${CERTBOT_EMAIL}" && "${NON_INTERACTIVE}" -eq 0 ]]; then
        read -r -p "Let's Encrypt contact email for ${DOMAIN}: " CERTBOT_EMAIL
        [[ -n "${CERTBOT_EMAIL}" ]] || die "--email is required for a direct-TLS install (or pass --proxy-mode / --skip-tls)."
    fi
}

setup_logging() {
    touch "${LOG_FILE}"
    chmod 640 "${LOG_FILE}"
    chown root:root "${LOG_FILE}"
    exec > >(tee -a "${LOG_FILE}") 2>&1
    set -x
}

# ---------------------------------------------------------------------------
# 1. Packages
# ---------------------------------------------------------------------------
apt_install_if_missing() {
    local -a missing=()
    local pkg
    for pkg in "$@"; do
        package_installed "${pkg}" || missing+=("${pkg}")
    done
    if [[ "${#missing[@]}" -gt 0 ]]; then
        info "Installing packages: ${missing[*]}"
        # One retry after refreshing the package lists: a mirror that rotated a package out (404) between the
        # earlier `apt-get update` and now makes the first attempt fail on an otherwise healthy box.
        if ! DEBIAN_FRONTEND=noninteractive apt-get install -y "${missing[@]}"; then
            warn "apt-get install failed; refreshing package lists and retrying once."
            apt-get update -qq
            DEBIAN_FRONTEND=noninteractive apt-get install -y "${missing[@]}"
        fi
    else
        info "Requested packages already installed, skipping apt-get install for: $*"
    fi
}

install_packages() {
    info "Refreshing apt package index..."
    apt-get update -qq

    if ! apt-cache show php8.4-fpm >/dev/null 2>&1; then
        info "php8.4-fpm not available from this box's configured repos — adding the ondrej/php PPA."
        package_installed software-properties-common || apt-get install -y software-properties-common
        add-apt-repository -y ppa:ondrej/php
        apt-get update -qq
    fi

    apt_install_if_missing "${REQUIRED_BASE_PACKAGES[@]}" "${REQUIRED_PHP_PACKAGES[@]}"

    if ! service_is_active php8.4-fpm; then
        systemctl enable --now php8.4-fpm
    fi
    success "Packages ready."
}

# ---------------------------------------------------------------------------
# 2. Application code
# ---------------------------------------------------------------------------
provision_app_code() {
    if [[ -f "${APP_DIR}/config.php" ]]; then
        info "config.php already exists at ${APP_DIR} — treating this as a re-run against an already-set-up instance; leaving application code alone."
        return 0
    fi

    if [[ -d "${APP_DIR}" && -n "$(ls -A "${APP_DIR}" 2>/dev/null)" ]]; then
        info "${APP_DIR} already exists and is non-empty; assuming a previous partial run and leaving existing files in place where possible."
    fi
    mkdir -p "${APP_DIR}"

    if [[ -f "${REPO_ROOT}/scripts/setup_cli.php" ]]; then
        info "Running from inside an existing checkout (${REPO_ROOT}) — copying it into ${APP_DIR} instead of cloning fresh."
        rsync -a \
            --exclude='/config.php' \
            --exclude='/node_modules/' \
            --exclude='/.claude/' \
            --exclude='/uploads/' \
            --exclude='/backups/' \
            "${REPO_ROOT}/" "${APP_DIR}/"
    else
        info "Cloning ${REPO_URL} (branch: ${REPO_BRANCH}) into ${APP_DIR}..."
        git clone --branch "${REPO_BRANCH}" --single-branch "${REPO_URL}" "${APP_DIR}"
    fi

    if [[ -f "${APP_DIR}/composer.json" ]] && command_exists composer; then
        info "Installing PHP dependencies via composer..."
        # RivetCore (rivet/rivet-core) is installed from its GitHub repository, so the server needs outbound access to github.com.
        # This repository also commits vendor/, so a failed download is survivable when RivetCore is already on disk.
        if ! ( cd "${APP_DIR}" && composer install --no-dev --optimize-autoloader --no-interaction ); then
            if [[ -f "${APP_DIR}/vendor/rivet/rivet-core/composer.json" ]]; then
                warn "composer install failed (is github.com reachable?); continuing with the RivetCore copy shipped in vendor/."
            else
                die "composer install failed and RivetCore is not on disk. Check that this server can reach github.com, then re-run the installer."
            fi
        fi
    fi
}

setup_upload_dirs() {
    info "Ensuring uploads/ and backups/ directories exist..."
    local d
    for d in "${UPLOAD_SUBDIRS[@]}"; do
        mkdir -p "${APP_DIR}/uploads/${d}"
        [[ -f "${APP_DIR}/uploads/${d}/index.php" ]] || : > "${APP_DIR}/uploads/${d}/index.php"
    done
    mkdir -p "${APP_DIR}/backups"
}

set_file_permissions() {
    info "Setting ownership (www-data:www-data) and permissions under ${APP_DIR}..."
    chown -R www-data:www-data "${APP_DIR}"
    find "${APP_DIR}" -type d -exec chmod 750 {} +
    find "${APP_DIR}" -type f -exec chmod 640 {} +
    # Widen only uploads/ and backups/ back to writable — everything else
    # (application code) stays read-only to the group.
    chmod -R u+rwX,g+rwX "${APP_DIR}/uploads" "${APP_DIR}/backups"
    if [[ -f "${APP_DIR}/scripts/setup_cli.php" ]]; then
        chmod u+x,g+x "${APP_DIR}/scripts/setup_cli.php"
    fi
    if [[ -f "${APP_DIR}/scripts/update_cli.php" ]]; then
        chmod u+x,g+x "${APP_DIR}/scripts/update_cli.php"
    fi
    if [[ -d "${APP_DIR}/deploy" ]]; then
        find "${APP_DIR}/deploy" -maxdepth 1 -name '*.sh' -exec chmod u+x,g+x {} +
    fi
    if [[ -d "${APP_DIR}/cron" ]]; then
        find "${APP_DIR}/cron" -maxdepth 1 -name '*.php' -exec chmod u+x,g+x {} +
    fi
}

# ---------------------------------------------------------------------------
# 3. Database
# ---------------------------------------------------------------------------
provision_database() {
    if mysql -u root -e "SHOW DATABASES LIKE '${DB_NAME}';" 2>/dev/null | grep -q "${DB_NAME}"; then
        info "Database '${DB_NAME}' already exists — leaving it and its user alone (re-run against an already-set-up instance)."
        return 0
    fi

    info "Creating database and user '${DB_NAME}'..."
    local defaults_file
    defaults_file="$(mktemp)"
    register_tmpfile "${defaults_file}"
    chmod 600 "${defaults_file}"

    # Grants deliberately include CREATE/DROP at the database level (not
    # just table level within it) — deploy/restore.sh's DROP DATABASE +
    # CREATE DATABASE step, run later by this same user, needs exactly
    # this. Nothing global, no SUPER/FILE/GRANT OPTION.
    mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;
CREATE USER IF NOT EXISTS '${DB_NAME}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES, CREATE TEMPORARY TABLES ON \`${DB_NAME}\`.* TO '${DB_NAME}'@'localhost';
FLUSH PRIVILEGES;
SQL
    rm -f "${defaults_file}"
    success "Database '${DB_NAME}' and user '${DB_NAME}'@'localhost' ready."
}

# ---------------------------------------------------------------------------
# 3b. Redis — shared box-level service, not per-instance
# ---------------------------------------------------------------------------
# includes/redis_functions.php hardcodes REDIS_HOST=127.0.0.1/REDIS_PORT=6380
# (6380, not redis' default 6379, to avoid colliding with an unrelated stack
# already using the default on the same box) for live ticket/chat push. It
# degrades gracefully if nothing answers there — the app still works, that
# one feature just silently stops — so this step is best-effort, not
# fatal-if-it-fails, and (like MariaDB/nginx) is a box-wide shared resource:
# every company instance on this box uses the SAME redis, so a second
# company's install.sh run must not reconfigure/restart one that's already
# correctly listening on 6380.
configure_redis() {
    local conf="/etc/redis/redis.conf"
    if [[ ! -f "${conf}" ]]; then
        warn "Expected ${conf} after installing redis-server but it's missing — skipping redis configuration. Live ticket/chat push will not work until this is fixed by hand."
        return 0
    fi

    if ss -tln 2>/dev/null | grep -q '127\.0\.0\.1:6380'; then
        info "redis-server already listening on 127.0.0.1:6380 — leaving it alone (shared across every instance on this box)."
        return 0
    fi

    info "Configuring redis-server to listen on 127.0.0.1:6380 (matching includes/redis_functions.php)..."
    if grep -qE '^port\s' "${conf}"; then
        sed -i 's/^port\s.*/port 6380/' "${conf}"
    else
        printf '\nport 6380\n' >> "${conf}"
    fi
    if ! grep -qE '^bind\s+127\.0\.0\.1' "${conf}"; then
        printf 'bind 127.0.0.1 -::1\n' >> "${conf}"
    fi

    systemctl restart redis-server
    if ! ss -tln 2>/dev/null | grep -q '127\.0\.0\.1:6380'; then
        warn "redis-server restarted but isn't listening on 127.0.0.1:6380 — check 'systemctl status redis-server' and ${conf} by hand. The app will run fine without it; live ticket/chat push just won't."
    else
        success "redis-server listening on 127.0.0.1:6380."
    fi
}

# ---------------------------------------------------------------------------
# 4. Certificate + nginx vhost
# ---------------------------------------------------------------------------
bootstrap_selfsigned_cert() {
    SSL_CERT_PATH="/etc/ssl/certs/${DOMAIN}.crt"
    SSL_CERT_KEY_PATH="/etc/ssl/private/${DOMAIN}.key"
    if [[ -f "${SSL_CERT_PATH}" && -f "${SSL_CERT_KEY_PATH}" ]]; then
        info "Certificate already present at ${SSL_CERT_PATH} — leaving it alone."
        return 0
    fi
    info "Bootstrapping a self-signed certificate for ${DOMAIN} (needed before nginx -t will accept a config referencing it)..."
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout "${SSL_CERT_KEY_PATH}" -out "${SSL_CERT_PATH}" \
        -subj "/CN=${DOMAIN}" >/dev/null 2>&1
    chmod 600 "${SSL_CERT_KEY_PATH}"
    chmod 644 "${SSL_CERT_PATH}"
    chown root:root "${SSL_CERT_KEY_PATH}" "${SSL_CERT_PATH}"
}

render_nginx_vhost() {
    info "Deploying shared nginx locations snippet (if not already present)..."
    if [[ ! -f /etc/nginx/snippets/itflow-locations.conf ]] || ! cmp -s "${TEMPLATES_DIR}/itflow-locations.conf" /etc/nginx/snippets/itflow-locations.conf; then
        install -o root -g root -m 0644 "${TEMPLATES_DIR}/itflow-locations.conf" /etc/nginx/snippets/itflow-locations.conf
    fi

    local rate_limit_dest="/etc/nginx/conf.d/itflow-rate-limit.conf"
    if [[ ! -f "${rate_limit_dest}" ]]; then
        info "Writing shared login/setup rate-limit zone (once per box)..."
        cat > "${rate_limit_dest}" <<'EOF'
limit_req_zone $binary_remote_addr zone=itflow_login:10m rate=5r/m;
EOF
    fi

    info "Rendering nginx vhost for ${DOMAIN}..."
    local vhost_tmp
    vhost_tmp="$(mktemp)"
    register_tmpfile "${vhost_tmp}"

    local default_server=""
    if (( DEFAULT_VHOST )); then
        default_server="default_server"
    fi

    # shellcheck disable=SC2016
    DOMAIN="${DOMAIN}" APP_ROOT="${APP_DIR}" PHP_SOCK="${PHP_SOCK}" \
        DEFAULT_SERVER="${default_server}" \
        SSL_CERT_PATH="${SSL_CERT_PATH}" SSL_CERT_KEY_PATH="${SSL_CERT_KEY_PATH}" \
        envsubst '${DOMAIN} ${APP_ROOT} ${PHP_SOCK} ${SSL_CERT_PATH} ${SSL_CERT_KEY_PATH} ${DEFAULT_SERVER}' \
        < "${TEMPLATES_DIR}/nginx-vhost.conf.template" > "${vhost_tmp}"

    grep -q '__PROXY_MODE_BLOCK__' "${vhost_tmp}" \
        || die "Rendered vhost is missing the __PROXY_MODE_BLOCK__ sentinel — deploy/templates/nginx-vhost.conf.template may have been edited incompatibly."

    # Strip the template's documentation header FIRST (see that template's
    # own header comment for why this order matters — its prose quotes the
    # marker strings, which would otherwise give the sed below extra,
    # earlier matches and corrupt the result).
    local first_code_line
    first_code_line="$(grep -n -v -E '^#|^$' "${vhost_tmp}" | head -1 | cut -d: -f1)"
    [[ -n "${first_code_line}" && "${first_code_line}" -gt 1 ]] && sed -i "1,$((first_code_line - 1))d" "${vhost_tmp}"

    if [[ "${PROXY_MODE}" -eq 1 ]]; then
        sed -i '/# BEGIN DIRECT-TLS ONLY/,/# END DIRECT-TLS ONLY/d; /# __PROXY_MODE_BLOCK__/d; /# BEGIN PROXY-MODE ONLY/d; /# END PROXY-MODE ONLY/d' "${vhost_tmp}"
    else
        sed -i '/# BEGIN PROXY-MODE ONLY/,/# END PROXY-MODE ONLY/d; /# __PROXY_MODE_BLOCK__/d; /# BEGIN DIRECT-TLS ONLY/d; /# END DIRECT-TLS ONLY/d' "${vhost_tmp}"
    fi

    install -o root -g root -m 0644 "${vhost_tmp}" "/etc/nginx/sites-available/${DOMAIN}.conf"
    rm -f "${vhost_tmp}"

    if [[ ! -L "/etc/nginx/sites-enabled/${DOMAIN}.conf" ]]; then
        ln -s "/etc/nginx/sites-available/${DOMAIN}.conf" "/etc/nginx/sites-enabled/${DOMAIN}.conf"
    fi

    if ! nginx -t; then
        die "nginx -t failed after rendering the vhost for ${DOMAIN} — inspect /etc/nginx/sites-available/${DOMAIN}.conf before re-running."
    fi
    systemctl reload nginx
    success "nginx vhost for ${DOMAIN} live."
}

maybe_run_certbot() {
    if [[ "${PROXY_MODE}" -eq 1 || "${SKIP_TLS}" -eq 1 ]]; then
        info "Skipping certbot (--proxy-mode or --skip-tls) — serving the self-signed cert long-term."
        return 0
    fi
    announce "Requesting a Let's Encrypt certificate for ${DOMAIN} via certbot."
    if ! certbot --nginx -d "${DOMAIN}" -m "${CERTBOT_EMAIL}" --agree-tos --non-interactive --redirect; then
        warn "certbot failed for ${DOMAIN} — the self-signed cert is still in place, so the site is reachable over HTTPS with a browser warning. Common causes: DNS for ${DOMAIN} doesn't point at this box yet, or port 80/443 isn't reachable from the internet. Re-run 'certbot --nginx -d ${DOMAIN} -m ${CERTBOT_EMAIL} --agree-tos' by hand once that's fixed."
    else
        success "Let's Encrypt certificate issued for ${DOMAIN}."
    fi
}

# ---------------------------------------------------------------------------
# 5. PHP-FPM + MariaDB hardening (inline copies of harden.sh's steps 1-2 —
#    see deploy/README.md for why install.sh doesn't just call harden.sh)
# ---------------------------------------------------------------------------
apply_php_hardening() {
    local dest="/etc/php/8.4/fpm/conf.d/99-itflow-hardening.ini"
    if [[ -f "${dest}" ]] && cmp -s "${TEMPLATES_DIR}/php-hardening.ini" "${dest}"; then
        info "PHP-FPM hardening already up to date at ${dest}."
        return 0
    fi
    install -o root -g root -m 0644 "${TEMPLATES_DIR}/php-hardening.ini" "${dest}"
    if ! php-fpm8.4 -t; then
        die "php-fpm8.4 -t failed after installing ${dest}. Not restarting php8.4-fpm with a config that fails to validate — inspect the file and re-run."
    fi
    systemctl restart php8.4-fpm
    success "PHP-FPM hardening applied."
}

apply_mariadb_hardening() {
    local dest="/etc/mysql/mariadb.conf.d/99-itflow-hardening.cnf"
    if [[ -f "${dest}" ]] && cmp -s "${TEMPLATES_DIR}/mariadb-hardening.cnf" "${dest}"; then
        info "MariaDB hardening already up to date at ${dest}."
        return 0
    fi
    install -o root -g root -m 0644 "${TEMPLATES_DIR}/mariadb-hardening.cnf" "${dest}"
    announce "Restarting mariadb to apply hardening (bind-address=127.0.0.1) — drops any existing remote connections."
    if ! systemctl restart mariadb; then
        error "mariadb failed to restart after applying ${dest}; rolling back."
        rm -f "${dest}"
        systemctl restart mariadb || die "mariadb did not come back up even after rollback — manual intervention required."
        die "Rolled back ${dest}; mariadb is back on its previous config. Investigate before re-applying hardening."
    fi
    success "MariaDB hardening applied."
}

# ---------------------------------------------------------------------------
# 6. Firewall + fail2ban
# ---------------------------------------------------------------------------
configure_firewall() {
    if [[ "${SKIP_FIREWALL}" -eq 1 ]]; then
        info "Skipping firewall configuration (--skip-firewall)."
        return 0
    fi
    local ssh_port
    ssh_port="$(detect_ssh_port)"
    # SSH must be allowed BEFORE `ufw enable`, or a fresh enable on a box
    # with no prior rules locks out the very session running this script.
    ufw allow "${ssh_port}/tcp" comment "RivetMSP install: SSH"
    ufw allow 80/tcp comment "RivetMSP install: HTTP"
    ufw allow 443/tcp comment "RivetMSP install: HTTPS"
    if [[ "${PROXY_MODE}" -eq 1 ]]; then
        ufw allow 8443/tcp comment "RivetMSP install: proxy-mode backend"
    fi
    if ufw status | grep -q "Status: active"; then
        info "ufw already active — rules above merged in."
    else
        announce "Enabling ufw (SSH/HTTP/HTTPS already allowed above)."
        ufw --force enable
    fi
    success "Firewall configured."
}

configure_fail2ban() {
    if [[ "${SKIP_FAIL2BAN}" -eq 1 ]]; then
        info "Skipping fail2ban configuration (--skip-fail2ban)."
        return 0
    fi
    install -o root -g root -m 0644 "${TEMPLATES_DIR}/jail-itflow.local" /etc/fail2ban/jail.d/itflow.local
    cat > /etc/fail2ban/filter.d/itflow-auth.conf <<'EOF'
[Definition]
failregex = ^<HOST> .* "POST /(login|post)\.php[^"]*" (401|403|429)
ignoreregex =
EOF
    if ! fail2ban-client -t >/dev/null 2>&1; then
        die "fail2ban config test failed after installing itflow jail/filter — inspect /etc/fail2ban/jail.d/itflow.local and /etc/fail2ban/filter.d/itflow-auth.conf."
    fi
    systemctl restart fail2ban
    success "fail2ban jails active."
}

# ---------------------------------------------------------------------------
# 7. Cron
# ---------------------------------------------------------------------------
install_cron() {
    local cron_file="/etc/cron.d/itflow-$(printf '%s' "${DOMAIN}" | tr -cd 'a-zA-Z0-9_-')"
    cat > "${cron_file}" <<EOF
*/5 * * * * www-data /usr/bin/php ${APP_DIR}/cron/cron.php >> /var/log/itflow-cron.log 2>&1
EOF
    chmod 644 "${cron_file}"
    chown root:root "${cron_file}"
    info "Cron entry installed at ${cron_file} (runs every 5 minutes; inert until enabled in the app's own Settings)."
}

# ---------------------------------------------------------------------------
# 8. Application setup — fresh company, or restore onto this new box
# ---------------------------------------------------------------------------

# verify_setup_completed(): scripts/setup_cli.php's every failure path is a
# plain `die("some message\n")` — PHP's die()/exit() with a STRING argument
# always prints it and exits 0 (only an *integer* argument sets a non-zero
# status). Confirmed live: `php -r 'die("x\n");'` exits 0. That means the
# shell exit code from invoking setup_cli.php below is NOT a reliable
# success signal — a mid-run failure (bad email, DB error, a malformed
# db.sql statement) looks identical, at the shell level, to a clean run.
# The one reliable signal is config.php's own trailing
# `$config_enable_setup = 0;` line, appended as the LAST thing the script
# does only after every earlier step succeeded — the same flag
# includes/redirect_if_setup_enabled.php itself gates the whole app on.
verify_setup_completed() {
    if [[ ! -f "${APP_DIR}/config.php" ]]; then
        die "scripts/setup_cli.php did not produce ${APP_DIR}/config.php (see its output above for the actual failure — its exit code alone cannot be trusted, see this function's comment)."
    fi
    if ! grep -qE '^\$config_enable_setup\s*=\s*0\s*;' "${APP_DIR}/config.php"; then
        die "scripts/setup_cli.php wrote ${APP_DIR}/config.php but never reached its final step (missing \$config_enable_setup = 0;) — it died partway through (see its output above for the actual failure). Remove ${APP_DIR}/config.php and re-run to retry."
    fi
}

run_fresh_setup() {
    if [[ -f "${APP_DIR}/config.php" ]]; then
        info "config.php already exists — skipping application setup (already set up)."
        return 0
    fi

    info "Running the application's own CLI installer (scripts/setup_cli.php) as www-data..."
    local -a setup_args=(
        --host=localhost
        --username="${DB_NAME}"
        --database="${DB_NAME}"
        --base-url="${DOMAIN}"
        --locale="${LOCALE}"
        --timezone="${TIMEZONE}"
        --currency="${CURRENCY}"
        --company-name="${COMPANY_NAME}"
        --country="${COUNTRY}"
        --address="${ADDRESS}"
        --city="${CITY}"
        --state="${STATE}"
        --zip="${ZIP}"
        --phone="${PHONE}"
        --company-email="${COMPANY_EMAIL}"
        --website="${WEBSITE}"
        --user-name="${ADMIN_NAME}"
        --user-email="${ADMIN_EMAIL}"
    )
    [[ "${NON_INTERACTIVE}" -eq 1 ]] && setup_args+=(--non-interactive)

    ( cd "${APP_DIR}/scripts" && sudo -u www-data env ITFLOW_DB_PASSWORD="${DB_PASSWORD}" ITFLOW_ADMIN_PASSWORD="${ADMIN_PASSWORD}" php setup_cli.php "${setup_args[@]}" < /dev/null )
    verify_setup_completed
    success "Application setup complete."

    chmod 640 "${APP_DIR}/config.php"
    chown www-data:www-data "${APP_DIR}/config.php"
}

# run_restore_setup(): writes config.php + a fresh db.sql schema via the
# SAME setup_cli.php path as a normal install, using throwaway placeholder
# values for every company/admin field — they exist for the few seconds
# between this call and deploy/restore.sh overwriting the whole database
# with the real backup, and are never actually used. Deliberately does NOT
# add a --config-only mode to setup_cli.php: that script's full run already
# produces exactly the "config.php exists, DB has SOME schema" precondition
# restore.sh requires, and restore.sh's own DROP DATABASE + re-import step
# replaces all of it moments later anyway.
run_restore_setup() {
    if [[ -f "${APP_DIR}/config.php" ]]; then
        die "config.php already exists at ${APP_DIR} — --restore-from is for a BRAND NEW instance only. Remove the existing config.php first if you really want to overwrite this instance (deploy/restore.sh can then be run directly against it), or point --app-dir at a fresh location."
    fi

    info "Restore mode: running scripts/setup_cli.php with throwaway placeholder values (overwritten by the real backup in a moment)..."
    local placeholder_password
    placeholder_password="$(gen_secret 32)"
    local -a setup_args=(
        --host=localhost
        --username="${DB_NAME}"
        --database="${DB_NAME}"
        --base-url="${DOMAIN}"
        --locale=en_US
        --timezone=UTC
        --currency=USD
        --company-name="Restoring"
        --country="United States"
        --address=""
        --city=""
        --state=""
        --zip=""
        --phone=""
        --company-email=""
        --website=""
        --user-name="Restore Placeholder"
        --user-email="restore-placeholder@example.com"
        --non-interactive
    )

    ( cd "${APP_DIR}/scripts" && sudo -u www-data env ITFLOW_DB_PASSWORD="${DB_PASSWORD}" ITFLOW_ADMIN_PASSWORD="${placeholder_password}" php setup_cli.php "${setup_args[@]}" < /dev/null )
    verify_setup_completed
    success "Placeholder setup complete."

    chmod 640 "${APP_DIR}/config.php"
    chown www-data:www-data "${APP_DIR}/config.php"

    announce "Running deploy/restore.sh to overwrite the placeholder instance with ${RESTORE_FROM}."
    if ! "${SCRIPT_DIR}/restore.sh" --app-dir="${APP_DIR}" --backup="${RESTORE_FROM}" \
        --passphrase-file="${RESTORE_PASSPHRASE_FILE}" --confirm-restore \
        --no-pre-restore-backup-confirmed; then
        die "deploy/restore.sh failed (see its output above). ${APP_DIR}/config.php exists but points at a placeholder instance, not the restored one — fix the restore failure and re-run deploy/restore.sh directly against ${APP_DIR} (it no longer needs install.sh once config.php exists)."
    fi
    success "Restore onto the new instance complete."
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
# run_db_migrations(): bring the schema (and RivetCore's own tables) to the latest version after setup or a restore (the backup
# may be older than this code). update_cli.php applies the pending steps; repeat until it reports the latest version or stops.
run_db_migrations() {
    info "Bringing the database schema up to date (scripts/update_cli.php --update_db)..."
    local i out
    for (( i = 1; i <= 200; i++ )); do
        if ! out="$( cd "${APP_DIR}/scripts" && sudo -u www-data php update_cli.php --update_db 2>&1 )"; then
            warn "update_cli.php --update_db failed: ${out}"
            return 1
        fi
        if grep -q "already at the latest version" <<<"${out}"; then
            success "Database schema is current."
            return 0
        fi
    done
    warn "Database schema still not current after 200 update steps; run scripts/update_cli.php --update_db manually and check for errors."
    return 1
}

main() {
    parse_args "$@"
    require_root "$@"
    detect_os
    validate_args
    setup_logging

    info "=== RivetMSP install starting: domain=${DOMAIN} app-dir=${APP_DIR} db-name=${DB_NAME} ==="

    install_packages
    configure_redis
    provision_app_code
    setup_upload_dirs
    set_file_permissions
    ensure_updater_remote "${APP_DIR}" www-data
    ignore_git_filemode "${APP_DIR}" www-data

    set +x
    DB_PASSWORD="$(gen_secret 32)"
    set -x
    provision_database

    bootstrap_selfsigned_cert
    render_nginx_vhost
    maybe_run_certbot

    apply_php_hardening
    apply_mariadb_hardening

    configure_firewall
    configure_fail2ban

    install_cron

    if [[ -n "${RESTORE_FROM}" ]]; then
        run_restore_setup
    else
        run_fresh_setup
    fi
    run_db_migrations || true

    set +x
    success "=== Install complete for ${DOMAIN} ==="
    cat <<EOF

  Instance:   https://${DOMAIN}/
  App dir:    ${APP_DIR}
  Database:   ${DB_NAME} (user '${DB_NAME}'@'localhost')
  Log:        ${LOG_FILE}

Manual follow-ups (see deploy/README.md's Security model section for the full list):
  1. Enable "Enable Cron" in the app's own Settings once you've logged in —
     the system cron entry above is inert until you do.
  2. Set up a backup passphrase and enable deploy/templates/itflow-backup.timer.
  3. Consider running 'sudo deploy/harden.sh' too (mysql_secure_installation-
     equivalent cleanup + unattended-upgrades — see deploy/README.md).
  4. Enable MFA on the admin account.
EOF
}

main "$@"
