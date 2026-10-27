<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Explicit, consolidated adversarial test for one question (2026-10-26 security audit, item 3 of the newer request): can one MSP
 * client's RMM agent authenticate as, or submit data under, a DIFFERENT client's device/tenant context?
 *
 * This is deliberately narrower than tests/endpoint_agent_smoke.php's authorization matrix (which proves a HUMAN technician restricted
 * to one client cannot act on another client's device through the web UI / technician REST API). Here the attacker is the DEVICE
 * CREDENTIAL itself: a real, valid, un-revoked device bearer token for client A's own enrolled agent, used against the device-facing
 * endpoints (api/v1/agent_checkin, agent_jobs) with forged fields in the request body, and a cross-client identity-reuse attempt at
 * enrollment time. By design (RivetCore\Rmm\Device\DeviceRepository::authenticate(), RivetCore\Rmm\Http\DeviceApi, JobService), the
 * device's client/tenant binding comes ONLY from the SHA-256 hash of the bearer token looked up against endpoint_agent_devices.client_id
 * - never from a client/device id in the request body, which the wire protocol does not even define. This test proves that holds over
 * real HTTP, not just by code reading.
 *
 * Same scratch-DB rules and harness as tests/endpoint_agent_smoke.php.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=rivetmsp_scratch_x RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/rmm_tenant_isolation.php
 */
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
require_once "$root/includes/rmm_functions.php";
use RivetCore\Rmm\Authz\RmmPrincipal;

ea_reset();
$tokens = ea_seed_users();
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$r = $rmm->admin()->enable($admin);
$ok($r->ok && $rmm->enabled(), 'the module is switched on');
$rmm->admin()->saveSettings($admin, ['service_url' => $base]);

// ---------------------------------------------------------------- two clients, each with their own client-scoped enrollment token. Each
// device is pre-matched to an asset of its OWN client (by serial) so enrollment auto-links it and TechnicianActions::queueJob() (which
// requires a device linked to an asset) can queue jobs on it - exactly tests/endpoint_agent_smoke.php's pattern.
$tokA = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 5, 'tenantA')->data['token'];
$tokB = (string) $rmm->technician()->createToken($admin, 2, 0, 'stable', 24, 5, 'tenantB')->data['token'];

$q("INSERT INTO assets SET asset_type='Laptop', asset_name='TENANT-A-ASSET', asset_make='Dell', asset_serial='TISO-SER-A', asset_client_id=1, asset_status='Active'");
$assetIdA = (int) $db->insert_id;
$q("INSERT INTO assets SET asset_type='Laptop', asset_name='TENANT-B-ASSET', asset_make='Dell', asset_serial='TISO-SER-B', asset_client_id=2, asset_status='Active'");
$assetIdB = (int) $db->insert_id;

[$c, , $jA] = ea_enroll($tokA, ea_dev(['hostname' => 'TENANT-A-PC', 'serial' => 'TISO-SER-A']));
$ok($c === 201 && ($jA['status'] ?? '') === 'linked', 'client A: device enrolls and links to its own asset (' . $c . ')');
$devTokA = (string) $jA['device_token']; $devIdA = (int) $jA['device_id'];

[$c, , $jB] = ea_enroll($tokB, ea_dev(['hostname' => 'TENANT-B-PC', 'serial' => 'TISO-SER-B']));
$ok($c === 201 && ($jB['status'] ?? '') === 'linked', 'client B: device enrolls and links to its own asset (' . $c . ')');
$devTokB = (string) $jB['device_token']; $devIdB = (int) $jB['device_id'];

