#!/usr/bin/env bash
# deploy/install.sh: the RMM Agent Builder question and flags (26.10.34), exercised without installing anything: the two functions are cut out of the script and run.
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
I="${ROOT}/deploy/install.sh"
pass=0 fail=0
ok() { if [[ "$1" -eq 0 ]]; then echo "PASS  $2"; pass=$((pass + 1)); else echo "FAIL  $2"; fail=$((fail + 1)); fi; }
fn() { sed -n "/^$1() {/,/^}/p" "${I}"; }
FUNCS="$(fn ask_build_host)"
[[ -n "${FUNCS}" ]]; ok $? "ask_build_host is defined"
"${I}" --help | grep -q -- "--with-build-host" && "${I}" --help | grep -q -- "--no-build-host"; ok $? "--help documents both flags"
"${I}" --help | grep -qi "root-equivalent"; ok $? "--help carries the docker group warning"
grep -q 'Would you like to install the RMM Agent Builder (build the agent .exe/.msi on this server from the web UI)? \[y/N\]' "${I}"; ok $? "the prompt text is exact"
run() { # WITH NON_INTERACTIVE input -> value of WITH_BUILD_HOST
    bash -c "${FUNCS}"'
WITH_BUILD_HOST="$1"; NON_INTERACTIVE="$2"; ask_build_host; printf "%s" "${WITH_BUILD_HOST}"' _ "$1" "$2" <<<"$3" 2>/dev/null
}
[[ "$(run "" 1 "y")" == 0 ]]; ok $? "--non-interactive without a flag installs nothing (and does not ask)"
[[ "$(run 1 1 "")" == 1 ]]; ok $? "--with-build-host stays on, never asks"
[[ "$(run 0 0 "y")" == 0 ]]; ok $? "--no-build-host stays off, never asks"
# a terminal: use script(1) when present to give the function a tty on stdin
if command -v script >/dev/null; then
    for a in "y" "Y" "yes" "n" "" "maybe"; do
        got="$(printf '%s\n' "${a}" | script -qec "bash -c '${FUNCS//\'/}; WITH_BUILD_HOST=\"\"; NON_INTERACTIVE=0; ask_build_host; echo RESULT=\${WITH_BUILD_HOST}'" /dev/null 2>/dev/null | tr -d '\r' | sed -n 's/.*RESULT=//p')"
        want=0; [[ "${a}" =~ ^[Yy]([Ee][Ss])?$ ]] && want=1
        [[ "${got}" == "${want}" ]]; ok $? "interactive answer '${a}' gives ${want} (got '${got}')"
    done
else
    echo "SKIP  script(1) not available for the interactive cases"
fi
grep -q 'install_build_host$' "${I}"; ok $? "the helper runs after the install and migrations"
grep -q 'setup-build-host.sh" --yes' "${I}"; ok $? "it is called with --yes (the question was already answered)"
echo; echo "${pass} passed, ${fail} failed"
[[ ${fail} -eq 0 ]]
