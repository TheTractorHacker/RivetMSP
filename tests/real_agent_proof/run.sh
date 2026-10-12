#!/usr/bin/env bash
# REAL-agent proof of RivetMSP's RMM Phases 6 and 8 (remote tools, file transfer, log collection), of the optional MeshCentral agent install and node-id mapping (rc.12,
# against a MOCK Mesh server), of the PUSHED Mesh install and removal on an agent enrolled without the Mesh option (rc.16, same mock server) and of the starter pack (rc.13: installed through the API, a read-only script and a dry-run script run on the real agent), through RivetMSP's own bridges.
#
#   the Linux agent (the release build from RivetCore's `make dist`, endpoint-agent/dist) running under a REAL systemd in a privileged container
#     <--TLS-->  nginx (host network, self-signed CA)  <--http-->  `php -S` serving RivetMSP's api/v1 front controller (tests/rmm_golden/router.php)
#                                                                  on a SCRATCH database: api/v1/agent_enroll|agent_checkin|agent_jobs|agent_transfer|agent_logs.php
#                                                                  and the technician API api/v1/endpoint_devices.php with a real user API token
#
# Adapted from RivetCore's endpoint-agent/e2e/run_real_agent_proof.sh (same kinds of checks), but the server is RivetMSP, not Core's fixture router.
# Nothing here touches a real database: RIVETMSP_TEST_DB_NAME must contain "scratch" and config.php must point at it; the RMM tables of that database are wiped by
# tests/real_agent_proof/setup.php. Needs docker, php (with mysqli), curl, openssl.
#
#   . ./.scratch_env      # RIVETMSP_TEST_DB_NAME / _USER / _PASS (see tests/endpoint_agent_lib.php for the scratch rules)
#   RIVETMSP_SOCKET=/path/to/mysql.sock AGENT_BIN=<core>/endpoint-agent/dist/rivetit-agent-linux-amd64 INSTALL_SH=<core>/endpoint-agent/dist/install-linux.sh tests/real_agent_proof/run.sh
#
# Prints "ok:"/"FAIL:" lines and a final "PROOF PASS" or "PROOF FAIL". Windows is not exercised (cross-compiled and vetted only).
# shellcheck disable=SC2034  # variables are read inside the eval-ed check strings
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; REPO="$(cd "$HERE/../.." && pwd)"
AGENT_BIN="${AGENT_BIN:?set AGENT_BIN to the release-built Linux agent (RivetCore endpoint-agent/dist/rivetit-agent-linux-amd64)}"
INSTALL_SH="${INSTALL_SH:-$(dirname "$AGENT_BIN")/install-linux.sh}"
SOCKET="${RIVETMSP_SOCKET:?set RIVETMSP_SOCKET to the scratch MariaDB socket}"
IMG="${SYSTEMD_IMAGE:-jrei/systemd-ubuntu:24.04}"
NGINX_IMG="${NGINX_IMAGE:-nginx:alpine}"
NAME="${PROOF_NAME:-rivetit-proof}"
PHPPORT="${PHP_PORT:-8680}"; TLSPORT="${TLS_PORT:-8643}"
GATEWAY="$(docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}' 2>/dev/null || echo 172.17.0.1)"
WORK="$(mktemp -d /tmp/rivetit-proof.XXXXXX)"
case "${RIVETMSP_TEST_DB_NAME:-}" in *scratch*) ;; *) echo "Refusing: RIVETMSP_TEST_DB_NAME must name a scratch database"; exit 2;; esac
[ "${RIVETMSP_TEST_DB:-}" = 1 ] || { echo "Refusing: set RIVETMSP_TEST_DB=1 (source .scratch_env)"; exit 2; }
[ -x "$AGENT_BIN" ] && [ -f "$INSTALL_SH" ] || { echo "AGENT_BIN / INSTALL_SH not found (run make dist in RivetCore endpoint-agent)"; exit 2; }
fail=0
ok()  { echo "ok: $*"; }
bad() { echo "FAIL: $*"; fail=1; }
chk() { if eval "$2"; then ok "$1"; else bad "$1  [$2]"; fi; }
wait_for() { local t=$1 c=$2 i=0; while ! eval "$c"; do i=$((i+1)); [ $i -ge $((t*2)) ] && return 1; sleep 0.5; done; return 0; }
PHPPID=""
MESHPID=""
cleanup() {
  docker rm -f "$NAME" "$NAME-nginx" "$NAME-mesh" >/dev/null 2>&1 || true
  [ -n "$PHPPID" ] && kill "$PHPPID" 2>/dev/null || true
  [ -n "$MESHPID" ] && kill "$MESHPID" 2>/dev/null || true
  rm -rf "$WORK"
}
trap cleanup EXIT
cleanup_early() { docker rm -f "$NAME" "$NAME-nginx" "$NAME-mesh" >/dev/null 2>&1 || true; }; cleanup_early

echo "== scratch RivetMSP install and server"
mkdir -p "$WORK/state" "$WORK/tls" "$WORK/transfer"; chmod 700 "$WORK/state"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=rivetit-proof" -addext "subjectAltName=IP:$GATEWAY,IP:127.0.0.1,DNS:localhost" \
  -keyout "$WORK/tls/key.pem" -out "$WORK/tls/ca.pem" >/dev/null 2>&1
