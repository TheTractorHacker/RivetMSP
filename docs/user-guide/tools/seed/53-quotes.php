<?php
/*
 * Quotes for the RivetMSP user-guide demo (group 6).
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/53-quotes.php
 *
 * Eight quotes that cover every quote status the app shows: Draft (2), Sent (2, one of them already past its expiry date),
 * Viewed, Accepted, Declined and Invoiced. Line items come from the price list in seed 50; dates are relative to today.
 * The Invoiced quote is turned into an invoice by seed 54, which also writes the matching history line.
 * Depends on 00-core.sql and 50-billing-catalog.sql. Idempotent: skips a quote whose client and scope already exist.
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "53-quotes: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "53-quotes: " . mysqli_error($mysqli) . "\n  $sql\n"); exit(1); } return true; }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function e($s) { global $mysqli; return mysqli_real_escape_string($mysqli, (string) $s); }

$tz = (string) one('SELECT config_timezone FROM settings WHERE company_id = 1') ?: 'America/Chicago';
q("SET time_zone = '" . (new DateTime('now', new DateTimeZone($tz)))->format('P') . "'");

$tax_pct = (float) one("SELECT tax_percent FROM taxes WHERE tax_name = 'Sales Tax' LIMIT 1");

// client, scope, status, days ago created, days until expiry (negative = already past), category, discount, note, items, history lines [days ago, status, text]
// item: [catalog name or custom name, qty, price override, description override]
$note_std = "Prices are valid until the expiry date. Hardware is subject to availability. Work is scheduled after the quote is accepted.";
$quotes = [
  ['Ironwood Logistics', 'Warehouse Wi-Fi survey and install', 'Invoiced', 62, -32, 'Projects', 0, $note_std, [
      ['Wi-Fi site survey and heat map', 1, 450.00, 'On-site survey of the distribution center and dispatch office with a coverage report.'],
      ['Wi-Fi 6 Access Point', 6], ['24-port Managed Switch', 1], ['Project Labor (per hour)', 8]],
   [[62, 'Draft', 'Quote created!'], [61, 'Sent', 'Quote marked sent'], [60, 'Viewed', 'Quote viewed - 198.51.100.24 - Windows - Chrome'], [57, 'Accepted', 'Quote accepted by Frank Delaney']]],
  ['Maple Street Veterinary Clinic', 'Server migration to cloud', 'Declined', 40, -10, 'Projects', 0, $note_std, [
      ['Project Labor (per hour)', 22], ['Microsoft 365 Business Premium (per user)', 9, null, 'First month of licences for the new cloud mailboxes.'],
      ['Cloud Backup (per 100 GB)', 5]],
   [[40, 'Draft', 'Quote created!'], [39, 'Sent', 'Quote marked sent'], [37, 'Viewed', 'Quote viewed - 198.51.100.31 - macOS - Safari'], [31, 'Cancelled', 'Quote declined by Dr. Alan Whitcomb']]],
  ['Cedar & Pine Architects', 'Studio backup upgrade', 'Sent', 38, -8, 'Hardware', 0, $note_std, [
      ['UPS 1500VA', 2], ['Cloud Backup (per 100 GB)', 6], ['Project Labor (per hour)', 3]],
   [[38, 'Draft', 'Quote created!'], [38, 'Sent', 'Quote marked sent']]],
  ['Lakeview Family Credit Union', 'Annual penetration test and remediation plan', 'Accepted', 9, 21, 'Projects', 0, "Scope: external and internal network. A written report and one retest are included. Billed on completion of the retest.", [
      ['External and internal penetration test', 1, 5400.00, 'Testing of internet-facing systems and the internal network from a branch connection.'],
      ['Remediation planning and retest', 16, 150.00, 'Prioritised fix list, a working session with your IT lead and a retest of the findings.']],
   [[9, 'Draft', 'Quote created!'], [9, 'Sent', 'Quote marked sent'], [8, 'Viewed', 'Quote viewed - 198.51.100.42 - Windows - Edge'], [6, 'Accepted', 'Quote accepted by Elena Voss']]],
  ['Granite Peak Manufacturing', 'Plant firewall replacement', 'Viewed', 6, 24, 'Hardware', 0, $note_std, [
      ['Firewall Appliance (small office)', 2], ['Project Labor (per hour)', 6], ['Cat6 Patch Cable 3 ft (10-pack)', 2]],
   [[6, 'Draft', 'Quote created!'], [6, 'Sent', 'Quote marked sent'], [3, 'Viewed', 'Quote viewed - 198.51.100.57 - Windows - Chrome']]],
  ['Birchwood Law Partners', 'Office Wi-Fi refresh', 'Sent', 5, 25, 'Hardware', 100.00, "Includes a $100.00 discount for existing managed clients. " . $note_std, [
      ['Wi-Fi 6 Access Point', 4], ['24-port Managed Switch', 1], ['Project Labor (per hour)', 5]],
   [[5, 'Draft', 'Quote created!'], [5, 'Sent', 'Quote marked sent']]],
  ['Northshore Dental Group', 'Replace reception workstations', 'Draft', 3, 27, 'Hardware', 0, $note_std, [
      ['Business Laptop 14 in', 6], ['Project Labor (per hour)', 6]],
   [[3, 'Draft', 'Quote created!']]],
  ['Kestrel Property Management', 'Lobby check-in kiosk setup', 'Draft', 1, 29, 'Hardware', 0, '', [
      ['Business Laptop 14 in', 1], ['Remote Support (per hour)', 2]],
   [[1, 'Draft', 'Quote created!']]],
];

foreach ($quotes as [$client, $scope, $status, $age, $expire_off, $category, $discount, $note, $items, $hist]) {
    $cid = (int) one("SELECT client_id FROM clients WHERE client_name = '" . e($client) . "' LIMIT 1");
    if (!$cid) { echo "53-quotes: client '$client' missing, skipped\n"; continue; }
    if (one("SELECT quote_id FROM quotes WHERE quote_client_id = $cid AND quote_scope = '" . e($scope) . "'")) { continue; }
    $cat = (int) one("SELECT category_id FROM categories WHERE category_name = '" . e($category) . "' AND category_type = 'Income' LIMIT 1");

    $n = (int) one('SELECT config_quote_next_number FROM settings WHERE company_id = 1');
    q('UPDATE settings SET config_quote_next_number = ' . ($n + 1) . ' WHERE company_id = 1');
    $prefix = (string) one('SELECT config_quote_prefix FROM settings WHERE company_id = 1');

    $rows = []; $total = 0.0;
    foreach ($items as $i => $it) {
        $p = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT product_id, product_price, product_description, product_tax_id FROM products WHERE product_name = '" . e($it[0]) . "' LIMIT 1"));
        $qty = (float) $it[1];
        $price = isset($it[2]) ? (float) $it[2] : (float) ($p['product_price'] ?? 0);
        $desc = $it[3] ?? ($p['product_description'] ?? '');
        $tid = $p ? (int) $p['product_tax_id'] : 0;
        $sub = round($price * $qty, 2);
        $tax = $tid ? round($sub * $tax_pct / 100, 2) : 0.0;
        $rows[] = [$it[0], $desc, $qty, $price, $sub, $tax, $sub + $tax, $i + 1, $tid];
        $total += $sub + $tax;
    }
    $amount = round($total - $discount, 2);
    $key = bin2hex(random_bytes(16));

    q("INSERT INTO quotes SET quote_prefix = '" . e($prefix) . "', quote_number = $n, quote_scope = '" . e($scope) . "', quote_status = '$status',
        quote_discount_amount = $discount, quote_date = DATE_SUB(CURDATE(), INTERVAL $age DAY), quote_expire = DATE_ADD(CURDATE(), INTERVAL $expire_off DAY),
        quote_amount = $amount, quote_currency_code = 'USD', quote_note = '" . e($note) . "', quote_url_key = '$key',
        quote_created_at = DATE_SUB(NOW(), INTERVAL $age DAY), quote_category_id = $cat, quote_client_id = $cid");
    $qid = mysqli_insert_id($mysqli);

    foreach ($rows as [$name, $desc, $qty, $price, $sub, $tax, $tot, $ord, $tid]) {
        q("INSERT INTO quote_items SET item_name = '" . e($name) . "', item_description = '" . e($desc) . "', item_quantity = $qty, item_price = $price,
            item_subtotal = $sub, item_tax = $tax, item_total = $tot, item_order = $ord, item_tax_id = $tid, item_quote_id = $qid");
    }
    foreach ($hist as [$ago, $st, $text]) {
        q("INSERT INTO history SET history_status = '" . e($st) . "', history_description = '" . e($text) . "', history_quote_id = $qid,
            history_created_at = DATE_SUB(NOW(), INTERVAL $ago DAY)");
    }
    echo "53-quotes: $prefix$n $client ($scope) $status $amount\n";
}
