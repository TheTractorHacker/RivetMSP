<?php
// MSP-DS-164: webhook binds the PaymentIntent to this install, the invoice's client and currency. Run: php tests/msp_ds_164_stripe_webhook_binding.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
require __DIR__ . '/../includes/billing_guards.php';
$installation_id = 'abc123installA'; $GLOBALS['installation_id'] = $installation_id;
$mine = stripeInstallMarker();
$ok('marker is 16 hex chars and not the raw id', preg_match('/^[0-9a-f]{16}$/', $mine) === 1 && !str_contains($mine, 'abc123'));
$GLOBALS['installation_id'] = 'otherInstallB'; $theirs = stripeInstallMarker(); $GLOBALS['installation_id'] = $installation_id;
$ok('markers differ between installs', $mine !== $theirs);
$md = fn (array $a) => (object) $a;
$ok('own marker + same client + same currency accepted', stripeIntentMatchesInvoice($md(['itflow_install' => $mine, 'itflow_client_id' => '7']), 'usd', 7, 'USD', $mine));
$ok("another install's intent rejected", !stripeIntentMatchesInvoice($md(['itflow_install' => $theirs, 'itflow_client_id' => '7']), 'usd', 7, 'USD', $mine));
$ok('different client rejected (legacy intent without marker)', !stripeIntentMatchesInvoice($md(['itflow_client_id' => '9']), 'usd', 7, 'USD', $mine));
$ok('currency mismatch rejected', !stripeIntentMatchesInvoice($md(['itflow_install' => $mine, 'itflow_client_id' => '7']), 'eur', 7, 'USD', $mine));
$ok('legacy intent without marker still accepted when client and currency match', stripeIntentMatchesInvoice($md(['itflow_client_id' => '7']), 'USD', 7, 'USD', $mine));
foreach (['client/post.php', 'guest/guest_ajax.php', 'agent/post/payment.php', 'cron/cron.php'] as $f) { $ok("$f stamps the install marker", str_contains(file_get_contents(__DIR__ . "/../$f"), "'itflow_install' => stripeInstallMarker()")); }
$w = file_get_contents(__DIR__ . '/../guest/payment_webhook.php');
$ok('webhook checks the binding before recording', strpos($w, 'stripeIntentMatchesInvoice(') < strpos($w, '// Recompute invoice status'));
$ok('refund/dispute events notify instead of being silently ignored', str_contains($w, 'charge.refunded') && str_contains($w, 'charge.dispute.created'));
exit($fail ? 1 : 0);