$ok((int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = $devIdA") === 1, 'device A is bound to client 1 (Client A) in the database');
$ok((int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = $devIdB") === 2, 'device B is bound to client 2 (Client B) in the database');

// ---------------------------------------------------------------- 1. a forged client_id/device_id in the check-in body changes nothing: the
//    credential alone decides which row is updated. The wire protocol does not even define these fields, so this also proves extra,
//    unexpected keys are silently ignored rather than trusted.
$beforeA = $rows("SELECT last_checkin_at, client_id FROM endpoint_agent_devices WHERE device_id = $devIdA")[0];
$beforeB = $rows("SELECT last_checkin_at, client_id FROM endpoint_agent_devices WHERE device_id = $devIdB")[0];
[$c, , $j] = ea_checkin($devTokA, ['device_id' => $devIdB, 'client_id' => 2, 'asset_id' => 999999]);
$ok($c === 200 && ($j['ok'] ?? false) === true, 'check-in with device A\'s own credential but a forged device_id/client_id in the body: still 200');
$afterA = $rows("SELECT last_checkin_at, client_id FROM endpoint_agent_devices WHERE device_id = $devIdA")[0];
$afterB = $rows("SELECT last_checkin_at, client_id FROM endpoint_agent_devices WHERE device_id = $devIdB")[0];
$ok($afterA['last_checkin_at'] !== $beforeA['last_checkin_at'], 'only device A\'s own row was touched by the check-in...');
$ok($afterB['last_checkin_at'] === $beforeB['last_checkin_at'], '...device B\'s row is byte-for-byte unaffected (no last_checkin_at change)');
$ok((int) $afterA['client_id'] === 1 && (int) $afterB['client_id'] === 2, 'neither device\'s client_id moved: the forged body field had no effect at all');

// ---------------------------------------------------------------- 2. job isolation: a job queued for device B is never offered to, reportable
//    by, or visible through device A's credential, even by guessing/forging the job_id.
[$c, , $j] = http('POST', "/api/v1/endpoint_devices/$devIdB/jobs", $tokens['admin'], ['type' => 'reboot', 'confirm' => true]);
$jobIdB = (string) ($j['job_id'] ?? ($j['data']['job_id'] ?? ''));
$ok(in_array($c, [200, 201, 202], true) && $jobIdB !== '', 'a reboot job is queued for device B via the technician API (' . $c . ')');

[$c, , $j] = http('GET', '/api/v1/agent_jobs', $devTokA);
$offeredToA = array_column($j['jobs'] ?? [], 'job_id');
$ok($c === 200 && !in_array($jobIdB, $offeredToA, true), 'device A\'s own long-poll GET never offers device B\'s queued job (its SQL is scoped WHERE device_id = <A>)');

[$c, , $j] = http('GET', '/api/v1/agent_jobs', $devTokB);
$offeredToB = array_column($j['jobs'] ?? [], 'job_id');
$ok($c === 200 && in_array($jobIdB, $offeredToB, true), 'sanity: device B\'s own credential IS offered its own job');

// device A tries to report completion of device B's real job id using its own (device A's) credential
[$c, , $j] = ea_checkin($devTokA);  // not the job endpoint; left as a baseline no-op
[$c, , $j] = http('POST', '/api/v1/agent_jobs', $devTokA, ['job_id' => $jobIdB, 'attempt' => 1, 'state' => 'succeeded', 'exit_code' => 0, 'output' => 'pwned']);
$ok($c === 404, 'device A reporting device B\'s real job_id as its own: 404 not_found (JobService::report() requires job_id AND device_id = <credential>)');
$jobStateB = (string) $one("SELECT state FROM endpoint_agent_jobs WHERE job_id = '" . $esc($jobIdB) . "'");
$ok($jobStateB !== 'success' && $jobStateB !== 'failed', 'device B\'s job was NOT marked complete by device A\'s forged report (state is still ' . $jobStateB . ')');

// ---------------------------------------------------------------- 3. enrollment-time identity reuse across clients never hijacks the other
//    client's device row. A cloned image / stolen hardware fingerprint re-enrolling under a DIFFERENT client's token becomes a brand new
//    device of the attacker's OWN client, never a takeover of the victim's row or credential.
$sharedSerial = 'SHARED-SER-' . bin2hex(random_bytes(3));
[$c, , $jVictim] = ea_enroll($tokA, ea_dev(['hostname' => 'VICTIM-PC', 'serial' => $sharedSerial]));
$ok($c === 201, 'client A enrolls a device with a distinctive serial (' . $c . ')');
$victimDevId = (int) $jVictim['device_id']; $victimDevTok = (string) $jVictim['device_token'];
$victimHashBefore = (string) $one("SELECT token_hash FROM endpoint_agent_devices WHERE device_id = $victimDevId");

// Client B's enrollment token, a different install_id, but the SAME serial: an attacker presenting the victim's hardware identity under
// a token they actually hold for a DIFFERENT client.
[$c, , $jAttack] = ea_enroll($tokB, ea_dev(['hostname' => 'ATTACKER-CLONE', 'serial' => $sharedSerial]));
$ok($c === 201, 'client B enrolls a device claiming the SAME serial as client A\'s device (' . $c . ')');
$attackDevId = (int) ($jAttack['device_id'] ?? 0);
$ok($attackDevId !== $victimDevId, 'this is a NEW device row, not a takeover of client A\'s existing device_id');
$ok((int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = $attackDevId") === 2, 'the new row belongs to client B (the token\'s own client), never client A');
$ok((string) $one("SELECT token_hash FROM endpoint_agent_devices WHERE device_id = $victimDevId") === $victimHashBefore, 'client A\'s victim device credential (token_hash) is completely unchanged');

// The victim's original credential still works and still reports as client A.
[$c, , $j] = ea_checkin($victimDevTok);
$ok($c === 200 && ($j['ok'] ?? false) === true, 'client A\'s original device credential still checks in normally after the clone attempt');
$ok((int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = $victimDevId") === 1, 'and is still client A\'s, never reassigned');

// ---------------------------------------------------------------- 4. a device token is never accepted as any OTHER kind of credential, and a
//    raw enrollment token is never accepted as a device bearer token (confusing the two would also cross tenant boundaries).
[$c, , $j] = http('POST', '/api/v1/agent_checkin', $tokA, ['seq' => 1, 'collected_at' => ea_ts(), 'agent_version' => '1.0.0']);
$ok($c === 401 && ($j['code'] ?? '') === 'invalid_token', 'a raw ENROLLMENT token (client A\'s) is refused as a device check-in credential (401 invalid_token)');
[$c, , $j] = http('POST', '/api/v1/agent_checkin', 'not-a-real-token-' . bin2hex(random_bytes(8)), ['seq' => 1, 'collected_at' => ea_ts(), 'agent_version' => '1.0.0']);
$ok($c === 401 && ($j['code'] ?? '') === 'invalid_token', 'a garbage bearer token is refused (401 invalid_token), never treated as belonging to any client');

echo "\nSummary: a device credential is cryptographically bound (SHA-256 token_hash -> endpoint_agent_devices.client_id) to exactly one\n";
echo "client/tenant. No field in any device-facing request body (agent_checkin, agent_jobs) is read as a device or client identifier;\n";
echo "the only identity a request carries is the credential itself. Cross-client identity reuse at enrollment creates a new, separate\n";
echo "device under the enrolling token's own client and never adopts or mutates another client's existing row or credential.\n";
