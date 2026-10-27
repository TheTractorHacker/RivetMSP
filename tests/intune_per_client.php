<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Microsoft 365 / Entra ID tenant credentials, PER CLIENT (GitHub roadmap #10 slice 1 - schema + admin UI for
 * storing one tenant's credentials per department, encrypted at rest; the actual Graph API sync engine is a
 * separate, later, held-back piece - see agent/intune_settings.php's own header), against the REAL code on a
 * SCRATCH database, real HTTP (same harness as tests/security_*.php).
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/intune_per_client.php
 *
 * Covers: the DB step (db.sql parity, idempotent re-run, the UNIQUE client_id key actually enforced at the DB
 * level); saving a tenant encrypts the client secret at rest (never stored as the plaintext submitted) and it
 * round-trips through decryptSetting() with the SAME default context the app's other encryptSetting() callers
 * use; a blank secret field on a later save keeps the existing one, never blanks it; a changed tenant/app or a
 * real new secret clears any cached OAuth token (the column exists for slice 2, nothing reads it yet, but
 * clearing it now avoids a stale-token bug once something does); saving twice for the same client UPDATEs the
 * one row rather than creating a second. ADVERSARIAL tenant isolation (not just a happy path): a technician
 * restricted to Client B can read/save/delete Client B's own tenant, but is refused - server-side, not just a
 * hidden button - on every one of those same actions against Client A's tenant, by client_id in the page, by
 * client_id in the POST body, and by guessing Client A's row id directly in a delete URL; Client A's row is
 * provably untouched by every one of those attempts.
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/database_version.php";

echo "\n--- DB 2.6.113 step ---\n";
// Checked AFTER forcing a replay below, not before: this branch and msp-port-kb-v2 both branched from 2.6.112
// and each claims "2.6.113" for its own next step (documented in admin/database_updates.php) - whichever one's
// test ran most recently against this SHARED scratch database may have already left config_current_database_version
// at the literal string "2.6.113" without this branch's migration ever having run, which would make a check
// here BEFORE the replay pass or fail by accident depending on run order rather than on this branch's own code.
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.113', '>='), 'LATEST_DATABASE_VERSION is 2.6.113 or later');
$sqlText = (string) file_get_contents("$root/db.sql");
$ok(str_contains($sqlText, 'CREATE TABLE `microsoft_integrations`') && str_contains($sqlText, 'UNIQUE KEY `uniq_microsoft_integrations_client_id`'), 'db.sql carries the table and the unique key');
$verBefore = (string) $one('SELECT config_current_database_version FROM settings LIMIT 1');
$q("UPDATE settings SET config_current_database_version = '2.6.112'");
[, $updOut] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut, '2.6.112') && (string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === LATEST_DATABASE_VERSION, 'the updater advances past 2.6.112 to the latest version');
[, $updOut2] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut2, 'already at the latest'), 'running it again is a no-op, not an error');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'microsoft_integrations'") === 1, 'microsoft_integrations exists after the replay');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'microsoft_integrations' AND INDEX_NAME = 'uniq_microsoft_integrations_client_id'") > 0, 'the one-tenant-per-client UNIQUE key exists after the replay');
$q("UPDATE settings SET config_current_database_version = '" . $esc($verBefore) . "'");

echo "\n--- the UNIQUE key actually stops a second row for the same client ---\n";
$q("DELETE FROM microsoft_integrations WHERE client_id IN (SELECT client_id FROM clients WHERE client_name LIKE 'msint-test-%')");
$q("DELETE FROM clients WHERE client_name LIKE 'msint-test-%'");
$q("INSERT INTO clients SET client_name='msint-test-UniqueProbe'"); $probeClient = (int) $db->insert_id;
$q("INSERT INTO microsoft_integrations SET client_id=$probeClient, tenant_id='t1'");
mysqli_report(MYSQLI_REPORT_OFF);
$dupOk = mysqli_query($mysqli, "INSERT INTO microsoft_integrations SET client_id=$probeClient, tenant_id='t2'");
$ok($dupOk === false, 'a second row for the same client_id is rejected at the DB level, not just by application logic');
$q("DELETE FROM microsoft_integrations WHERE client_id=$probeClient");
$q("DELETE FROM clients WHERE client_id=$probeClient");

echo "\n--- fixtures ---\n";
$q("DELETE FROM microsoft_integrations WHERE client_id IN (SELECT client_id FROM clients WHERE client_name LIKE 'msint-test-%')");
$q("DELETE FROM clients WHERE client_name LIKE 'msint-test-%'");
$q("DELETE FROM user_client_permissions WHERE user_id IN (SELECT user_id FROM users WHERE user_email LIKE 'msint-test-%')");
$q("DELETE FROM users WHERE user_email LIKE 'msint-test-%'");
$q("DELETE FROM user_roles WHERE role_name = 'msint-test-TechB'");

$q("INSERT INTO clients SET client_name='msint-test-Client A'"); $clientA = (int) $db->insert_id;
$q("INSERT INTO clients SET client_name='msint-test-Client B'"); $clientB = (int) $db->insert_id;

