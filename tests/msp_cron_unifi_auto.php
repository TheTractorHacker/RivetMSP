<?php
// Scheduled UniFi sync: the REAL block from cron/cron.php (extracted by its markers) run on a scratch DB.
// An enabled controller gets an Auto run (triggered_by 0) logged; a second tick inside 15 minutes does nothing (even after a failure);
// a row older than 15 minutes lets it run again; a disabled controller is never touched. (The controller has no usable API key, so each run
// fails fast and is recorded as failed: no network.)
// Run: SCRATCH_TABLES="unifi_integrations unifi_sync_log app_logs" bash tests/lib/with_scratch_db.sh php tests/msp_cron_unifi_auto.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$root = dirname(__DIR__);
$GLOBALS['config_settings_enc_key'] = bin2hex(random_bytes(32));
require $root . '/functions.php';
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $mysqli->query("SET SESSION sql_mode=''");
$src = file_get_contents($root . '/cron/cron.php');
preg_match('/(\$sql_unifi_auto = .*?)\n\/\*\n \* #+\n \*  (GOOGLE|RMM ALERT AUTO)/s', $src, $m) or die("block not found\n");
$block = str_replace('dirname(__DIR__)', var_export($root, true), $m[1]);
$run = function () use ($block, $mysqli) { eval($block); };
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$n = fn (string $w = '1') => (int) $mysqli->query("SELECT COUNT(*) FROM unifi_sync_log WHERE $w")->fetch_row()[0];
$mysqli->query("INSERT INTO unifi_integrations (id, name, host, port, api_key_enc, enabled) VALUES (1,'On',  'u.example', 443, '', 1), (2,'Off', 'v.example', 443, '', 0)");
$run();
$ok('enabled controller got one logged run', $n('integration_id = 1') === 1);
$ok('it is an Auto run (triggered_by 0) and recorded as failed', $n("integration_id = 1 AND triggered_by = 0 AND status = 'failed'") === 1);
$ok('disabled controller is never synced', $n('integration_id = 2') === 0);
$run();
$ok('a second tick inside 15 minutes adds nothing', $n('integration_id = 1') === 1);
$mysqli->query("UPDATE unifi_sync_log SET started_at = NOW() - INTERVAL 20 MINUTE");
$run();
$ok('after 15 minutes it runs again', $n('integration_id = 1') === 2);
$ok('failure is written to the app log', (int) $mysqli->query("SELECT COUNT(*) FROM app_logs WHERE app_log_details LIKE 'UniFi sync failed%'")->fetch_row()[0] >= 1);
exit($fail ? 1 : 0);