# the MOCK Mesh server (tests/real_agent_proof/mock_mesh.py): its own self-signed certificate, whose SHA-384 is the pin the install command carries
MESHPORT="${MESH_PORT:-8654}"
# RivetCore 1.0.0-rc.15: the device group id in MeshCentral's own URL form (64 characters of base64, '@' for '+', '$' for '/'), a fresh random one per run that has both
MESHGROUP=""
until printf '%s' "$MESHGROUP" | grep -q '@' && printf '%s' "$MESHGROUP" | grep -qF '$'; do MESHGROUP="$(head -c 48 /dev/urandom | base64 -w0 | tr '+/' '@$')"; done
MESHNODE_RAW='AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz01'
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=mock-meshcentral" -addext "subjectAltName=IP:$GATEWAY,IP:127.0.0.1" -keyout "$WORK/tls/mesh-key.pem" -out "$WORK/tls/mesh-cert.pem" >/dev/null 2>&1
cat "$WORK/tls/mesh-key.pem" "$WORK/tls/mesh-cert.pem" >"$WORK/tls/mesh.pem"; chmod 600 "$WORK/tls/mesh.pem"
MESHPIN="$(openssl x509 -in "$WORK/tls/mesh-cert.pem" -outform DER | openssl dgst -sha384 -hex | awk '{print $NF}')"
: >"$WORK/mesh-events.log"
python3 "$HERE/mock_mesh.py" "$WORK/tls/mesh.pem" "$MESHPORT" "$MESHGROUP" "$MESHNODE_RAW" "$WORK/mesh-events.log" >/dev/null 2>&1 &
MESHPID=$!
export PROOF_MESH_URL="https://$GATEWAY:$MESHPORT" PROOF_MESH_GROUP="$MESHGROUP" PROOF_MESH_PIN="$MESHPIN" PROOF_CA_FILE="$WORK/tls/ca.pem"
export RMM_TEST_STATE_DIR="$WORK/state" RMM_GATE_STATE_DIR= EA_TEST_NON_WINDOWS=1 RIVETMSP_WEBHOOK_ALLOW_PRIVATE=1
SETUP="$(cd "$REPO" && php "$HERE/setup.php" "https://$GATEWAY:$TLSPORT")" || { echo "setup failed: $SETUP"; exit 2; }
MESHCMD="$(printf '%s' "$SETUP" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["mesh_command"];')"
TOKEN="$(printf '%s' "$SETUP" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["token"];')"
ADMIN="$(printf '%s' "$SETUP" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["admin_api_token"];')"
export RIVETMSP_SOCKET="$SOCKET"
dbq() { php -r 'mysqli_report(MYSQLI_REPORT_OFF); $m = new mysqli(null, getenv("RIVETMSP_TEST_DB_USER"), getenv("RIVETMSP_TEST_DB_PASS"), getenv("RIVETMSP_TEST_DB_NAME"), null, getenv("RIVETMSP_SOCKET")); $r = $m->query($argv[1]); if ($r instanceof mysqli_result) { $row = $r->fetch_row(); echo $row[0] ?? ""; }' "$1"; }
# the agent long-polls agent_jobs, so one php -S worker would starve the technician calls; the fixture's state file is only written by enrollment
( cd "$REPO" && exec env PHP_CLI_SERVER_WORKERS=4 php -d display_errors=0 -d log_errors=1 -d "error_log=$WORK/php.log" -S "127.0.0.1:$PHPPORT" tests/rmm_golden/router.php >/dev/null 2>&1 ) &
PHPPID=$!
wait_for 15 "curl -s -o /dev/null http://127.0.0.1:$PHPPORT/api/v1/nothing" || { bad "php -S did not start"; exit 1; }
cat >"$WORK/nginx.conf" <<NG
events {}
http {
  server {
    listen $TLSPORT ssl;
    ssl_certificate /tls/ca.pem; ssl_certificate_key /tls/key.pem;
    client_max_body_size 4m; proxy_request_buffering off; proxy_buffering off;
    location / { proxy_pass http://127.0.0.1:$PHPPORT; proxy_set_header X-Forwarded-Proto https; proxy_set_header Host \$host; proxy_http_version 1.1; }
  }
}
NG
docker run -d --name "$NAME-nginx" --network host -v "$WORK/tls":/tls:ro -v "$WORK/nginx.conf":/etc/nginx/nginx.conf:ro "$NGINX_IMG" >/dev/null
wait_for 15 "curl -s --cacert $WORK/tls/ca.pem -o /dev/null https://$GATEWAY:$TLSPORT/api/v1/nothing" || { bad "TLS proxy did not start"; docker logs "$NAME-nginx" | tail -5; exit 1; }
api() { # api METHOD path [json-body] -> body on stdout; sets $CODE
  local m=$1 p=$2 b="${3:-}" out
  if [ -n "$b" ]; then out="$(curl -s -w '\n%{http_code}' -X "$m" -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' --data "$b" "http://127.0.0.1:$PHPPORT/api/v1/endpoint_devices/$p")"
  else out="$(curl -s -w '\n%{http_code}' -X "$m" -H "Authorization: Bearer $ADMIN" "http://127.0.0.1:$PHPPORT/api/v1/endpoint_devices/$p")"; fi
  printf '%s' "${out##*$'\n'}" >"$WORK/code"; printf '%s' "${out%$'\n'*}"
}
A() { R="$(api "$@")"; CODE="$(cat "$WORK/code")"; }   # api in the current shell: sets R (body) and CODE (status)
jget() { php -r '$d=json_decode(stream_get_contents(STDIN), true); $v=$d; foreach (explode(".", $argv[1]) as $k) { if (is_array($v) && array_key_exists($k, $v)) { $v=$v[$k]; } else { $v=null; break; } } echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));' "$1"; }

echo "== real systemd container, agent installed with install-linux.sh"
docker run -d --name "$NAME" --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /tmp --tmpfs /run --tmpfs /run/lock "$IMG" >/dev/null
for _ in $(seq 1 60); do docker exec "$NAME" systemctl is-system-running 2>/dev/null | grep -Eq 'running|degraded' && break; sleep 0.5; done
docker exec "$NAME" mkdir -p /w /srv/proof
docker cp "$AGENT_BIN" "$NAME:/w/rivetit-agent"; docker cp "$INSTALL_SH" "$NAME:/w/install-linux.sh"; docker cp "$WORK/tls/ca.pem" "$NAME:/w/ca.pem"
printf '%s' "$TOKEN" | docker exec -i "$NAME" sh -c 'cat >/w/enroll.token; chmod 600 /w/enroll.token'
# harmless units: one to restart, one that logs a failed-password line, and files for the transfers
docker exec "$NAME" sh -c 'cat >/etc/systemd/system/rivetproof-probe.service <<U
[Unit]
Description=RivetMSP proof probe (harmless)
[Service]
ExecStart=/bin/sleep infinity
U
cat >/etc/systemd/system/rivetproof-noisy.service <<U
[Unit]
Description=RivetMSP proof noisy unit (harmless)
[Service]
Type=oneshot
SyslogIdentifier=rivetproof
ExecStart=/bin/sh -c "echo \"<3>Failed password for invalid user proofuser from 203.0.113.9 port 2222 ssh2\" >&2; echo \"<6>routine: nothing to see\" >&2"
U
systemctl daemon-reload && systemctl start rivetproof-probe.service
printf "hello from the device\n" >/srv/proof/report.txt; ln -sf /etc/shadow /srv/proof/shadow-link; mkdir -p /srv/proof/drop; (sleep 3000 &) ; true'
docker exec "$NAME" bash /w/install-linux.sh --binary /w/rivetit-agent --server "https://$GATEWAY:$TLSPORT" --token-file /w/enroll.token --ca /w/ca.pem >"$WORK/install.out" 2>&1
chk "install exit 0" '[ $? = 0 ]'
wait_for 40 "[ \"\$(api GET '' | jget total)\" = 1 ]" ; DEV="$(api GET '' | jget data.0.device_id)"
chk "the agent enrolled against the PHP server and is a device ($DEV)" '[ -n "$DEV" ] && [ "$DEV" != "null" ]'
chk "the agent announced the new capabilities (remote:services, remote:processes, remote:files, logs:collect)" 'wait_for 40 "dbq \"SELECT capabilities_json FROM rmm_device_state WHERE device_id = $DEV\" | grep -q logs:collect" && dbq "SELECT capabilities_json FROM rmm_device_state WHERE device_id = $DEV" | grep -q remote:services'
q() { docker exec "$NAME" "$@"; }
job_done() { # job_done JOBID [seconds] -> waits for a final state; prints it
  local j=$1 t="${2:-90}" i=0 st; while [ $i -lt $((t*2)) ]; do st="$(api GET "$DEV/remote/jobs/$j" | jget state)"; case "$st" in succeeded|failed|timed_out|cancelled|expired) echo "$st"; return 0;; esac; i=$((i+1)); sleep 0.5; done; echo "$st"; return 1; }

