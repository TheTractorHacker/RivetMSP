<?php
// MSP-DS-2: the overdue block claims (invoice, day, date) before acting. Run: SCRATCH_TABLES=history tests/lib/with_scratch_db.sh php tests/msp_ds_2_cron_overdue_once.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$src = file_get_contents(__DIR__ . '/../cron/cron.php');
$a = strpos($src, '$overdue_marker = ');
$b = strpos($src, '// Late Charges', $a);
$ok('claim code found in cron.php before the late-fee block', $a !== false && $b !== false);
// run the extracted claim twice for the same invoice/day, then for another day
$claim = function (int $invoice_id, int $day) use ($m, $src, $a, $b): bool {
    $mysqli = $m; $code = substr($src, $a, $b - $a);
    $fn = 'foreach ([1] as $_) { ' . $code . ' $GLOBALS["__claimed"] = true; }';
    $GLOBALS['__claimed'] = false; eval($fn); return $GLOBALS['__claimed'];
};
$ok('first tick claims the reminder', $claim(501, 30) === true);
$ok('second tick the same day is skipped', $claim(501, 30) === false);
$ok('288th tick is skipped too', (function () use ($claim) { for ($i = 0; $i < 20; $i++) { if ($claim(501, 30)) { return false; } } return true; })());
$ok('another reminder day for the same invoice is a new claim', $claim(501, 60) === true);
$ok('another invoice the same day is a new claim', $claim(502, 30) === true);
$other = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$other->query("SELECT GET_LOCK('rivet_cron_overdue_claim', 0)");
$ok('a concurrent run holding the claim lock makes this run skip (no double charge)', $claim(503, 30) === false);
$other->query("SELECT RELEASE_LOCK('rivet_cron_overdue_claim')");
$n = (int) $m->query("SELECT COUNT(*) FROM history WHERE history_invoice_id = 501")->fetch_row()[0];
$ok("exactly one marker per (invoice, day): $n rows", $n === 2);
exit($fail ? 1 : 0);
