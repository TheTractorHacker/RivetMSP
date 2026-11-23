<?php
// Review R10: every Stripe amount site converts with the currency-aware helpers; helper pairs round-trip. Run: php tests/msp_ds_review_R10_stripe_minor_sites.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
require __DIR__ . '/../includes/billing_guards.php';
foreach ([['JPY', 5000, 5000], ['USD', 19.99, 1999], ['USD', 0.29, 29], ['KWD', 10.0, 10000], ['KWD', 1.23, 1230]] as [$c, $major, $minor]) {
    $ok(stripeAmountToMinor($major, $c) === $minor, "$c $major -> $minor");
    $ok(abs(stripeAmountFromMinor($minor, $c) - $major) < 1e-9, "$c $minor -> $major (round trip)");
}
// structural: no Stripe 'amount' parameter or refund uses a hard-coded x100
$root = realpath(__DIR__ . '/..');
$files = ['client/post.php', 'guest/guest_ajax.php', 'agent/post/payment.php', 'cron/cron.php', 'includes/class_stripe_payment_provider.php'];
foreach ($files as $f) {
    $src = file_get_contents("$root/$f");
    $ok(!preg_match("/'amount'\s*=>\s*moneyToCents\(/", $src), "$f: PaymentIntent amount is not moneyToCents");
    $ok(!preg_match("/\\\$params\['amount'\]\s*=\s*intval\(round\(\\\$amount\s*\*\s*100/", $src), "$f: no hard-coded x100 refund amount");
}
$cp = file_get_contents("$root/client/post.php");
$ok(str_contains($cp, "'amount' => stripeAmountToMinor(\$balance_to_pay, \$invoice_currency_code)"), 'portal saved-card charge uses stripeAmountToMinor');
$ok(str_contains($cp, 'stripeAmountToMinor($balance_to_pay, $invoice_currency_code) === intval($payment_intent->amount_received)'), 'portal success check compares minor units');
$rf = file_get_contents("$root/includes/class_stripe_payment_provider.php");
$ok(str_contains($rf, 'stripeAmountToMinor($amount, $pi->currency)'), 'refund converts with the PaymentIntent currency');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
