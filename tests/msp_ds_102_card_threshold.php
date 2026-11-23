<?php
// MSP-DS-102: the card threshold is enforced where the card is charged. Run:
// SCRATCH_TABLES="payment_providers" tests/lib/with_scratch_db.sh php tests/msp_ds_102_card_threshold.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
require __DIR__ . '/../includes/billing_guards.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$ok('no active provider: no limit', cardPaymentAllowedForInvoice($mysqli, 40000));
$mysqli->query("INSERT INTO payment_providers (payment_provider_id, payment_provider_name, payment_provider_active, payment_provider_threshold) VALUES (1,'Stripe',1,5000)");
$ok('below the threshold is allowed', cardPaymentAllowedForInvoice($mysqli, 4999.99));
$ok('at the threshold is refused (same rule as the hidden button)', !cardPaymentAllowedForInvoice($mysqli, 5000));
$ok('above the threshold is refused', !cardPaymentAllowedForInvoice($mysqli, 40000));
$mysqli->query("UPDATE payment_providers SET payment_provider_threshold = 0");
$ok('threshold 0 means no limit', cardPaymentAllowedForInvoice($mysqli, 40000));
$mysqli->query("UPDATE payment_providers SET payment_provider_threshold = 100, payment_provider_active = 0");
$ok('an inactive provider has no say', cardPaymentAllowedForInvoice($mysqli, 40000));
$root = dirname(__DIR__);
foreach (['guest/guest_ajax.php' => 'INSERT INTO|paymentIntents->create|stripe->|new \\Stripe|createPaymentIntent', 'guest/guest_pay_invoice_stripe.php' => 'SELECT \* FROM invoice_items', 'client/post.php' => 'new \\\\Stripe\\\\StripeClient\(\$private_key\)'] as $f => $after) {
    $s = file_get_contents("$root/$f");
    $g = strpos($s, 'cardPaymentAllowedForInvoice(');
    $ok("$f: threshold check present", $g !== false);
}
exit($fail ? 1 : 0);
