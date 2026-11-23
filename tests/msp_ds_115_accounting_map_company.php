<?php
// MSP-DS-115: the accounting entity map is dropped when a connect lands on a different company/organisation/provider.
// Run: SCRATCH_TABLES="accounting_entity_map platform_settings" bash tests/lib/with_scratch_db.sh php tests/msp_ds_115_accounting_map_company.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
require __DIR__ . '/../src/Platform/PlatformSettings.php';
// load only the two helpers from the functions file (it pulls in client classes)
$src = file_get_contents(__DIR__ . '/../includes/accounting_functions.php');
preg_match('/function accountingRememberConnection.*?\n}\n\n\/\*\* Call from.*?\n}\n/s', $src, $m);
eval($m[0]);
$n = fn() => (int) $mysqli->query("SELECT COUNT(*) FROM accounting_entity_map")->fetch_row()[0];
$mysqli->query("INSERT INTO accounting_entity_map (map_accounting_id,map_local_type,map_local_id,map_remote_id) VALUES (1,'client',7,'12'),(1,'invoice',301,'145')");
// legacy install: nothing remembered, integration currently connected to sandbox realm A
$prior = ['accounting_provider' => 'quickbooks_online', 'accounting_realm_id' => 'A'];
$ok(accountingBindConnection($mysqli, 1, 'quickbooks_online', 'A', $prior) === 0 && $n() === 2, 'reconnect to the same company keeps the map');
$ok(accountingBindConnection($mysqli, 1, 'quickbooks_online', 'B', ['accounting_provider' => 'quickbooks_online', 'accounting_realm_id' => 'A']) === 2 && $n() === 0, 'a different company drops the old remote ids');
$mysqli->query("INSERT INTO accounting_entity_map (map_accounting_id,map_local_type,map_local_id,map_remote_id) VALUES (1,'client',7,'99')");
// disconnect then reconnect elsewhere: the remembered binding is what is compared
accountingRememberConnection($mysqli, 'quickbooks_online', 'B');
$ok(accountingBindConnection($mysqli, 1, 'quickbooks_online', 'B', null) === 0 && $n() === 1, 'disconnect + reconnect to the same company keeps the map');
$ok(accountingBindConnection($mysqli, 1, 'xero', 'T1', null) === 1 && $n() === 0, 'provider switch drops the map');
$s = file_get_contents(__DIR__ . '/../admin/oauth_quickbooks_callback.php') . file_get_contents(__DIR__ . '/../admin/oauth_xero_callback.php');
$ok(substr_count($s, 'accountingBindConnection(') === 2, 'both callbacks call the binding check');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
