<?php
// MSP-DS-35: a 'processing' bank payment is not reported as failed, and an unrecordable succeeded payment is never silently ignored. No Stripe call is made.
// Run: SCRATCH_TABLES=history tests/lib/with_scratch_db.sh php tests/msp_ds_35_ach_processing_reported.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
require __DIR__ . '/../includes/billing_guards.php';
$a = invoiceHistoryOnce($mysqli, 9, 'Payment pending', 'Bank payment submitted and processing (not yet settled). Stripe PaymentIntent', 'pi_123');
$b = invoiceHistoryOnce($mysqli, 9, 'Payment pending', 'Bank payment submitted and processing (not yet settled). Stripe PaymentIntent', 'pi_123');
$c = invoiceHistoryOnce($mysqli, 9, 'Payment pending', 'Bank payment submitted and processing (not yet settled). Stripe PaymentIntent', 'pi_456');
$ok('first sighting writes the history row (caller notifies)', $a === true);
$ok('a page reload / event replay writes nothing and does not notify again', $b === false);
$ok('a different PaymentIntent is a separate row', $c === true);
$n = (int) $mysqli->query("SELECT COUNT(*) FROM history WHERE history_invoice_id = 9")->fetch_row()[0];
$ok("two rows for invoice 9 ($n)", $n === 2);
$pg = file_get_contents(__DIR__ . '/../guest/guest_pay_invoice_stripe.php');
$i = strpos($pg, '$pi_obj->status === "processing"'); $j = strpos($pg, '$pi_obj->status !== "succeeded"');
$ok("return handler treats 'processing' before the failure exit", $i !== false && $j !== false && $i < $j);
$block = substr($pg, $i, $j - $i);
$ok("the processing branch does not show the failure wording", !str_contains($block, 'WORDING_PAYMENT_FAILED') && str_contains($block, 'processing'));
$ok('the processing branch records and notifies, then stops without recording a payment', str_contains($block, 'invoiceHistoryOnce(') && str_contains($block, 'appNotify(') && !str_contains($block, 'INSERT INTO payments'));
$wh = file_get_contents(__DIR__ . '/../guest/payment_webhook.php');
$k = strpos($wh, 'Invoice unknown or no longer payable'); $l = strpos($wh, '$invoice_id            = intval($inv', $k);
$wb = substr($wh, $k, $l - $k);
$ok('webhook: an unrecordable succeeded payment raises a notification and history row', str_contains($wb, 'invoiceHistoryOnce(') && str_contains($wb, 'appNotify('));
// review R29: a foreign install's intent is 'ignored' (before the alert); our own unrecordable payment is still flagged 'error'
$own = strpos($wb, 'stripeUnrecordedIntentIsOurs(');
$ok("webhook: our unrecordable payment is flagged 'error' (needs review); only a foreign intent (checked first) is 'ignored'", str_contains($wb, "event_status = 'error'") && $own !== false && substr_count($wb, "event_status = 'ignored'") === 1 && strpos($wb, "event_status = 'ignored'") > $own && strpos($wb, "event_status = 'ignored'") < strpos($wb, 'invoiceHistoryOnce('));
exit($fail ? 1 : 0);
