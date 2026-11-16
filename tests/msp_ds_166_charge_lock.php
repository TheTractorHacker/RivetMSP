<?php
// MSP-DS-166: card charges for one invoice are serialised by a named lock and the balance is read after it.
// Run: SCRATCH_TABLES="payments" tests/lib/with_scratch_db.sh php tests/msp_ds_166_charge_lock.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mk = fn () => new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
require __DIR__ . '/../includes/billing_guards.php';
$a = $mk(); $b = $mk();
$ok('first charge path gets the invoice lock', invoiceChargeLock($a, 42, 0));
// second path (different connection = different request) cannot take it while the first is charging
$r = $b->query("SELECT GET_LOCK('rivetmsp_charge_inv_42', 0)")->fetch_row();
$ok('a concurrent path is refused while the first holds it', intval($r[0]) === 0);
$r = $b->query("SELECT GET_LOCK('rivetmsp_charge_inv_43', 0)")->fetch_row();
$ok('another invoice is not blocked', intval($r[0]) === 1);
invoiceChargeUnlock($a);
$r = $b->query("SELECT GET_LOCK('rivetmsp_charge_inv_42', 0)")->fetch_row();
$ok('after release the second path proceeds (and re-reads the recorded balance)', intval($r[0]) === 1);
$b->query("SELECT RELEASE_LOCK('rivetmsp_charge_inv_42')");
// switching invoices in one process releases the previous lock (cron loop)
invoiceChargeLock($a, 1, 0); invoiceChargeLock($a, 2, 0);
$r = $b->query("SELECT GET_LOCK('rivetmsp_charge_inv_1', 0)")->fetch_row();
$ok('cron loop: moving on to invoice 2 released invoice 1', intval($r[0]) === 1);
foreach (['agent/post/payment.php' => 2, 'client/post.php' => 1, 'cron/cron.php' => 1] as $f => $n) {
    $s = file_get_contents(__DIR__ . "/../$f");
    $c = 0; $off = 0;
    while (($pos = strpos($s, 'invoiceChargeLock(', $off)) !== false) { $off = $pos + 1; if (strpos(substr($s, $pos, 700), 'invoiceBalanceDue(') !== false) { $c++; } }
    $ok("$f takes the lock right before invoiceBalanceDue ($n site(s))", $c === $n);
}
exit($fail ? 1 : 0);
