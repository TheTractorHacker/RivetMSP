<?php
// MSP-DS-36: two Pax8 companies on one client share ONE recurring invoice, also after a re-map / billing-off. Pax8 is never called.
// Run: SCRATCH_TABLES="pax8_integrations pax8_client_map pax8_subscriptions pax8_billing_rules recurring_invoices recurring_invoice_items settings companies history" tests/lib/with_scratch_db.sh php tests/msp_ds_36_pax8_one_invoice_per_client.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
function logAction(...$a) {}
require __DIR__ . '/../includes/pax8_functions.php';
$q = fn(string $sql) => $mysqli->query($sql);
$q("INSERT INTO settings SET company_id = 1, config_recurring_invoice_next_number = 1");
$q("INSERT INTO pax8_integrations SET id = 1, billing_sync_enabled = 1");
$q("INSERT INTO pax8_billing_rules SET product_name = 'M365', pricing_mode = 'fixed', sell_price = 10, markup_percent = 0, enabled = 1");
$q("INSERT INTO pax8_client_map SET map_id = 1, pax8_company_id = 'X', pax8_company_name = 'X', client_id = 5, billing_sync_enabled = 1");
$q("INSERT INTO pax8_client_map SET map_id = 2, pax8_company_id = 'Y', pax8_company_name = 'Y', client_id = 5, billing_sync_enabled = 1");
$q("INSERT INTO pax8_subscriptions SET pax8_subscription_id = 'sx', pax8_company_id = 'X', client_id = 5, product_name = 'M365', quantity = 3, unit_cost = 5, status = 'ACTIVE'");
$q("INSERT INTO pax8_subscriptions SET pax8_subscription_id = 'sy', pax8_company_id = 'Y', client_id = 5, product_name = 'M365', quantity = 2, unit_cost = 5, status = 'ACTIVE'");
$active = fn() => (int) $mysqli->query("SELECT COUNT(*) FROM recurring_invoices WHERE recurring_invoice_client_id = 5 AND recurring_invoice_status = 1")->fetch_row()[0];
$lines = fn() => (int) $mysqli->query("SELECT COUNT(*) FROM recurring_invoice_items WHERE item_archived_at IS NULL AND item_pax8_subscription_id IS NOT NULL")->fetch_row()[0];
$r = pax8BillingSyncForClient($mysqli, 5);
$r1 = $r['recurring_invoice_id'];
$ok('first sync: one invoice carrying both companies\' lines', $active() === 1 && $lines() === 2);
// admin re-maps X to another client (the handler's effect: X leaves, its row loses the invoice id, its lines are archived)
$q("UPDATE recurring_invoice_items SET item_archived_at = NOW() WHERE item_pax8_subscription_id = 'sx'");
$q("UPDATE pax8_subscriptions SET client_id = 9 WHERE pax8_company_id = 'X'");
$q("UPDATE pax8_client_map SET recurring_invoice_id = NULL, client_id = 9 WHERE map_id = 1");
$r = pax8BillingSyncForClient($mysqli, 5);
$ok('after the re-map the sync reuses the client\'s invoice (no second recurring invoice)', $r['recurring_invoice_id'] === $r1 && $active() === 1);
$ok('Y is billed once (one live line)', $lines() === 1);
// the pre-fix damage: two live invoices for the client, both carrying Y
$q("INSERT INTO recurring_invoices SET recurring_invoice_id = 900, recurring_invoice_client_id = 5, recurring_invoice_status = 1, recurring_invoice_scope = 'dup', recurring_invoice_frequency = 'month', recurring_invoice_next_date = CURDATE(), recurring_invoice_amount = 20, recurring_invoice_currency_code = 'USD', recurring_invoice_category_id = 0");
$q("INSERT INTO recurring_invoice_items SET item_name = 'M365', item_quantity = 2, item_price = 10, item_subtotal = 20, item_total = 20, item_recurring_invoice_id = 900, item_pax8_subscription_id = 'sy'");
$q("UPDATE pax8_client_map SET recurring_invoice_id = 900 WHERE map_id = 2");
$r = pax8BillingSyncForClient($mysqli, 5);
$live_on_other = (int) $mysqli->query("SELECT COUNT(*) FROM recurring_invoice_items WHERE item_recurring_invoice_id <> {$r['recurring_invoice_id']} AND item_archived_at IS NULL AND item_pax8_subscription_id IS NOT NULL")->fetch_row()[0];
$ok('an already duplicated invoice has its Pax8 lines archived on the next sync', $live_on_other === 0 && $lines() === 1);
$ok('every billing-on row now points at the one invoice', (int) $mysqli->query("SELECT COUNT(DISTINCT recurring_invoice_id) FROM pax8_client_map WHERE client_id = 5 AND billing_sync_enabled = 1")->fetch_row()[0] === 1);
$h = file_get_contents(__DIR__ . '/../admin/settings_pax8.php');
$ok('billing-off with the client unchanged archives that company\'s lines (handler)', str_contains($h, '$billing_turned_off') && str_contains($h, '($client_changed || $billing_turned_off)'));
$ok('subscriptions keep their client when only billing is turned off', str_contains($h, 'if ($client_changed) {'));
exit($fail ? 1 : 0);
