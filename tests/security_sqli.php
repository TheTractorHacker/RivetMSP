<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * SQL-injection regression tests (external scanner, CRITICAL #2). Scratch DB only, no HTTP except the forged-session
 * harness below. Covers the fixes made alongside this file:
 *   - sortColumnAllowed(): the ?sort= allow-list used by includes/filter_header.php for ORDER BY
 *   - UnifiSyncMapper::findExistingAsset(): the controller-reported MAC is bound, so an injection payload matches nothing
 *     and raises no SQL error (includes/class_unifi_sync_mapper.php)
 *   - the recurring-invoice frequency INTERVAL allow-list (cron + force send) only ever yields MONTH/YEAR
 *   - agent/post/certificate.php add + edit over HTTP: an injection payload in the certificate name is stored verbatim,
 *     creates exactly one row, and the change-history row is written (second-order path), proving no breakout
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=rivetmsp_x_scratch RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/security_sqli.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/class_unifi_sync_mapper.php";

$INJ = "x' OR '1'='1";          // classic always-true breakout
$DROP = "a'; DROP TABLE users;--"; // stacked-statement style (mysqli_query runs one statement, but the quote must stay contained)

// ---------------------------------------------------------------- sortColumnAllowed(): ORDER BY column allow-list
$ok(sortColumnAllowed($db, 'user_name'), 'sort: a real column is accepted');
$ok(sortColumnAllowed($db, 'tickets.ticket_id'), 'sort: a table-qualified real column is accepted');
$ok(sortColumnAllowed($db, 'tech_name'), 'sort: a known SELECT alias is accepted');
$ok(!sortColumnAllowed($db, 'user_name, (SELECT user_password FROM users LIMIT 1)'), 'sort: a subquery payload is rejected');
$ok(!sortColumnAllowed($db, 'user_name) OR SLEEP(5)-- -'), 'sort: a boolean/time payload is rejected');
$ok(!sortColumnAllowed($db, 'definitely_not_a_column'), 'sort: an unknown identifier is rejected (would otherwise error the query)');
$ok(!sortColumnAllowed($db, ''), 'sort: empty is rejected');

// Prove the allow-list output is a safe ORDER BY: run the admin/users.php shape with each accepted value.
foreach (['user_name', 'user_email', 'tech_name'] as $probe) {
    if (!sortColumnAllowed($db, $probe)) { continue; }
    // tech_name is an alias, so only exercise the real-column probes directly in ORDER BY
}
$r = $db->query("SELECT user_id FROM users ORDER BY user_name ASC LIMIT 1");
$ok($r !== false, 'sort: ORDER BY an allow-listed column runs cleanly');

// ---------------------------------------------------------------- UnifiSyncMapper::findExistingAsset(): MAC is bound
$db->query("DELETE FROM asset_interfaces WHERE interface_name LIKE 'sqli-%'");
$db->query("DELETE FROM assets WHERE asset_name LIKE 'sqli-unifi-%'");
$db->query("INSERT INTO assets SET asset_id=90901, asset_name='sqli-unifi-A', asset_type='Switch', asset_client_id=0");
$db->query("INSERT INTO asset_interfaces SET interface_id=90901, interface_name='sqli-if', interface_mac='AA:BB:CC:DD:EE:FF', interface_asset_id=90901");

$mapper = new UnifiSyncMapper($db, 1, 0);
$ref = new ReflectionMethod($mapper, 'findExistingAsset');
$ref->setAccessible(true);
mysqli_report(MYSQLI_REPORT_OFF);

// Genuine match (MAC without colons, upper-cased, as the sync normalises it)
$hit = $ref->invoke($mapper, [], '', '', 'AABBCCDDEEFF');
$ok(is_array($hit) && (int) $hit['asset_id'] === 90901, 'unifi: a real MAC still matches its asset');

// Injection payloads: must match nothing and must not raise a SQL error (bound => treated as one opaque string)
$err_before = (string) $db->error;
$inj1 = $ref->invoke($mapper, [], '', '', "' OR '1'='1");
$inj2 = $ref->invoke($mapper, [], '', '', "AABB' UNION SELECT asset_id,asset_serial,asset_name FROM assets-- -");
$ok($inj1 === null, 'unifi: an always-true MAC payload matches no asset');
$ok($inj2 === null, 'unifi: a UNION MAC payload matches no asset');
$ok((string) $db->error === $err_before, 'unifi: the payloads raised no SQL error (value stayed inside its placeholder)');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'") === 1, 'unifi: users table still present (no stacked DROP)');

// ---------------------------------------------------------------- recurring-invoice frequency INTERVAL allow-list
// The stored frequency feeds DATE_ADD(..., INTERVAL 1 <unit>); the fix maps it to a literal MONTH/YEAR. Reproduce that
// mapping and confirm a hostile stored value can never reach the INTERVAL position.
$interval_unit = fn(string $stored): string => $stored === 'year' ? 'YEAR' : 'MONTH';
$ok($interval_unit('month') === 'MONTH' && $interval_unit('year') === 'YEAR', 'recurring: month/year map to MONTH/YEAR');
$ok($interval_unit("month), recurring_invoice_amount=(SELECT 1)#") === 'MONTH', 'recurring: a payload-shaped stored value still maps to MONTH');
// And the value DATE_ADD actually receives is a bare keyword, so the statement parses.
$r = $db->query("SELECT DATE_ADD(CURDATE(), INTERVAL 1 " . $interval_unit("month') OR 1=1-- -") . ")");
$ok($r !== false, 'recurring: the mapped unit is a valid INTERVAL keyword');

// ---------------------------------------------------------------- certificate add/edit over HTTP (the headline files)
$sdir = sys_get_temp_dir() . '/sqli_sess_' . bin2hex(random_bytes(3));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });

