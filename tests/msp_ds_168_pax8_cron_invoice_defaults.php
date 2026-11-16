<?php
// MSP-DS-168: Pax8 billing recurring invoice takes prefix + currency from the settings (cron has no session globals).
// Run: SCRATCH_TABLES="companies settings recurring_invoices history" tests/lib/with_scratch_db.sh php tests/msp_ds_168_pax8_cron_invoice_defaults.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
function logAction() {}
require __DIR__ . '/../includes/pax8_functions.php';
foreach (['companies', 'settings', 'recurring_invoices', 'history'] as $t) { $mysqli->query("DELETE FROM $t"); }
$mysqli->query("INSERT INTO companies (company_id, company_name, company_currency) VALUES (1,'Acme','JPY')");
$mysqli->query("INSERT INTO settings (company_id, config_recurring_invoice_prefix, config_recurring_invoice_next_number) VALUES (1,'REC-',7)");
$id = pax8CreateBillingRecurringInvoice($mysqli, 55);   // no globals set, as under cron
$r = $mysqli->query("SELECT recurring_invoice_prefix p, recurring_invoice_currency_code c FROM recurring_invoices WHERE recurring_invoice_id = $id")->fetch_assoc();
$ok('prefix from settings (' . ($r['p'] ?? 'null') . ')', ($r['p'] ?? '') === 'REC-');
$ok('currency from the company (' . ($r['c'] ?? 'null') . ')', ($r['c'] ?? '') === 'JPY');
exit($fail ? 1 : 0);
