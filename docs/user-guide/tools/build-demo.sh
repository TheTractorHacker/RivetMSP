#!/usr/bin/env bash
# Rebuild the demo instance behind the user-guide screenshots, from nothing.
#
#   docs/user-guide/tools/build-demo.sh [options]
#
#   --app-dir=DIR   Scratch copy of the app to build (default: $TMPDIR/rivetmsp-user-guide-demo).
#                   Never the repository itself: the repo must not gain a config.php or uploads.
#   --db=NAME       Demo database to (re)create (default: rivetmsp_demo). DROPPED first.
#   --port=N        Port for the PHP built-in server (default: 8090).
#   --only=GLOB     Replay only seed files whose name matches, e.g. --only='00-*'.
#   --no-setup      Stop after creating the empty database and the app copy: no setup_cli.php, no
#                   modules, no seeds, and no config.php. The instance is left un-installed so the
#                   browser wizard (setup-wizard.cjs) can install it.
#   --no-server     Build everything but don't start the web server.
#   --force-db      Allow a database name that doesn't look like a demo/test one.
#   -h, --help      This help.
#
# What it does
#   1. Copies the repository into the scratch app directory.
#   2. Drops/recreates the demo database and runs scripts/setup_cli.php for the fictional company
#      "Lakeshore Managed IT" (administrator Morgan Reyes).
#   3. Runs the schema migrations (update_cli.php --update_db, one step per call).
#   4. Replays every file in tools/seed/ in filename order (.sql via mysql, .php via php with
#      RIVETIT_APP_DIR pointing at the scratch app). 01-modules-and-permissions.php turns on every module the
#      guide documents (billing, KB, live chat, payroll, RMM, ...), adds the missing permission modules and roles.
#   5. Starts PHP's built-in web server on 127.0.0.1:PORT.
#
# Then regenerate the screenshots:
#   NODE_PATH=$(npm root -g) DEMO_URL=http://127.0.0.1:8090 node docs/user-guide/tools/run-all.cjs
#
# The server runs with Docker-style upload limits (500M) so the setup wizard's checks page looks like a
# properly provisioned server; install `whois` and `dnsutils` (dig) if you want those checks green too.
#
# Needs: bash, php (mysqli + zip), tar, curl, and MariaDB/MySQL reachable as root over the local
# socket (override the client command with MYSQL_ROOT="mysql -u root -p...").
set -euo pipefail

TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${TOOLS_DIR}/../../.." && pwd)"

APP_DIR="${TMPDIR:-/tmp}/rivetmsp-user-guide-demo"
DB_NAME="rivetmsp_demo"
DB_USER="rivetmsp_demo"
DB_PASS="DemoPass_2026"
ADMIN_PASSWORD='DemoAdmin#2026'
PORT=8090
START_SERVER=1
SKIP_SETUP=0
ONLY=""
FORCE_DB=0
MYSQL_ROOT="${MYSQL_ROOT:-mysql -u root}"
MARKER=".rivetmsp-user-guide-demo"

usage() { sed -n '2,/^set -euo/p' "${BASH_SOURCE[0]}" | sed '$d' | sed 's/^# \{0,1\}//'; }
die() { echo "build-demo: $*" >&2; exit 1; }
step() { echo; echo "==> $*"; }
# A failing seed stops the build, unless KEEP_GOING=1 (useful while a seed is being updated for a new release).
seed_failed() {
    if [[ "${KEEP_GOING:-0}" == "1" ]]; then echo "build-demo: WARNING seed $1 failed, continuing (KEEP_GOING=1)" >&2; FAILED_SEEDS="${FAILED_SEEDS:-} $1"; else die "seed $1 failed"; fi
}
stop_server() {
    local pidfile="$1" pid
    [[ -f "${pidfile}" ]] || return 0
    pid="$(cat "${pidfile}")"
    if [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null; then
        pkill -P "${pid}" 2>/dev/null || true   # the built-in server's worker processes
        kill "${pid}" 2>/dev/null || true
        sleep 1
    fi
    rm -f "${pidfile}"
}

for arg in "$@"; do
    case "${arg}" in
        --app-dir=*)  APP_DIR="${arg#*=}" ;;
        --db=*)       DB_NAME="${arg#*=}" ;;
        --port=*)     PORT="${arg#*=}" ;;
        --only=*)     ONLY="${arg#*=}" ;;
        --no-server)  START_SERVER=0 ;;
        --no-setup)   SKIP_SETUP=1 ;;
        --force-db)   FORCE_DB=1 ;;
        -h|--help)    usage; exit 0 ;;
        *)            usage >&2; die "unknown option: ${arg}" ;;
    esac
done

# ---- guards: this script drops a database and deletes a directory -----------------------------
[[ "${DB_NAME}" =~ ^[A-Za-z0-9_]+$ ]] || die "database name must be letters, digits and underscores only"
if [[ "${FORCE_DB}" -ne 1 && ! "${DB_NAME}" =~ (demo|guide|test|rebuild) ]]; then
    die "refusing to DROP '${DB_NAME}': it doesn't look like a demo database. Use a name containing demo/guide/test/rebuild, or pass --force-db."