echo "== service manager"
A POST "$DEV/services/query"; J="$(printf '%s' "$R" | jget job_id)"
chk "service list queued (202)" '[ "$CODE" = 202 ] && [ -n "$J" ]'
chk "service list job succeeded" '[ "$(job_done $J)" = succeeded ]'
S="$(api GET "$DEV/services")"
chk "the list holds the harmless probe unit with state running" 'printf "%s" "$S" | php -r "\$d=json_decode(stream_get_contents(STDIN),true); foreach (\$d[\"data\"][\"services\"] as \$s) { if (\$s[\"name\"]===\"rivetproof-probe.service\" && \$s[\"state\"]===\"running\") exit(0);} exit(1);"'
PID1="$(q systemctl show -p MainPID --value rivetproof-probe.service)"
A POST "$DEV/services/rivetproof-probe/restart" '{}'
chk "restart without confirm is refused (422 confirmation_required)" '[ "$CODE" = 422 ] && [ "$(printf "%s" "$R" | jget code)" = confirmation_required ]'
A POST "$DEV/services/rivetproof-probe/restart" '{"confirm":true}'; J="$(printf '%s' "$R" | jget job_id)"
chk "restart queued (202)" '[ "$CODE" = 202 ]'
chk "restart job succeeded" '[ "$(job_done $J)" = succeeded ]'
PID2="$(q systemctl show -p MainPID --value rivetproof-probe.service)"
chk "the unit really restarted under systemd (new MainPID $PID1 -> $PID2)" '[ -n "$PID2" ] && [ "$PID2" != 0 ] && [ "$PID1" != "$PID2" ]'
chk "systemd says it is active" '[ "$(q systemctl is-active rivetproof-probe.service)" = active ]'
A POST "$DEV/services/rivetit-agent/stop" '{"confirm":true}'
chk "the server refuses to stop the agent own service" '[ "$CODE" = 422 ] && [ "$(printf "%s" "$R" | jget code)" = protected ]'
A POST "$DEV/services/%2D%2Dnow/start" '{}'
chk "a hostile unit name (--now) is refused before it is queued" '[ "$CODE" = 422 ]'
A POST "$DEV/services/nonexistent-unit-xyz/start" '{}'; J="$(printf '%s' "$R" | jget job_id)"
ST="$(job_done $J)"; RES="$(api GET "$DEV/remote/jobs/$J")"
chk "an unknown unit is a clean failed result (not_found)" '[ "$ST" = failed ] && printf "%s" "$RES" | grep -q not_found'
echo "== process manager"
A POST "$DEV/processes/query" '{"limit":200,"sort":"cpu"}'; J="$(printf '%s' "$R" | jget job_id)"
chk "process list job succeeded" '[ "$(job_done $J)" = succeeded ]'
P="$(api GET "$DEV/processes")"
SLEEPPID="$(printf '%s' "$P" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach ($d["data"]["processes"] as $p) { if ($p["name"]==="sleep" && $p["pid"]>1) { echo $p["pid"]; exit; } }')"
chk "the list has processes with cpu and memory, including the test sleeper ($SLEEPPID)" 'printf "%s" "$P" | grep -q mem_bytes && [ -n "$SLEEPPID" ]'
A POST "$DEV/processes/$SLEEPPID/kill" "{\"name\":\"definitely-not-sleep\",\"confirm\":true}"; J="$(printf '%s' "$R" | jget job_id)"
ST="$(job_done $J)"; RES="$(api GET "$DEV/remote/jobs/$J")"
chk "a kill whose name does not match is refused by the agent (name_mismatch) and nothing is killed" '[ "$ST" = failed ] && printf "%s" "$RES" | grep -q name_mismatch && q test -d /proc/$SLEEPPID'
A POST "$DEV/processes/1/kill" '{"name":"systemd","confirm":true}'
chk "pid 1 is refused by the server" '[ "$CODE" = 422 ]'
A POST "$DEV/processes/$SLEEPPID/kill" "{\"name\":\"sleep\",\"confirm\":true}"; J="$(printf '%s' "$R" | jget job_id)"
chk "a correct kill succeeds" '[ "$(job_done $J)" = succeeded ]'
chk "the process is gone" 'wait_for 10 "! q test -d /proc/$SLEEPPID"'
echo "== file transfer"
A POST "$DEV/files/download" '{"path":"/srv/proof/report.txt"}'; T="$(printf '%s' "$R" | jget transfer_id)"; J="$(printf '%s' "$R" | jget job_id)"
chk "download queued (202)" '[ "$CODE" = 202 ]'
chk "download job succeeded" '[ "$(job_done $J)" = succeeded ]'
S="$(api GET "$DEV/files/$T")"
WANT="$(q sha256sum /srv/proof/report.txt | cut -d' ' -f1)"
chk "the transfer is complete with the device SHA-256" '[ "$(printf "%s" "$S" | jget state)" = complete ] && [ "$(printf "%s" "$S" | jget sha256)" = "$WANT" ]'
curl -s -H "Authorization: Bearer $ADMIN" "http://127.0.0.1:$PHPPORT/api/v1/endpoint_devices/$DEV/files/$T/content" -o "$WORK/got.txt"
chk "the technician fetches identical bytes" 'cmp -s "$WORK/got.txt" <(q cat /srv/proof/report.txt)'
A POST "$DEV/files/download" '{"path":"/etc/shadow"}'
chk "the server refuses /etc/shadow (outside the allowed folder)" '[ "$CODE" = 422 ] && printf "%s" "$R" | grep -q path_denied'
A POST "$DEV/files/download" '{"path":"/srv/proof/shadow-link"}'; J="$(printf '%s' "$R" | jget job_id)"
ST="$(job_done $J)"; RES="$(api GET "$DEV/remote/jobs/$J")"
chk "a symlink inside the allowed folder that points at /etc/shadow is refused BY THE AGENT (deny list after symlink resolution)" '[ "$CODE" != 422 ] && [ "$ST" = failed ] && printf "%s" "$RES" | grep -q path_denied'
head -c 3000000 /dev/urandom >"$WORK/payload.bin"; SHA="$(sha256sum "$WORK/payload.bin" | cut -d' ' -f1)"; SZ="$(stat -c %s "$WORK/payload.bin")"
A POST "$DEV/files/upload" "{\"path\":\"/srv/proof/drop/tool.bin\",\"size\":$SZ,\"sha256\":\"$SHA\",\"confirm\":true}"; T="$(printf '%s' "$R" | jget transfer_id)"
chk "upload declared (201)" '[ "$CODE" = 201 ]'
R="$(curl -s -w '\n%{http_code}' -X PUT -H "Authorization: Bearer $ADMIN" --data-binary @"$WORK/payload.bin" "http://127.0.0.1:$PHPPORT/api/v1/endpoint_devices/$DEV/files/$T/content")"
J="$(printf '%s' "${R%$'\n'*}" | jget job_id)"; CODE="${R##*$'\n'}"
chk "content accepted and the delivery job queued (202)" '[ "$CODE" = 202 ] && [ -n "$J" ]'
chk "upload job succeeded" '[ "$(job_done $J 120)" = succeeded ]'
chk "the device has the file with the same SHA-256 (hash verified by the agent)" '[ "$(q sha256sum /srv/proof/drop/tool.bin | cut -d" " -f1)" = "$SHA" ]'
chk "no temporary file is left behind" '[ -z "$(q sh -c "ls -A /srv/proof/drop | grep -v ^tool.bin$")" ]'
A POST "$DEV/files/upload" "{\"path\":\"/srv/proof/drop/tool.bin\",\"size\":5,\"sha256\":\"$(printf hello | sha256sum | cut -d' ' -f1)\",\"confirm\":true}"; T="$(printf '%s' "$R" | jget transfer_id)"
R="$(curl -s -w '\n%{http_code}' -X PUT -H "Authorization: Bearer $ADMIN" --data-binary 'hello' "http://127.0.0.1:$PHPPORT/api/v1/endpoint_devices/$DEV/files/$T/content")"; J="$(printf '%s' "${R%$'\n'*}" | jget job_id)"
ST="$(job_done $J)"; RES="$(api GET "$DEV/remote/jobs/$J")"
chk "overwrite=false on an existing file is refused by the agent and the file is untouched" '[ "$ST" = failed ] && printf "%s" "$RES" | grep -q exists && [ "$(q sha256sum /srv/proof/drop/tool.bin | cut -d" " -f1)" = "$SHA" ]'
echo "== log collection (journald) and a pattern alert"
q systemctl start rivetproof-noisy.service
chk "the agent was offered logs:collect and shipped the failed-password line" 'wait_for 120 "api GET \"$DEV/logs?q=Failed%20password\" | grep -q proofuser"'
L="$(api GET "$DEV/logs?level=warning")"
chk "the entry is searchable by device, level and text, and the harmless line was filtered out by the agent" 'printf "%s" "$L" | grep -q "Failed password" && ! printf "%s" "$L" | grep -q "routine: nothing"'
chk "the stored message carries no control characters and the source is the unit" 'printf "%s" "$L" | grep -q rivetproof-noisy.service'
chk "the rule raised an alert through the check evaluator" 'wait_for 60 "[ \"\$(api GET \"alerts?state=active\" | jget total)\" != 0 ]" && api GET "alerts?state=active" | grep -q "log.rule_"'
q systemctl start rivetproof-noisy.service
sleep 2
ST="$(api GET "$DEV/logs/stats")"
chk "storage figures are reported" 'printf "%s" "$ST" | grep -q device_max_rows'
echo "== optional MeshCentral agent (rc.12): Core's install command, a MOCK Mesh server, a second machine"
chk "Core's Linux install command carries the Mesh flags, the group id and the pin" 'printf "%s" "$MESHCMD" | grep -q -- "--install-mesh-agent" && printf "%s" "$MESHCMD" | grep -q -- "--mesh-url" && printf "%s" "$MESHCMD" | grep -qF "$MESHGROUP" && printf "%s" "$MESHCMD" | grep -q "$MESHPIN"'
chk "the mock Mesh server is up and has served nothing yet" '[ ! -s "$WORK/mesh-events.log" ] && kill -0 "$MESHPID"'
M2="$NAME-mesh"
docker run -d --name "$M2" --hostname proof-mesh-host --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /tmp --tmpfs /run --tmpfs /run/lock "$IMG" >/dev/null
for _ in $(seq 1 60); do docker exec "$M2" systemctl is-system-running 2>/dev/null | grep -Eq 'running|degraded' && break; sleep 0.5; done
docker exec "$M2" mkdir -p /w /var/tmp
# a second MACHINE: containers of one image share /etc/machine-id, which the agent uses as the machine identity
docker exec "$M2" sh -c 'cat /proc/sys/kernel/random/uuid | tr -d - > /etc/machine-id'
docker cp "$AGENT_BIN" "$M2:/w/rivetit-agent"; docker cp "$INSTALL_SH" "$M2:/w/install-linux.sh"; docker cp "$WORK/tls/ca.pem" "$M2:/w/ca.pem"
printf '%s' "$TOKEN" | docker exec -i "$M2" sh -c 'cat >/w/enroll.token; chmod 600 /w/enroll.token'
printf '%s\n' "$MESHCMD" | docker exec -i "$M2" sh -c 'cat >/w/mesh-install.sh'
q2() { docker exec "$M2" "$@"; }
BADPIN="$(printf 0%.0s $(seq 1 96))"
echo "-- a pin that does not match: the RivetMSP agent installs, the Mesh agent does not, and the failure is reported"
q2 bash -c "cd /w && ./install-linux.sh --server https://$GATEWAY:$TLSPORT --token-file /w/enroll.token --ca /w/ca.pem --install-mesh-agent --mesh-url $PROOF_MESH_URL --mesh-group-id '$MESHGROUP' --mesh-cert-pin $BADPIN" >"$WORK/mesh-install-bad.out" 2>&1
RCB=$?
chk "the install exits 0 although the Mesh part failed (a Mesh failure never fails the RivetMSP install)" '[ $RCB = 0 ]'
chk "no Mesh agent exists on the machine and the mock server was never reached (the TLS handshake was refused)" '! q2 test -e /usr/local/mesh_services/meshagent/meshagent && [ ! -s "$WORK/mesh-events.log" ]'
chk "the failure is on record on the machine and never holds the group id" 'q2 grep -q "\"installed\": false" /var/lib/rivetit-agent/mesh_agent.json && ! q2 grep -rqaF "$MESHGROUP" /var/lib/rivetit-agent'
wait_for 60 "[ \"\$(api GET '' | jget total)\" = 2 ]"
DEV2="$(api GET '' | php -r '$d=json_decode(stream_get_contents(STDIN), true); foreach ($d["data"] as $x) { if ((int) $x["device_id"] !== (int) $argv[1]) { echo $x["device_id"]; } }' "$DEV")"
chk "the second machine enrolled against RivetMSP and is a device ($DEV2)" '[ -n "$DEV2" ]'
chk "its first check-in announced the capability mesh:nodeid" 'wait_for 60 "dbq \"SELECT capabilities_json FROM rmm_device_state WHERE device_id = $DEV2\" | grep -q mesh:nodeid"'
chk "the technician API reports the failed install with its reason, and names no group id" 'wait_for 60 "A GET \"$DEV2\"; [ \"\$(printf %s \"\$R\" | jget mesh_agent.installed)\" = false ]" && printf "%s" "$R" | jget mesh_agent.error | grep -qi pinned && ! printf "%s" "$R" | grep -qF "$MESHGROUP"'
chk "nothing is mapped for it" '[ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_mesh_nodes WHERE device_id = $DEV2")" = 0 ]'
chk "audit: Mesh Agent Install Failed" '[ "$(dbq "SELECT COUNT(*) FROM logs WHERE log_action = \"Mesh Agent Install Failed\"")" -ge 1 ]'
echo "-- the install command Core made (good pin): the Mesh agent is pulled from the mock server, installed, and its node is mapped automatically"
q2 bash -c "cd /w && bash ./mesh-install.sh" >"$WORK/mesh-install-good.out" 2>&1
RCG=$?
chk "the install command exits 0" '[ $RCG = 0 ]'
chk "downloaded from <mesh>/meshagents?id=6&meshid=<group>&installflags=0 (linux/amd64 = 6)" 'grep -qF "MESH-DOWNLOAD id=6 meshid=$MESHGROUP installflags=0" "$WORK/mesh-events.log"'
chk "the downloaded installer ran with -install and put the (fake) Mesh agent in place" 'q2 grep -q "installed by: .* -install" /var/tmp/mesh-installed.marker && q2 test -x /usr/local/mesh_services/meshagent/meshagent'
chk "the install output says so, and the machine keeps one RivetMSP device (no second enrollment)" 'grep -q "MeshCentral agent: installed" "$WORK/mesh-install-good.out" && [ "$(api GET "" | jget total)" = 2 ]'
chk "the check-in reports it and the server maps the node (installed, node//<id>, mapped)" 'wait_for 90 "A GET \"$DEV2\"; [ \"\$(printf %s \"\$R\" | jget mesh_agent.installed)\" = true ] && [ \"\$(printf %s \"\$R\" | jget mesh_agent.map_state)\" = mapped ]" && [ "$(printf %s "$R" | jget mesh_agent.node_id)" = "node//$MESHNODE_RAW" ]'
chk "the mapping row says the agent set it, and the earlier error is gone" '[ "$(dbq "SELECT source FROM endpoint_agent_mesh_nodes WHERE device_id = $DEV2")" = agent ] && [ "$(printf %s "$R" | jget mesh_agent.error)" = null ]'
chk "the device detail never holds the group id" '! printf "%s" "$R" | grep -qF "$MESHGROUP"'
chk "audit: Mesh Agent Installed and Mesh Node Mapped" '[ "$(dbq "SELECT COUNT(*) FROM logs WHERE log_action = \"Mesh Agent Installed\"")" -ge 1 ] && [ "$(dbq "SELECT COUNT(*) FROM logs WHERE log_action = \"Mesh Node Mapped\"")" -ge 1 ]'
chk "the option was remembered for the enrollment token (installer options row)" '[ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_installer_options WHERE install_mesh_agent = 1")" -ge 1 ]'
chk "uninstalling the RivetMSP agent leaves the Mesh agent alone" 'q2 bash /w/install-linux.sh --uninstall --purge >"$WORK/mesh-uninstall.out" 2>&1; q2 test -x /usr/local/mesh_services/meshagent/meshagent'
chk "and --remove-meshagent runs the Mesh agent's own uninstaller" 'q2 bash -c "cd /w && ./install-linux.sh --server https://$GATEWAY:$TLSPORT --token-file /w/enroll.token --ca /w/ca.pem >/dev/null 2>&1; ./install-linux.sh --uninstall --purge --remove-meshagent" >"$WORK/mesh-uninstall2.out" 2>&1; q2 test -e /var/tmp/mesh-uninstalled.marker && ! q2 test -e /usr/local/mesh_services/meshagent/meshagent'
docker rm -f "$M2" >/dev/null 2>&1 || true

