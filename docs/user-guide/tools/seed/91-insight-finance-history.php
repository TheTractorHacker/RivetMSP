<?php
/*
 * Twelve months of billing and bookkeeping history for the RivetMSP user-guide demo (group G9, Insight: dashboard and reports).
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/91-insight-finance-history.php
 *
 * Seeds 52-56 cover about the last quarter. This adds the months before it so Income, Expense, Profit and Loss, Tax Summary,
 * Annual Budget, MRR and the AR aging report have a year to show:
 *   - monthly managed-services invoices before each client's recurring invoice existed, with payments;
 *   - hardware, project, consulting and software one-off invoices (taxed lines use the Sales Tax rate), with payments;
 *   - time-and-materials invoices built from the billable tickets of seed 90 (the invoice lines are the logged hours, and the
 *     tickets are marked invoiced). Cedar & Pine, Kestrel and one Granite Peak month are deliberately left unbilled so the
 *     Unbilled Tickets reports have history;
 *   - four old unpaid invoices (one partly paid) that fill the 31-60, 61-90 and 90+ day AR aging buckets;
 *   - monthly expenses (rent, licensing, internet, fuel, insurance, hardware, training), Stripe fees for card payments,
 *     interest and rebate revenue, and the annual budget for 2025 and 2026;
 *   - recurring-invoice created dates set to each client's contract start (only while they still all carry the same
 *     setup date), so the MRR trend grows over the year.
 * Depends on 00, 50, 51, 52, 54, 55, 56 and 90. Idempotent: an invoice is skipped when the client, scope and date exist, an
 * expense or revenue when the same date, vendor/category and description exist, a budget cell when it exists.
 * Example data: payments are ledger rows, nothing was charged anywhere.
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "91-insight-finance-history: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "91-insight-finance-history: " . mysqli_error($mysqli) . "\n  " . substr($sql, 0, 300) . "\n"); exit(1); } return true; }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function e($s) { global $mysqli; return mysqli_real_escape_string($mysqli, (string) $s); }

$tzName = (string) one('SELECT config_timezone FROM settings WHERE company_id = 1') ?: 'America/Chicago';
date_default_timezone_set($tzName);
q("SET time_zone = '" . (new DateTime('now', new DateTimeZone($tzName)))->format('P') . "'");
$TODAY = date('Y-m-d');
$tax_pct = (float) one("SELECT tax_percent FROM taxes WHERE tax_name = 'Sales Tax' LIMIT 1");
$tax_id = (int) one("SELECT tax_id FROM taxes WHERE tax_name = 'Sales Tax' LIMIT 1");
$prefix = (string) one('SELECT config_invoice_prefix FROM settings WHERE company_id = 1');
$acctBank = (int) one("SELECT account_id FROM accounts WHERE account_name = 'Lakeshore Business Checking' LIMIT 1");
$acctStripe = (int) one("SELECT account_id FROM accounts WHERE account_name = 'Stripe' LIMIT 1");
$acctCard = (int) one("SELECT account_id FROM accounts WHERE account_name = 'Company Credit Card' LIMIT 1");
$acctSavings = (int) one("SELECT account_id FROM accounts WHERE account_name LIKE 'Operating Reserve%' LIMIT 1");
if (!$acctBank || !$acctStripe || !$acctCard || !$acctSavings || !$tax_id) { fwrite(STDERR, "91-insight-finance-history: run seeds 55 and 54 first (accounts, Sales Tax).\n"); exit(1); }
$inc = function ($n) { return (int) one("SELECT category_id FROM categories WHERE category_type = 'Income' AND category_name = '" . e($n) . "' ORDER BY category_id LIMIT 1"); };
$exp = function ($n) { return (int) one("SELECT category_id FROM categories WHERE category_type = 'Expense' AND category_name = '" . e($n) . "' ORDER BY category_id LIMIT 1"); };
$vend = function ($n) { return (int) one("SELECT vendor_id FROM vendors WHERE vendor_name = '" . e($n) . "' ORDER BY vendor_id LIMIT 1"); };
$CAT = ['ms' => $inc('Managed Services'), 'hw' => $inc('Hardware'), 'pr' => $inc('Projects'), 'sw' => $inc('Software and Licensing'), 'co' => $inc('Consulting'), 'sv' => $inc('Service')];
$PROD = [];
$r = mysqli_query($mysqli, 'SELECT product_id, product_name, product_price, product_type, product_tax_id FROM products');
while ($p = mysqli_fetch_assoc($r)) { $PROD[$p['product_name']] = $p; }
foreach (['Remote Support (per hour)', 'On-site Support (per hour)', 'After-hours Emergency Support (per hour)', 'Project Labor (per hour)', 'Business Laptop 14 in'] as $need) {
    if (!isset($PROD[$need])) { fwrite(STDERR, "91-insight-finance-history: product '$need' missing; run seed 50.\n"); exit(1); }
}
$clientId = function ($n) { return (int) one("SELECT client_id FROM clients WHERE client_name = '" . e($n) . "'"); };
$terms = function ($cid) { return (int) one("SELECT client_net_terms FROM clients WHERE client_id = $cid") ?: 30; };

mt_srand(9101);
function rnd($a, $b) { return mt_rand($a, $b); }
$counter = ['inv' => 0, 'pay' => 0, 'exp' => 0, 'rev' => 0, 'bud' => 0, 'tm' => 0];

// ---- the invoice maker -----------------------------------------------------------------------
// items: [name, qty] (catalog item, optionally [name, qty, price, description]) ; pays: [days after invoice, 'full'|amount, method, ref, account]
function make_invoice($client, $scope, $cat, $date, $items, $discount, $status, $pays, $note = '', $ticketIds = []) {
    global $mysqli, $PROD, $tax_pct, $tax_id, $prefix, $terms, $clientId, $counter, $TODAY, $acctBank, $acctStripe;
    $cid = is_int($client) ? $client : $clientId($client);
    if (!$cid) { return 0; }
    if (one("SELECT invoice_id FROM invoices WHERE invoice_client_id = $cid AND invoice_scope = '" . e($scope) . "' AND invoice_date = '$date'")) { return 0; }
    $rows = []; $total = 0.0;
    foreach ($items as $i => $it) {
        $p = $PROD[$it[0]] ?? null;
        $qty = (float) $it[1];
        $price = isset($it[2]) ? (float) $it[2] : (float) ($p['product_price'] ?? 0);
        $desc = $it[3] ?? '';
        $taxed = $p ? (int) $p['product_tax_id'] : (int) ($it[4] ?? 0);
        $sub = round($price * $qty, 2);
        $tax = $taxed ? round($sub * $tax_pct / 100, 2) : 0.0;
        $rows[] = [$it[0], $desc, $qty, $price, $sub, $tax, $sub + $tax, $i + 1, $taxed ? $tax_id : 0, $p ? (int) $p['product_id'] : 0];
        $total += $sub + $tax;
    }
    $amount = round($total - $discount, 2);
    $n = (int) one('SELECT config_invoice_next_number FROM settings WHERE company_id = 1');
    q('UPDATE settings SET config_invoice_next_number = ' . ($n + 1) . ' WHERE company_id = 1');
    $due = date('Y-m-d', strtotime("$date +" . $terms($cid) . " day"));
    $key = bin2hex(random_bytes(16));
    q("INSERT INTO invoices SET invoice_prefix = '" . e($prefix) . "', invoice_number = $n, invoice_scope = '" . e($scope) . "', invoice_status = '$status',
        invoice_date = '$date', invoice_due = '$due', invoice_discount_amount = $discount, invoice_amount = $amount, invoice_currency_code = 'USD',
        invoice_note = '" . e($note) . "', invoice_url_key = '$key', invoice_created_at = '$date 09:00:00', invoice_category_id = $cat,
        invoice_recurring_invoice_id = 0, invoice_client_id = $cid");
    $iid = mysqli_insert_id($mysqli);
    foreach ($rows as [$name, $desc, $qty, $price, $sub, $tax, $tot, $ord, $tid, $pid]) {
        q("INSERT INTO invoice_items SET item_name = '" . e($name) . "', item_description = '" . e($desc) . "', item_quantity = $qty, item_price = $price,
            item_subtotal = $sub, item_tax = $tax, item_total = $tot, item_order = $ord, item_tax_id = $tid, item_product_id = $pid, item_invoice_id = $iid,
            item_created_at = '$date 09:00:00'");
    }
    $h = function ($day, $st, $text) use ($iid) {
        q("INSERT INTO history SET history_status = '" . e($st) . "', history_description = '" . e($text) . "', history_invoice_id = $iid, history_created_at = '$day 09:05:00'");
    };
    $h($date, 'Draft', 'Invoice created by Jules Whitaker');
    $h($date, 'Sent', 'Invoice marked sent by Jules Whitaker');
    foreach ($pays as [$after, $amt, $method, $ref, $acct]) {
        $amt = $amt === 'full' ? $amount : (float) $amt;
        $pd = date('Y-m-d', strtotime("$date +$after day"));
        if ($pd > $TODAY) { continue; }
        q("INSERT INTO payments SET payment_date = '$pd', payment_amount = $amt, payment_currency_code = 'USD', payment_method = '" . e($method) . "',
            payment_reference = '" . e($ref) . "', payment_account_id = $acct, payment_invoice_id = $iid, payment_created_at = '$pd 10:00:00'");
        $counter['pay']++;
        $h($pd, $status === 'Paid' ? 'Paid' : 'Partial', 'Payment added');
        if ($method === 'Stripe') {
            $fee = round($amt * 0.029 + 0.30, 2);
            $vid = (int) one("SELECT vendor_id FROM vendors WHERE vendor_name = 'Stripe' ORDER BY vendor_id LIMIT 1");
            $cat70 = (int) one("SELECT category_id FROM categories WHERE category_type = 'Expense' AND category_name = 'Processing Fee' LIMIT 1");
            q("INSERT INTO expenses SET expense_description = '" . e("Stripe Transaction for Invoice $prefix$n In the Amount of " . number_format($amt, 1, '.', '')) . "', expense_amount = $fee,
                expense_currency_code = 'USD', expense_date = '$pd', expense_reference = '" . e($ref) . "', expense_vendor_id = $vid, expense_category_id = $cat70, expense_account_id = $acctStripe,
                expense_created_at = '$pd 10:00:00'");
        }
    }
    $counter['inv']++;
    if ($ticketIds) { q("UPDATE tickets SET ticket_invoice_id = $iid, ticket_updated_at = ticket_updated_at WHERE ticket_id IN (" . implode(',', array_map('intval', $ticketIds)) . ")"); }
    return $iid;
}
$METHOD = [
    'Northshore Dental Group' => ['ACH', $acctBank, 8, 20], 'Birchwood Law Partners' => ['Check', $acctBank, 6, 14], 'Granite Peak Manufacturing' => ['ACH', $acctBank, 28, 44],
    'Lakeview Family Credit Union' => ['ACH', $acctBank, 12, 26], 'Cedar & Pine Architects' => ['Check', $acctBank, 20, 40], 'Maple Street Veterinary Clinic' => ['Stripe', $acctStripe, 5, 20],
    'Ironwood Logistics' => ['ACH', $acctBank, 8, 18], 'Bluebird Early Learning' => ['Check', $acctBank, 15, 30], 'Kestrel Property Management' => ['ACH', $acctBank, 12, 25],
];
// a full payment for a client, with a realistic delay (sometimes a late payer)
function full_pay($client, $extra = 0) {
    global $METHOD;
    [$method, $acct, $lo, $hi] = $METHOD[$client];
    $after = rnd($lo, $hi) + (rnd(1, 100) <= 7 ? rnd(25, 40) : 0) + $extra;
    $ref = $method === 'Stripe' ? 'Stripe - pi_ex_' . substr(md5($client . $after . mt_rand()), 0, 6) : ($method === 'Check' ? 'Check #' . rnd(1000, 9999) : 'ACH-' . rnd(100000, 999999));
    return [[$after, 'full', $method, $ref, $acct]];
}
function month_start($k) { return new DateTime(date('Y-m-01', strtotime("first day of -$k month"))); }

// ---- A. recurring invoices: created at the contract start, so the MRR trend grows -------------------
$same = (int) one('SELECT COUNT(DISTINCT recurring_invoice_created_at) FROM recurring_invoices');
if ($same === 1) {
    $r = mysqli_query($mysqli, 'SELECT recurring_invoice_id, recurring_invoice_client_id, recurring_invoice_frequency FROM recurring_invoices');
    while ($ri = mysqli_fetch_assoc($r)) {
        $start = one("SELECT MIN(contract_start_date) FROM contracts WHERE contract_client_id = " . (int) $ri['recurring_invoice_client_id'] . " AND contract_status IN ('Active', 'Expired') AND contract_archived_at IS NULL");
        if ($ri['recurring_invoice_frequency'] === 'year') { $start = date('Y-m-d', strtotime($TODAY . ' -6 month')); }
        if ($start && $start > date('Y-m-d', strtotime($TODAY . ' -380 day')) && $start < date('Y-m-d', strtotime($TODAY . ' -35 day'))) {
            q("UPDATE recurring_invoices SET recurring_invoice_created_at = '$start 09:00:00' WHERE recurring_invoice_id = " . (int) $ri['recurring_invoice_id']);
        }
    }
}

// ---- B. monthly managed-services invoices before the recurring ones ----------------------------------
// client => [scope, day of month, first k (months back), last k, [amount for k >= x, ...]]
$SERIES = [
    ['Northshore Dental Group', 'Managed IT services - monthly', 14, 12, 4, [[8, 2380.00], [0, 2520.00]]],
    ['Birchwood Law Partners', 'Managed workstations and Microsoft 365 - monthly', 16, 11, 4, [[8, 1380.00], [0, 1464.60]]],
    ['Granite Peak Manufacturing', 'Managed services and plant network - monthly', 28, 8, 4, [[0, 3890.00]]],
    ['Lakeview Family Credit Union', 'Managed IT and compliance reporting - monthly', 9, 5, 4, [[0, 4200.00]]],
    ['Ironwood Logistics', 'Managed services - monthly', 1, 12, 4, [[0, 2640.00]]],
    ['Bluebird Early Learning', 'Non-profit support plan - monthly', 14, 7, 3, [[0, 1155.20]]],
];
foreach ($SERIES as [$client, $scope, $day, $kFrom, $kTo, $amounts]) {
    for ($k = $kFrom; $k >= $kTo; $k--) {
        $amt = 0; foreach ($amounts as [$minK, $a]) { if ($k >= $minK) { $amt = $a; break; } }
        $date = month_start($k)->format('Y-m-') . sprintf('%02d', $day);
        $pays = full_pay($client);
        $disc = ($client === 'Bluebird Early Learning') ? 100.00 : 0;
        $iid = make_invoice($client, $scope, $CAT['ms'], $date, [[$scope, 1, $amt + $disc, 'Monthly managed services, as per the signed agreement.']], $disc, 'Paid', $pays);
    }
}

// ---- C. one-off hardware, project, consulting and software invoices -----------------------------------
// [client, age in days, scope, category, items, discount, extra days to pay]
$LAP = 'Business Laptop 14 in'; $LAB = 'Project Labor (per hour)';
$ONEOFF = [
    ['Northshore Dental Group', 332, 'Operatory PC refresh (2 workstations)', 'hw', [[$LAP, 2], [$LAB, 4]], 0, 0],
    ['Birchwood Law Partners', 305, 'Conference room Wi-Fi upgrade', 'pr', [['Wi-Fi 6 Access Point', 2], ['Cat6 Patch Cable 3 ft (10-pack)', 1], [$LAB, 3]], 0, 0],
    ['Granite Peak Manufacturing', 285, 'Plant 1 switch upgrade', 'pr', [['24-port Managed Switch', 3], [$LAB, 8]], 0, 6],
    ['Ironwood Logistics', 270, 'Dispatch office UPS and cabling', 'hw', [['UPS 1500VA', 2], ['Cat6 Patch Cable 3 ft (10-pack)', 2], [$LAB, 2]], 0, 0],
    ['Birchwood Law Partners', 260, 'Annual security awareness training (12 users)', 'sw', [['Security Awareness Training (per user, year)', 12]], 0, 0],
    ['Lakeview Family Credit Union', 255, 'Security assessment', 'co', [['Security assessment (written report)', 1, 2400.00, 'Two-day assessment with a written findings report.'], ['Virtual CIO Review (quarterly)', 1]], 0, 5],
    ['Bluebird Early Learning', 240, 'Classroom Wi-Fi access points', 'hw', [['Wi-Fi 6 Access Point', 4]], 100.00, 0],
    ['Maple Street Veterinary Clinic', 225, 'Reception PC', 'hw', [[$LAP, 1], [$LAB, 1]], 0, 0],
    ['Cedar & Pine Architects', 205, 'Plotter network install', 'pr', [[$LAB, 4], ['24-port Managed Switch', 1]], 0, 12],
    ['Northshore Dental Group', 190, 'Annual endpoint protection renewal (12 seats)', 'sw', [['Endpoint protection, annual (per seat)', 12, 48.00, 'Twelve-month licence for twelve seats.']], 0, 0],
    ['Granite Peak Manufacturing', 170, 'Firewall appliance install', 'hw', [['Firewall Appliance (small office)', 1], [$LAB, 4]], 0, 4],
    ['Lakeview Family Credit Union', 150, 'Branch workstation setup', 'hw', [[$LAP, 3], [$LAB, 6]], 0, 0],
    ['Birchwood Law Partners', 128, 'Microsoft 365 migration labor', 'pr', [[$LAB, 5]], 0, 0],
    ['Ironwood Logistics', 135, 'Warehouse scanner Wi-Fi survey', 'pr', [[$LAB, 6]], 0, 0],
    ['Kestrel Property Management', 110, 'Reception PC', 'hw', [[$LAP, 1], [$LAB, 1]], 0, 6],
    ['Northshore Dental Group', 96, 'Front desk monitors and docks', 'hw', [['24-port Managed Switch', 1], [$LAB, 2]], 0, 0],
];
foreach ($ONEOFF as [$client, $age, $scope, $cat, $items, $disc, $extra]) {
    $date = date('Y-m-d', strtotime("$TODAY -$age day"));
    make_invoice($client, $scope, $CAT[$cat], $date, $items, $disc, 'Paid', full_pay($client, $extra));
}

// ---- D. time-and-materials invoices from the billable tickets of seed 90 --------------------------------
$LABPROD = [1 => 'Remote Support (per hour)', 2 => 'On-site Support (per hour)', 3 => 'After-hours Emergency Support (per hour)', 4 => 'Project Labor (per hour)', 5 => 'On-site Support (per hour)'];
$groups = [];
$r = mysqli_query($mysqli, "SELECT t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_client_id, c.client_name, DATE_FORMAT(t.ticket_closed_at, '%Y-%m') ym
    FROM tickets t JOIN clients c ON c.client_id = t.ticket_client_id
    WHERE t.ticket_url_key LIKE 'hist90-%' AND t.ticket_billable = 1 AND t.ticket_invoice_id = 0 AND t.ticket_closed_at IS NOT NULL ORDER BY t.ticket_closed_at");
while ($t = mysqli_fetch_assoc($r)) { $groups[$t['client_name'] . '|' . $t['ym']][] = $t; }
foreach ($groups as $gk => $tickets) {
    [$client, $ym] = explode('|', $gk);
    $k = (int) round((strtotime(date('Y-m-01')) - strtotime($ym . '-01')) / (86400 * 30.4375));
    if ($client === 'Cedar & Pine Architects' && $k <= 5) { continue; }          // left unbilled
    if ($client === 'Kestrel Property Management' && $k <= 5) { continue; }      // left unbilled
    if ($client === 'Granite Peak Manufacturing' && $k === 3) { continue; }      // left unbilled
    $date = date('Y-m-d', strtotime($ym . '-01 +1 month +2 day'));
    if ($date > date('Y-m-d', strtotime($TODAY . ' -30 day'))) { continue; }
    $ids = array_map(function ($t) { return (int) $t['ticket_id']; }, $tickets);
    $mins = [];
    $r2 = mysqli_query($mysqli, 'SELECT COALESCE(ticket_reply_labor_type_id, 1) lt, SUM(TIME_TO_SEC(ticket_reply_time_worked)) s FROM ticket_replies
        WHERE ticket_reply_ticket_id IN (' . implode(',', $ids) . ') AND ticket_reply_time_worked IS NOT NULL GROUP BY lt');
    while ($m = mysqli_fetch_assoc($r2)) { $mins[$LABPROD[(int) $m['lt']]] = ($mins[$LABPROD[(int) $m['lt']]] ?? 0) + (int) $m['s']; }
    if (!$mins) { continue; }
    $nums = implode(', ', array_map(function ($t) { return $t['ticket_prefix'] . $t['ticket_number']; }, $tickets));
    $items = [];
    foreach ($mins as $prod => $secs) {
        $items[] = [$prod, ceil($secs / 900) / 4, $PROD[$prod]['product_price'], 'Tickets: ' . $nums];
    }
    $scope = 'Support time - ' . date('F Y', strtotime($ym . '-01'));
    $iid = make_invoice($client, $scope, $CAT['sv'], $date, $items, 0, 'Paid', full_pay($client), '', $ids);
    if ($iid) { $counter['tm']++; }
}

// ---- E. old unpaid invoices for the AR aging report ----------------------------------------------------
$OLD = [
    ['Maple Street Veterinary Clinic', 140, 'Exam room workstation refresh', 'hw', [[$LAP, 3], [$LAB, 4]], 'Sent', []],
    ['Kestrel Property Management', 100, 'Lobby network re-cabling', 'pr', [[$LAB, 8], ['Cat6 Patch Cable 3 ft (10-pack)', 2]], 'Sent', []],
    ['Cedar & Pine Architects', 96, 'Plotter replacement and network setup', 'hw', [['24-port Managed Switch', 2], [$LAB, 6]], 'Partial', [[26, 1000.00, 'Check', 'Check #5521', $acctBank]]],
];
foreach ($OLD as [$client, $age, $scope, $cat, $items, $status, $pays]) {
    make_invoice($client, $scope, $CAT[$cat], date('Y-m-d', strtotime("$TODAY -$age day")), $items, 0, $status, $pays, 'Reminder sent twice.');
}

// ---- F. expenses, revenue and the budget -----------------------------------------------------------------
function add_expense($date, $amount, $vendor, $category, $account, $method, $desc, $ref) {
    global $counter;
    global $TODAY;
    if ($date > $TODAY || !$vendor || !$category) { return; }
    if (one("SELECT expense_id FROM expenses WHERE expense_date = '$date' AND expense_vendor_id = $vendor AND expense_category_id = $category AND expense_description = '" . e($desc) . "'")) { return; }
    q("INSERT INTO expenses SET expense_description = '" . e($desc) . "', expense_amount = $amount, expense_currency_code = 'USD', expense_date = '$date',
        expense_reference = '" . e($ref) . "', expense_payment_method = " . ($method ? "'" . e($method) . "'" : 'NULL') . ", expense_vendor_id = $vendor, expense_category_id = $category,
        expense_account_id = $account, expense_created_at = '$date 10:00:00'");
    $counter['exp']++;
}
$V = ['rent' => $vend('Hawthorne Properties'), 'ms' => $vend('Microsoft'), 'pax8' => $vend('Pax8'), 'isp' => $vend('Badger State Wireless'), 'fuel' => $vend('Northern Fuel Card Services'),
    'ins' => $vend('Midwest Business Insurance'), 'dell' => $vend('Dell Technologies'), 'train' => $vend('SkillForge Training'), 'office' => $vend('Capital City Office Supply')];
$X = ['rent' => $exp('Rent and Utilities'), 'sw' => $exp('Software and Licensing'), 'tel' => $exp('Telecom and Internet'), 'veh' => $exp('Vehicle and Fuel'), 'ins' => $exp('Insurance'),
    'hw' => $exp('Hardware and Equipment'), 'train' => $exp('Training'), 'off' => $exp('Office Supplies')];
$hwBuys = [11 => 2380.00, 10 => 1149.00, 9 => 3420.50, 8 => 1870.00, 7 => 562.40, 6 => 2210.00, 5 => 1140.00, 4 => 1680.30];
$pax8 = [12 => 1924.60, 11 => 1960.10, 10 => 1988.00, 9 => 2012.45, 8 => 2037.20, 7 => 2056.80, 6 => 2074.00, 5 => 2089.15, 4 => 2102.70, 3 => 2118.30];
for ($k = 12; $k >= 3; $k--) {
    $m = month_start($k); $Y = $m->format('Y'); $M = $m->format('F'); $ym = $m->format('Y-m');
    add_expense("$ym-01", $k >= 8 ? 2250.00 : 2350.00, $V['rent'], $X['rent'], $acctBank, 'ACH', "Office rent, $M", "Rent $ym");
    if ($k >= 4) {
        add_expense("$ym-03", $k >= 8 ? 1190.00 : 1284.00, $V['ms'], $X['sw'], $acctBank, 'ACH', "Microsoft 365 partner licensing, $M", 'MS-INV-' . (86000 + (12 - $k) * 410 + 13));
        add_expense("$ym-12", 389.00, $V['isp'], $X['tel'], $acctBank, 'ACH', "Office internet, $M", "BSW-$ym");
    }
    add_expense("$ym-08", $pax8[$k], $V['pax8'], $X['sw'], $acctBank, 'ACH', "Pax8 subscriptions, $M", 'PAX8-' . strtoupper(substr($M, 0, 3)) . '-' . $Y);
    add_expense("$ym-19", round(372 + ($k * 7919 % 61), 2), $V['fuel'], $X['veh'], $acctCard, 'Credit Card', "Fuel card, $M", 'NFC-' . $m->format('my'));
    if ($k % 2 === 0) { add_expense("$ym-15", round(78 + ($k * 131 % 70), 2), $V['office'], $X['off'], $acctCard, 'Credit Card', 'Toner and printer paper', 'CCOS-' . (54000 + $k * 37)); }
    if (isset($hwBuys[$k])) { add_expense("$ym-" . sprintf('%02d', 10 + $k % 9), $hwBuys[$k], $V['dell'], $X['hw'], $acctCard, 'Credit Card', 'Client hardware order (' . $M . ')', 'DELL-' . (9840000 + $k * 913)); }
}
foreach ([11, 8, 5, 2] as $k) { $m = month_start($k); add_expense($m->format('Y-m-') . '10', 1485.00, $V['ins'], $X['ins'], $acctBank, 'ACH', 'Business insurance, quarterly premium (' . $m->format('F') . ')', 'MBI-' . $m->format('Ym')); }
add_expense(month_start(9)->format('Y-m-') . '20', 695.00, $V['train'], $X['train'], $acctCard, 'Credit Card', 'Vendor certification course (Sam Ortiz)', 'SF-' . (76100 + 9));
add_expense(month_start(6)->format('Y-m-') . '14', 349.00, $V['train'], $X['train'], $acctCard, 'Credit Card', 'Exam voucher: security certification (Devon Park)', 'SF-' . (76100 + 6));

function add_revenue($date, $amount, $category, $account, $method, $desc, $ref) {
    global $counter, $TODAY;
    if ($date > $TODAY || !$category) { return; }
    if (one("SELECT revenue_id FROM revenues WHERE revenue_date = '$date' AND revenue_category_id = $category AND revenue_description = '" . e($desc) . "'")) { return; }
    q("INSERT INTO revenues SET revenue_date = '$date', revenue_amount = $amount, revenue_currency_code = 'USD', revenue_payment_method = '" . e($method) . "', revenue_reference = '" . e($ref) . "',
        revenue_description = '" . e($desc) . "', revenue_category_id = $category, revenue_account_id = $account, revenue_created_at = '$date 10:00:00'");
    $counter['rev']++;
}
$interest = $inc('Interest'); $rebate = $inc('Vendor Rebate'); $referral = $inc('Referral Commission');
for ($k = 12; $k >= 3; $k--) {
    $m = month_start($k);
    add_revenue($m->format('Y-m-t'), round(88.4 + ($k * 53 % 9) + $k * 0.37, 2), $interest, $acctSavings, 'ACH', 'Interest on operating reserve, ' . $m->format('F'), 'INT-' . strtoupper($m->format('M')));
}
add_revenue(month_start(10)->format('Y-m-') . '15', 388.20, $rebate, $acctBank, 'ACH', 'Pax8 partner rebate, fourth quarter', 'PAX8-REB-Q4');
add_revenue(month_start(7)->format('Y-m-') . '15', 401.90, $rebate, $acctBank, 'ACH', 'Pax8 partner rebate, first quarter', 'PAX8-REB-Q1');
add_revenue(month_start(9)->format('Y-m-') . '20', 250.00, $referral, $acctBank, 'Check', 'Referral commission from a hardware reseller', 'Check 30102');

// Budget: monthly amounts per expense category for the previous and the current year (Annual Budget report)
$BUD = ['Software and Licensing' => 3500, 'Rent and Utilities' => 2350, 'Telecom and Internet' => 400, 'Insurance' => 500, 'Vehicle and Fuel' => 420, 'Training' => 100,
    'Hardware and Equipment' => 2000, 'Office Supplies' => 120, 'Processing Fee' => 150, 'Travel' => 100, 'Advertising' => 100];
$thisY = (int) date('Y');
foreach ([$thisY - 1, $thisY] as $by) {
    foreach ($BUD as $cn => $amt) {
        $cid = $exp($cn);
        if (!$cid) { continue; }
        $amt = $by < $thisY ? round($amt * 0.94) : $amt;
        for ($mo = 1; $mo <= 12; $mo++) {
            if (one("SELECT budget_id FROM budget WHERE budget_year = $by AND budget_month = $mo AND budget_category_id = $cid")) { continue; }
            q("INSERT INTO budget SET budget_month = $mo, budget_year = $by, budget_amount = $amt, budget_category_id = $cid, budget_description = 'Planned monthly spend'");
            $counter['bud']++;
        }
    }
}

echo "91-insight-finance-history: {$counter['inv']} invoices ({$counter['tm']} time-and-materials), {$counter['pay']} payments, {$counter['exp']} expenses, {$counter['rev']} revenues, {$counter['bud']} budget cells.\n";
