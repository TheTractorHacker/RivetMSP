<?php
// MSP-DS-32: autopay is attempted once per invoice per day, a bank debit that is still 'processing' is not charged again, and cron/guest/webhook keep the
// charge attempt in step with Stripe. Real functions against a scratch DB, plus wiring checks on the entry points.
// Run: SCRATCH_TABLES="invoices payments invoice_charge_attempts history" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_32.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mk = function () { $m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''"); return $m; };
require __DIR__ . '/../includes/charge_attempts.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = $mk();
// 8. cron: once per invoice per day, whatever the outcome
$ok('autopay claim: first run of the day wins', autopayClaimToday($m, 701, '2026-11-16') === true);
$ok('autopay claim: every later run that day is skipped', autopayClaimToday($m, 701, '2026-11-16') === false && autopayClaimToday($m, 701, '2026-11-16') === false);
$ok('autopay claim: next day / other invoice are separate', autopayClaimToday($m, 701, '2026-11-17') && autopayClaimToday($m, 702, '2026-11-16'));
$other = $mk(); $other->query("SELECT GET_LOCK('rivet_cron_autopay_claim', 0)");
$ok('autopay claim: an overlapping run holding the claim lock makes this run skip', autopayClaimToday($m, 703, '2026-11-16') === false);


// ACH through the cron path: the debit is 'processing' at Stripe, so the next day's / next run's claim must not charge
$m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount) VALUES (30,'Sent',90.00)");
$c = chargeAttemptClaim($m, 30, 'cron', 4); chargeAttemptStoreResult($m, $c['attempt_id'], 'processing', 'pi_cron_ach');
$r = chargeAttemptClaim($m, 30, 'cron', 4);
$ok("bank autopay 'processing': a later cron run is refused (pending), never re-charged", !$r['ok'] && $r['reason'] === 'pending');
chargeAttemptSettleIntent($m, 'pi_cron_ach', 30, 9000, 'succeeded');
$ok('the webhook succeeded event closes the attempt', $m->query("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_pi_id='pi_cron_ach'")->fetch_row()[0] === 'succeeded');

// wiring
$cron = file_get_contents(__DIR__ . '/../cron/cron.php');
$a = strpos($cron, 'autopayClaimToday('); $b = strpos($cron, 'chargeAttemptClaim(', $a); $s = strpos($cron, '$provider->chargeSavedMethod(', $b);
$ok('cron: day claim, then charge claim, then Stripe', $a !== false && $b > $a && $s > $b);
$ok("cron: a 'processing' PaymentIntent is recorded as pending, not as a failure to retry", str_contains($cron, "\$payment_intent->status == 'processing'") && str_contains($cron, 'chargeAttemptStoreResult('));
$ok('cron: the key comes from the claim (no clock bucket)', str_contains($cron, "\$idempotency_key = \$charge_claim['key']") && !preg_match('/cron_autopay_stripe/', $cron));
$hook = file_get_contents(__DIR__ . '/../guest/payment_webhook.php');
foreach (['payment_intent.processing', 'payment_intent.payment_failed', 'payment_intent.canceled'] as $ev) { $ok("webhook handles $ev", str_contains($hook, "'$ev'")); }
$ok('webhook settles the attempt on payment_intent.succeeded', substr_count($hook, "chargeAttemptSettleIntent(") >= 3);
$ajax = file_get_contents(__DIR__ . '/../guest/guest_ajax.php');
$ok('guest page refuses to create an intent while a charge is live', str_contains($ajax, 'chargeAttemptLive('));
$ret = file_get_contents(__DIR__ . '/../guest/guest_pay_invoice_stripe.php');
$ok("guest return: a processing debit is recorded as pending for the other paths", str_contains($ret, "'pending', 'guest'"));
exit($fail ? 1 : 0);
