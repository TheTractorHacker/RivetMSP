<?php
// MSP-DS-32: cron autopay claims (invoice, day) before any Stripe call. Run: SCRATCH_TABLES=history tests/lib/with_scratch_db.sh php tests/msp_ds_32_autopay_once_per_day.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$src = file_get_contents(__DIR__ . '/../cron/cron.php');
$a = strpos($src, '$autopay_marker = ');
$b = strpos($src, '// One key per invoice', $a);
$c = strpos($src, '$payment_intent = $provider->chargeSavedMethod(', $b);
$ok('claim sits before the Stripe call', $a !== false && $b !== false && $c !== false && $a < $b && $b < $c);
$claim = function (int $invoice_id) use ($m, $src, $a, $b): bool {
    $mysqli = $m; $code = substr($src, $a, $b - $a);
    $GLOBALS['__claimed'] = false; eval('foreach ([1] as $_) { ' . $code . ' $GLOBALS["__claimed"] = true; }'); return $GLOBALS['__claimed'];
};
$ok('first cron tick claims the autopay attempt', $claim(701) === true);
$ok('the next tick the same day is skipped', $claim(701) === false);
$ok('every later tick that day is skipped (288 per day at */5)', (function () use ($claim) { for ($i = 0; $i < 30; $i++) { if ($claim(701)) { return false; } } return true; })());
$ok('another invoice the same day is a separate claim', $claim(702) === true);
$other = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$other->query("SELECT GET_LOCK('rivet_cron_autopay_claim', 0)");
$ok('a concurrent run holding the claim lock makes this run skip (no double charge)', $claim(703) === false);
$other->query("SELECT RELEASE_LOCK('rivet_cron_autopay_claim')");
$n = (int) $m->query("SELECT COUNT(*) FROM history WHERE history_invoice_id = 701")->fetch_row()[0];
$ok("exactly one marker row for invoice 701 ($n)", $n === 1);
$ok('idempotency key no longer rotates every minute', !str_contains($src, 'intdiv(time(), 60)') || !preg_match('/cron_autopay_stripe_[^;]*intdiv\(time\(\), 60\)/', $src));
exit($fail ? 1 : 0);
