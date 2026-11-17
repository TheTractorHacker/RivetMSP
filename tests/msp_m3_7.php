<?php
// MSP-DS-166 (re-review M3-7): a claim with a different saved method used to mark a stale 'processing' row abandoned and charge again with no Stripe check.
// A stale row that cannot be replayed is now looked up at Stripe first (or, past the 23h key window, refused and flagged). REAL claim code, scratch DB.
// Run: SCRATCH_TABLES="invoices payments invoice_charge_attempts history" bash tests/lib/with_scratch_db.sh php tests/msp_m3_7.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../includes/charge_attempts.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount) VALUES (1,'Sent',100.00),(2,'Sent',100.00),(3,'Sent',100.00),(4,'Sent',100.00),(5,'Sent',100.00)");
$old = function (int $inv, string $age, string $pi = '', int $method = 3) use ($m) {
    $m->query("INSERT INTO invoice_charge_attempts SET attempt_invoice_id = $inv, attempt_key = 'oldkey-$inv', attempt_balance_cents = 10000, attempt_method_id = $method, attempt_source = 'portal', attempt_status = 'processing', attempt_pi_id = '$pi', attempt_created_at = NOW() - INTERVAL $age, attempt_updated_at = NOW() - INTERVAL $age");
};
$st = fn (int $inv) => $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_key = 'oldkey-$inv'");

// 30 minutes old, a DIFFERENT saved method (cannot be replayed), the old charge actually went through at Stripe
$old(1, '30 MINUTE', 'pi_paid');
$r = chargeAttemptClaim($m, 1, 'agent', 5, fn ($pi) => 'succeeded');
$ok('old attempt succeeded at Stripe: a charge with another method is refused and flagged', !$r['ok'] && $r['reason'] === 'needs_review' && $st(1) === 'processing');
$ok('billing is flagged on the invoice', intval($one("SELECT COUNT(*) FROM history WHERE history_invoice_id = 1 AND history_status = 'Payment needs review'")) === 1);

// Stripe says the old intent never happened: closed and a new attempt is allowed
$old(2, '30 MINUTE', 'pi_cancel');
$r = chargeAttemptClaim($m, 2, 'agent', 5, fn ($pi) => 'canceled');
$ok('old attempt canceled at Stripe: closed, new charge allowed', $r['ok'] && $st(2) === 'failed');

// past 23h, different method, no lookup possible (no PaymentIntent id was ever stored): refused, not silently abandoned
$old(3, '25 HOUR');
$r = chargeAttemptClaim($m, 3, 'agent', 5);
$ok('a 25h-old row of another method cannot be checked: refused and kept blocking', !$r['ok'] && $r['reason'] === 'needs_review' && $st(3) === 'processing');

// a row with no intent id whose Stripe search by attempt key finds nothing (M4-3; an errored search refuses, see msp_m4_3)
$old(4, '30 MINUTE');
$r = chargeAttemptClaim($m, 4, 'agent', 5, fn ($pi, $c = false, $k = null) => $k !== null ? 'none' : null);   // M4-3: the search by attempt key finds nothing
$ok('a 30 minute old row with no intent id (the request died before Stripe) is still just abandoned', $r['ok'] && $st(4) === 'failed');

// the SAME method keeps replaying its key (unchanged), no Stripe call needed
$old(5, '30 MINUTE', '', 5);
$called = false;
$r = chargeAttemptClaim($m, 5, 'portal', 5, function ($pi) use (&$called) { $called = true; return 'succeeded'; });
$ok('same method: the key is replayed as before', $r['ok'] && $r['reused'] && $r['key'] === 'oldkey-5' && !$called);
exit($fail ? 1 : 0);
