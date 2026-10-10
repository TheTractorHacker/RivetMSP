<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Wave 1 integration (security + mail intake + recovery + RivetCore rc.8), the seams between the branches. Scratch database only (same harness
 * and environment as tests/security_*.php; config.php must point at the same scratch database):
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=...scratch... RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... RIVETMSP_REDIS_PORT=... RIVETMSP_REDIS_ENV_FILE=/dev/null \
 *     php tests/wave1_integration.php
 *
 * Covers: the database step chain (2.6.78 MCP collation / Core 0017, 2.6.79 security, 2.6.80 intake, 2.6.81 recovery, 2.6.82 Core runner step for rc.8) and db.sql; the mail OAuth columns
 * (wrapped, read through decryptSetting, written wrapped by the mail queue); the RivetCore rc.8 pin (composer.json, lock, installed.json and
 * installed.php agree and match the vendored package); the fingerprint constant shared by the app, the drill and backup.sh; single-use TOTP;
 * and what the installer schedules. The end-to-end backup and restore-drill test is tests/recovery_integration.php.
 */
require_once __DIR__ . '/support/security_lib.php';
require_once "$root/includes/security_crypto.php";

$read = fn(string $f): string => (string) file_get_contents("$root/$f");

// ------------------------------------------------------------------ the database step chain
$upd = $read('admin/database_updates.php');
preg_match_all('/if \(\$rivetit_db_version\(\) == \'(2\.6\.\d+)\'\) \{/', $upd, $gates);
$gates = $gates[1];
$ok(array_slice($gates, -6) === ['2.6.76', '2.6.77', '2.6.78', '2.6.79', '2.6.80', '2.6.81'], 'the last steps run from 2.6.76, 77, 78, 79, 80 and 81 (to 2.6.82), in that order (' . implode(', ', array_slice($gates, -6)) . ')');
$ok(count($gates) === count(array_unique($gates)), 'no step is gated twice');
foreach (['2.6.77' => '2.6.78', '2.6.78' => '2.6.79', '2.6.79' => '2.6.80', '2.6.80' => '2.6.81', '2.6.81' => '2.6.82'] as $gate => $next) {
    $a = strpos($upd, "if (\$rivetit_db_version() == '$gate') {");
    $b = strpos($upd, "if (\$rivetit_db_version() == '$next') {");
    $end = $b === false ? strlen($upd) : $b;
    $nextGate = strpos($upd, "if (\$rivetit_db_version() == '", $a + 10);
    $block = substr($upd, $a, ($nextGate === false ? strlen($upd) : $nextGate) - $a);
    $ok(strpos($block, "config_current_database_version` = '$next'") !== false, "the $gate step ends by setting the version to $next");
}
require "$root/includes/database_version.php";
$ok(LATEST_DATABASE_VERSION === '2.6.82', 'LATEST_DATABASE_VERSION is 2.6.82 (' . LATEST_DATABASE_VERSION . ')');
$ok($one("SELECT config_current_database_version FROM settings WHERE company_id=1") === LATEST_DATABASE_VERSION, 'the scratch install is at the latest version');
$step82 = substr($upd, strpos($upd, "if (\$rivetit_db_version() == '2.6.81') {"));
$ok(str_contains($step82, 'Migration0017McpIdentityBinaryCollation::class') && str_contains($step82, 'CoreMigrations::all()') && str_contains($step82, 'MysqliDatabaseAdapter($mysqli)'), 'the 2.6.82 step runs the Core migration runner (editions apply Core migrations through their own step)');
$ok(str_contains($step82, 'class_exists(') && strpos($step82, 'class_exists(') < strpos($step82, "= '2.6.82'"), 'and only advances the version when the package that ships 0017 is installed');

