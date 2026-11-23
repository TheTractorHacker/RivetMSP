<?php
// Setup creates the restore drill's scoped account and enables the drill: REAL setupProvisionRestoreDrill() on a scratch DB.
// Success path (recording exec seam): CREATE USER + GRANT limited to `drill\_%`, password stored ENCRYPTED, drill_enabled = 1.
// Failure path (the scratch DB user really cannot CREATE USER): nothing is enabled, message carries the SQL to run.
// Run: SCRATCH_TABLES="recovery_settings" bash tests/lib/with_scratch_db.sh php tests/msp_setup_restore_drill.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../vendor/autoload.php';
$config_settings_enc_key = bin2hex(random_bytes(32)); $GLOBALS['config_settings_enc_key'] = $config_settings_enc_key;
require __DIR__ . '/../functions.php';
require __DIR__ . '/../setup/setup_functions.php';
use RivetMSP\Recovery\RecoverySettings;
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$ok('drill is off before setup', RecoverySettings::get($m, 'drill_enabled') === '0');
$real = setupProvisionRestoreDrill($m, 'localhost');
$ok('real run without CREATE USER rights: not ok', $real['ok'] === false && $real['enabled'] === false);
$ok('... drill stays off', RecoverySettings::get($m, 'drill_enabled') === '0' && RecoverySettings::get($m, 'drill_db_user') === '');
$ok('... message gives the GRANT to run', str_contains($real['message'], 'GRANT ALL PRIVILEGES ON `drill\_%`'));
$sql = [];
$r = setupProvisionRestoreDrill($m, 'localhost', function (string $q) use (&$sql) { $sql[] = $q; return true; });
$ok('seam run ok and enabled', $r['ok'] && $r['enabled']);
$ok('drill_enabled = 1', RecoverySettings::get($m, 'drill_enabled') === '1');
$ok('drill user saved', RecoverySettings::get($m, 'drill_db_user') === 'rivet_drill');
$stored = RecoverySettings::get($m, 'drill_db_pass');
$plain = RecoverySettings::drillPassword($m);
$ok('password is stored encrypted, not plain', $stored !== '' && $stored !== $plain && strlen($plain) === 48);
$grant = array_values(array_filter($sql, fn ($q) => str_starts_with($q, 'GRANT')))[0] ?? '';
$ok('GRANT is scoped to drill\_% only', preg_match('/^GRANT ALL PRIVILEGES ON `drill\\\\_%`\.\* TO \'rivet_drill\'@\'localhost\'$/', $grant) === 1);
$ok('the same password reached CREATE USER', str_contains($sql[0], $plain));
$sql2 = [];
setupProvisionRestoreDrill($m, 'db.internal', function (string $q) use (&$sql2) { $sql2[] = $q; return true; });
$ok("remote database host uses '%' account host", str_contains($sql2[0], "'rivet_drill'@'%'"));
exit($fail ? 1 : 0);
