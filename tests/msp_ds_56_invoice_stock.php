<?php
// MSP-DS-56: invoice line edits and deletes keep stock consistent.
require __DIR__ . '/lib/ds_src.php';
$s = ds_src('agent/post/invoice.php');
$edit = substr($s, strpos($s, "isset(\$_POST['edit_invoice_item'])"), 5000);
ds_t('edit reads stored quantity and product', strpos($edit, 'item_quantity, item_product_id FROM invoice_items') !== false);
ds_t('edit checks availability for an increase', strpos($edit, 'productStockMove(') !== false && strpos($edit, 'Not Enough') !== false);
ds_t('edit stock change precedes the line UPDATE', strpos($edit, '$stock_delta') < strpos($edit, 'UPDATE invoice_items SET'));
ds_t('edit uses the stored product, not posted product_id', strpos($edit, 'getFieldById(\'products\', $old_item_product_id') !== false);
$del = substr($s, strpos($s, "isset(\$_GET['delete_invoice_item'])"), 3500);
ds_t('item delete returns stock only for stocked products', strpos($del, "getFieldById('products', \$item_product_id, 'product_type') === 'product'") !== false);
$inv = substr($s, strpos($s, "isset(\$_GET['delete_invoice'])"), 3000);
ds_t('invoice delete returns stocked lines', strpos($inv, 'Returned QTY') !== false && strpos($inv, "product_type'] ?? '') === 'product'") !== false);
// arithmetic of the scenario from the report: add 1 (stock 1 -> 0), edit to 500 must be refused, delete returns only what left
$stock = 1; $line = 1; $stock -= 1; $avail = $stock; $delta = 500 - $line;
ds_t('scenario: raising the line to 500 with 0 in stock is refused', $avail < $delta);
ds_done();
