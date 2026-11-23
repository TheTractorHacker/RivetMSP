<?php
// MSP-DS-56 (re-review MRR-3): delete_invoice returns stock once, even for a double click or a race with delete_invoice_item. Runs the REAL handler in parallel processes.
// Run: SCRATCH_TABLES="users user_roles user_settings user_client_permissions logs clients invoices invoice_items products product_stock payments history taxes tickets" bash tests/lib/with_scratch_db.sh php tests/msp_mrr_3.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
require __DIR__ . '/lib/handler_run.php';
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$stock = fn (int $p) => floatval($one("SELECT COALESCE(SUM(stock_qty),0) FROM product_stock WHERE stock_product_id = $p"));
$hash = password_hash('x', PASSWORD_DEFAULT);
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (1,'Admin',1)");
$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status) VALUES (1,'Adm','a@x.test','$hash',1,1,1)");
$m->query("INSERT INTO clients (client_id, client_name) VALUES (1,'C')");
$m->query("INSERT INTO products (product_id, product_name, product_type, product_price) VALUES (1,'Widget','product',10)");
$spawn = function (array $get) {
    $cmd = [PHP_BINARY, __DIR__ . '/lib/run_post_handler.php', json_encode(['handler' => '../post/invoice.php', 'base' => 'agent', 'user_id' => 1, 'post' => [], 'get' => $get])];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
    return [$p, $pp];
};
$wait = function (array $ps) { foreach ($ps as [$p, $pp]) { stream_get_contents($pp[1]); stream_get_contents($pp[2]); proc_close($p); } };
$seed = function (int $inv) use ($m) {
    $m->query("DELETE FROM invoice_items"); $m->query("DELETE FROM invoices"); $m->query("DELETE FROM product_stock WHERE stock_note <> 'opening'");
    $m->query("INSERT INTO invoices (invoice_id, invoice_client_id, invoice_status, invoice_amount, invoice_prefix, invoice_number) VALUES ($inv,1,'Draft',20,'INV-',$inv)");
    $m->query("INSERT INTO invoice_items (item_invoice_id, item_name, item_quantity, item_price, item_total, item_product_id) VALUES ($inv,'Widget',2,10,20,1),($inv,'Widget',3,10,30,1)");
};
$m->query("INSERT INTO product_stock (stock_qty, stock_note, stock_product_id) VALUES (10,'opening',1)");

// 1. double click (6 concurrent GETs): the 5 units on the invoice come back once
$seed(1); $before = $stock(1);
$ps = []; for ($i = 0; $i < 6; $i++) { $ps[] = $spawn(['delete_invoice' => 1]); } $wait($ps);
$ok('invoice is gone', intval($one("SELECT COUNT(*) FROM invoices WHERE invoice_id = 1")) === 0 && intval($one("SELECT COUNT(*) FROM invoice_items WHERE item_invoice_id = 1")) === 0);
$ok('6 concurrent deletes return the 5 units once (stock ' . $before . ' -> ' . $stock(1) . ')', $stock(1) === $before + 5.0);

// 2. delete_invoice racing delete_invoice_item on one line: every unit returns exactly once
for ($round = 0; $round < 4; $round++) {
    $inv = 2 + $round; $seed($inv); $before = $stock(1);
    $item = intval($one("SELECT MIN(item_id) FROM invoice_items WHERE item_invoice_id = $inv"));
    $ps = [$spawn(['delete_invoice' => $inv]), $spawn(['delete_invoice_item' => $item]), $spawn(['delete_invoice' => $inv]), $spawn(['delete_invoice_item' => $item])]; $wait($ps);
    $ok("round $round: item delete + invoice delete race returns 5 units once (stock " . $before . ' -> ' . $stock(1) . ')', $stock(1) === $before + 5.0 && intval($one("SELECT COUNT(*) FROM invoices WHERE invoice_id = $inv")) === 0);
}
exit($fail ? 1 : 0);