// db.sql: the same schema a migration produces, nothing environment specific
$sql = $read('db.sql');
$ok(stripos($sql, 'uca1400') === false, 'db.sql carries no utf8mb4_uca1400 collation (not portable to MariaDB 10.x)');
$ok(preg_match('/CREATE TABLE `mcp_unlinked_identities` \((.*?)\n\) ENGINE/s', $sql, $m) === 1 && str_contains($m[1], '`issuer` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL') && str_contains($m[1], '`subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL'), 'db.sql has the utf8mb4_bin MCP identity columns (Core migration 0017)');
$ok(str_contains($sql, "('0017_mcp_identity_binary_collation'"), 'db.sql records Core migration 0017 as applied');
foreach (['security_settings', 'user_recovery_codes', 'user_sessions', 'mail_intake_state', 'mail_intake_settings', 'mail_alerts', 'backup_runs', 'restore_drill_log', 'recovery_alerts', 'recovery_settings'] as $t) {
    $ok(str_contains($sql, "CREATE TABLE `$t`") && $one("SHOW TABLES LIKE '$t'") === $t, "table $t is in db.sql and in the scratch database");
}
$ok(str_contains($sql, '`config_backup_passphrase` text'), 'settings.config_backup_passphrase is in db.sql');
$ok(stripos((string) $one("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mcp_unlinked_identities' AND COLUMN_NAME='issuer'"), 'utf8mb4_bin') === 0, 'the scratch database has the binary issuer collation');
$ok((int) $one("SELECT COUNT(*) FROM rivet_core_migrations WHERE migration_id='0017_mcp_identity_binary_collation'") === 1, '... and 0017 is recorded as applied');
$ok(in_array('0017_mcp_identity_binary_collation', array_map(fn($mg) => $mg->id(), \RivetCore\Migration\CoreMigrations::all()), true), 'CoreMigrations::all() (the installed package) includes 0017');

// ------------------------------------------------------------------ the RivetCore rc.8 pin
$composer = json_decode($read('composer.json'), true);
$lock = json_decode($read('composer.lock'), true);
$ok(($composer['require']['rivet/rivet-core'] ?? '') === '^1.0.0-rc.8', 'composer.json requires rivet/rivet-core ^1.0.0-rc.8');
$pkg = null; foreach ($lock['packages'] as $p) { if ($p['name'] === 'rivet/rivet-core') { $pkg = $p; } }
$ok($pkg && $pkg['version'] === 'v1.0.0-rc.8' && $pkg['source']['reference'] === $pkg['dist']['reference'] && str_contains($pkg['dist']['url'], $pkg['source']['reference']), 'composer.lock pins v1.0.0-rc.8 with one reference for source and dist');
$ref = $pkg['source']['reference'] ?? '';
$inst = json_decode($read('vendor/composer/installed.json'), true);
$ip = null; foreach (($inst['packages'] ?? $inst) as $p) { if (($p['name'] ?? '') === 'rivet/rivet-core') { $ip = $p; } }
$ok($ip && $ip['version'] === 'v1.0.0-rc.8' && $ip['source']['reference'] === $ref, 'vendor/composer/installed.json agrees with the lock');
$phpInst = require "$root/vendor/composer/installed.php";
$ok(($phpInst['versions']['rivet/rivet-core']['reference'] ?? '') === $ref && ($phpInst['versions']['rivet/rivet-core']['pretty_version'] ?? '') === 'v1.0.0-rc.8', 'vendor/composer/installed.php agrees with the lock');
$ok(is_file("$root/vendor/rivet/rivet-core/src/Mcp/Migration/Migration0017McpIdentityBinaryCollation.php") && str_contains($read('vendor/rivet/rivet-core/CHANGELOG.md'), '## 1.0.0-rc.8'), 'the vendored package is rc.8');
$ok(preg_match('/^[0-9a-f]{40}$/', $ref) === 1, 'the lock reference is a full commit id');

