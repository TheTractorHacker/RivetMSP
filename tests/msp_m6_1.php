<?php
// MSP-DS-166/32 (re-review M6-1): Clear honoured "I checked Stripe" even when the Stripe SEARCH worked and found an intent, if the retrieve of that intent then failed once
// (429, 5xx, timeout). Clear stored the hit and freed the row with the false note "search unavailable", so the next claim charged again while the found intent (for
// example a processing ACH) still settled. The tick now covers only a search that itself failed; with a hit and no readable status Clear refuses.
// REAL chargeAttemptClear() / chargeAttemptPiOps() with fake Stripe callables on a scratch DB.
// Run: SCRATCH_TABLES="invoices invoice_charge_attempts history" bash tests/lib/with_scratch_db.sh php tests/msp_m6_1.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../includes/charge_attempts.php';
require_once __DIR__ . '/../plugins/stripe-php/init.php';
require __DIR__ . '/lib/m5_stripe_fakes.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
for ($i = 1; $i <= 3; $i++) { $m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount, invoice_client_id, invoice_prefix, invoice_number) VALUES ($i,'Sent',100.00,1,'INV-',$i)"); }
$mk = function (int $inv) use ($m, $one) {
    $m->query("INSERT INTO invoice_charge_attempts SET attempt_invoice_id = $inv, attempt_key = 'key-$inv', attempt_balance_cents = 10000, attempt_method_id = 3, attempt_source = 'portal', attempt_status = 'processing', attempt_pi_id = '', attempt_created_at = NOW() - INTERVAL 2 HOUR, attempt_updated_at = NOW() - INTERVAL 2 HOUR");
    return intval($one("SELECT attempt_id FROM invoice_charge_attempts WHERE attempt_key = 'key-$inv'"));
};
$st = fn (int $inv) => $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_key = 'key-$inv'");
// the search finds pi_X, then the retrieve of pi_X fails transiently
$flaky = function (): callable {
    return chargeAttemptPiOps(
        function (string $id) { throw new RuntimeException('Stripe 429 / timeout'); },
        function (string $key) { $p = new M5Pi('pi_X', 'processing', $key); return [$p]; }
    );
};
$id1 = $mk(1);
$r = chargeAttemptClear($m, $id1, 'stuck ACH', $flaky(), 'Alice', true);
$ok('search hit + failed retrieve + "I checked Stripe": REFUSED, row kept open', !$r['ok'] && $st(1) === 'processing');
$ok('the refusal names the found payment and does not claim the search was unavailable', str_contains($r['message'], 'pi_X') && stripos($r['message'], 'unavailable') === false);
$r = chargeAttemptClear($m, $id1, 'stuck ACH', $flaky(), 'Alice', false);
$ok('same without the tick: refused too', !$r['ok'] && $st(1) === 'processing');
// the search itself failing is still the case the tick is for
$id2 = $mk(2); $s = new M5Stripe; $s->searchDown = true;
$r = chargeAttemptClear($m, $id2, 'India account, no search', $s->lookup(), 'Alice', true);
$ok('search itself unavailable + tick: still cleared', $r['ok'] && $st(2) === 'failed');
// a hit whose status IS readable keeps working (canceled/missing frees it)
$id3 = $mk(3); $s = new M5Stripe; $s->add('pi_Y', 'requires_payment_method', 'key-3');
$r = chargeAttemptClear($m, $id3, 'timed out', $s->lookup(), 'Alice', false);
$ok('a readable cancelable hit is canceled and cleared as before', $r['ok'] && $st(3) === 'failed');
exit($fail);