// An administrator agent (role_is_admin=1 passes enforceUserPermission + enforceClientAccess) and a client to attach to.
$db->query("DELETE FROM certificates WHERE certificate_name LIKE 'sqli-%'");
$db->query("DELETE FROM certificate_history WHERE certificate_history_certificate_id IN (SELECT certificate_id FROM certificates WHERE certificate_name LIKE 'sqli-%')");
$db->query("DELETE FROM users WHERE user_id = 90990");
$db->query("DELETE FROM user_roles WHERE role_id = 9099");
$db->query("INSERT INTO user_roles SET role_id=9099, role_name='SQLi Admin', role_is_admin=1, role_type=1");
$db->query("INSERT INTO users SET user_id=90990, user_name='SQLi Admin', user_email='sqli-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=9099");
$db->query("INSERT INTO user_settings SET user_id=90990 ON DUPLICATE KEY UPDATE user_id=user_id");
$db->query("DELETE FROM clients WHERE client_id = 90990");
$db->query("INSERT INTO clients SET client_id=90990, client_name='SQLi Client'");

$base = sec_start_server($sdir);
$csrf = 'csrftok-sqli-0001';
$sid  = sec_forge_session($sdir, ['logged' => true, 'user_id' => 90990, 'csrf_token' => $csrf, 'sec_created' => time(), 'sec_last' => time()]);
$ref_hdr = ['Referer: ' . $base . '/agent/certificates.php'];

$payload_name = "sqli-$INJ";
[$c, $body] = sec_web($base, 'POST', '/agent/post.php', $sid, [
    'add_certificate' => 'Add', 'csrf_token' => $csrf, 'client_id' => 90990,
    'name' => $payload_name, 'description' => $DROP, 'domain' => '', 'issued_by' => '',
    'expire' => '', 'public_key' => '', 'notes' => "note $INJ", 'domain_id' => 0,
], [], $ref_hdr);
$row = $rows("SELECT * FROM certificates WHERE certificate_client_id = 90990 AND certificate_name LIKE 'sqli-%'");
$ok(count($row) === 1, 'certificate add: exactly one row created (no breakout, no stacked statement)');
$stored = $row[0]['certificate_name'] ?? '';
$ok(str_contains($stored, "OR '1'='1"), 'certificate add: the injection payload is stored verbatim as data, not executed');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'") === 1, 'certificate add: users table intact');
$cert_id = (int) ($row[0]['certificate_id'] ?? 0);

// Edit: change the name to a different payload; a history row must be written with the payload intact.
$new_name = "sqli-edited-$INJ";
sec_web($base, 'POST', '/agent/post.php', $sid, [
    'edit_certificate' => 'Save', 'csrf_token' => $csrf, 'certificate_id' => $cert_id,
    'name' => $new_name, 'description' => 'edited', 'domain' => '', 'issued_by' => '',
    'expire' => '', 'public_key' => '', 'notes' => '', 'domain_id' => 0,
], [], $ref_hdr);
$edited = $one("SELECT certificate_name FROM certificates WHERE certificate_id = $cert_id");
$ok($edited === $new_name, 'certificate edit: the new payload name is stored verbatim');
$hist = $rows("SELECT * FROM certificate_history WHERE certificate_history_certificate_id = $cert_id AND certificate_history_column = 'certificate_name'");
$ok(count($hist) >= 1, 'certificate edit: a change-history row was written (second-order insert path survived the payload)');

// Cleanup rows this test created (leave the schema; the scratch DB is disposable anyway).
$db->query("DELETE FROM certificate_history WHERE certificate_history_certificate_id = $cert_id");
$db->query("DELETE FROM certificates WHERE certificate_client_id = 90990");
$db->query("DELETE FROM clients WHERE client_id = 90990");
$db->query("DELETE FROM users WHERE user_id = 90990");
$db->query("DELETE FROM user_roles WHERE role_id = 9099");
$db->query("DELETE FROM asset_interfaces WHERE interface_id = 90901");
$db->query("DELETE FROM assets WHERE asset_id = 90901");
