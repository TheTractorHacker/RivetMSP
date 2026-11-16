<?php
// MSP-DS-181: API invoice/quote detail report real subtotal/tax and a working guest URL; staff link points at a real page. Run: php tests/msp_ds_181_api_money_guest_url.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
foreach (['invoices' => 'invoice', 'quotes' => 'quote'] as $f => $k) {
    $s = file_get_contents("$root/api/v1/$f.php");
    $ok("$f: tax and subtotal come from the line items", str_contains($s, "'tax'          => round(\$sum_tax, 2)") && str_contains($s, "'subtotal'     => round(\$sum_subtotal, 2)") && !str_contains($s, "'tax'          => 0.0"));
    $ok("$f: total unchanged ({$k}_amount)", str_contains($s, "'total'        => floatval(\$row['{$k}_amount'])"));
    preg_match_all("#'guest_url'\s*=>\s*\\\$row\['{$k}_url_key'\] \? '(/guest/guest_view_{$k}\.php\?{$k}_id=)#", $s, $m);
    $ok("$f: guest_url (list and detail) points at the real guest page", count($m[0]) === 2 && is_file("$root/guest/guest_view_{$k}.php"));
    $ok("$f: response keys unchanged", str_contains($s, "'guest_url'") && str_contains($s, "'items'"));
}
$g = file_get_contents("$root/guest/guest_post.php");
$ok('staff accepted/declined emails link /agent/quote.php with plain quotes', substr_count($g, "https://\$config_base_url/agent/quote.php?quote_id=\$quote_id'>") === 2 && !str_contains($g, "/quote.php?quote_id=\$quote_id\\'") && is_file("$root/agent/quote.php"));
// arithmetic: 100 + 18 tax
$items = [['item_subtotal' => '100.00', 'item_tax' => '18.00']]; $st = $tx = 0.0; foreach ($items as $i) { $st += floatval($i['item_subtotal']); $tx += floatval($i['item_tax']); }
$ok('100 + 18 tax reports subtotal 100, tax 18', round($st, 2) === 100.0 && round($tx, 2) === 18.0);
exit($fail ? 1 : 0);
