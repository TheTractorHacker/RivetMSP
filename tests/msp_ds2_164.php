<?php
// MSP-DS-164: refund and dispute events are RECORDED (payment_reversals + invoice history), only for payments this install recorded; amount mismatches are flagged.
// Run: SCRATCH_TABLES="payments payment_reversals history" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_164.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
require __DIR__ . '/../includes/charge_attempts.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$m->query("INSERT INTO payments (payment_id, payment_invoice_id, payment_amount, payment_date, payment_method, payment_reference) VALUES (1, 42, 100.00, CURDATE(), 'Stripe', 'Stripe - pi_ours')");

$refund = (object) ['id' => 'ch_1', 'payment_intent' => 'pi_ours', 'amount_refunded' => 2500, 'currency' => 'usd', 'refunded' => false];
$r = stripeRecordReversal($m, 'charge.refunded', $refund);
$ok('a partial refund of our payment is recorded', $r && $r['changed'] && $r['kind'] === 'refund' && $r['amount'] === 25.0 && $r['invoice_id'] === 42 && $r['status'] === 'partially_refunded');
$ok('... with an invoice history row', intval($one("SELECT COUNT(*) FROM history WHERE history_invoice_id = 42 AND history_status = 'Payment refunded'")) === 1);
$r = stripeRecordReversal($m, 'charge.refunded', $refund);
$ok('a replayed event changes nothing and adds no second history row', $r && !$r['changed'] && intval($one("SELECT COUNT(*) FROM payment_reversals")) === 1 && intval($one("SELECT COUNT(*) FROM history WHERE history_invoice_id = 42")) === 1);
$refund->amount_refunded = 10000; $refund->refunded = true;
$r = stripeRecordReversal($m, 'charge.refunded', $refund);
$ok('the full refund (a new total) is a second reversal row', $r['changed'] && $r['status'] === 'refunded' && intval($one("SELECT COUNT(*) FROM payment_reversals WHERE reversal_kind='refund'")) === 2);

$dispute = (object) ['id' => 'dp_1', 'payment_intent' => 'pi_ours', 'amount' => 10000, 'currency' => 'usd', 'status' => 'needs_response'];
$r = stripeRecordReversal($m, 'charge.dispute.created', $dispute);
$ok('a dispute is recorded with its status', $r && $r['kind'] === 'dispute' && $r['status'] === 'needs_response' && $r['changed']);
$r = stripeRecordReversal($m, 'charge.dispute.funds_withdrawn', $dispute);
$ok('funds withdrawn updates the SAME dispute row (not a new one)', $r['changed'] && $r['status'] === 'funds_withdrawn' && intval($one("SELECT COUNT(*) FROM payment_reversals WHERE reversal_kind='dispute'")) === 1);
$dispute->status = 'won';
$r = stripeRecordReversal($m, 'charge.dispute.closed', $dispute);
$ok('closing it as won is recorded', $r['status'] === 'won' && $one("SELECT reversal_status FROM payment_reversals WHERE reversal_ref = 'dispute:dp_1'") === 'won');
$ok('disputes show on the invoice', intval($one("SELECT COUNT(*) FROM history WHERE history_invoice_id = 42 AND history_status = 'Payment disputed'")) === 3);

$foreign = (object) ['id' => 'ch_x', 'payment_intent' => 'pi_other_install', 'amount_refunded' => 500, 'currency' => 'usd', 'refunded' => false];
$before = intval($one("SELECT COUNT(*) FROM payment_reversals"));
$ok("another install's refund (no payment recorded here) is ignored", stripeRecordReversal($m, 'charge.refunded', $foreign) === null && intval($one("SELECT COUNT(*) FROM payment_reversals")) === $before);
$ok('a charge without a PaymentIntent is ignored', stripeRecordReversal($m, 'charge.refunded', (object) ['id' => 'ch_y', 'amount_refunded' => 1]) === null);

$w = file_get_contents(__DIR__ . '/../guest/payment_webhook.php');
foreach (['charge.refunded', 'charge.dispute.created', 'charge.dispute.funds_withdrawn', 'charge.dispute.closed'] as $ev) { $ok("webhook routes $ev to stripeRecordReversal", str_contains($w, "'$ev'")); }
$ok('webhook binds the intent to this install/client/currency before recording', strpos($w, 'stripeIntentMatchesInvoice(') < strpos($w, 'insertStripePaymentOnce('));
$ok('webhook flags an amount that differs from the balance, after reading the balance and before recording', ($a = strpos($w, '$balance_before_cents = ')) !== false && $a < strpos($w, 'insertStripePaymentOnce(') && str_contains($w, "'Payment amount differs'"));
exit($fail ? 1 : 0);
