<?php
// MSP-DS-56: invoice line add / edit / delete keep stock consistent and atomic. Runs the REAL agent/post/invoice.php handlers against a scratch DB.
// Run: SCRATCH_TABLES="users user_roles user_settings user_client_permissions logs clients invoices invoice_items products product_stock payments history taxes" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_56.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
require __DIR__ . '/lib/handler_run.php';
mysqli_report(MYSQLI_REPORT_OFF);
$mk = function () { $m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''"); return $m; };
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = $mk();
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$stock = fn (int $p) => floatval($one("SELECT COALESCE(SUM(stock_qty),0) FROM product_stock WHERE stock_product_id = $p"));

$hash = password_hash('x', PASSWORD_DEFAULT);
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (1,'Admin',1)");
$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status) VALUES (1,'Adm','a@x.test','$hash',1,1,1)");
$m->query("INSERT INTO clients (client_id, client_name) VALUES (1,'C')");
$m->query("INSERT INTO products (product_id, product_name, product_type, product_price) VALUES (1,'Widget','product',10),(2,'Support','service',50)");
$m->query("INSERT INTO invoices (invoice_id, invoice_client_id, invoice_status, invoice_amount, invoice_prefix, invoice_number) VALUES (1,1,'Draft',0,'INV-',1),(2,1,'Draft',0,'INV-',2)");
$m->query("INSERT INTO product_stock (stock_qty, stock_note, stock_product_id) VALUES (1, 'opening', 1), (5, 'opening', 2)");
$add = fn (int $inv, int $prod, $qty, $price = 10) => runPostHandler('../post/invoice.php', 1, ['add_invoice_item' => 1, 'invoice_id' => $inv, 'name' => 'Widget', 'description' => '', 'qty' => $qty, 'price' => $price, 'tax_id' => 0, 'item_order' => 1, 'product_id' => $prod]);

// the scenario from the report: add 1 (stock 1 -> 0), edit to 500, delete
[$o, $alert] = $add(1, 1, 1);
$item = intval($one("SELECT item_id FROM invoice_items WHERE item_invoice_id = 1"));
$ok("add: one unit leaves stock 0 ($alert)", $item > 0 && $stock(1) === 0.0);
[$o, $alert] = runPostHandler('../post/invoice.php', 1, ['edit_invoice_item' => 1, 'item_id' => $item, 'name' => 'Widget', 'description' => '', 'qty' => 500, 'price' => 10, 'tax_id' => 0, 'product_id' => 1]);
$ok("edit to 500 with nothing in stock is refused ($alert)", str_contains($alert, 'Not Enough') && floatval($one("SELECT item_quantity FROM invoice_items WHERE item_id = $item")) === 1.0 && $stock(1) === 0.0);
$m->query("INSERT INTO product_stock (stock_qty, stock_note, stock_product_id) VALUES (4, 'received', 1)");
[$o, $alert] = runPostHandler('../post/invoice.php', 1, ['edit_invoice_item' => 1, 'item_id' => $item, 'name' => 'Widget', 'description' => '', 'qty' => 4, 'price' => 10, 'tax_id' => 0, 'product_id' => 1]);
$ok("edit 1 -> 4 takes only the difference (3): stock 4 -> 1 ($alert)", $stock(1) === 1.0 && floatval($one("SELECT item_quantity FROM invoice_items WHERE item_id = $item")) === 4.0);
[$o, $alert] = runPostHandler('../post/invoice.php', 1, ['edit_invoice_item' => 1, 'item_id' => $item, 'name' => 'Widget', 'description' => '', 'qty' => 2, 'price' => 10, 'tax_id' => 0, 'product_id' => 1]);
$ok('edit 4 -> 2 returns the difference (2): stock 1 -> 3', $stock(1) === 3.0);
[$o, $alert] = runPostHandler('../post/invoice.php', 1, ['edit_invoice_item' => 1, 'item_id' => $item, 'name' => 'Widget', 'description' => '', 'qty' => 2, 'price' => 99, 'tax_id' => 0, 'product_id' => 2]);
$ok('a posted product_id cannot redirect the stock movement (price-only edit moves nothing)', $stock(1) === 3.0 && $stock(2) === 5.0);
[$o, $alert] = runPostHandler('../post/invoice.php', 1, [], ['delete_invoice_item' => $item]);
$ok('delete returns the ORIGINAL stored quantity (2), not an edited/phantom one: stock 3 -> 5, total back to the 5 received', $stock(1) === 5.0 && intval($one("SELECT COUNT(*) FROM invoice_items WHERE item_id = $item")) === 0);
$ok("the invoice total dropped with the line", floatval($one("SELECT invoice_amount FROM invoices WHERE invoice_id = 1")) === 0.0);
[$o, $alert] = runPostHandler('../post/invoice.php', 1, [], ['delete_invoice_item' => $item]);
$ok('a second delete of the same line returns nothing again', str_contains($alert, 'not found') && $stock(1) === 5.0);