// ------------------------------------------------------------------ mail OAuth secrets: wrapped, read through decryptSetting, written wrapped by the queue
$oauth = ['config_mail_oauth_client_secret', 'config_mail_oauth_refresh_token', 'config_mail_oauth_access_token'];
$ok(array_diff($oauth, secStragglerColumns()['settings'][1]) === [] && secDeferredColumns() === [], 'the three mail OAuth columns are in secStragglerColumns() and secDeferredColumns() is empty');
$mq = $read('cron/mail_queue.php');
foreach ($oauth as $c) { $ok(preg_match('/decryptSetting\(\$row\[\'' . $c . '\'\]/', $mq) === 1, "cron/mail_queue.php reads $c through decryptSetting()"); }
$ok(!preg_match('/\$row\[\'config_mail_oauth_(client_secret|refresh_token|access_token)\'\] \?\? \'\';/', preg_replace('/decryptSetting\(\$row\[[^\]]+\] \?\? \'\'\)/', '', $mq)), 'and no raw read of those columns is left in the queue');
// run the queue's own persistMailOauthTokens() against the scratch row
preg_match('/function persistMailOauthTokens\(.*?\n\}\n/s', $mq, $fn);
$ok(!empty($fn[0]), 'persistMailOauthTokens() found in cron/mail_queue.php');
// Test only: the function text comes from this repository's own cron/mail_queue.php (the script itself cannot be included, it runs the queue on load).
if (!empty($fn[0]) && !function_exists('persistMailOauthTokens')) { eval($fn[0]); }
$q("UPDATE settings SET config_mail_oauth_access_token='old-plain-access', config_mail_oauth_refresh_token='old-plain-refresh', config_mail_oauth_client_secret='client-secret-plain' WHERE company_id=1");
persistMailOauthTokens('fresh-access-token', '2030-01-01 00:00:00', 'fresh-refresh-token');
$acc = $one("SELECT config_mail_oauth_access_token FROM settings WHERE company_id=1"); $ref2 = $one("SELECT config_mail_oauth_refresh_token FROM settings WHERE company_id=1");
$ok(secIsWrapped($acc) && decryptSetting($acc) === 'fresh-access-token' && secIsWrapped($ref2) && decryptSetting($ref2) === 'fresh-refresh-token', 'tokens written by the mail queue are wrapped (ENC2:) and read back');
persistMailOauthTokens('second-access', '2030-01-02 00:00:00');
$ok(decryptSetting($one("SELECT config_mail_oauth_refresh_token FROM settings WHERE company_id=1")) === 'fresh-refresh-token' && decryptSetting($one("SELECT config_mail_oauth_access_token FROM settings WHERE company_id=1")) === 'second-access', 'a refresh without a new refresh token keeps the stored one and wraps the new access token');
$savedKey = $GLOBALS['config_settings_enc_key']; $GLOBALS['config_settings_enc_key'] = '';
persistMailOauthTokens('plaintext-must-not-land', '2030-01-03 00:00:00', 'plaintext-refresh-must-not-land');
$GLOBALS['config_settings_enc_key'] = $savedKey;
$left = $one("SELECT CONCAT(config_mail_oauth_access_token, config_mail_oauth_refresh_token) FROM settings WHERE company_id=1");
$ok(!str_contains((string) $left, 'must-not-land'), 'without a settings key nothing is stored (never plaintext)');
// the re-wrap walks them, and the legacy plaintext of an upgraded install is wrapped and still readable by the queue's reader
$q("UPDATE settings SET config_mail_oauth_access_token='legacy-plain-access', config_mail_oauth_refresh_token='legacy-plain-refresh', config_mail_oauth_client_secret='legacy-plain-secret' WHERE company_id=1");
$res = secRewrapAll($db);
$row = $rows("SELECT * FROM settings WHERE company_id=1")[0];
$ok($res['no_key'] === false && secIsWrapped($row['config_mail_oauth_access_token']) && secIsWrapped($row['config_mail_oauth_refresh_token']) && secIsWrapped($row['config_mail_oauth_client_secret']), 'secRewrapAll wraps legacy plaintext mail OAuth values');
$ok(decryptSetting($row['config_mail_oauth_client_secret']) === 'legacy-plain-secret' && decryptSetting($row['config_mail_oauth_refresh_token']) === 'legacy-plain-refresh', '... and they decrypt back to the original values');
$q("UPDATE settings SET config_mail_oauth_access_token=NULL, config_mail_oauth_refresh_token=NULL, config_mail_oauth_client_secret=NULL WHERE company_id=1");

