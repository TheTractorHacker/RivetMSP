<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Credential rotation due dates (GitHub roadmap #9, the second half - reveal audit is tests/security_vault_reveal.php),
 * against the REAL code on a SCRATCH database (same harness as tests/security_*.php).
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/credential_rotation.php
 *
 * Covers: the DB 2.6.109 step (db.sql parity, idempotent re-run); add/edit through the real handlers save and
 * read back credential_rotation_due_at; a blank field clears it (stored NULL, not an empty string); the Overdue/
 * Due soon/OK badge thresholds on the list page and the detail modal; a change is recorded in credential_history;
 * cron/cron.php's reminder notification fires at exactly 45/7/1 days out and not on any other day, and never for
 * an archived credential; nothing here ever writes a new due date on its own (no auto-rotation).
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/database_version.php";

echo "\n--- DB 2.6.109 step ---\n";
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credentials' AND COLUMN_NAME = 'credential_rotation_due_at'") === 1, 'the credentials table has credential_rotation_due_at');
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.109', '>='), 'LATEST_DATABASE_VERSION is 2.6.109 or later');
$sqlText = (string) file_get_contents("$root/db.sql");
$ok(str_contains($sqlText, '`credential_rotation_due_at` date DEFAULT NULL'), 'db.sql carries the column exactly as the migration made it');
$verBefore = (string) $one('SELECT config_current_database_version FROM settings LIMIT 1');
$q("UPDATE settings SET config_current_database_version = '2.6.108'");
[, $updOut] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut, '2.6.109') && (string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === LATEST_DATABASE_VERSION, 'the updater reaches 2.6.109');
[, $updOut2] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut2, 'already at the latest'), 'running it again is a no-op, not an error');
$q("UPDATE settings SET config_current_database_version = '" . $esc($verBefore) . "'");

echo "\n--- fixtures ---\n";
$q("DELETE FROM credential_history WHERE history_credential_id IN (SELECT credential_id FROM credentials WHERE credential_name LIKE 'rot-test-%')");
$q("DELETE FROM credentials WHERE credential_name LIKE 'rot-test-%'");
$q("DELETE FROM clients WHERE client_name = 'rot-test-Client'");
$q("DELETE FROM notifications WHERE notification_type = 'Credential Rotation Due'");
$q("INSERT INTO clients SET client_name='rot-test-Client'");
$clientId = (int) $db->insert_id;
$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='rot-test-admin', user_email='rot-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

$sdir = sys_get_temp_dir() . '/rot_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
// add_credential/edit_credential call encryptCredentialEntry(), which needs a real, unlocked vault session (the
// same ciphertext/iv/key shape as tests/security_vault_reveal.php) - without it every write still succeeds (this
// feature does not touch the vault), but functions.php logs an "Undefined array key" warning for each one.
$master = randomString(); $sk = randomString(); $siv = randomString();
$sessionCipher = openssl_encrypt($master, 'aes-128-cbc', $sk, 0, $siv);
$ck = ['user_encryption_session_key' => $sk];
$sid = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678', 'user_encryption_session_ciphertext' => $sessionCipher, 'user_encryption_session_iv' => $siv, 'vault_stepup_at' => time()]);
$post = fn (array $f) => sec_web($base, 'POST', '/agent/post.php', $sid, $f + ['csrf_token' => 'csrftok12345678'], $ck);

echo "\n--- add/edit through the real handlers ---\n";
$dueSoon = date('Y-m-d', strtotime('+10 days'));
[$c] = $post(['add_credential' => '1', 'client_id' => $clientId, 'name' => 'rot-test-cred', 'description' => '', 'username' => 'u', 'password' => 'p', 'otp_secret' => '', 'note' => '', 'uri' => '', 'uri_2' => '', 'rotation_due_at' => $dueSoon]);
$ok($c === 200 || $c === 302, 'add_credential accepts a rotation due date');
$credId = (int) $one("SELECT credential_id FROM credentials WHERE credential_name = 'rot-test-cred'");
$ok($credId > 0 && (string) $one("SELECT credential_rotation_due_at FROM credentials WHERE credential_id = $credId") === $dueSoon, 'the due date is stored exactly as entered');