fi
[[ "${PORT}" =~ ^[0-9]+$ ]] || die "--port must be a number"
case "${APP_DIR}" in
    ""|"/"|"${REPO_ROOT}"|"${HOME:-/nonexistent}") die "refusing to use '${APP_DIR}' as the scratch app directory" ;;
esac
if [[ -e "${APP_DIR}" && -n "$(ls -A "${APP_DIR}" 2>/dev/null)" && ! -f "${APP_DIR}/${MARKER}" ]]; then
    die "${APP_DIR} exists, isn't empty, and wasn't created by this script - choose another --app-dir"
fi

for cmd in php tar curl; do command -v "${cmd}" >/dev/null 2>&1 || die "${cmd} is required"; done
command -v "${MYSQL_ROOT%% *}" >/dev/null 2>&1 || die "the mysql client is required"

if ! ${MYSQL_ROOT} -e 'SELECT 1' >/dev/null 2>&1; then
    if command -v service >/dev/null 2>&1; then
        echo "MariaDB is not answering; trying to start it..."
        service mariadb start >/dev/null 2>&1 || service mysql start >/dev/null 2>&1 || true
        sleep 3
    fi
    ${MYSQL_ROOT} -e 'SELECT 1' >/dev/null 2>&1 || die "cannot reach MariaDB/MySQL as root (set MYSQL_ROOT to your client command)"
fi

START_TS=$(date +%s)

# ---- 1. scratch copy of the app ----------------------------------------------------------------
step "Copying the app to ${APP_DIR}"
rm -rf "${APP_DIR}"
mkdir -p "${APP_DIR}"
tar -C "${REPO_ROOT}" \
    --exclude=.git --exclude=node_modules --exclude=./config.php \
    --exclude=./docs/user-guide/images \
    -cf - . | tar -xf - -C "${APP_DIR}"
touch "${APP_DIR}/${MARKER}"
# keep only the tracked placeholders inside uploads/ (drop anything a developer's own use left behind)
if [[ -d "${APP_DIR}/uploads" ]]; then
    find "${APP_DIR}/uploads" -type f ! -name index.php ! -name .htaccess -delete
fi

# vendor/ is committed in this repository, so no `composer install` is needed. composer.json requires PHP >= 8.5, and
# Composer's generated platform check (vendor/composer/platform_check.php, required from autoload_real.php) refuses to
# run on an older PHP. The code itself passes `php -l` on 8.3, so in the SCRATCH COPY ONLY neutralise that require.
if [[ -f "${APP_DIR}/vendor/composer/autoload_real.php" ]]; then
    sed -i -E "s#^([[:space:]]*)require __DIR__ \. '/platform_check\.php';#\1// (user-guide demo) platform check disabled: require __DIR__ . '/platform_check.php';#" "${APP_DIR}/vendor/composer/autoload_real.php"
elif [[ -f "${APP_DIR}/composer.json" ]]; then
    command -v composer >/dev/null 2>&1 || die "vendor/ is missing and composer is not installed"
    step "Installing PHP dependencies (composer install --no-dev)"
    ( cd "${APP_DIR}" && composer install --no-dev --optimize-autoloader --no-interaction --quiet --ignore-platform-reqs ${COMPOSER_ARGS:-} )
    sed -i -E "s#^([[:space:]]*)require __DIR__ \. '/platform_check\.php';#\1// (user-guide demo) platform check disabled#" "${APP_DIR}/vendor/composer/autoload_real.php"
fi

