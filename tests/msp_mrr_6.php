<?php
// MSP-DS-166 (re-review MRR-6): a stale 'processing' attempt is never taken over with a key older than Stripe's 24h window. REAL claim code, scratch DB.
// Run: SCRATCH_TABLES="invoices payments invoice_charge_attempts history" bash tests/lib/with_scratch_db.sh php tests/msp_mrr_6.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../includes/charge_attempts.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount) VALUES (1,'Sent',100.00),(2,'Sent',100.00),(3,'Sent',100.00),(4,'Sent',100.00)");
$old = function (int $inv, string $age, string $pi = '') use ($m) {
    $m->query("INSERT INTO invoice_charge_attempts SET attempt_invoice_id = $inv, attempt_key = 'oldkey-$inv', attempt_balance_cents = 10000, attempt_method_id = 5, attempt_source = 'agent', attempt_status = 'processing', attempt_pi_id = '$pi', attempt_created_at = NOW() - INTERVAL $age, attempt_updated_at = NOW() - INTERVAL $age");
};

// under 23h: the key is replayed (Stripe still remembers it and returns the original PaymentIntent)
$old(1, '2 HOUR'); $r = chargeAttemptClaim($m, 1, 'portal', 5);
$ok('a 2h-old abandoned attempt is taken over with its own key', $r['ok'] && $r['reused'] && $r['key'] === 'oldkey-1');

// over 23h, no way to ask Stripe: refused, flagged, the attempt keeps blocking
$old(2, '25 HOUR'); $r = chargeAttemptClaim($m, 2, 'portal', 5);
$ok('a 25h-old attempt is NOT taken over with its old key', !$r['ok'] && $r['reason'] === 'needs_review');
$ok('the old attempt stays processing (still blocks, not silently failed)', $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_invoice_id = 2") === 'processing');
$ok('the invoice is flagged for billing in its history', intval($one("SELECT COUNT(*) FROM history WHERE history_invoice_id = 2 AND history_status = 'Payment needs review'")) === 1);
$r = chargeAttemptClaim($m, 2, 'cron', 5);
$ok('every later claim is refused the same way, with one flag only', !$r['ok'] && $r['reason'] === 'needs_review' && intval($one("SELECT COUNT(*) FROM history WHERE history_invoice_id = 2")) === 1);

// over 23h with Stripe available
$old(3, '25 HOUR', 'pi_cancelled'); $r = chargeAttemptClaim($m, 3, 'portal', 5, fn ($pi) => $pi === 'pi_cancelled' ? 'canceled' : null);
$ok('Stripe says the old intent was cancelled: a new attempt with a NEW key', $r['ok'] && !$r['reused'] && $r['key'] !== 'oldkey-3' && str_ends_with($r['key'], '-a1'));
$ok('the old attempt is closed as failed', $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_key = 'oldkey-3'") === 'failed');
$old(4, '25 HOUR', 'pi_paid'); $r = chargeAttemptClaim($m, 4, 'portal', 5, fn ($pi) => 'succeeded');
$ok('Stripe says the old intent succeeded: refused, flagged, not charged again', !$r['ok'] && $r['reason'] === 'needs_review');
exit($fail ? 1 : 0);