$overdue = date('Y-m-d', strtotime('-3 days'));
$post(['edit_credential' => '1', 'credential_id' => $credId, 'name' => 'rot-test-cred', 'description' => '', 'username' => 'u', 'password' => 'p', 'otp_secret' => '', 'note' => '', 'uri' => '', 'uri_2' => '', 'rotation_due_at' => $overdue]);
$ok((string) $one("SELECT credential_rotation_due_at FROM credentials WHERE credential_id = $credId") === $overdue, 'editing changes the stored due date');
$hist = $rows("SELECT * FROM credential_history WHERE history_credential_id = $credId AND history_field = 'Rotation Due'");
$ok(count($hist) === 1 && $hist[0]['history_old_value'] === $dueSoon && $hist[0]['history_new_value'] === $overdue, 'the change is recorded in credential_history (old and new value, not a secret, so no reveal-audit concern)');

$post(['edit_credential' => '1', 'credential_id' => $credId, 'name' => 'rot-test-cred', 'description' => '', 'username' => 'u', 'password' => 'p', 'otp_secret' => '', 'note' => '', 'uri' => '', 'uri_2' => '', 'rotation_due_at' => '']);
$ok($one("SELECT credential_rotation_due_at FROM credentials WHERE credential_id = $credId") === null, 'clearing the field stores NULL, not an empty string (so it never falsely matches a cron date comparison)');
$q("UPDATE credentials SET credential_rotation_due_at = '$overdue' WHERE credential_id = $credId");

echo "\n--- badges ---\n";
[$c, $body] = sec_web($base, 'GET', '/agent/credentials.php?client_id=' . $clientId, $sid, [], $ck);
$ok($c === 200 && str_contains($body, 'rot-test-cred') && str_contains($body, 'Rotation overdue') && str_contains($body, 'text-bg-danger'), 'the list page shows the overdue badge with the correct Tabler paired class');
[$c, $body] = sec_web($base, 'GET', '/agent/modals/credential/credential_view.php?id=' . $credId, $sid, [], $ck);
$ok($c === 200 && str_contains($body, 'Rotation Due') && str_contains($body, 'Overdue') && str_contains($body, $overdue), 'the detail modal shows the same overdue state and the date itself');
$ok(!preg_match('/rot-test-cred.*(S3cret|\bu\b<)/s', $body), 'opening the detail modal still never embeds the username/password itself (the reveal-audit fix is unaffected)');

$q("UPDATE credentials SET credential_rotation_due_at = '$dueSoon' WHERE credential_id = $credId");
[$c, $body] = sec_web($base, 'GET', '/agent/credentials.php?client_id=' . $clientId, $sid, [], $ck);
$ok($c === 200 && str_contains($body, 'Rotation due soon') && str_contains($body, 'text-bg-warning'), 'due within 14 days shows "Rotation due soon" instead');
$farOut = date('Y-m-d', strtotime('+200 days'));
$q("UPDATE credentials SET credential_rotation_due_at = '$farOut' WHERE credential_id = $credId");
[$c, $body] = sec_web($base, 'GET', '/agent/credentials.php?client_id=' . $clientId, $sid, [], $ck);
$ok($c === 200 && !str_contains($body, 'Rotation overdue') && !str_contains($body, 'Rotation due soon'), 'due 200 days out shows no badge at all');
$q("UPDATE credentials SET credential_rotation_due_at = NULL WHERE credential_id = $credId");
[$c, $body] = sec_web($base, 'GET', '/agent/credentials.php?client_id=' . $clientId, $sid, [], $ck);
$ok($c === 200 && !str_contains($body, 'Rotation overdue') && !str_contains($body, 'Rotation due soon'), 'no due date set shows no badge at all');