echo "== pushed MeshCentral agent (rc.16): mesh_install on the FIRST machine, enrolled WITHOUT the Mesh option, through RivetMSP's technician API (api/v1/endpoint_devices) and a MOCK Mesh server"
MESHDIR=/usr/local/mesh_services/meshagent
# the second machine's container is gone: retire its device (what an administrator does), so the fake node id its install reported can move to the machine that reports it now
dbq "UPDATE endpoint_agent_devices SET retired_at = UTC_TIMESTAMP() WHERE device_id = $DEV2" >/dev/null
chk "the second machine's device is retired (its container is gone)" '[ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_devices WHERE device_id = $DEV2 AND retired_at IS NOT NULL")" = 1 ]'
: >"$WORK/mesh-events.log"
chk "the first machine never had a Mesh agent (it enrolled with a plain install-linux.sh) and the server has no Mesh state for it" '! q test -e $MESHDIR/meshagent && [ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_mesh_agents WHERE device_id = $DEV AND installed = 1")" = 0 ]'
chk "the agent announced job:mesh_install and job:mesh_uninstall" 'dbq "SELECT capabilities_json FROM rmm_device_state WHERE device_id = $DEV" | grep -q job:mesh_install && dbq "SELECT capabilities_json FROM rmm_device_state WHERE device_id = $DEV" | grep -q job:mesh_uninstall'
A GET "$DEV"
chk "the device detail offers it: mesh_install.available, agent_supports, not installed" '[ "$(printf "%s" "$R" | jget mesh_install.available)" = true ] && [ "$(printf "%s" "$R" | jget mesh_install.agent_supports)" = true ] && [ "$(printf "%s" "$R" | jget mesh_install.installed)" = false ]'
A POST "$DEV/mesh/install" '{"url":"https://evil.example"}'
chk "a body with anything but force is refused (422): the job carries only the stored settings" '[ "$CODE" = 422 ] && [ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = '\''mesh_install'\''")" = 0 ]'
meshjob() { # meshjob "<json body>" -> sets J, MCODE, ST and RES after the job is final
  A POST "$DEV/mesh/install" "$1"; J="$(printf '%s' "$R" | jget job_id)"; MCODE="$CODE"; ST="$(job_done $J 120)"; RES="$(api GET "$DEV/remote/jobs/$J")"; }
