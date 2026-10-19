#!/usr/bin/env bash
# deploy/setup-build-host.sh for real, as root, inside a throwaway container (26.10.34): the user, the files and their owners/modes, the 0600 config with the token, the
# docker-group rules, a second run, and --uninstall. Docker, git, python3, systemctl and apt-get are STUBS (nothing is installed, no network: --network none), the application
# side is a stub of scripts/build_host_setup.php with the same output contract. The pinned files come from a RivetCore clone via --fetch-base (SHA-256 checks still apply).
#   tests/build_host_setup/container.sh      [CORE_CHECKOUT=/path/to/rivet-core] [IMAGE=php:8.4-cli]
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${HERE}/../.." && pwd)"
CORE="${CORE_CHECKOUT:-/home/sysadmin/rivet-core}"
IMAGE="${IMAGE:-php:8.4-cli}"
PIN="$(sed -n 's/^CORE_PIN_COMMIT="\(.*\)"/\1/p' "${ROOT}/deploy/setup-build-host.sh")"
git -C "${CORE}" cat-file -e "${PIN}^{commit}" 2>/dev/null || { echo "SKIP: ${CORE} lacks ${PIN}"; exit 0; }
docker image inspect "${IMAGE}" >/dev/null 2>&1 || { echo "SKIP: image ${IMAGE} not present"; exit 0; }
W="$(mktemp -d)"; trap 'rm -rf "${W}"' EXIT
mkdir -p "${W}/fix"
for f in build-host.sh build-host.service build-host.timer; do git -C "${CORE}" show "${PIN}:endpoint-agent/scripts/${f}" >"${W}/fix/${f}"; done
cat >"${W}/inside.sh" <<'IN'
#!/usr/bin/env bash
set -uo pipefail
pass=0 fail=0
ok() { if [[ "$1" -eq 0 ]]; then echo "PASS  $2"; pass=$((pass + 1)); else echo "FAIL  $2"; fail=$((fail + 1)); fi; }
# stubs
mkdir -p /stubs && cat >/stubs/stub <<'S'
#!/bin/sh
echo "$(basename "$0") $*" >>/tmp/calls.log
exit 0
S
chmod +x /stubs/stub
for c in docker git python3 systemctl apt-get; do ln -sf /stubs/stub "/usr/local/bin/${c}"; done
groupadd docker
# a stub application
mkdir -p /app/scripts && : >/app/config.php
cat >/app/scripts/build_host_setup.php <<'P'
<?php
$cmd = $argv[1] ?? ''; $name = $argv[3] ?? ''; $f = '/tmp/hoststate';
$state = is_file($f) ? file_get_contents($f) : 'none';
if ($cmd === 'info') { echo "ready=1\nhost=$state\nsite_url=https://msp.test\n"; exit(0); }
if ($cmd === 'create') {
    if ($state !== 'none' && !in_array('--rotate', $argv, true)) { fwrite(STDERR, "exists\n"); exit(3); }
    file_put_contents($f, 'exists'); echo 'rbh_' . bin2hex(random_bytes(8)) . '_' . bin2hex(random_bytes(24)) . "\n"; exit(0);
}
if ($cmd === 'delete') { file_put_contents($f, 'none'); file_put_contents('/tmp/deleted', $name); exit(0); }
exit(4);
P
chmod 644 /app/scripts/build_host_setup.php
S=/repo/deploy/setup-build-host.sh
RUN=("${S}" --yes --no-register --fetch-base=file:///fix --app-dir=/app --name=ct-host)
OUT="$("${RUN[@]}" 2>&1)"; rc=$?
ok "${rc}" "first run succeeds"; [[ ${rc} -ne 0 ]] && echo "${OUT}"
id rivetit-build >/dev/null 2>&1; ok $? "the rivetit-build account exists"
[[ "$(getent passwd rivetit-build | cut -d: -f6)" == /var/lib/rivetit-build-host && "$(getent passwd rivetit-build | cut -d: -f7)" == /usr/sbin/nologin ]]; ok $? "home /var/lib/rivetit-build-host and shell nologin"
id -nG rivetit-build | tr ' ' '\n' | grep -qx docker; ok $? "rivetit-build is in the docker group"
! id -nG www-data | tr ' ' '\n' | grep -qx docker; ok $? "www-data is NOT in the docker group"
[[ "$(stat -c '%U:%G %a' /opt/rivetit-build-host/build-host.sh)" == "root:root 755" && "$(stat -c '%U:%G %a' /opt/rivetit-build-host)" == "root:root 755" ]]; ok $? "build-host.sh and its directory are root-owned 755"
[[ "$(stat -c '%U:%G %a' /etc/systemd/system/build-host.service)" == "root:root 644" && "$(stat -c '%U:%G %a' /etc/systemd/system/build-host.timer)" == "root:root 644" ]]; ok $? "unit files root-owned 644"
cmp -s /fix/build-host.sh /opt/rivetit-build-host/build-host.sh; ok $? "the installed script is byte-identical to the pinned one"
[[ "$(stat -c '%U:%G %a' /etc/rivetit/build-host.conf)" == "rivetit-build:rivetit-build 600" ]]; ok $? "the config is mode 600 owned by rivetit-build"
grep -q '^SERVER_URL=https://msp.test/api/v1/agent_build_host$' /etc/rivetit/build-host.conf && grep -Eq '^TOKEN=rbh_[0-9a-f]{16}_[0-9a-f]{48}$' /etc/rivetit/build-host.conf && grep -q '^CORE_REPO=https://github.com/TheTractorHacker/rivet-core.git$' /etc/rivetit/build-host.conf && grep -q '^ALLOWED_REFS="main v\* agent-v\*"$' /etc/rivetit/build-host.conf && grep -q '^WORKDIR=/var/lib/rivetit-build-host$' /etc/rivetit/build-host.conf; ok $? "the config has the MSP endpoint, the token, the repo, refs and workdir"
bash -c '. /etc/rivetit/build-host.conf && [ -n "$TOKEN" ] && [ "$ALLOWED_REFS" = "main v* agent-v*" ]'; ok $? "the config sources cleanly in bash"
TOK="$(sed -n 's/^TOKEN=//p' /etc/rivetit/build-host.conf)"
! grep -q "${TOK:21}" <<<"${OUT}"; ok $? "the token is not in the script's output"
! grep -rqF "${TOK:21}" /tmp/calls.log /var/log 2>/dev/null; ok $? "the token is in no stubbed command line and no log"
grep -q "systemctl enable --now build-host.timer" /tmp/calls.log && grep -q "systemctl daemon-reload" /tmp/calls.log; ok $? "the timer is reloaded and enabled with --now"
[[ -z "$(find /etc/rivetit -name '.build-host.conf.*')" ]]; ok $? "no temporary config file is left behind"
[[ "$(stat -c %a /var/lib/rivetit-build-host)" == 750 && "$(stat -c %U /var/lib/rivetit-build-host)" == rivetit-build ]]; ok $? "the workdir is 750 owned by rivetit-build"
# second run
cp /etc/rivetit/build-host.conf /tmp/conf1; : >/tmp/calls.log
OUT2="$("${RUN[@]}" 2>&1)"; rc=$?
ok "${rc}" "second run succeeds (idempotent)"; [[ ${rc} -ne 0 ]] && echo "${OUT2}"
grep -q "unchanged: /opt/rivetit-build-host/build-host.sh" <<<"${OUT2}" && grep -q "unchanged: /etc/systemd/system/build-host.timer" <<<"${OUT2}"; ok $? "second run leaves the files alone"
! grep -q "useradd\|groupadd" /tmp/calls.log; ok $? "second run does not recreate the account"
[[ "$(stat -c '%U:%G %a' /etc/rivetit/build-host.conf)" == "rivetit-build:rivetit-build 600" ]]; ok $? "the config is still 600 rivetit-build"
[[ "$(id -nG rivetit-build | tr ' ' '\n' | grep -cx docker)" == 1 ]]; ok $? "still exactly one docker group membership"
# a tampered download is refused and nothing is replaced
mkdir -p /fixbad && cp /fix/* /fixbad/ && echo '# evil' >>/fixbad/build-host.sh
H1="$(sha256sum /opt/rivetit-build-host/build-host.sh)"
OUT3="$("${S}" --yes --no-register --fetch-base=file:///fixbad --app-dir=/app --name=ct-host 2>&1)"; rc=$?
[[ ${rc} -ne 0 ]] && grep -q "SHA-256 mismatch" <<<"${OUT3}" && [[ "$(sha256sum /opt/rivetit-build-host/build-host.sh)" == "${H1}" ]]; ok $? "a tampered download is refused and the installed script is untouched"
# the web user in the docker group stops the run
usermod -aG docker www-data
OUT4="$("${RUN[@]}" 2>&1)"; rc=$?
[[ ${rc} -ne 0 ]] && grep -q "member of the docker group" <<<"${OUT4}"; ok $? "refuses to run while www-data is in the docker group"
gpasswd -d www-data docker >/dev/null
# check mode changes nothing
touch -d '2001-01-01' /etc/rivetit/build-host.conf; M1="$(stat -c %Y /etc/rivetit/build-host.conf)"
"${S}" --check --yes --fetch-base=file:///fix --app-dir=/app --name=ct-host >/tmp/check.out 2>&1; ok $? "--check succeeds"
[[ "$(stat -c %Y /etc/rivetit/build-host.conf)" == "${M1}" ]]; ok $? "--check leaves the config alone"
# uninstall
: >/tmp/calls.log
OUT5="$("${S}" --uninstall --yes --app-dir=/app --name=ct-host 2>&1)"; rc=$?
ok "${rc}" "--uninstall succeeds"; [[ ${rc} -ne 0 ]] && echo "${OUT5}"
[[ ! -e /opt/rivetit-build-host && ! -e /etc/rivetit/build-host.conf && ! -e /etc/systemd/system/build-host.timer && ! -e /etc/systemd/system/build-host.service && ! -e /var/lib/rivetit-build-host ]]; ok $? "uninstall removes the script, config, units and workdir"
! id rivetit-build >/dev/null 2>&1; ok $? "uninstall removes the account"
[[ "$(cat /tmp/deleted 2>/dev/null)" == ct-host ]]; ok $? "uninstall deletes the build host in the application"
grep -q "systemctl disable --now build-host.timer" /tmp/calls.log; ok $? "uninstall stops the timer"
! grep -q "apt-get purge" /tmp/calls.log; ok $? "Docker is NOT purged without --purge-docker"
"${RUN[@]}" >/dev/null 2>&1; : >/tmp/calls.log
"${S}" --uninstall --purge-docker --keep-host --yes --app-dir=/app --name=ct-host >/dev/null 2>&1
grep -q "apt-get purge -y docker.io" /tmp/calls.log; ok $? "--purge-docker purges docker.io"
echo; echo "${pass} passed, ${fail} failed"
[[ ${fail} -eq 0 ]]
IN
chmod +x "${W}/inside.sh"
docker run --rm --network none -v "${ROOT}:/repo:ro" -v "${W}/fix:/fix:ro" -v "${W}/inside.sh:/inside.sh:ro" "${IMAGE}" bash /inside.sh