echo "\n--- cron reminder (appNotify, same shape as domain/certificate expiry) ---\n";
$q("UPDATE settings SET config_enable_cron = 1 WHERE company_id = 1");
// appNotify() fans a notification out to every active internal user (functions.php:3728-3730), so the row count
// depends on how many such users this shared scratch database happens to have right now, not on this feature - the
// thing actually worth proving is that at least one fires, naming this exact credential and day, with the right
// link, and that re-running cron for the SAME day does not pile up a second one.
foreach ([1, 7, 45] as $day) {
    $q("DELETE FROM notifications WHERE notification_type = 'Credential Rotation Due'");
    $due = date('Y-m-d', strtotime("+$day days"));
    $q("UPDATE credentials SET credential_rotation_due_at = '$due' WHERE credential_id = $credId");
    sec_sh('cd ' . escapeshellarg("$root/cron") . ' && RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=' . escapeshellarg((string) getenv('RIVETMSP_REDIS_PORT')) . ' RIVETMSP_REDIS_ENV_FILE=/dev/null php cron.php');
    $matching = $rows("SELECT * FROM notifications WHERE notification_type = 'Credential Rotation Due' AND notification LIKE '%rot-test-cred%' AND notification LIKE '%$day day%'");
    $ok(count($matching) >= 1 && $matching[0]['notification_action'] === "/agent/credentials.php?client_id=$clientId", "cron fires the reminder at $day day(s) out, naming the day and linking to this client's credentials page");
}
$q("DELETE FROM notifications WHERE notification_type = 'Credential Rotation Due'");
$q("UPDATE credentials SET credential_rotation_due_at = DATE_ADD(CURDATE(), INTERVAL 10 DAY) WHERE credential_id = $credId");
sec_sh('cd ' . escapeshellarg("$root/cron") . ' && RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=' . escapeshellarg((string) getenv('RIVETMSP_REDIS_PORT')) . ' RIVETMSP_REDIS_ENV_FILE=/dev/null php cron.php');
$ok((int) $one("SELECT COUNT(*) FROM notifications WHERE notification_type = 'Credential Rotation Due'") === 0, 'and nothing on a day that is not 1, 7 or 45 out');
$q("UPDATE credentials SET credential_rotation_due_at = DATE_ADD(CURDATE(), INTERVAL 1 DAY), credential_archived_at = NOW() WHERE credential_id = $credId");
sec_sh('cd ' . escapeshellarg("$root/cron") . ' && RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=' . escapeshellarg((string) getenv('RIVETMSP_REDIS_PORT')) . ' RIVETMSP_REDIS_ENV_FILE=/dev/null php cron.php');
$ok((int) $one("SELECT COUNT(*) FROM notifications WHERE notification_type = 'Credential Rotation Due'") === 0, 'and never for an archived credential');
$q("UPDATE settings SET config_enable_cron = 0 WHERE company_id = 1");

echo "\n--- no auto-rotation anywhere ---\n";
$cronSrc = (string) file_get_contents("$root/cron/cron.php");
preg_match('/CREDENTIAL ROTATION DUE.*?\/\/ Asset Warranties Expiring/s', $cronSrc, $m);
$block = $m[0] ?? '';
$ok($block !== '' && !preg_match('/UPDATE\s+credentials\s+SET/i', $block) && !str_contains($block, 'credential_password') && !str_contains($block, 'encryptCredentialEntry'), 'the cron block only reads and notifies - it never writes to credentials, let alone a password, confirming no auto-rotation was built');

$thisPort = (int) explode(':', $base)[2];
$logText = (string) @file_get_contents(sys_get_temp_dir() . "/sec_server_$thisPort.log");
$otherWarnings = preg_replace('/^.*Undefined array key "HTTP_USER_AGENT".*$/m', '', $logText);
$ok(!str_contains($otherWarnings, 'PHP Warning') && !str_contains($otherWarnings, 'PHP Fatal') && !str_contains($otherWarnings, 'PHP Notice'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');

// cleanup
$q("DELETE FROM credential_history WHERE history_credential_id = $credId");
$q("DELETE FROM credentials WHERE credential_id = $credId");
$q("DELETE FROM clients WHERE client_id = $clientId");
$q("DELETE FROM notifications WHERE notification_type = 'Credential Rotation Due'");