# ---- 2. database + first-run setup -------------------------------------------------------------
step "Creating database ${DB_NAME}"
${MYSQL_ROOT} <<SQL
DROP DATABASE IF EXISTS \`${DB_NAME}\`;
CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

if [[ "${SKIP_SETUP}" -eq 0 ]]; then
  step "Running scripts/setup_cli.php (Lakeshore Managed IT)"
  ( cd "${APP_DIR}/scripts" && \
    RIVETMSP_SKIP_KEYFILE=1 ITFLOW_DB_PASSWORD="${DB_PASS}" ITFLOW_ADMIN_PASSWORD="${ADMIN_PASSWORD}" php setup_cli.php \
      --host=localhost --username="${DB_USER}" --database="${DB_NAME}" --base-url="localhost:${PORT}" \
      --locale=en_US --timezone=America/Chicago --currency=USD \
      --company-name="Lakeshore Managed IT" --country="United States" \
      --address="1200 Ridge Road" --city=Madison --state=WI --zip=53703 --phone=6085550100 \
      --company-email=hello@lakeshoremsp.example --website=https://lakeshoremsp.example \
      --user-name="Morgan Reyes" --user-email=morgan.reyes@lakeshoremsp.example \
      --non-interactive </dev/null ) | tail -3
  # http:// demo server: the session cookie must not be marked Secure-only (setup writes TRUE; the CLI's closing
  # message wrongly prints https://)
  sed -i 's/\$config_https_only = TRUE;/$config_https_only = FALSE;/' "${APP_DIR}/config.php"

  # db.sql is a schema snapshot (see its RIVETIT_SCHEMA_VERSION line); setup_cli.php records that version and
  # install.sh then runs the migrations that came after it. Do the same here, or newer features have no columns.
  step "Applying database migrations (scripts/update_cli.php --update_db)"
  # update_cli.php applies ONE version step per call (install.sh loops the same way), so repeat until done.
  for _ in $(seq 1 200); do
      out="$( cd "${APP_DIR}/scripts" && php update_cli.php --update_db 2>&1 )" || { echo "${out}" >&2; die "update_cli.php --update_db failed"; }
      [[ "${out}" == *"already at the latest version"* ]] && break
  done
  echo "  ${out}" | tail -1

  # ---- 4. demo data ------------------------------------------------------------------------------
  step "Replaying seed files"
  # The app keeps company-local times (America/Chicago) but a plain `mysql` session is UTC, so SQL seeds that
  # use NOW() would land hours in the future ("5 hours from now"). Give SQL seeds the company's UTC offset.
  # (PHP seeds load the app's own timezone code; seeds that manage the clock themselves just override this.)
  SEED_TZ_OFFSET="$(php -r 'date_default_timezone_set("America/Chicago"); echo date("P");')"
  mapfile -t SEEDS < <(find "${TOOLS_DIR}/seed" -maxdepth 1 -type f \( -name '*.sql' -o -name '*.php' \) | sort)
  for f in "${SEEDS[@]}"; do
      base="$(basename "${f}")"
      if [[ -n "${ONLY}" && "${base}" != ${ONLY} ]]; then continue; fi
      echo "--- ${base}"
      case "${f}" in
          *.sql) ${MYSQL_ROOT} --init-command="SET time_zone='${SEED_TZ_OFFSET}'" "${DB_NAME}" < "${f}" || seed_failed "${base}" ;;
          *.php) RIVETIT_APP_DIR="${APP_DIR}" php "${f}" || seed_failed "${base}" ;;
      esac
  done
fi

# ---- 5. web server -----------------------------------------------------------------------------
if [[ "${START_SERVER}" -eq 1 ]]; then
    step "Starting the PHP built-in server on 127.0.0.1:${PORT}"
    PIDFILE="${APP_DIR}.server.pid"
    stop_server "${PIDFILE}"
    # exec the server directly with EVERY descriptor redirected: a wrapper shell left holding this
    # script's stdout would keep any pipe reading our output (tee, tail, a CI log) open forever.
    ( cd "${APP_DIR}" && exec env PHP_CLI_SERVER_WORKERS=8 php -d upload_max_filesize=500M -d post_max_size=500M -d memory_limit=512M -S "127.0.0.1:${PORT}" -t . >"${APP_DIR}.server.log" 2>&1 </dev/null ) &
    echo $! >"${PIDFILE}"
    disown 2>/dev/null || true
    for _ in $(seq 1 20); do
        if curl -fsS -o /dev/null "http://127.0.0.1:${PORT}/login.php" 2>/dev/null; then break; fi
        sleep 0.5
    done
    curl -fsS -o /dev/null "http://127.0.0.1:${PORT}/login.php" || { tail -5 "${APP_DIR}.server.log" >&2; die "the web server did not come up on port ${PORT}"; }
fi

echo
echo "Done in $(( $(date +%s) - START_TS ))s."
[[ -n "${FAILED_SEEDS:-}" ]] && echo "  Seeds that FAILED:${FAILED_SEEDS}"
echo "  App directory : ${APP_DIR}"
echo "  Database      : ${DB_NAME}"
if [[ "${START_SERVER}" -eq 1 ]]; then
    echo "  URL           : http://127.0.0.1:${PORT}/   (stop it with: pkill -P \$(cat ${APP_DIR}.server.pid); kill \$(cat ${APP_DIR}.server.pid))"
fi
if [[ "${SKIP_SETUP}" -eq 1 ]]; then
    echo "  Not installed : open /setup/, or run:"
    echo "    DEMO_URL=http://127.0.0.1:${PORT} WIZ_DB=${DB_NAME} WIZ_DB_USER=${DB_USER} WIZ_DB_PASS='${DB_PASS}' NODE_PATH=\$(npm root -g) node docs/user-guide/tools/setup-wizard.cjs"
else
    echo "  Sign in       : morgan.reyes@lakeshoremsp.example / ${ADMIN_PASSWORD}   (devon.park@ / sam.ortiz@ / jules.whitaker@lakeshoremsp.example and the portal login hannah.brooks@northshoredental.example: DemoPass#2026)"
    echo "  Screenshots   : NODE_PATH=\$(npm root -g) DEMO_URL=http://127.0.0.1:${PORT} node docs/user-guide/tools/run-all.cjs"
fi
