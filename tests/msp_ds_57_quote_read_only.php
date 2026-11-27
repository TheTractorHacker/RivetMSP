<?php
// MSP-DS-57: quote items/header cannot change once Accepted, Declined or Invoiced (server side, not only hidden buttons).
require __DIR__ . '/lib/ds_src.php';
require __DIR__ . '/../includes/billing_guards.php';
foreach (['Draft' => true, 'Sent' => true, 'Viewed' => true, 'Accepted' => false, 'Declined' => false, 'Invoiced' => false] as $st => $exp) {
    ds_t("quoteContentEditable($st) = " . var_export($exp, true), quoteContentEditable($st) === $exp);
}
$s = ds_src('agent/post/quote.php');
foreach (['isset($_POST[\'add_quote_item\'])', 'isset($_POST[\'edit_quote_item\'])', 'isset($_POST[\'edit_quote\'])', 'isset($_GET[\'delete_quote_item\'])'] as $h) {
    $i = strpos($s, $h); $b = substr($s, $i, 2500);
    $g = strpos($b, 'quoteContentEditable('); $w = strpos($b, 'UPDATE quote') !== false ? min(array_filter([strpos($b, 'UPDATE quote'), strpos($b, 'INSERT INTO quote_items'), strpos($b, 'DELETE FROM quote_items')], fn($x) => $x !== false)) : PHP_INT_MAX;
    ds_t("$h is guarded before it writes", $g !== false && $g < $w);
}
ds_done();
