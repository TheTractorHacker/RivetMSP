<?php
// MSP-DS-129: lines of an invoice with payments cannot be edited/deleted; edit_invoice refuses a total below what was paid.
// Run: SCRATCH_TABLES="invoices payments" bash tests/lib/with_scratch_db.sh php tests/msp_ds_129_partial_invoice_edit.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
require __DIR__ . '/../includes/billing_guards.php';
$mysqli->query("INSERT INTO invoices (invoice_id, invoice_client_id, invoice_status, invoice_amount) VALUES (1,1,'Partial',100),(2,1,'Sent',100)");
$mysqli->query("INSERT INTO payments (payment_id, payment_invoice_id, payment_amount, payment_date) VALUES (1,1,60,'2026-01-01')");
$ok(invoiceChangeBlockReason($mysqli, 1, true) !== null, 'Partial invoice: line edit/delete is refused');
$ok(invoiceChangeBlockReason($mysqli, 2, true) === null, 'Sent invoice without payments: still editable');
$ok(invoiceBalanceDue($mysqli, 1, 40) < 0, 'a total of 40 under 60 paid is detected');
$ok(invoiceBalanceDue($mysqli, 1, 60) >= 0 && invoiceBalanceDue($mysqli, 1, 150) > 0, 'totals at or above the paid amount pass');
$s = file_get_contents(__DIR__ . '/../agent/post/invoice.php');
foreach (['edit_invoice_item', 'delete_invoice_item'] as $h) {
    $p = strpos($s, "isset(\$_POST['$h'])") ?: strpos($s, "isset(\$_GET['$h'])");
    $ok($p !== false && strpos(substr($s, $p, 3500), 'invoiceChangeBlockReason($mysqli, $invoice_id, true)') !== false, "$h uses the block-if-payments guard");
}
$ok(strpos($s, 'invoiceBalanceDue($mysqli, $invoice_id, $invoice_amount) < 0') !== false, 'edit_invoice checks the new total against payments');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
