<?php
// MSP-DS-103: nothing reads the non-existent companies.company_base_url; the host comes from config.php. Run: php tests/msp_ds_103_guest_base_url.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$root = dirname(__DIR__);
$db = file_get_contents("$root/db.sql");
$companies = substr($db, strpos($db, 'CREATE TABLE `companies`'), 2500);
$companies = substr($companies, 0, strpos($companies, 'ENGINE='));
$ok(!str_contains($companies, 'company_base_url'), 'premise: companies has no company_base_url column');
foreach (['guest/guest_pay_invoice_stripe.php', 'guest/payment_webhook.php'] as $f) {
    $s = file_get_contents("$root/$f");
    $code = preg_replace('#//[^\n]*#', '', $s);
    $ok(!str_contains($code, 'company_base_url'), "$f has no company_base_url query or lookup");
}
$p = file_get_contents("$root/guest/guest_pay_invoice_stripe.php");
$ok(str_contains($p, '$existing_base_url     = (string) $config_base_url;'), 'already-recorded branch redirects with the config.php host');
$ok(!preg_match('/\$config_base_url\s*=\s*nullable_htmlentities/', $p), 'the pay page no longer blanks $config_base_url');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