$q("INSERT INTO user_roles SET role_name='msint-test-TechB', role_is_admin=0, role_type=1");
$roleTechB = (int) $db->insert_id;
$modSupportId = (int) $one("SELECT module_id FROM modules WHERE module_name='module_support'");
if ($modSupportId === 0) { $q("INSERT INTO modules SET module_name='module_support'"); $modSupportId = (int) $db->insert_id; }
$q("DELETE FROM user_role_permissions WHERE user_role_id = $roleTechB");
$q("INSERT INTO user_role_permissions SET user_role_id=$roleTechB, module_id=$modSupportId, user_role_permission_level=2"); // write

$q("INSERT INTO users SET user_name='msint-test-techb', user_email='msint-test-techb@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$roleTechB");
$techB = (int) $db->insert_id;
$q("INSERT INTO user_settings SET user_id=$techB ON DUPLICATE KEY UPDATE user_id=user_id");
$q("INSERT INTO user_client_permissions SET user_id=$techB, client_id=$clientB");   // restricted to Client B only

$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='msint-test-admin', user_email='msint-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

$sdir = sys_get_temp_dir() . '/msint_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sidAdmin = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
$sidTechB = sec_forge_session($sdir, ['logged' => true, 'user_id' => $techB, 'csrf_token' => 'csrftok12345678']);
$ck = [];
$postAdmin = fn (array $f) => sec_web($base, 'POST', '/agent/post.php', $sidAdmin, $f + ['csrf_token' => 'csrftok12345678'], $ck);
$postTechB = fn (array $f) => sec_web($base, 'POST', '/agent/post.php', $sidTechB, $f + ['csrf_token' => 'csrftok12345678'], $ck);
$getAdmin = fn (string $path) => sec_web($base, 'GET', $path, $sidAdmin, [], $ck);
$getTechB = fn (string $path) => sec_web($base, 'GET', $path, $sidTechB, [], $ck);
// delete_microsoft_integration is a GET-based action (an <a href="post.php?delete_...">, same shape as every
// other revoke/delete link in this app) - exercised as a real GET to agent/post.php, not the POST helper.
$deleteAdmin = fn (int $id, int $clientId) => $getAdmin("/agent/post.php?delete_microsoft_integration=$id&client_id=$clientId&csrf_token=csrftok12345678");
$deleteTechB = fn (int $id, int $clientId) => $getTechB("/agent/post.php?delete_microsoft_integration=$id&client_id=$clientId&csrf_token=csrftok12345678");

echo "\n--- saving a tenant encrypts the secret, and it round-trips ---\n";
[$c] = $postAdmin(['save_microsoft_integration' => '1', 'client_id' => $clientA, 'tenant_id' => 'tenant-aaa-111', 'azure_client_id' => 'app-aaa-111', 'client_secret' => 'S3cret-Plaintext-Value', 'enabled' => '1']);
$ok($c === 200 || $c === 302, 'save_microsoft_integration succeeds');
$rowA = $one("SELECT microsoft_integration_id FROM microsoft_integrations WHERE client_id = $clientA") ? $rows("SELECT * FROM microsoft_integrations WHERE client_id = $clientA")[0] : null;
$ok($rowA !== null, 'a row was created for Client A');
$msIdA = (int) $rowA['microsoft_integration_id'];
$ok($rowA['tenant_id'] === 'tenant-aaa-111' && $rowA['azure_client_id'] === 'app-aaa-111' && (int) $rowA['enabled'] === 1, 'tenant_id/azure_client_id/enabled stored exactly as entered');
$ok(!empty($rowA['client_secret_enc']) && !str_contains($rowA['client_secret_enc'], 'S3cret-Plaintext-Value'), 'the stored secret is NOT the plaintext - it is encrypted at rest');
$decrypted = decryptSetting($rowA['client_secret_enc']);
$ok($decrypted === 'S3cret-Plaintext-Value', 'decryptSetting() with the SAME default context every other encryptSetting() caller uses round-trips to the original secret');

echo "\n--- saving again with a BLANK secret keeps the existing one ---\n";
$cipherBefore = $rowA['client_secret_enc'];
$postAdmin(['save_microsoft_integration' => '1', 'client_id' => $clientA, 'tenant_id' => 'tenant-aaa-111', 'azure_client_id' => 'app-aaa-111', 'client_secret' => '', 'enabled' => '1']);
$rowA2 = $rows("SELECT * FROM microsoft_integrations WHERE client_id = $clientA")[0];
$ok($rowA2['client_secret_enc'] === $cipherBefore, 'a blank secret field never blanks or changes the stored ciphertext');
$ok((int) $one("SELECT COUNT(*) FROM microsoft_integrations WHERE client_id = $clientA") === 1, 'saving twice for the same client UPDATEs the one row, never creates a second');

