<?php
// Review R29: a foreign install's PaymentIntent never raises "payment received but not recorded"; ours still does. Run: php tests/msp_ds_review_R29_webhook_foreign_intent.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
require __DIR__ . '/../includes/billing_guards.php';
$mine = 'aaaa1111'; $theirs = 'bbbb2222';
$inv = ['invoice_client_id' => 5, 'invoice_currency_code' => 'USD'];
$md = fn(array $a) => (object) $a;
$ok(!stripeUnrecordedIntentIsOurs($md(['itflow_install' => $theirs, 'itflow_client_id' => '5']), 'USD', $inv, $mine), 'other install marker, paid invoice: ignored');
$ok(!stripeUnrecordedIntentIsOurs($md(['itflow_install' => $theirs]), 'USD', null, $mine), 'other install marker, unknown invoice: ignored');
$ok(!stripeUnrecordedIntentIsOurs($md(['itflow_install' => $mine, 'itflow_client_id' => '9']), 'USD', $inv, $mine), 'our marker but another client: ignored');
$ok(!stripeUnrecordedIntentIsOurs($md(['itflow_install' => $mine, 'itflow_client_id' => '5']), 'EUR', $inv, $mine), 'our marker, currency mismatch: ignored');
$ok(stripeUnrecordedIntentIsOurs($md(['itflow_install' => $mine, 'itflow_client_id' => '5']), 'usd', $inv, $mine), 'our payment on a closed invoice: still alerts');
$ok(stripeUnrecordedIntentIsOurs($md(['itflow_client_id' => '5']), 'USD', $inv, $mine), 'legacy intent without marker: still alerts');
$w = file_get_contents(__DIR__ . '/../guest/payment_webhook.php');
$g = strpos($w, 'stripeUnrecordedIntentIsOurs(');
$ok($g !== false && $g < strpos($w, 'invoiceHistoryOnce(') && $g < strpos($w, 'appNotify("Invoice Payment Needs Review"'), 'webhook checks ownership before history row and alert');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
