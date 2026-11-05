<?php
/*
 * Recurring invoices for the RivetMSP user-guide demo (group 6).
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/52-recurring-invoices.php
 *
 * Ten recurring invoices over eight clients: nine monthly and one yearly, nine active and one inactive (the one linked
 * to the expired Cedar & Pine contract, as the app would pause it). Most are linked to the contracts from seed 51;
 * their totals equal the contract billing values. Dates are relative to today.
 * Depends on 00-core.sql, 50-billing-catalog.sql and 51-contracts.sql. Idempotent: skips a recurring invoice whose
 * client and scope already exist.
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "52-recurring: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "52-recurring: " . mysqli_error($mysqli) . "\n  $sql\n"); exit(1); } return true; }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function e($s) { global $mysqli; return mysqli_real_escape_string($mysqli, (string) $s); }

// The app keeps company-local dates; give this session the company's UTC offset so CURDATE() matches the screens.
$tz = (string) one('SELECT config_timezone FROM settings WHERE company_id = 1') ?: 'America/Chicago';
q("SET time_zone = '" . (new DateTime('now', new DateTimeZone($tz)))->format('P') . "'");

$tax_id = (int) one("SELECT tax_id FROM taxes WHERE tax_name = 'Sales Tax' LIMIT 1");
$tax_pct = (float) one("SELECT tax_percent FROM taxes WHERE tax_name = 'Sales Tax' LIMIT 1");

// Item shorthand: [product name or custom name, qty, unit price or null to use the catalog price, description or null]
$list = [
  ['Northshore Dental Group', 'Managed IT services - monthly', 'month', 8, -22, 'Fully Managed IT Agreement', 'Managed Services', 0, 1, [
      ['Managed Workstation', 26], ['Managed Server', 2], ['Network Monitoring (per site)', 2], ['Cloud Backup (per 100 GB)', 10]]],
  ['Birchwood Law Partners', 'Managed workstations and Microsoft 365 - monthly', 'month', 10, -20, 'Partially Managed IT Agreement', 'Managed Services', 0, 1, [
      ['Managed Workstation', 14], ['Managed Server', 1], ['Microsoft 365 Business Premium (per user)', 14]]],
  ['Granite Peak Manufacturing', 'Managed services and plant network - monthly', 'month', 22, -8, 'Managed Services and Plant Network Agreement', 'Managed Services', 0, 1, [
      ['Managed Workstation', 38], ['Managed Server', 4], ['Network Monitoring (per site)', 2], ['Cloud Backup (per 100 GB)', 20]]],
  ['Lakeview Family Credit Union', 'Managed IT and compliance reporting - monthly', 'month', 3, -27, 'Compliance and Security SLA', 'Managed Services', 0, 1, [
      ['Managed Workstation', 22], ['Managed Server', 3], ['Network Monitoring (per site)', 2], ['Cloud Backup (per 100 GB)', 30],
      ['Compliance monitoring and quarterly examiner report', 1, 1315.00, 'Control checks, evidence collection and the quarterly report for the examiner.']]],
  ['Cedar & Pine Architects', 'Break/fix support retainer - monthly', 'month', 18, -70, 'Break/Fix Support Agreement', 'Service', 0, 0, [
      ['Support retainer (4 hours remote)', 1, 500.00, 'Prepaid block of four remote support hours per month.']]],
  ['Maple Street Veterinary Clinic', 'Support plan with included hours - monthly', 'month', 12, -18, 'Support Plan with Included Hours', 'Managed Services', 0, 1, [
      ['Managed Workstation', 9], ['Cloud Backup (per 100 GB)', 5]]],
  ['Ironwood Logistics', 'Managed services - monthly', 'month', 26, -4, 'Managed Services Agreement', 'Managed Services', 0, 1, [
      ['Managed Workstation', 30], ['Managed Server', 2], ['Network Monitoring (per site)', 2], ['Cloud Backup (per 100 GB)', 12]]],
  ['Ironwood Logistics', 'Annual security training and vCIO reviews', 'year', 190, -175, null, 'Consulting', 0, 1, [
      ['Security Awareness Training (per user, year)', 60], ['Virtual CIO Review (quarterly)', 4]]],
  ['Bluebird Early Learning', 'Non-profit support plan - monthly', 'month', 9, -21, 'Nonprofit Support Plan', 'Managed Services', 100.00, 1, [
      ['Managed Workstation', 12], ['Microsoft 365 Business Premium (per user)', 18]]],
  ['Kestrel Property Management', 'Office IT - monthly', 'month', 15, -15, 'Property Management IT Agreement', 'Managed Services', 0, 1, [
      ['Managed Workstation', 8], ['Network Monitoring (per site)', 1]]],
];

foreach ($list as [$client, $scope, $freq, $next_off, $last_off, $contract, $category, $discount, $active, $items]) {
    $cid = (int) one("SELECT client_id FROM clients WHERE client_name = '" . e($client) . "' LIMIT 1");
    if (!$cid) { echo "52-recurring: client '$client' missing, skipped\n"; continue; }
    if (one("SELECT recurring_invoice_id FROM recurring_invoices WHERE recurring_invoice_client_id = $cid AND recurring_invoice_scope = '" . e($scope) . "'")) { continue; }

    $cat = (int) one("SELECT category_id FROM categories WHERE category_name = '" . e($category) . "' AND category_type = 'Income' LIMIT 1");
    $contract_sql = 'NULL';
    if ($contract !== null) {
        $k = (int) one("SELECT contract_id FROM contracts WHERE contract_client_id = $cid AND contract_name = '" . e($contract) . "' LIMIT 1");
        if ($k) { $contract_sql = $k; }
    }

    // Number from the settings counter, like the app does
    $n = (int) one('SELECT config_recurring_invoice_next_number FROM settings WHERE company_id = 1');
    q('UPDATE settings SET config_recurring_invoice_next_number = ' . ($n + 1) . ' WHERE company_id = 1');
    $prefix = (string) one('SELECT config_recurring_invoice_prefix FROM settings WHERE company_id = 1');

    // Totals
    $rows = []; $total = 0.0;
    foreach ($items as $i => $it) {
        $name = $it[0]; $qty = (float) $it[1];
        $p = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT product_id, product_price, product_description, product_tax_id FROM products WHERE product_name = '" . e($name) . "' LIMIT 1"));
        $price = isset($it[2]) ? (float) $it[2] : (float) ($p['product_price'] ?? 0);
        $desc = $it[3] ?? ($p['product_description'] ?? '');
        $tid = $p ? (int) $p['product_tax_id'] : 0;
        $sub = round($price * $qty, 2);
        $tax = $tid ? round($sub * $tax_pct / 100, 2) : 0.0;
        $rows[] = [$name, $desc, $qty, $price, $sub, $tax, $sub + $tax, $i + 1, $tid, $p ? (int) $p['product_id'] : 0];
        $total += $sub + $tax;
    }
    $amount = round($total - $discount, 2);
    $status = $active ? 1 : 0;

    q("INSERT INTO recurring_invoices SET recurring_invoice_prefix = '" . e($prefix) . "', recurring_invoice_number = $n, recurring_invoice_scope = '" . e($scope) . "',
        recurring_invoice_frequency = '$freq', recurring_invoice_last_sent = DATE_ADD(CURDATE(), INTERVAL $last_off DAY),
        recurring_invoice_next_date = DATE_ADD(CURDATE(), INTERVAL $next_off DAY), recurring_invoice_status = $status,
        recurring_invoice_discount_amount = $discount, recurring_invoice_amount = $amount, recurring_invoice_currency_code = 'USD',
        recurring_invoice_note = '', recurring_invoice_email_notify = 1, recurring_invoice_category_id = $cat,
        recurring_invoice_client_id = $cid, recurring_invoice_contract_id = $contract_sql,
        recurring_invoice_created_at = DATE_SUB(NOW(), INTERVAL 400 DAY)");
    $rid = mysqli_insert_id($mysqli);

    foreach ($rows as [$name, $desc, $qty, $price, $sub, $tax, $tot, $ord, $tid, $pid]) {
        q("INSERT INTO recurring_invoice_items SET item_name = '" . e($name) . "', item_description = '" . e($desc) . "', item_quantity = $qty, item_price = $price,
            item_subtotal = $sub, item_tax = $tax, item_total = $tot, item_order = $ord, item_tax_id = $tid, item_product_id = $pid, item_recurring_invoice_id = $rid");
    }

    q("INSERT INTO history SET history_status = '1', history_description = 'Recurring Invoice created', history_recurring_invoice_id = $rid, history_created_at = DATE_SUB(NOW(), INTERVAL 400 DAY)");
    if (!$active) {
        q("INSERT INTO history SET history_status = '0', history_description = 'Recurring Invoice paused automatically - the linked contract \"Break/Fix Support Agreement\" was marked Expired', history_recurring_invoice_id = $rid, history_created_at = DATE_SUB(NOW(), INTERVAL 40 DAY)");
    }
    echo "52-recurring: $prefix$n $client ($scope) $amount\n";
}
