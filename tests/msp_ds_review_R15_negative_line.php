<?php
// Review R15: a negative-price line cannot take an invoice below what was paid (add_invoice_item, add_invoice_from_ticket); from-ticket quotes are floored at 0.
// Run: SCRATCH_TABLES="invoices payments invoice_items" bash tests/lib/with_scratch_db.sh php tests/msp_ds_review_R15_negative_line.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''"); $mysqli->query("SET FOREIGN_KEY_CHECKS=0");
require __DIR__ . '/../includes/billing_guards.php';
$mysqli->query("INSERT INTO invoices (invoice_id, invoice_client_id, invoice_status, invoice_amount, invoice_discount_amount) VALUES (1,1,'Partial',100,0),(2,1,'Sent',100,0)");
$mysqli->query("INSERT INTO invoice_items (item_id, item_invoice_id, item_total) VALUES (1,1,100),(2,2,100)");
$mysqli->query("INSERT INTO payments (payment_id, payment_invoice_id, payment_amount, payment_date) VALUES (1,1,60,'2026-01-01')");
$ok(invoiceLineBlockReason($mysqli, 1, -100) !== null, '$100 invoice, $60 paid: -100 line refused');
$ok(invoiceLineBlockReason($mysqli, 1, -50) !== null, '-50 line (total 50 < 60 paid) refused');
$ok(invoiceLineBlockReason($mysqli, 1, -40) === null, '-40 line (total exactly the paid amount) allowed');
$ok(invoiceLineBlockReason($mysqli, 1, 25) === null, 'positive line allowed');
$ok(invoiceLineBlockReason($mysqli, 2, -100) === null, 'unpaid invoice may take a credit line');
$mysqli->query("UPDATE invoices SET invoice_amount = 60 WHERE invoice_id = 1");
invoiceSettleStatus($mysqli, 1);
$ok($mysqli->query("SELECT invoice_status FROM invoices WHERE invoice_id = 1")->fetch_row()[0] === 'Paid', 'Partial invoice fully covered by payments is settled to Paid');
invoiceSettleStatus($mysqli, 2);
$ok($mysqli->query("SELECT invoice_status FROM invoices WHERE invoice_id = 2")->fetch_row()[0] === 'Sent', 'unpaid invoice status untouched');
$inv = file_get_contents(__DIR__ . '/../agent/post/invoice.php');
$tk = file_get_contents(__DIR__ . '/../agent/post/ticket.php');
$b = substr($inv, strpos($inv, "isset(\$_POST['add_invoice_item'])"), 4000);
$ok(str_contains($b, 'invoiceLineBlockReason(') && strpos($b, 'invoiceLineBlockReason(') < strpos($b, 'INSERT INTO invoice_items') && strpos($b, 'invoiceLineBlockReason(') < strpos($b, 'INSERT INTO product_stock'), 'add_invoice_item checks before stock/insert');
$ok(str_contains($b, 'invoiceSettleStatus(') || str_contains(substr($inv, strpos($inv, "isset(\$_POST['add_invoice_item'])"), 7000), 'invoiceSettleStatus('), 'add_invoice_item settles status');
$b = substr($tk, strpos($tk, "isset(\$_POST['add_invoice_from_ticket'])"), 9000);
$ok(str_contains($b, 'invoiceLineBlockReason(') && strpos($b, 'invoiceLineBlockReason(') < strpos($b, 'INSERT INTO invoice_items'), 'add_invoice_from_ticket checks before insert');
$ok(str_contains($b, 'invoiceSettleStatus('), 'add_invoice_from_ticket settles status');
$b = substr($tk, strpos($tk, "isset(\$_POST['add_quote_from_ticket'])"), 6000);
$ok(str_contains($b, 'quote_amount = " . max(0, $total)'), 'add_quote_from_ticket floors quote_amount at 0');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