echo "\n--- changing the tenant clears any cached OAuth token (slice 2's columns, harmless now) ---\n";
$q("UPDATE microsoft_integrations SET token_cache_enc = 'fake-cached-token', token_expires_at = NOW() WHERE microsoft_integration_id = $msIdA");
$postAdmin(['save_microsoft_integration' => '1', 'client_id' => $clientA, 'tenant_id' => 'tenant-aaa-CHANGED', 'azure_client_id' => 'app-aaa-111', 'client_secret' => '', 'enabled' => '1']);
$rowA3 = $rows("SELECT * FROM microsoft_integrations WHERE client_id = $clientA")[0];
$ok($rowA3['token_cache_enc'] === null && $rowA3['token_expires_at'] === null, 'changing the tenant ID clears the cached token, not just the credential fields');

echo "\n--- the settings page shows a Stored placeholder, never the secret itself ---\n";
[$c, $body] = $getAdmin("/agent/intune_settings.php?client_id=$clientA");
$ok($c === 200 && str_contains($body, 'Stored - leave blank to keep current') && !str_contains($body, 'S3cret-Plaintext-Value'), 'the page never echoes the stored secret back, only a placeholder');

echo "\n--- ADVERSARIAL: a technician restricted to Client B cannot touch Client A's tenant ---\n";
[$c, $body] = $getTechB("/agent/intune_settings.php?client_id=$clientA");
$ok($c !== 200 || !str_contains($body, 'tenant-aaa-CHANGED'), 'GET the Client A settings page as the Client-B-only technician does not render Client A\'s tenant data');

$beforeAttack = $rows("SELECT * FROM microsoft_integrations WHERE client_id = $clientA")[0];
$postTechB(['save_microsoft_integration' => '1', 'client_id' => $clientA, 'tenant_id' => 'tenant-HIJACKED', 'azure_client_id' => 'app-HIJACKED', 'client_secret' => 'stolen-secret', 'enabled' => '0']);
$afterAttack1 = $rows("SELECT * FROM microsoft_integrations WHERE client_id = $clientA")[0];
$ok($afterAttack1['tenant_id'] === $beforeAttack['tenant_id'] && $afterAttack1['client_secret_enc'] === $beforeAttack['client_secret_enc'] && (int) $afterAttack1['enabled'] === (int) $beforeAttack['enabled'], 'POSTing a save for Client A as the Client-B-only technician is refused server-side - Client A\'s row is byte-for-byte unchanged, not just hidden from the UI');

$deleteTechB($msIdA, $clientA);
$ok((int) $one("SELECT COUNT(*) FROM microsoft_integrations WHERE microsoft_integration_id = $msIdA") === 1, 'a delete of Client A\'s row by the Client-B-only technician (guessing the real id directly in the URL) is refused - the row still exists');

echo "\n--- and that same technician CAN manage Client B's own tenant (the restriction is tenant-specific, not a blanket failure) ---\n";
[$c] = $postTechB(['save_microsoft_integration' => '1', 'client_id' => $clientB, 'tenant_id' => 'tenant-bbb-222', 'azure_client_id' => 'app-bbb-222', 'client_secret' => 'B-secret', 'enabled' => '1']);
$ok($c === 200 || $c === 302, 'save_microsoft_integration for Client B (the technician\'s own department) succeeds');
$rowB = $one("SELECT microsoft_integration_id FROM microsoft_integrations WHERE client_id = $clientB AND tenant_id = 'tenant-bbb-222'");
$ok((int) $rowB > 0, 'Client B now has its own, separate tenant row');
$msIdB = (int) $rowB;
[$c] = $deleteTechB($msIdB, $clientB);
$ok((int) $one("SELECT COUNT(*) FROM microsoft_integrations WHERE microsoft_integration_id = $msIdB") === 0, 'and can delete it again - the earlier refusals were specifically about Client A, not a broken permission for this technician generally');

echo "\n--- admin can remove a tenant ---\n";
[$c] = $deleteAdmin($msIdA, $clientA);
$ok($c === 200 || $c === 302, 'delete_microsoft_integration succeeds for the admin');
$ok((int) $one("SELECT COUNT(*) FROM microsoft_integrations WHERE microsoft_integration_id = $msIdA") === 0, 'Client A\'s tenant row is gone');

$thisPort = (int) explode(':', $base)[2];
$logText = (string) @file_get_contents(sys_get_temp_dir() . "/sec_server_$thisPort.log");
$otherWarnings = preg_replace('/^.*Undefined array key "HTTP_USER_AGENT".*$/m', '', $logText);
$ok(!str_contains($otherWarnings, 'PHP Warning') && !str_contains($otherWarnings, 'PHP Fatal') && !str_contains($otherWarnings, 'PHP Notice'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');

echo "\n--- cleanup ---\n";
$q("DELETE FROM microsoft_integrations WHERE client_id IN ($clientA, $clientB)");
$q("DELETE FROM clients WHERE client_id IN ($clientA, $clientB)");
$q("DELETE FROM user_client_permissions WHERE user_id = $techB");
$q("DELETE FROM users WHERE user_id = $techB");
$q("DELETE FROM user_role_permissions WHERE user_role_id = $roleTechB");
$q("DELETE FROM user_roles WHERE user_role_id = $roleTechB");
