<?php
// MSP-DS-161: Stripe amounts use the currency's minor unit. Run: php tests/msp_ds_161_stripe_minor_units.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
require __DIR__ . '/../includes/billing_guards.php';
$ok('USD 19.99 -> 1999', stripeAmountToMinor(19.99, 'usd') === 1999);
$ok('JPY 10000 -> 10000 (not 1,000,000)', stripeAmountToMinor(10000, 'JPY') === 10000);
$ok('KRW round trip', stripeAmountFromMinor(50000, 'krw') === 50000.0);
$ok('KWD 1.234 -> 1230 (ends in 0)', stripeAmountToMinor(1.234, 'KWD') === 1230);
$ok('KWD 1230 -> 1.23', abs(stripeAmountFromMinor(1230, 'KWD') - 1.23) < 1e-9);
$ok('USD 1999 -> 19.99', abs(stripeAmountFromMinor(1999, 'USD') - 19.99) < 1e-9);
foreach (['guest/guest_ajax.php', 'agent/post/payment.php', 'cron/cron.php', 'client/post.php', 'guest/payment_webhook.php', 'guest/guest_pay_invoice_stripe.php'] as $f) {
    $s = file_get_contents(__DIR__ . "/../$f");
    $ok("$f has no hard-coded x100 or /100 on Stripe amounts", !preg_match('/balance_to_pay \* 100|amount_received \/ 100/', $s));
}
exit($fail ? 1 : 0);
