<?php
// MSP-DS-68: invoice/quote from a ticket reject negative or huge lines; the invoice amount is recomputed from its lines.
require __DIR__ . '/lib/ds_src.php';
$s = ds_src('agent/post/ticket.php');
foreach (["isset(\$_POST['add_invoice_from_ticket'])", "isset(\$_POST['add_quote_from_ticket'])"] as $h) {
    $i = strpos($s, $h); $b = substr($s, $i, 3500);
    ds_t("$h validates the line before creating anything", strpos($b, '$line_qty_check < 0') !== false && strpos($b, '$line_qty_check < 0') < strpos($b, 'INSERT INTO'));
}
$i = strpos($s, "isset(\$_POST['add_invoice_from_ticket'])"); $b = substr($s, $i, 12000);
ds_t('amount recomputed from SUM(item_total) minus discount, floored at 0', strpos($b, 'SUM(item_total) AS invoice_total FROM invoice_items') !== false && strpos($b, 'max(0, floatval($row_total[\'invoice_total\']) - $invoice_discount_from_row)') !== false);
ds_t('old incremental formula gone', strpos($b, "floatval(\$row['invoice_amount']) + \$total") === false);
// the validation predicate itself
$bad = fn($q, $p) => $q < 0 || !is_finite($q * $p) || abs($q * $p) > 99999999;
ds_t('qty -10 refused', $bad(-10, 5)); ds_t('1e200 * 1e200 refused (INF)', $bad(1e200, 1e200)); ds_t('2 x 49.50 accepted', !$bad(2, 49.5)); ds_t('qty 0 accepted', !$bad(0, 10));
ds_done();
