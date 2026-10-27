<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * MSP-specific tenant-isolation adversarial test for RivetCore 1.0.0-rc.40's log/change denormalisation
 * (migration 0043: nullable client_id on endpoint_agent_log_events/endpoint_agent_change_records/
 * endpoint_agent_change_state). Proves, against the REAL code (RmmModule::logActions()->fleetLogs() /
 * changeActions()->fleetChanges(), the exact path agent/rmm_logs.php and the change-monitoring page use),
 * not just by reading Core's source:
 *
 *   1. A technician scoped to Client A (a real user_client_permissions row) NEVER sees Client B's log or
 *      change rows, even though both exist in the SAME physical table (the store is MariaDB; a store-level
 *      SQL IN(...) bug here would leak rows across tenants).
 *   2. A row with client_id = NULL (a device whose client was never backfilled, or a future bug) is
 *      visible to NOBODY scoped by client - confirmed explicitly, not assumed - and IS visible to an
 *      unrestricted/admin caller (matching migration 0043's own documented intent).
 *   3. Moving a device between clients via DeviceService::transfer() (RmmModule::technician()->transfer(),
 *      the ONLY call site in this edition that changes endpoint_agent_devices.client_id - confirmed by
 *      grepping the whole edition for any other UPDATE of that column) actually moves its log AND change
 *      history: the OLD client's technician loses visibility, the NEW client's technician gains it, in the
 *      same physical rows (not a copy).
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/rmm_tenant_isolation_client_scope.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/rmm_bootstrap.php";

$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$q("UPDATE endpoint_agent_settings SET enabled = 1");
rivetRmmModule($mysqli)->syncState();

echo "\n--- fixtures ---\n";
$q("DELETE FROM endpoint_agent_log_events WHERE message LIKE 'tiso-test-%'");
$q("DELETE FROM endpoint_agent_change_records WHERE summary LIKE 'tiso-test-%'");
$q("DELETE FROM endpoint_agent_devices WHERE hostname LIKE 'tiso-test-%'");
$q("DELETE FROM user_client_permissions WHERE user_id IN (SELECT user_id FROM users WHERE user_email LIKE 'tiso-test-%')");
$q("DELETE FROM users WHERE user_email LIKE 'tiso-test-%'");
$q("DELETE FROM user_roles WHERE role_name LIKE 'tiso-test-%'");
$q("DELETE FROM clients WHERE client_name LIKE 'tiso-test-%'");

$q("INSERT INTO clients SET client_name='tiso-test-ClientA'");
$clientA = (int) $db->insert_id;
$q("INSERT INTO clients SET client_name='tiso-test-ClientB'");
$clientB = (int) $db->insert_id;

// A real non-admin role with exactly the RMM log/change permissions fleetLogs()/fleetChanges() require
// (module_rmm >= 1 for view, module_rmm_scripts >= 2 for JOB_RUN_SAVED/CHANGE_VIEW - see
// src/Core/Adapter/Endpoint/EndpointAccessPolicy.php's own doc comment for the exact matrix).
$q("INSERT INTO user_roles SET role_name='tiso-test-role', role_is_admin=0, role_type=1");
$roleId = (int) $db->insert_id;
foreach (['module_rmm', 'module_rmm_scripts'] as $mod) {
    $modId = (int) $one("SELECT module_id FROM modules WHERE module_name = '$mod'");
    if ($modId > 0) {
        $q("INSERT INTO user_role_permissions SET user_role_id=$roleId, module_id=$modId, user_role_permission_level=3");
    }
}

function tisoMakeTech(mysqli $db, callable $q, int $roleId, string $label, int $scopedToClient): int {
    $q("INSERT INTO users SET user_name='tiso-test-$label', user_email='tiso-test-$label@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$roleId");
    $uid = (int) $db->insert_id;
    if ($scopedToClient > 0) {
        $q("INSERT INTO user_client_permissions SET user_id=$uid, client_id=$scopedToClient");
    }
    return $uid;
}
$techA = tisoMakeTech($db, $q, $roleId, 'TechA', $clientA);           // scoped to Client A only
$techB = tisoMakeTech($db, $q, $roleId, 'TechB', $clientB);           // scoped to Client B only
$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = (SELECT role_id FROM user_roles WHERE role_is_admin = 1 LIMIT 1) LIMIT 1");
if (!$admin) {
    $q("INSERT INTO user_roles SET role_name='tiso-test-admin-role', role_is_admin=1, role_type=1");
    $adminRole = (int) $db->insert_id;
    $q("INSERT INTO users SET user_name='tiso-test-admin', user_email='tiso-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$adminRole");
    $admin = (int) $db->insert_id;
}

$q("INSERT INTO endpoint_agent_devices SET client_id=$clientA, hostname='tiso-test-DeviceA', agent_version='1.0', link_state='linked', os='windows', install_id='tiso-a-" . bin2hex(random_bytes(4)) . "', token_hash='x', created_at=UTC_TIMESTAMP(), first_seen_at=UTC_TIMESTAMP()");
$deviceA = (int) $db->insert_id;
$q("INSERT INTO endpoint_agent_devices SET client_id=$clientB, hostname='tiso-test-DeviceB', agent_version='1.0', link_state='linked', os='windows', install_id='tiso-b-" . bin2hex(random_bytes(4)) . "', token_hash='x', created_at=UTC_TIMESTAMP(), first_seen_at=UTC_TIMESTAMP()");
$deviceB = (int) $db->insert_id;
// A device whose client_id on the LOG/CHANGE rows is NULL - simulating a row an older release wrote before
// migration 0043 backfilled it (migration 0043's own doc comment: "a row that still has NULL... shows to
// an unscoped reader and to nobody scoped by client"). Its own endpoint_agent_devices row still has a real
// client (clientA) - only the log/change ROWS themselves are deliberately left NULL here.
$q("INSERT INTO endpoint_agent_devices SET client_id=$clientA, hostname='tiso-test-DeviceOrphanRows', agent_version='1.0', link_state='linked', os='windows', install_id='tiso-o-" . bin2hex(random_bytes(4)) . "', token_hash='x', created_at=UTC_TIMESTAMP(), first_seen_at=UTC_TIMESTAMP()");
$deviceOrphan = (int) $db->insert_id;

function tisoInsertLog(mysqli $db, callable $q, callable $esc, int $deviceId, string $msg, $clientIdSql): void {
    $dedupe = bin2hex(random_bytes(16));
    $q("INSERT INTO endpoint_agent_log_events SET device_id=$deviceId, occurred_at=UTC_TIMESTAMP(), received_at=UTC_TIMESTAMP(), level=6, source='app', message='" . $esc($msg) . "', dedupe=UNHEX('$dedupe'), client_id=$clientIdSql");
}
function tisoInsertChange(mysqli $db, callable $q, callable $esc, int $deviceId, string $summary, $clientIdSql): void {
    $dedupe = bin2hex(random_bytes(16));
    $q("INSERT INTO endpoint_agent_change_records SET device_id=$deviceId, occurred_at=UTC_TIMESTAMP(), received_at=UTC_TIMESTAMP(), kind='registry', watch='w', change_type=1, summary='" . $esc($summary) . "', dedupe=UNHEX('$dedupe'), client_id=$clientIdSql");
}

tisoInsertLog($db, $q, $esc, $deviceA, 'tiso-test-log-on-A', $clientA);
tisoInsertLog($db, $q, $esc, $deviceB, 'tiso-test-log-on-B', $clientB);
tisoInsertLog($db, $q, $esc, $deviceOrphan, 'tiso-test-log-NULL-client', 'NULL');
tisoInsertChange($db, $q, $esc, $deviceA, 'tiso-test-change-on-A', $clientA);
tisoInsertChange($db, $q, $esc, $deviceB, 'tiso-test-change-on-B', $clientB);
tisoInsertChange($db, $q, $esc, $deviceOrphan, 'tiso-test-change-NULL-client', 'NULL');

$rmm = rivetRmmModule($mysqli);
$from = gmdate('Y-m-d H:i:s', time() - 3600);
$to = gmdate('Y-m-d H:i:s', time() + 3600);

echo "\n--- Client A's technician never sees Client B's or the NULL-client log rows ---\n";
$whoA = rivetRmmPrincipal($techA, 'TechA');
$r = $rmm->logActions()->fleetLogs($whoA, ['from' => $from, 'to' => $to], 50, 0);
$ok($r->ok, 'fleetLogs() succeeds for a client-A-scoped technician: ' . ($r->ok ? '' : $r->message));
$msgs = array_column($r->data['items'] ?? [], 'message');
$ok(in_array('tiso-test-log-on-A', $msgs, true), "Client A's own log row IS visible to Client A's technician");
$ok(!in_array('tiso-test-log-on-B', $msgs, true), "Client B's log row is NEVER visible to Client A's technician");
$ok(!in_array('tiso-test-log-NULL-client', $msgs, true), "a NULL-client log row is NEVER visible to a client-scoped technician (confirmed explicitly, not assumed)");

echo "\n--- Client B's technician symmetrically never sees Client A's or the NULL-client rows ---\n";
$whoB = rivetRmmPrincipal($techB, 'TechB');
$rB = $rmm->logActions()->fleetLogs($whoB, ['from' => $from, 'to' => $to], 50, 0);
$msgsB = array_column($rB->data['items'] ?? [], 'message');
$ok(in_array('tiso-test-log-on-B', $msgsB, true), "Client B's own log row IS visible to Client B's technician");
$ok(!in_array('tiso-test-log-on-A', $msgsB, true), "Client A's log row is NEVER visible to Client B's technician");
$ok(!in_array('tiso-test-log-NULL-client', $msgsB, true), "the NULL-client log row is NEVER visible to Client B's technician either");

echo "\n--- an unrestricted (admin) caller sees everything, including the NULL-client row ---\n";
$whoAdmin = rivetRmmPrincipal($admin, 'Admin');
$rAdmin = $rmm->logActions()->fleetLogs($whoAdmin, ['from' => $from, 'to' => $to], 50, 0);
$msgsAdmin = array_column($rAdmin->data['items'] ?? [], 'message');
$ok(in_array('tiso-test-log-on-A', $msgsAdmin, true) && in_array('tiso-test-log-on-B', $msgsAdmin, true), 'an unrestricted admin sees both clients\' log rows');
$ok(in_array('tiso-test-log-NULL-client', $msgsAdmin, true), 'an unrestricted admin ALSO sees the NULL-client row (matches migration 0043\'s documented intent: "shows to an unscoped reader")');

echo "\n--- the SAME isolation holds for change records, not just logs ---\n";
$rc = $rmm->changeActions()->fleetChanges($whoA, ['from' => $from, 'to' => $to], 50, 0);
$ok($rc->ok, 'fleetChanges() succeeds for Client A\'s technician: ' . ($rc->ok ? '' : $rc->message));
$sums = array_column($rc->data['items'] ?? [], 'summary');
$ok(in_array('tiso-test-change-on-A', $sums, true), "Client A's own change row IS visible to Client A's technician");
$ok(!in_array('tiso-test-change-on-B', $sums, true), "Client B's change row is NEVER visible to Client A's technician");
$ok(!in_array('tiso-test-change-NULL-client', $sums, true), "a NULL-client change row is NEVER visible to a client-scoped technician");

echo "\n--- DeviceService::transfer() (the ONLY call site that changes a device's client) actually moves log/change history ---\n";
$rmm->technician()->transfer(rivetRmmPrincipal($admin, 'Admin'), $deviceA, $clientB);
$row = mysqli_fetch_assoc(mysqli_query($db, "SELECT client_id FROM endpoint_agent_devices WHERE device_id = $deviceA"));
$ok((int) $row['client_id'] === $clientB, 'the device itself moved to Client B');
$logRow = mysqli_fetch_assoc(mysqli_query($db, "SELECT client_id FROM endpoint_agent_log_events WHERE device_id = $deviceA AND message = 'tiso-test-log-on-A'"));
$ok((int) $logRow['client_id'] === $clientB, "the device's EXISTING log row's client_id was updated to Client B by the transfer (reassignClient()), not left behind");
$chgRow = mysqli_fetch_assoc(mysqli_query($db, "SELECT client_id FROM endpoint_agent_change_records WHERE device_id = $deviceA AND summary = 'tiso-test-change-on-A'"));
$ok((int) $chgRow['client_id'] === $clientB, "the device's EXISTING change row's client_id was updated to Client B too");

echo "\n--- visibility flips with the move: old client loses it, new client gains it, same physical row ---\n";
$rAfterA = $rmm->logActions()->fleetLogs($whoA, ['from' => $from, 'to' => $to], 50, 0);
$msgsAfterA = array_column($rAfterA->data['items'] ?? [], 'message');
$ok(!in_array('tiso-test-log-on-A', $msgsAfterA, true), "after the move, Client A's technician no longer sees the (now Client B's) log row");
$rAfterB = $rmm->logActions()->fleetLogs($whoB, ['from' => $from, 'to' => $to], 50, 0);
$msgsAfterB = array_column($rAfterB->data['items'] ?? [], 'message');
$ok(in_array('tiso-test-log-on-A', $msgsAfterB, true), "after the move, Client B's technician NOW sees that same row (its own id did not change - reassignClient() updated the existing row, not a copy)");
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_log_events WHERE device_id = $deviceA AND message = 'tiso-test-log-on-A'") === 1, 'exactly one physical row for it - the move updated in place, it did not duplicate');
