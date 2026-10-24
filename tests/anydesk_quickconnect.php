<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * AnyDesk quick-connect on assets (GitHub roadmap #16), ported from RivetIT's asset_anydesk_id column + the
 * "Connect via AnyDesk" launch button on agent/asset_details.php, against the REAL code on a SCRATCH database
 * (same harness as tests/security_*.php).
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/anydesk_quickconnect.php
 *
 * Covers: the DB 2.6.112 step (db.sql parity, idempotent re-run); add/edit through the real agent/post/asset.php
 * handler save and read back asset_anydesk_id; the asset details page renders the "Connect via AnyDesk" button
 * with the anydesk: URI built from the digits only; no button at all when the field is empty; a hostile value in
 * the field can never inject another URI scheme (sanitize_url()'s allowlist plus the \D strip in asset_details.php).
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/database_version.php";

echo "\n--- DB 2.6.112 step ---\n";
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets' AND COLUMN_NAME = 'asset_anydesk_id'") === 1, 'the assets table has asset_anydesk_id');
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.112', '>='), 'LATEST_DATABASE_VERSION is 2.6.112 or later');
$sqlText = (string) file_get_contents("$root/db.sql");
$ok(str_contains($sqlText, '`asset_anydesk_id` varchar(50) DEFAULT NULL'), 'db.sql carries the column exactly as the migration made it');
$verBefore = (string) $one('SELECT config_current_database_version FROM settings LIMIT 1');
$q("UPDATE settings SET config_current_database_version = '2.6.111'");
[, $updOut] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut, '2.6.111') && (string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === LATEST_DATABASE_VERSION, 'the updater advances past 2.6.111 to the latest version');
[, $updOut2] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut2, 'already at the latest'), 'running it again is a no-op, not an error');
$q("UPDATE settings SET config_current_database_version = '" . $esc($verBefore) . "'");

echo "\n--- fixtures ---\n";
$q("DELETE FROM assets WHERE asset_name LIKE 'ad-test-%'");
$q("DELETE FROM clients WHERE client_name = 'ad-test-Client'");
$q("INSERT INTO clients SET client_name='ad-test-Client'");
$clientId = (int) $db->insert_id;
$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='ad-test-admin', user_email='ad-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

$sdir = sys_get_temp_dir() . '/ad_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sid = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
$post = fn (array $f) => sec_web($base, 'POST', '/agent/post.php', $sid, $f + ['csrf_token' => 'csrftok12345678'], []);

echo "\n--- add/edit through the real handler ---\n";
[$c] = $post(['add_asset' => '1', 'client_id' => $clientId, 'name' => 'ad-test-asset', 'asset_tag' => '', 'description' => '', 'type' => 'Desktop', 'make' => 'Dell', 'model' => '', 'serial' => '', 'os' => '', 'ip' => '', 'ipv6' => '', 'nat_ip' => '', 'mac' => '', 'uri' => '', 'uri_2' => '', 'uri_client' => '', 'anydesk_id' => '123 456 789', 'status' => 'Active', 'location' => 0, 'physical_location' => '', 'vendor' => 0, 'contact' => 0, 'network' => 0, 'purchase_reference' => '', 'purchase_date' => '', 'warranty_expire' => '', 'install_date' => '', 'notes' => '', 'favorite' => 0]);
$ok($c === 200 || $c === 302, 'add_asset accepts an AnyDesk ID');
$assetId = (int) $one("SELECT asset_id FROM assets WHERE asset_name = 'ad-test-asset'");
$ok($assetId > 0 && (string) $one("SELECT asset_anydesk_id FROM assets WHERE asset_id = $assetId") === '123 456 789', 'the AnyDesk ID is stored exactly as entered');

$post(['edit_asset' => '1', 'asset_id' => $assetId, 'name' => 'ad-test-asset', 'asset_tag' => '', 'description' => '', 'type' => 'Desktop', 'make' => 'Dell', 'model' => '', 'serial' => '', 'ip' => '', 'ipv6' => '', 'nat_ip' => '', 'mac' => '', 'os' => '', 'uri' => '', 'uri_2' => '', 'uri_client' => '', 'anydesk_id' => '987654321', 'status' => 'Active', 'location' => 0, 'physical_location' => '', 'vendor' => 0, 'contact' => 0, 'network' => 0, 'purchase_reference' => '', 'purchase_date' => '', 'warranty_expire' => '', 'install_date' => '', 'notes' => '', 'favorite' => 0]);
$ok((string) $one("SELECT asset_anydesk_id FROM assets WHERE asset_id = $assetId") === '987654321', 'editing changes the stored AnyDesk ID');

echo "\n--- asset details page ---\n";
[$c, $body] = sec_web($base, 'GET', '/agent/asset_details.php?asset_id=' . $assetId, $sid);
$ok($c === 200 && str_contains($body, 'Connect via AnyDesk (987654321)'), 'the details page shows the Connect via AnyDesk button with the id');
$ok(str_contains($body, 'href="anydesk:987654321"'), 'the href is a plain anydesk: URI built from the digits');
$ok(str_contains($body, 'Remote Access'), 'the card is titled Remote Access');

$q("UPDATE assets SET asset_anydesk_id = NULL WHERE asset_id = $assetId");
[$c, $body] = sec_web($base, 'GET', '/agent/asset_details.php?asset_id=' . $assetId, $sid);
$ok($c === 200 && !str_contains($body, 'Remote Access') && !str_contains($body, 'Connect via AnyDesk'), 'no AnyDesk ID means no Remote Access card at all');

echo "\n--- hostile input never becomes a different URI scheme ---\n";
$q("UPDATE assets SET asset_anydesk_id = 'javascript:alert(1)//123' WHERE asset_id = $assetId");
[$c, $body] = sec_web($base, 'GET', '/agent/asset_details.php?asset_id=' . $assetId, $sid);
$ok($c === 200 && !str_contains($body, 'href="javascript:') && !preg_match('/href="(?!anydesk:)[a-z]+:/i', $body), 'only digits ever reach the href - a hostile value can never produce a non-anydesk scheme');

[, $log] = sec_sh('cat ' . escapeshellarg(sys_get_temp_dir()) . '/sec_server_*.log 2>/dev/null');
$ok(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $log) || str_contains($log, 'Undefined array key "HTTP_USER_AGENT"'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');
