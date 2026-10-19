#!/usr/bin/env bash
# shellcheck disable=SC2319,SC2016,SC2181
# deploy/setup-build-host.sh checks that need no root, no network and change nothing on the machine (26.10.34).
#   tests/build_host_setup/run.sh            [CORE_CHECKOUT=/path/to/rivet-core]  (a clone that has the pinned commit; those files stand in for GitHub through --fetch-base)
# Covers: shellcheck, option and value validation (nothing unsafe can reach the sourced config), --check output, refusal as non-root, the SHA-256 pin
# (a tampered file is refused), and that the pins in the script are well formed.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${HERE}/../.." && pwd)"
S="${ROOT}/deploy/setup-build-host.sh"
CORE="${CORE_CHECKOUT:-/home/sysadmin/rivet-core}"
pass=0 fail=0
ok() { if [[ "$1" -eq 0 ]]; then echo "PASS  $2"; pass=$((pass + 1)); else echo "FAIL  $2"; fail=$((fail + 1)); fi; }
t() { local label="$1"; shift; "$@" >/dev/null 2>&1; ok $? "${label}"; }
# shellcheck disable=SC2319,SC2181
nt() { local label="$1"; shift; "$@" >/dev/null 2>&1; [[ $? -ne 0 ]]; ok $? "${label}"; }

PIN="$(sed -n 's/^CORE_PIN_COMMIT="\(.*\)"/\1/p' "${S}")"
[[ "${PIN}" =~ ^[0-9a-f]{40}$ ]]; ok $? "the pinned commit is a full 40-character hash"
for v in PIN_SHA_SCRIPT PIN_SHA_SERVICE PIN_SHA_TIMER; do [[ "$(sed -n "s/^${v}=\"\(.*\)\"/\1/p" "${S}")" =~ ^[0-9a-f]{64}$ ]]; ok $? "${v} is a 64-hex SHA-256"; done
grep -q 'raw.githubusercontent.com/TheTractorHacker/rivet-core/${CORE_PIN_COMMIT}/' "${S}"; ok $? "files are fetched from raw URLs at the pinned commit (never a branch)"
if command -v shellcheck >/dev/null; then shellcheck -x "${S}" "${BASH_SOURCE[0]}"; ok $? "shellcheck is clean"; else echo "SKIP  shellcheck not installed"; fi
bash -n "${S}"; ok $? "bash -n"
[[ -x "${S}" ]]; ok $? "the script is executable"

FIX="$(mktemp -d)"; trap 'rm -rf "${FIX}"' EXIT
if git -C "${CORE}" cat-file -e "${PIN}^{commit}" 2>/dev/null; then
    for f in build-host.sh build-host.service build-host.timer; do git -C "${CORE}" show "${PIN}:endpoint-agent/scripts/${f}" >"${FIX}/${f}"; done
    FB="--fetch-base=file://${FIX}"
    # a throwaway "app" so the checks need no real install
    APP="$(mktemp -d)"; mkdir -p "${APP}/scripts"; : >"${APP}/config.php"; echo '<?php' >"${APP}/scripts/build_host_setup.php"
    trap 'rm -rf "${FIX}" "${APP}"' EXIT
    OUT="$("${S}" --check --yes "${FB}" "--app-dir=${APP}" --site-url=https://msp.example.com --name=build-test 2>&1)"; rc=$?
    # --check runs the php lookup as the web user, which a non-root user cannot do: the run is expected to stop there with a clear message
    grep -q "verified build-host.sh" <<<"${OUT}"; ok $? "the pinned build-host.sh is downloaded and verified"
    grep -q "verified build-host.service" <<<"${OUT}" && grep -q "verified build-host.timer" <<<"${OUT}"; ok $? "the service and timer are verified"
    grep -q "would run: " <<<"${OUT}"; ok $? "check mode says what it would run"
    # tamper: a modified script must be refused
    cp -r "${FIX}" "${FIX}.bad"; echo '# evil' >>"${FIX}.bad/build-host.sh"
    OUT2="$("${S}" --check --yes "--fetch-base=file://${FIX}.bad" "--app-dir=${APP}" --name=build-test 2>&1)"; rc2=$?
    [[ ${rc2} -ne 0 ]] && grep -q "SHA-256 mismatch" <<<"${OUT2}"; ok $? "a tampered build-host.sh is refused (SHA-256 mismatch)"
    ! grep -q "would run: install .*build-host.sh" <<<"${OUT2}"; ok $? "and is not installed"
    cp -r "${FIX}" "${FIX}.bad2"; echo '# evil' >>"${FIX}.bad2/build-host.service"
    OUT3="$("${S}" --check --yes "--fetch-base=file://${FIX}.bad2" "--app-dir=${APP}" --name=build-test 2>&1)"; rc3=$?
    [[ ${rc3} -ne 0 ]] && grep -q "SHA-256 mismatch for build-host.service" <<<"${OUT3}"; ok $? "a tampered unit file is refused too"
    rm -rf "${FIX}.bad" "${FIX}.bad2"
else
    echo "SKIP  ${CORE} does not have the pinned commit ${PIN}: fetch RivetCore to run the pin checks"
fi

# not root, not --check: refuse
if [[ "${EUID}" -ne 0 ]]; then
    OUT="$("${S}" --yes 2>&1)"; rc=$?
    [[ ${rc} -ne 0 ]] && grep -q "must run as root" <<<"${OUT}"; ok $? "a real run as non-root is refused"
    OUT="$("${S}" --uninstall --yes 2>&1)"; rc=$?
    [[ ${rc} -ne 0 ]] && grep -q "must run as root" <<<"${OUT}"; ok $? "--uninstall as non-root is refused"
fi
# shellcheck disable=SC2016
# validation: nothing that could break out of the sourced config is accepted
for bad in "--name=a b" "--name=\$(id)" "--name=a;b" "--name=" '--name=x"y' "--allowed-refs=main;rm" '--allowed-refs=$(id)' "--allowed-refs=a\`b\`" "--core-repo=http://plain.example.com/x.git" "--core-repo=https://x.example.com/a b" '--core-repo=/tmp/$(id)' "--workdir=relative" "--workdir=/var/lib/a b" "--workdir=/x;y" "--workdir=/etc" "--workdir=/" "--workdir=/var/lib/../etc" "--workdir=/usr/lib/x" "--site-url=http://insecure.example.com" "--site-url=https://x.example.com/a b" "--site-url=https://x.example.com;id" "--web-user=ROOT user" "--fetch-base=http://insecure.example.com/x" "--app-dir=relative" "--bogus" "--purge-docker"; do
    OUTV="$("${S}" --check "${bad}" 2>&1)"; rcv=$?
    [[ ${rcv} -ne 0 ]] && grep -Eq "ERROR: (--[a-z-]+ |unknown option|--purge-docker)" <<<"${OUTV}"; ok $? "refused by validation: ${bad}"
done
"${S}" --help | grep -q "RMM Agent Builder"; ok $? "--help describes the script"
"${S}" --help | grep -qi "root-equivalent"; ok $? "--help carries the docker group warning"

echo; echo "${pass} passed, ${fail} failed"
[[ ${fail} -eq 0 ]]
