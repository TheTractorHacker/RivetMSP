<?php
// MSP-DS-166/32 (re-review M5-2): a bank debit waiting for microdeposits stopped blocking 10 days after it became 'pending' because settle calls rewrote the same
// status and pi (ON UPDATE never fired), and the next claim's blanket abandon UPDATE then failed the pending row WITHOUT asking Stripe and charged the card while the
// ACH landed days later. Now a pending row is freed only by a Stripe lookup (cancel, bank included) that says canceled/missing; otherwise the claim refuses
// (pending when money is moving, needs_review when Stripe cannot say); settle/store calls restart the pending window.
// REAL charge_attempts.php functions on a scratch DB, Stripe faked behind the real chargeAttemptPiOps().
// Run: SCRATCH_TABLES="invoices payments invoice_charge_attempts history" bash tests/lib/with_scratch_db.sh php tests/msp_m5_2.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../includes/charge_attempts.php';
require_once __DIR__ . '/../plugins/stripe-php/init.php';
require __DIR__ . '/lib/m5_stripe_fakes.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
for ($i = 1; $i <= 6; $i++) { $m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount) VALUES ($i,'Sent',100.00)"); }
$pending = function (int $inv, string $pi, string $age) use ($m) {
    $m->query("INSERT INTO invoice_charge_attempts SET attempt_invoice_id = $inv, attempt_key = 'bankkey-$inv', attempt_balance_cents = 10000, attempt_method_id = 3, attempt_source = 'portal', attempt_status = 'pending', attempt_pi_id = '$pi'");
    $m->query("UPDATE invoice_charge_attempts SET attempt_created_at = NOW() - INTERVAL $age, attempt_updated_at = NOW() - INTERVAL $age WHERE attempt_key = 'bankkey-$inv'");
};
$st = fn (int $inv) => $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_key = 'bankkey-$inv'");

// the bug: settle rewrites the same status/pi and the timestamp never moved
$pending(1, 'pi_b1', '9 DAY');
chargeAttemptSettleIntent($m, 'pi_b1', 1, 10000, 'pending', 'webhook');
$ok('a settle call on a pending row restarts its window (attempt_updated_at bumped)', intval($one("SELECT TIMESTAMPDIFF(HOUR, attempt_updated_at, NOW()) FROM invoice_charge_attempts WHERE attempt_key = 'bankkey-1'")) < 1);
$r = chargeAttemptClaim($m, 1, 'cron', 5, (new M5Stripe)->lookup());
$ok('... so the row still blocks (pending) and nothing was abandoned', !$r['ok'] && $r['reason'] === 'pending' && $st(1) === 'pending');

// a pending row past its window is never freed without Stripe
$pending(2, 'pi_b2', '11 DAY');
$r = chargeAttemptClaim($m, 2, 'cron', 5, null);
$ok('past 10 days, no way to ask Stripe: refused (needs_review), row stays pending, NOT abandoned', !$r['ok'] && $r['reason'] === 'needs_review' && $st(2) === 'pending');
$r = chargeAttemptClaim($m, 2, 'cron', 5, fn (string $pi, bool $c = false, ?string $k = null, bool $b = false) => null);
$ok('past 10 days, lookup errors: refused, row stays pending', !$r['ok'] && $st(2) === 'pending');
$ok('... and the invoice is flagged for billing', intval($one("SELECT COUNT(*) FROM history WHERE history_invoice_id = 2 AND history_status = 'Payment needs review'")) === 1);

$pending(3, 'pi_b3', '11 DAY');
$s = new M5Stripe; $s->add('pi_b3', 'processing');
$r = chargeAttemptClaim($m, 3, 'cron', 5, $s->lookup());
$ok('past 10 days but Stripe says the ACH is still processing: refused (pending), not abandoned, nothing canceled', !$r['ok'] && $r['reason'] === 'pending' && $st(3) === 'pending' && $s->pis['pi_b3']->cancels === 0);
$s = new M5Stripe; $s->add('pi_b3', 'succeeded');
$r = chargeAttemptClaim($m, 3, 'cron', 5, $s->lookup());
$ok('... and succeeded: refused, the webhook records it', !$r['ok'] && $st(3) === 'pending');

$pending(4, 'pi_b4', '11 DAY');
$s = new M5Stripe; $s->add('pi_b4', 'requires_action', '', true);
$r = chargeAttemptClaim($m, 4, 'cron', 5, $s->lookup());
$ok('past 10 days, still awaiting microdeposits: the lookup CANCELS it (bank included), then the row is freed and a new charge may go', $r['ok'] && $s->pis['pi_b4']->cancels === 1 && $st(4) === 'failed');

$pending(5, 'pi_b5', '12 DAY');
$s = new M5Stripe;
$r = chargeAttemptClaim($m, 5, 'cron', 5, $s->lookup());
$ok('past 10 days, Stripe has no such intent (missing): freed', $r['ok'] && $st(5) === 'failed');

// the store path restarts the window too
$m->query("INSERT INTO invoice_charge_attempts SET attempt_invoice_id = 6, attempt_key = 'k6', attempt_balance_cents = 10000, attempt_method_id = 3, attempt_source = 'portal', attempt_status = 'processing'");
$id = intval($one("SELECT attempt_id FROM invoice_charge_attempts WHERE attempt_key = 'k6'"));
$m->query("UPDATE invoice_charge_attempts SET attempt_updated_at = NOW() - INTERVAL 5 DAY WHERE attempt_id = $id");
chargeAttemptStoreResult($m, $id, 'processing', 'pi_b6');
$ok('chargeAttemptStoreResult pending stamps the window start', $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_id = $id") === 'pending' && intval($one("SELECT TIMESTAMPDIFF(HOUR, attempt_updated_at, NOW()) FROM invoice_charge_attempts WHERE attempt_id = $id")) < 1);
exit($fail ? 1 : 0);