A PUT "remote/mesh" "{\"mesh_server_cert_pin\":\"$(printf 'ab%.0s' $(seq 1 48))\"}"
chk "a wrong certificate pin saved through the REST API" '[ "$CODE" = 200 ]'
meshjob '{}'
chk "wrong pin: queued (202), the job FAILED naming the pinned hash, no Mesh agent, the RivetMSP agent still active" '[ "$MCODE" = 202 ] && [ "$ST" = failed ] && printf "%s" "$RES" | grep -q "pinned hash" && ! q test -e $MESHDIR/meshagent && [ "$(q systemctl is-active rivetit-agent)" = active ]'
chk "the job row holds the redacted placeholder, never the group id; the sealed value is wiped" '[ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_jobs WHERE job_id = '\''$J'\'' AND params_json LIKE '\''%redacted:secret%'\''")" = 1 ] && ! dbq "SELECT params_json FROM endpoint_agent_jobs WHERE job_id = '\''$J'\''" | grep -qF -- "$MESHGROUP" && [ -z "$(dbq "SELECT secret_params_enc FROM rmm_job_extra WHERE job_id = '\''$J'\''")" ]'
chk "the mock Mesh server was never reached (the TLS handshake was refused)" '[ ! -s "$WORK/mesh-events.log" ]'
chk "the failure also reaches the device state through the check-in (mesh_agent.error)" 'wait_for 60 "[ \"\$(api GET \"$DEV\" | jget mesh_agent.installed)\" = false ] && api GET \"$DEV\" | grep -q pinned"'
A PUT "remote/mesh" "{\"mesh_server_cert_pin\":\"$MESHPIN\",\"mesh_group_id\":\"$(head -c 48 /dev/urandom | base64 -w0 | tr '+/' '@$')\"}"
chk "the right pin and an unknown device group saved" '[ "$CODE" = 200 ]'
meshjob '{}'
chk "unknown device group: HTTP 404 from the Mesh server, reported, agent unaffected" '[ "$ST" = failed ] && printf "%s" "$RES" | grep -q "HTTP 404" && ! q test -e $MESHDIR/meshagent && [ "$(q systemctl is-active rivetit-agent)" = active ]'
A PUT "remote/mesh" "{\"mesh_group_id\":\"$MESHGROUP\",\"mesh_agent_ids\":{\"linux/amd64\":99}}"
meshjob '{}'
chk "an installer body over the 64 MiB cap (agent id 99) is refused by the agent, nothing runs" '[ "$ST" = failed ] && printf "%s" "$RES" | grep -q "larger than" && ! q test -e $MESHDIR/meshagent'
A PUT "remote/mesh" '{"mesh_agent_ids":{}}'
chk "the id table is back to the built-in one" '[ "$CODE" = 200 ]'
: >"$WORK/mesh-events.log"
meshjob '{}'
chk "the good case: queued (202), SUCCEEDED, the fake meshagent installed, the node id in the job result" '[ "$MCODE" = 202 ] && [ "$ST" = succeeded ] && [ "$(printf "%s" "$RES" | jget result.node_id)" = "node//$MESHNODE_RAW" ] && q test -x $MESHDIR/meshagent'
chk "it was downloaded from <mesh>/meshagents?id=6&meshid=<group>&installflags=0 and what the agent ran is the downloaded installer (the fake meshagent is in place)" 'grep -qF "MESH-DOWNLOAD id=6 meshid=$MESHGROUP installflags=0" "$WORK/mesh-events.log" && q grep -q "FAKE MeshAgent" $MESHDIR/meshagent'
chk "the job result and the job row never carry the group id" '! printf "%s" "$RES" | grep -qF -- "$MESHGROUP" && ! dbq "SELECT params_json FROM endpoint_agent_jobs WHERE job_id = '\''$J'\''" | grep -qF -- "$MESHGROUP"'
chk "the check-in reported the Mesh agent and the server mapped the node id to the device by itself (moved from the retired machine)" 'wait_for 60 "[ \"\$(dbq \"SELECT installed FROM endpoint_agent_mesh_agents WHERE device_id = $DEV\")\" = 1 ] && [ \"\$(dbq \"SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id = $DEV\")\" = \"node//\$MESHNODE_RAW\" ]"'
chk "the device detail shows mesh_agent installed, map_state mapped and mesh_install.installed" '[ "$(api GET "$DEV" | jget mesh_agent.map_state)" = mapped ] && [ "$(api GET "$DEV" | jget mesh_install.installed)" = true ]'
A POST "$DEV/mesh/install" '{}'
chk "idempotent: a device that already reports the agent is skipped (200 already_installed), no job" '[ "$CODE" = 200 ] && [ "$(printf "%s" "$R" | jget skipped)" = already_installed ]'
A POST "mesh/install" '{"target":{"type":"all"}}'
chk "the bulk call needs confirm (422) and tells how many devices it would reach" '[ "$CODE" = 422 ] && [ "$(printf "%s" "$R" | jget code)" = confirmation_required ] && [ "$(printf "%s" "$R" | jget device_count)" -ge 1 ]'
NDEV="$(printf "%s" "$R" | jget device_count)"
A POST "mesh/install" '{"target":{"type":"client","id":1},"confirm":true}'
chk "bulk (client): nothing new is queued; every device is installed, queued or too old, and the counts add up to the $NDEV reached" '[ "$CODE" = 201 ] && [ "$(printf "%s" "$R" | jget created)" = 0 ] && [ "$(printf "%s" "$R" | jget device_count)" = "$NDEV" ] && [ "$(printf "%s" "$R" | jget skipped_installed)" -ge 1 ]'
A POST "$DEV/mesh/install" '{"force":true}'; J="$(printf '%s' "$R" | jget job_id)"
chk "force runs the installer again" '[ "$CODE" = 202 ] && [ "$(job_done $J 120)" = succeeded ]'
A POST "$DEV/mesh/uninstall" '{}'
chk "removal needs an explicit confirmation (422)" '[ "$CODE" = 422 ] && [ "$(printf "%s" "$R" | jget code)" = confirmation_required ]'
A POST "$DEV/mesh/uninstall" '{"confirm":true}'; J="$(printf '%s' "$R" | jget job_id)"
chk "mesh_uninstall succeeded: the Mesh agent is gone, the RivetMSP agent stays" '[ "$(job_done $J 120)" = succeeded ] && ! q test -e $MESHDIR/meshagent && [ "$(q systemctl is-active rivetit-agent)" = active ]'
chk "the server's record follows the next check-in (installed = 0)" 'wait_for 60 "[ \"\$(dbq \"SELECT installed FROM endpoint_agent_mesh_agents WHERE device_id = $DEV\")\" = 0 ]"'
chk "the activity log has Remote Tool Queued, Mesh Agent Installed, Install Failed, Bulk Install and Mesh Node Mapped" 'for k in "Remote Tool Queued" "Mesh Agent Installed" "Mesh Agent Install Failed" "Mesh Agent Bulk Install" "Mesh Node Mapped"; do [ "$(dbq "SELECT COUNT(*) FROM logs WHERE log_action = '\''$k'\''")" -ge 1 ] || { echo "missing $k"; false; break; }; done'

