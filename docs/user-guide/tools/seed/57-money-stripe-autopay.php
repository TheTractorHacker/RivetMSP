<?php
/*
 * Money in for the RivetMSP user-guide demo (group 7, part 3): the Stripe payment provider, three clients that have
 * authorised automatic payments (a customer link plus a saved card), and AutoPay switched on for their recurring invoices.
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/57-money-stripe-autopay.php
 *
 * EXAMPLE DATA, NOT A LIVE CONNECTION. The keys, customer ids and card ids below are invented placeholders; nothing here
 * can reach Stripe. The secret key and webhook secret are stored through encryptSetting() exactly as the app does, so the
 * pages that decrypt them render normally.
 * Depends on 55 (accounts, Stripe vendor, Processing Fee category) and 56 (invoices). Reuses a client's existing active
 * recurring invoice when the billing seeds created one; otherwise creates one. Idempotent.
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "57-stripe: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "57-stripe: " . mysqli_error($mysqli) . "\n  $sql\n"); exit(1); } return true; }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function esc($v) { global $mysqli; return mysqli_real_escape_string($mysqli, $v); }

// ---- Payment provider ---------------------------------------------------------------------------------------------
$account = intval(one("SELECT account_id FROM accounts WHERE account_name = 'Stripe' LIMIT 1"));
$vendor = intval(one("SELECT vendor_id FROM vendors WHERE vendor_name = 'Stripe' AND vendor_client_id = 0 LIMIT 1"));
$category = intval(one("SELECT category_id FROM categories WHERE category_name = 'Processing Fee' AND category_type = 'Expense' LIMIT 1"));
if (!$account || !$vendor || !$category) {
    fwrite(STDERR, "57-stripe: run seed 55 first (Stripe account / vendor / Processing Fee category missing).\n");
    exit(1);
}
if (!one("SELECT payment_provider_id FROM payment_providers WHERE payment_provider_name = 'Stripe' LIMIT 1")) {
    $secret = esc(encryptSetting('sk_test_EXAMPLE_not_a_real_key'));
    $whsec = esc(encryptSetting('whsec_EXAMPLE_not_a_real_secret'));
    q("INSERT INTO payment_providers SET payment_provider_name = 'Stripe', payment_provider_description = 'Example data, not a live Stripe account',
         payment_provider_public_key = 'pk_test_EXAMPLE_not_a_real_key', payment_provider_private_key = '$secret', payment_provider_webhook_secret = '$whsec',
         payment_provider_threshold = 10000.00, payment_provider_active = 1, payment_provider_account = $account,
         payment_provider_expense_vendor = $vendor, payment_provider_expense_category = $category,
         payment_provider_expense_percentage_fee = 0.0290, payment_provider_expense_flat_fee = 0.30");
}
$provider = intval(one("SELECT payment_provider_id FROM payment_providers WHERE payment_provider_name = 'Stripe' LIMIT 1"));

// ---- Clients that authorised automatic payments --------------------------------------------------------------------
// client => [customer id, saved-payment description, saved method id, invoice amount per month, plan line]
$autopay = [
    'Birchwood Law Partners'       => ['cus_ExampleBirchwood01', 'visa - 4242 | Exp 8/2028',        'pm_ExampleBirchwood01', 2420.00, 'Managed services, monthly'],
    'Lakeview Family Credit Union' => ['cus_ExampleLakeview01',  'mastercard - 4444 | Exp 11/2027', 'pm_ExampleLakeview01',  4275.00, 'Managed services, monthly'],
    'Bluebird Early Learning'      => ['cus_ExampleBluebird01',  'visa - 4242 | Exp 3/2029',        'pm_ExampleBluebird01',  620.00,  'Managed services, monthly'],
];
$service_category = intval(one("SELECT category_id FROM categories WHERE category_name = 'Service' AND category_type = 'Income' LIMIT 1"));
foreach ($autopay as $client_name => $a) {
    $cid = intval(one("SELECT client_id FROM clients WHERE client_name = '" . esc($client_name) . "' LIMIT 1"));
    if (!$cid) { continue; }
    [$cus, $desc, $pm, $amount, $scope] = $a;

    if (!one("SELECT 1 FROM client_payment_provider WHERE client_id = $cid AND payment_provider_id = $provider")) {
        q("INSERT INTO client_payment_provider SET client_id = $cid, payment_provider_id = $provider, payment_provider_client = '" . esc($cus) . "'");
    }
    if (!one("SELECT 1 FROM client_saved_payment_methods WHERE saved_payment_client_id = $cid AND saved_payment_provider_method = '" . esc($pm) . "'")) {
        q("INSERT INTO client_saved_payment_methods SET saved_payment_provider_method = '" . esc($pm) . "', saved_payment_description = '" . esc($desc) . "',
             saved_payment_client_id = $cid, saved_payment_provider_id = $provider, saved_payment_type = 'card'");
    }
    $saved = intval(one("SELECT saved_payment_id FROM client_saved_payment_methods WHERE saved_payment_client_id = $cid AND saved_payment_provider_method = '" . esc($pm) . "' LIMIT 1"));

    // Use the client's active recurring invoice if one exists, otherwise create a simple monthly one
    $ri = intval(one("SELECT recurring_invoice_id FROM recurring_invoices WHERE recurring_invoice_client_id = $cid AND recurring_invoice_status = 1 AND recurring_invoice_archived_at IS NULL ORDER BY recurring_invoice_id ASC LIMIT 1"));
    if (!$ri) {
        $prefix = esc((string) one("SELECT config_recurring_invoice_prefix FROM settings WHERE company_id = 1"));
        $number = intval(one("SELECT IFNULL(MAX(recurring_invoice_number), 0) + 1 FROM recurring_invoices"));
        $next = date('Y-m-d', strtotime('2026-11-01 +1 month'));
        q("INSERT INTO recurring_invoices SET recurring_invoice_prefix = '$prefix', recurring_invoice_number = $number, recurring_invoice_scope = '" . esc($scope) . "',
             recurring_invoice_frequency = 'month', recurring_invoice_last_sent = '2026-11-01', recurring_invoice_next_date = '$next', recurring_invoice_status = 1,
             recurring_invoice_amount = $amount, recurring_invoice_currency_code = 'USD', recurring_invoice_category_id = $service_category,
             recurring_invoice_client_id = $cid");
        $ri = intval(mysqli_insert_id($mysqli));
        q("INSERT INTO recurring_invoice_items SET item_name = 'Managed support plan', item_description = 'Monthly managed services', item_quantity = 1,
             item_price = $amount, item_subtotal = $amount, item_total = $amount, item_order = 1, item_recurring_invoice_id = $ri");
        q("INSERT INTO history SET history_status = 'Active', history_description = 'Recurring Invoice created', history_recurring_invoice_id = $ri");
        q("UPDATE settings SET config_recurring_invoice_next_number = GREATEST(config_recurring_invoice_next_number, $number + 1) WHERE company_id = 1");
    }
    // The client's open October invoice came from this recurring invoice
    q("UPDATE invoices SET invoice_recurring_invoice_id = $ri WHERE invoice_client_id = $cid AND invoice_recurring_invoice_id = 0
         AND invoice_status IN ('Sent', 'Viewed') AND invoice_url_key = MD5('ug-invoice-A14')");
    // AutoPay: one recurring_payments row per recurring invoice, pointing at the saved card
    if (!one("SELECT 1 FROM recurring_payments WHERE recurring_payment_recurring_invoice_id = $ri")) {
        q("INSERT INTO recurring_payments SET recurring_payment_currency_code = 'USD', recurring_payment_account_id = $account, recurring_payment_method = 'Credit Card',
             recurring_payment_recurring_invoice_id = $ri, recurring_payment_saved_payment_id = $saved");
    }
}

echo "57-stripe: provider $provider, " . count($autopay) . " autopay clients\n";