// a service product never touches stock, in either direction
$add(1, 2, 3, 50);
$svc = intval($one("SELECT item_id FROM invoice_items WHERE item_product_id = 2"));
runPostHandler('../post/invoice.php', 1, [], ['delete_invoice_item' => $svc]);
$ok('a service line neither takes nor returns stock', $stock(2) === 5.0);

// a failing line INSERT gives the stock back (a trigger makes the INSERT fail for the name BOOM, like an out-of-range total would)
$m->query("CREATE TRIGGER boom BEFORE INSERT ON invoice_items FOR EACH ROW BEGIN IF NEW.item_name = 'BOOM' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boom'; END IF; END");
$before = $stock(1);
[$o, $alert] = runPostHandler('../post/invoice.php', 1, ['add_invoice_item' => 1, 'invoice_id' => 1, 'name' => 'BOOM', 'description' => '', 'qty' => 2, 'price' => 10, 'tax_id' => 0, 'item_order' => 1, 'product_id' => 1]);
$ok("a failed line INSERT does not lose stock ($alert)", str_contains($alert, 'could not be added') && $stock(1) === $before && intval($one("SELECT COUNT(*) FROM invoice_items WHERE item_invoice_id = 1")) === 0);
$m->query("DROP TRIGGER boom");

// two concurrent adds of the last unit: exactly one wins (stock check + decrement atomic on the product row)
$m->query("DELETE FROM product_stock WHERE stock_product_id = 1"); $m->query("INSERT INTO product_stock (stock_qty, stock_note, stock_product_id) VALUES (1,'last unit',1)");
$m->query("DELETE FROM invoice_items");
$procs = [];
foreach ([1, 2, 1, 2] as $i => $inv) {
    $cmd = [PHP_BINARY, __DIR__ . '/lib/run_post_handler.php', json_encode(['handler' => '../post/invoice.php', 'base' => 'agent', 'user_id' => 1, 'post' => ['add_invoice_item' => 1, 'invoice_id' => $inv, 'name' => 'Widget', 'description' => '', 'qty' => 1, 'price' => 10, 'tax_id' => 0, 'item_order' => 1, 'product_id' => 1], 'get' => []])];
    $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp[$i]);
}
foreach ($procs as $i => $p) { stream_get_contents($pp[$i][1]); proc_close($p); }
$lines = intval($one("SELECT COUNT(*) FROM invoice_items WHERE item_product_id = 1"));
$ok("4 concurrent adds of the last unit: one line, stock 0 ($lines line(s), stock " . $stock(1) . ')', $lines === 1 && $stock(1) === 0.0);

// invoice delete returns stocked lines
$m->query("INSERT INTO product_stock (stock_qty, stock_note, stock_product_id) VALUES (6,'refill',1)");
$add(2, 1, 3);
$s0 = $stock(1); $lineqty = floatval($one("SELECT SUM(item_quantity) FROM invoice_items WHERE item_invoice_id = 2"));
runPostHandler('../post/invoice.php', 1, [], ['delete_invoice' => 2]);
$ok('deleting the invoice returns its stocked lines (' . $s0 . ' -> ' . $stock(1) . ')', $stock(1) === $s0 + $lineqty && $lineqty >= 3.0);
exit($fail ? 1 : 0);