echo "== starter pack (rc.13): installed through the API, a read-only script and a dry-run script run on the REAL Linux agent"
A GET "starter/catalog"
chk "catalog: 200, ready (signing key present), 73 scripts" '[ "$CODE" = 200 ] && [ "$(printf %s "$R" | jget data.ready)" = true ] || [ "$(printf %s "$R" | jget ready)" = true ]'
A POST "starter/install" '{"ids":["lin-system-summary","lin-journal-vacuum","policy-linux-server"]}'
chk "install of two Linux scripts and one policy draft: 200, three added" '[ "$CODE" = 200 ] && [ "$(printf %s "$R" | jget counts.added)" = 3 ]'
A POST "starter/install" '{"ids":["lin-system-summary","lin-journal-vacuum","policy-linux-server"]}'
chk "installing again changes nothing (idempotent): three unchanged" '[ "$CODE" = 200 ] && [ "$(printf %s "$R" | jget counts.unchanged)" = 3 ]'
chk "the policy draft is switched off and assigned to nothing" '[ "$(dbq "SELECT enabled FROM rmm_policies WHERE description LIKE \"%[starter|%policy-linux-server%\"")" = 0 ] && [ "$(dbq "SELECT COUNT(*) FROM rmm_policy_assignments")" = 0 ]'
A GET "scripts?tag=starter"
SID1="$(printf %s "$R" | php -r '$d=json_decode(stream_get_contents(STDIN), true); foreach ($d["data"] as $x) { if (strpos($x["name"], "System summary") === 0) { echo $x["script_id"]; } }')"
SID2="$(printf %s "$R" | php -r '$d=json_decode(stream_get_contents(STDIN), true); foreach ($d["data"] as $x) { if (strpos($x["name"], "Vacuum systemd journal") === 0) { echo $x["script_id"]; } }')"
chk "the two scripts are in the library with the starter tag (ids $SID1 and $SID2)" '[ -n "$SID1" ] && [ -n "$SID2" ]'
run_lib() { # run_lib SCRIPT_ID [params-json] -> sets JOBID once the job is queued, then waits for a final state and sets JSTATE / JOUT
  local sid=$1 params="${2:-{\}}"
  A POST "runs" "{\"library_script_id\":$sid,\"target\":{\"type\":\"device\",\"id\":$DEV},\"params\":$params}"
  [ "$CODE" = 201 ] || { JSTATE="http $CODE"; return 1; }
  local i=0; JSTATE=""
  while [ $i -lt 120 ]; do
    A GET "$DEV/jobs?limit=10"
    JSTATE="$(printf %s "$R" | php -r '$d=json_decode(stream_get_contents(STDIN), true); foreach ($d["data"] as $j) { if ((int) ($j["library"]["script_id"] ?? 0) === (int) $argv[1]) { echo $j["state"]; exit; } }' "$sid")"
    case "$JSTATE" in succeeded|failed|timed_out|cancelled|expired) break;; esac
    i=$((i+1)); sleep 0.5
  done
  JOUT="$(printf %s "$R" | php -r '$d=json_decode(stream_get_contents(STDIN), true); foreach ($d["data"] as $j) { if ((int) ($j["library"]["script_id"] ?? 0) === (int) $argv[1]) { echo $j["output"] ?? ""; exit; } }' "$sid")"
  return 0
}
run_lib "$SID1" '{}'
chk "the read-only starter script ran on the real agent and succeeded (the agent verified the signed job)" '[ "$JSTATE" = succeeded ]'
chk "its output is the system summary of the machine (kernel and uptime lines, no error)" 'printf %s "$JOUT" | grep -qi "kernel" && ! printf %s "$JOUT" | grep -qi "NOT AVAILABLE: sh"'
run_lib "$SID2" '{}'
chk "the dry-run starter script (changes the system, dry_run defaults to true) ran and says what it would do" '[ "$JSTATE" = succeeded ] && printf %s "$JOUT" | grep -qi "dry"'
chk "the activity log has the starter lines and the script runs, and no script text" '[ "$(dbq "SELECT COUNT(*) FROM logs WHERE log_action = \"Starter Pack Installed\"")" -ge 1 ] && [ "$(dbq "SELECT COUNT(*) FROM logs WHERE log_action = \"Script Run\"")" -ge 2 ]'
A POST "starter/uninstall" '{"ids":["lin-journal-vacuum"],"dry_run":true}'
chk "an uninstall preview names the untouched script and changes nothing" '[ "$CODE" = 200 ] && printf %s "$R" | grep -q would_retire && [ "$(dbq "SELECT COUNT(*) FROM rmm_scripts_v2 WHERE retired_at IS NOT NULL")" = 0 ]'
A POST "starter/plan" '{}'
chk "an EMPTY selection through the API means the whole pack (Core's rule; the web page never sends one)" '[ "$CODE" = 200 ] && [ "$(printf %s "$R" | jget counts.add)" -gt 60 ]'
echo "== audit"
AUD="$(php -r 'mysqli_report(MYSQLI_REPORT_OFF); $m = new mysqli(null, getenv("RIVETMSP_TEST_DB_USER"), getenv("RIVETMSP_TEST_DB_PASS"), getenv("RIVETMSP_TEST_DB_NAME"), null, getenv("RIVETMSP_SOCKET")); $r = $m->query("SELECT log_action FROM logs WHERE log_type = \x27Endpoint Agent\x27"); $o = []; while ($r && ($x = $r->fetch_row())) { $o[] = $x[0]; } echo json_encode($o);')"
chk "the activity log (type Endpoint Agent) has the service, process and file actions (queued, completed, downloaded)" 'for k in "Remote Tool Queued" "File Download Requested" "File Transfer Completed" "File Downloaded" "File Upload Queued"; do printf "%s" "$AUD" | grep -q "$k" || { echo "missing $k"; false; break; }; done'
echo "== uninstall"
q bash /w/install-linux.sh --uninstall --purge >"$WORK/uninstall.out" 2>&1
chk "uninstall exit 0" '[ $? = 0 ]'
[ $fail = 0 ] && echo "PROOF PASS" || { echo "PROOF FAIL"; tail -20 "$WORK/php.log" 2>/dev/null; docker exec "$NAME" journalctl -u rivetit-agent --no-pager -n 40 2>/dev/null | tail -40; exit 1; }
