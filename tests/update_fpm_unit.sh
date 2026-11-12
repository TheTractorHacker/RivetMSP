#!/usr/bin/env bash
# php_fpm_unit(): the full-version unit (source build, php8.5.12-fpm) wins, the major.minor unit (apt) is the fallback, and nothing active is a failure.
# systemctl is stubbed; nothing touches the real system.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../deploy/lib/common.sh
source "${here}/../deploy/lib/common.sh"
fail=0
check() { if [[ "$2" == "$3" ]]; then echo "ok   $1"; else echo "FAIL $1: got '$2', want '$3'"; fail=1; fi; }

ACTIVE=""
systemctl() { [[ "$1" == "is-active" ]] && [[ " ${ACTIVE} " == *" ${*: -1} "* ]]; }
export PHP_FPM_UNIT_CANDIDATES="php8.5.12-fpm php8.5-fpm"

ACTIVE="php8.5.12-fpm";               check "full-version unit" "$(php_fpm_unit)" "php8.5.12-fpm"
ACTIVE="php8.5-fpm";                  check "major.minor fallback" "$(php_fpm_unit)" "php8.5-fpm"
ACTIVE="php8.5.12-fpm php8.5-fpm";    check "full version preferred" "$(php_fpm_unit)" "php8.5.12-fpm"
ACTIVE=""; rc=0; php_fpm_unit >/dev/null || rc=$?; check "none active fails" "$rc" "1"

unset PHP_FPM_UNIT_CANDIDATES
full="$(php -r 'echo PHP_VERSION;' | sed -E 's/^([0-9]+\.[0-9]+\.[0-9]+).*/\1/')"
ACTIVE="php${full}-fpm"; got="$(php_fpm_unit || true)"; check "derived from PHP_VERSION" "$got" "php${full}-fpm"
exit $fail