// ------------------------------------------------------------------ the key fingerprint shared by the app, the drill and deploy/backup.sh
define('FROM_POST_HANDLER', true);
chdir("$root/admin");                       // post/backup.php includes ../includes/app_version.php relative to admin/
require_once "$root/admin/post/backup.php";
$lit = 'rivetit-settings-key-fingerprint|v1|';
$want = substr(hash('sha256', $lit . 'some-settings-key'), 0, 16);
$ok(backup_settings_key_fingerprint('some-settings-key') === $want && \RivetMSP\Recovery\RestoreDrill::keyFingerprint('some-settings-key') === $want, 'the in-app backup and the restore drill compute the same fingerprint, with the literal prefix unchanged');
$ok(str_contains($read('admin/post/backup.php'), "'$lit'") && str_contains($read('src/Recovery/RestoreDrill.php'), "'$lit'") && str_contains($read('deploy/backup.sh'), '"' . $lit . '"'), 'the fingerprint literal is exactly "rivetit-settings-key-fingerprint|v1|" in backup.php, RestoreDrill.php and backup.sh');
[$c, $o] = sec_sh('php -r ' . escapeshellarg('echo substr(hash("sha256", "' . $lit . '" . "some-settings-key"), 0, 16);'));
$ok($o === $want, 'and backup.sh would compute the same value');

// ------------------------------------------------------------------ cron.php keeps both blocks (the automatic backup and the recovery watch)
$cron = $read('cron/cron.php');
$ok(str_contains($cron, 'if ($config_backup_auto_enabled) {') && str_contains($cron, 'build_backup(') && str_contains($cron, 'BackupPassphraseRequired'), 'cron.php still runs the automatic backup (and handles the passphrase refusal)');
$ok(str_contains($cron, '\\RivetMSP\\Recovery\\RecoveryWatch::run(') && strpos($cron, 'RecoveryWatch::run(') > strpos($cron, 'if ($config_backup_auto_enabled) {'), 'cron.php runs the recovery watch after the automatic backup block');
$ok(str_contains($cron, '\\RivetMSP\\Mail\\MailHealth::runChecks('), 'cron.php runs the mail health checks');
$ok(!preg_match('/mail_queue\.php|ticket_email_parser\.php/', preg_replace('#//[^\n]*#', '', $cron)) , 'cron.php does not call the mail queue or the parser itself (they are separate jobs)');

// ------------------------------------------------------------------ what the installer schedules
$inst = $read('deploy/install.sh');
preg_match('/install_cron\(\) \{.*?\n\}\n/s', $inst, $ic);
$icb = $ic[0] ?? '';
foreach (['cron.php' => '*/5 * * * *', 'mail_queue.php' => '* * * * *', 'ticket_email_parser.php' => '* * * * *', 'restore_drill.php' => '45 3 * * *'] as $job => $sched) {
    $ok(str_contains($icb, $sched . ' www-data /usr/bin/php ${APP_DIR}/cron/' . $job . ' >> /var/log/'), "install_cron schedules cron/$job ($sched) with a log under /var/log");
}
foreach (['itflow-cron.log', 'itflow-mail-queue-cron.log', 'itflow-mail-parser-cron.log', 'itflow-restore-drill-cron.log'] as $lg) {
    $ok(substr_count($icb, "/var/log/$lg") >= 2, "the log file $lg is created up front (cron cannot create files in /var/log)");
}
$sup = $read('docker/supervisord.conf');
$ok(str_contains($sup, 'cron/mail_queue.php') && str_contains($sup, 'cron/ticket_email_parser.php') && str_contains($sup, 'cron/cron.php'), 'the Docker supervisor runs cron.php, the mail queue and the parser');
$ok(str_contains($inst, 'install_cron') && substr_count($inst, "\n    install_cron\n") === 1, 'the installer calls install_cron once');

// ------------------------------------------------------------------ single-use TOTP is wired into both sign-in paths
$ok(str_contains($read('login.php'), 'TokenAuth6238::verifyOnce(') && !str_contains($read('login.php'), 'TokenAuth6238::verify('), 'login.php verifies the authenticator code once (no replayable verify())');
$ok(str_contains($read('api/v1/auth.php'), 'TokenAuth6238::verifyOnce(') && !str_contains($read('api/v1/auth.php'), 'TokenAuth6238::verify('), 'the mobile API login verifies the code once');
