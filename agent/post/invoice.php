<?php

/*
 * ITFlow - GET/POST request handler for invoices
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Accounting (QuickBooks Online) one-way sync enqueue helper. No-ops unless the
// accounting module + a connected integration with auto-push are configured.
require_once __DIR__ . '/../../includes/accounting_functions.php';

// M4-4: an admin clears one stuck charge attempt (needs_review forever / an intent Stripe can no longer return). Reason required and logged;
// refused while Stripe says the payment is succeeded/processing.
if (isset($_POST['clear_charge_attempt'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_sales', 2);
    enforceAdminPermission();

    $attempt_id = intval($_POST['attempt_id'] ?? 0);
    $attempt_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT attempt_invoice_id, invoice_client_id FROM invoice_charge_attempts LEFT JOIN invoices ON invoice_id = attempt_invoice_id WHERE attempt_id = $attempt_id"));
    if (!$attempt_row) {
        flash_alert("Charge attempt not found", 'error');
        redirect();
    }
    $invoice_id = intval($attempt_row['attempt_invoice_id']);
    $client_id = intval($attempt_row['invoice_client_id']);

    enforceClientAccess();

    $pi_lookup = null;
    $stripe_provider_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT payment_provider_id FROM payment_providers WHERE payment_provider_name = 'Stripe' LIMIT 1"));
    if ($stripe_provider_row) {
        try {
            require_once __DIR__ . '/../../includes/payment_provider_factory.php';
            $pi_lookup = chargeAttemptProviderLookup(getPaymentProvider(intval($stripe_provider_row['payment_provider_id'])));
        } catch (\Throwable $e) {
            $pi_lookup = null;
        }
    }

    $clear = chargeAttemptClear($mysqli, $attempt_id, (string) ($_POST['reason'] ?? ''), $pi_lookup, (string) ($session_name ?? 'admin'));
    if ($clear['ok']) {
        logAction("Invoice", "Edit", "$session_name cleared stuck charge attempt $attempt_id on invoice ID $invoice_id: " . substr(trim((string) ($_POST['reason'] ?? '')), 0, 200), $client_id, $invoice_id);
    }
    flash_alert($clear['message'], $clear['ok'] ? 'success' : 'error');
    redirect();

}

if (isset($_POST['add_invoice'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_sales', 2);

    require_once 'invoice_model.php';

    $client_id = intval($_POST['client_id']);

    enforceClientAccess();

    // A negative discount would inflate the total
    if (moneyToCents($invoice_discount) < 0) {
        flash_alert("Discount can not be negative", 'error');
        redirect();
    }

    $invoice_amount = 0;     // Items are added afterwards; a discount alone must not produce a negative total

    // Get Net Terms
    $client_net_terms = intval(getFieldById('clients', $client_id, 'client_net_terms'));

    // Atomically increment and get the new invoice number
    mysqli_query($mysqli, "
        UPDATE settings
        SET
            config_invoice_next_number = LAST_INSERT_ID(config_invoice_next_number),
            config_invoice_next_number = config_invoice_next_number + 1
        WHERE company_id = 1
    ");

    $invoice_number = mysqli_insert_id($mysqli);

    //Generate a unique URL key for clients to access
    $url_key = randomString(32);

    // Company currency / document prefixes are admin-entered settings loaded raw from the database: escape before use in SQL.
    $company_currency_sql = mysqli_real_escape_string($mysqli, (string) $session_company_currency);
    mysqli_query($mysqli,"INSERT INTO invoices SET invoice_prefix = '$config_invoice_prefix', invoice_number = $invoice_number, invoice_scope = '$scope', invoice_date = '$date', invoice_due = DATE_ADD('$date', INTERVAL $client_net_terms day), invoice_discount_amount = '$invoice_discount', invoice_amount = '$invoice_amount', invoice_currency_code = '$company_currency_sql', invoice_category_id = $category, invoice_status = 'Draft', invoice_url_key = '$url_key', invoice_client_id = $client_id");

    $invoice_id = mysqli_insert_id($mysqli);

    mysqli_query($mysqli,"INSERT INTO history SET history_status = 'Draft', history_description = 'Invoice created by $session_name', history_invoice_id = $invoice_id");

    logAction("Invoice", "Create", "$session_name created Invoice $config_invoice_prefix$invoice_number - $scope", $client_id, $invoice_id);

    customAction('invoice_create', $invoice_id);

    flash_alert("Invoice <strong>$config_invoice_prefix$invoice_number</strong> created");

    redirect("invoice.php?invoice_id=$invoice_id");

}

if (isset($_POST['edit_invoice'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_sales', 2);

    require_once 'invoice_model.php';

    $invoice_id = intval($_POST['invoice_id']);
    $due = sanitizeInput($_POST['due']);

    // Get Invoice Number and Prefix and Client ID for Logging
    $sql = mysqli_query($mysqli,"SELECT invoice_prefix, invoice_number, invoice_client_id FROM invoices WHERE invoice_id = $invoice_id");
    $row = mysqli_fetch_assoc($sql);
    $invoice_prefix = sanitizeInput($row['invoice_prefix']);
    $invoice_number = intval($row['invoice_number']);
    $client_id = intval($row['invoice_client_id']);

    enforceClientAccess();

    if ($block_reason = invoiceChangeBlockReason($mysqli, $invoice_id)) {
        flash_alert($block_reason, 'error');
        redirect();
    }

    // Calculate new total
    $sql = mysqli_query($mysqli,"SELECT * FROM invoice_items WHERE item_invoice_id = $invoice_id");
    $invoice_amount = 0;
    while($row = mysqli_fetch_assoc($sql)) {
        $item_total = floatval($row['item_total']);
        $invoice_amount = $invoice_amount + $item_total;
    }
    // Discount must not be negative, nor exceed the line-item subtotal once items exist
    if (moneyToCents($invoice_discount) < 0 || (moneyToCents($invoice_amount) > 0 && moneyToCents($invoice_discount) > moneyToCents($invoice_amount))) {
        flash_alert("Discount must be between 0 and the invoice subtotal", 'error');
        redirect();
    }

    $invoice_amount = max(0, $invoice_amount - $invoice_discount);

    // MSP-DS-129: the new total may never be lower than what has already been paid
    if (invoiceBalanceDue($mysqli, $invoice_id, $invoice_amount) < 0) {
        flash_alert("The new total would be lower than the payments already recorded on this invoice", 'error');
        redirect();
    }

    mysqli_query($mysqli,"UPDATE invoices SET invoice_scope = '$scope', invoice_date = '$date', invoice_due = '$due', invoice_category_id = $category, invoice_discount_amount = '$invoice_discount', invoice_amount = '$invoice_amount' WHERE invoice_id = $invoice_id");

    logAction("Invoice", "Edit", "$session_name edited Invoice $invoice_prefix$invoice_number - $scope", $client_id, $invoice_id);

    flash_alert("Invoice <strong>$invoice_prefix$invoice_number</strong> edited");

    redirect();

}

if (isset($_POST['add_invoice_copy'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_sales', 2);

    $invoice_id = intval($_POST['invoice_id']);
    $date = sanitizeInput($_POST['date']);

    //Get Net Terms
    $sql = mysqli_query($mysqli,"SELECT * FROM clients, invoices WHERE client_id = invoice_client_id AND invoice_id = $invoice_id");
    $row = mysqli_fetch_assoc($sql);
    $client_net_terms = intval($row['client_net_terms']);
    $invoice_scope = sanitizeInput($row['invoice_scope']);
    $invoice_discount_amount = floatval($row['invoice_discount_amount']);
    $invoice_amount = floatval($row['invoice_amount']);
    $invoice_currency_code = sanitizeInput($row['invoice_currency_code']);
    $invoice_note = sanitizeInput($row['invoice_note']);
    $client_id = intval($row['invoice_client_id']);
    $category_id = intval($row['invoice_category_id']);
    $old_invoice_prefix = sanitizeInput($row['invoice_prefix']);
    $old_invoice_number = intval($row['invoice_number']);

    enforceClientAccess();

    // Atomically increment and get the new invoice number
    mysqli_query($mysqli, "
        UPDATE settings
        SET
            config_invoice_next_number = LAST_INSERT_ID(config_invoice_next_number),
            config_invoice_next_number = config_invoice_next_number + 1
        WHERE company_id = 1
    ");

    $new_invoice_number = mysqli_insert_id($mysqli);

    //Generate a unique URL key for clients to access
    $url_key = randomString(32);

    // Company currency / document prefixes are admin-entered settings loaded raw from the database: escape before use in SQL.
    $invoice_prefix_sql = mysqli_real_escape_string($mysqli, (string) $config_invoice_prefix);
    mysqli_query($mysqli,"INSERT INTO invoices SET invoice_prefix = '$invoice_prefix_sql', invoice_number = $new_invoice_number, invoice_scope = '$invoice_scope', invoice_date = '$date', invoice_due = DATE_ADD('$date', INTERVAL $client_net_terms day), invoice_category_id = $category_id, invoice_status = 'Draft', invoice_discount_amount = $invoice_discount_amount, invoice_amount = $invoice_amount, invoice_currency_code = '$invoice_currency_code', invoice_note = '$invoice_note', invoice_url_key = '$url_key', invoice_client_id = $client_id");

    $new_invoice_id = mysqli_insert_id($mysqli);

    mysqli_query($mysqli,"INSERT INTO history SET history_status = 'Draft', history_description = 'Copied INVOICE!', history_invoice_id = $new_invoice_id");

    $sql_items = mysqli_query($mysqli,"SELECT * FROM invoice_items WHERE item_invoice_id = $invoice_id");
    while($row = mysqli_fetch_assoc($sql_items)) {
        $item_id = intval($row['item_id']);
        $item_name = sanitizeInput($row['item_name']);
        $item_description = sanitizeInput($row['item_description']);
        $item_quantity = floatval($row['item_quantity']);
        $item_price = floatval($row['item_price']);
        $item_subtotal = floatval($row['item_subtotal']);
        $item_tax = floatval($row['item_tax']);
        $item_total = floatval($row['item_total']);
        $item_order = intval($row['item_order']);
        $tax_id = intval($row['item_tax_id']);

        mysqli_query($mysqli,"INSERT INTO invoice_items SET item_name = '$item_name', item_description = '$item_description', item_quantity = $item_quantity, item_price = $item_price, item_subtotal = $item_subtotal, item_tax = $item_tax, item_total = $item_total, item_order = $item_order, item_tax_id = $tax_id, item_invoice_id = $new_invoice_id");
    }

    logAction("Invoice", "Create", "$session_name created new Invoice $config_invoice_prefix$new_invoice_number from $old_invoice_prefix$old_invoice_prefix", $client_id, $new_invoice_id);

    customAction('invoice_create', $new_invoice_id);

    flash_alert("Created new Invoice <strong>$config_invoice_prefix$new_invoice_number</strong> from <strong>$old_invoice_prefix$old_invoice_prefix</strong>");

    redirect("invoice.php?invoice_id=$new_invoice_id");

}

if (isset($_GET['mark_invoice_sent'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_sales', 2);

    $invoice_id = intval($_GET['mark_invoice_sent']);

    // Get Invoice Number and Prefix and Client ID for Logging
    $sql = mysqli_query($mysqli,"SELECT invoice_prefix, invoice_number, invoice_client_id, invoice_status FROM invoices WHERE invoice_id = $invoice_id");
    $row = mysqli_fetch_assoc($sql);
    $invoice_prefix = sanitizeInput($row['invoice_prefix']);
    $invoice_number = intval($row['invoice_number']);
    $client_id = intval($row['invoice_client_id']);

    enforceClientAccess();

    if ($block_reason = invoiceChangeBlockReason($mysqli, $invoice_id)) {
        flash_alert($block_reason, 'error');
        redirect();
    }

    // MSP-INJ-26(a): only a Draft can be marked sent. A Partial, Viewed, Cancelled... invoice set back to "Sent" lost its balance logic and was charged in full.
    if (($row['invoice_status'] ?? '') !== 'Draft') {
        flash_alert('Only a draft invoice can be marked sent (this one is ' . htmlspecialchars((string) ($row['invoice_status'] ?? ''), ENT_QUOTES) . ').', 'error');
        redirect();
    }

    mysqli_query($mysqli,"UPDATE invoices SET invoice_status = 'Sent' WHERE invoice_id = $invoice_id");

    mysqli_query($mysqli,"INSERT INTO history SET history_status = 'Sent', history_description = 'Invoice marked sent by $session_name', history_invoice_id = $invoice_id");

    logAction("Invoice", "Edit", "$session_name marked invoice $invoice_prefix$invoice_number sent", $client_id, $invoice_id);

    // Invoice finalized (-> Sent): enqueue a one-way push to the accounting provider.
    enqueueAccountingSync($mysqli, 'invoice', $invoice_id);

    flash_alert("Invoice marked sent");

    redirect();

}

if (isset($_GET['mark_invoice_non-billable'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_sales', 2);

    $invoice_id = intval($_GET['mark_invoice_non-billable']);

    // Get Invoice Number and Prefix and Client ID for Logging
    $sql = mysqli_query($mysqli,"SELECT invoice_prefix, invoice_number, invoice_client_id FROM invoices WHERE invoice_id = $invoice_id");
    $row = mysqli_fetch_assoc($sql);
    $invoice_prefix = sanitizeInput($row['invoice_prefix']);
    $invoice_number = intval($row['invoice_number']);
    $client_id = intval($row['invoice_client_id']);

    enforceClientAccess();

    if ($block_reason = invoiceChangeBlockReason($mysqli, $invoice_id, true)) {
        flash_alert($block_reason, 'error');
        redirect();
    }

    mysqli_query($mysqli,"UPDATE invoices SET invoice_status = 'Non-Billable' WHERE invoice_id = $invoice_id");

    mysqli_query($mysqli,"INSERT INTO history SET history_status = 'Non-Billable', history_description = 'INVOICE marked Non-Billable', history_invoice_id = $invoice_id");

    logAction("Invoice", "Edit", "$session_name marked invoice $invoice_prefix$invoice_number Non-Billable", $client_id, $invoice_id);

    flash_alert("Invoice marked Non-Billable");

    redirect();

}

if (isset($_GET['cancel_invoice'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_sales', 2);

    $invoice_id = intval($_GET['cancel_invoice']);

    // Get Invoice Number and Prefix and Client ID for Logging
    $sql = mysqli_query($mysqli,"SELECT invoice_prefix, invoice_number, invoice_client_id FROM invoices WHERE invoice_id = $invoice_id");
    $row = mysqli_fetch_assoc($sql);
    $invoice_prefix = sanitizeInput($row['invoice_prefix']);
    $invoice_number = intval($row['invoice_number']);
    $client_id = intval($row['invoice_client_id']);

    enforceClientAccess();

    if ($block_reason = invoiceChangeBlockReason($mysqli, $invoice_id, true)) {
        flash_alert($block_reason, 'error');
        redirect();
    }

    mysqli_query($mysqli,"UPDATE invoices SET invoice_status = 'Cancelled' WHERE invoice_id = $invoice_id");

    mysqli_query($mysqli,"INSERT INTO history SET history_status = 'Cancelled', history_description = 'Invoice cancelled by $session_name', history_invoice_id = $invoice_id");

    logAction("Invoice", "Edit", "$session_name cancelled invoice $invoice_prefix$invoice_number", $client_id, $invoice_id);

    flash_alert("Invoice <strong>$invoice_prefix$invoice_number</strong> cancelled", 'error');

    redirect();

}

if (isset($_GET['delete_invoice'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_sales', 3);

    $invoice_id = intval($_GET['delete_invoice']);

    // Get Invoice Number and Prefix and Client ID for Logging
    $sql = mysqli_query($mysqli,"SELECT invoice_prefix, invoice_number, invoice_client_id FROM invoices WHERE invoice_id = $invoice_id");
    $row = mysqli_fetch_assoc($sql);
    $invoice_prefix = sanitizeInput($row['invoice_prefix']);
    $invoice_number = intval($row['invoice_number']);
    $client_id = intval($row['invoice_client_id']);

    enforceClientAccess();

    if ($block_reason = invoiceChangeBlockReason($mysqli, $invoice_id, true)) {
        flash_alert($block_reason, 'error');
        redirect();
    }

    // MSP-DS-56 (re-review MRR-3): the delete is one transaction. The lines are locked first (the same order delete_invoice_item uses: line, then invoice),
    // then the invoice row, and the checks run again under the lock. Stock goes back only for a line whose own DELETE removed a row, so a double
    // click or a race with delete_invoice_item returns each stocked line exactly once.
    mysqli_begin_transaction($mysqli);
    $lines = mysqli_query($mysqli,"SELECT invoice_items.*, products.product_type FROM invoice_items LEFT JOIN products ON product_id = item_product_id WHERE item_invoice_id = $invoice_id FOR UPDATE");
    $lines = $lines ? mysqli_fetch_all($lines, MYSQLI_ASSOC) : [];
    $inv_locked = mysqli_fetch_assoc(mysqli_query($mysqli,"SELECT invoice_id FROM invoices WHERE invoice_id = $invoice_id FOR UPDATE"));
    if (!$inv_locked || ($block_reason = invoiceChangeBlockReason($mysqli, $invoice_id, true))) {
        mysqli_rollback($mysqli);
        flash_alert($inv_locked ? $block_reason : "Invoice not found (it may already have been deleted)", 'error');
        redirect();
    }

    mysqli_query($mysqli,"DELETE FROM invoices WHERE invoice_id = $invoice_id");

    //Delete Items Associated with the Invoice
    foreach ($lines as $row) {
        $item_id = intval($row['item_id']);
        mysqli_query($mysqli,"DELETE FROM invoice_items WHERE item_id = $item_id");
        if (mysqli_affected_rows($mysqli) !== 1) {
            continue;   // another request removed this line (and returned its stock) first
        }
        // MSP-DS-56: stocked lines go back to stock when their invoice is deleted
        if (intval($row['item_product_id']) > 0 && ($row['product_type'] ?? '') === 'product' && floatval($row['item_quantity']) > 0) {
            $return_qty = floatval($row['item_quantity']);
            productStockMove($mysqli, intval($row['item_product_id']), $return_qty, "Returned QTY $return_qty back to stock from deleted Invoice $invoice_id");
        }
    }

    //Delete History Associated with the Invoice
    $sql = mysqli_query($mysqli,"SELECT * FROM history WHERE history_invoice_id = $invoice_id");
    while($row = mysqli_fetch_assoc($sql)) {
        $history_id = intval($row['history_id']);
        mysqli_query($mysqli,"DELETE FROM history WHERE history_id = $history_id");
    }

    //Delete Payments Associated with the Invoice
    $sql = mysqli_query($mysqli,"SELECT * FROM payments WHERE payment_invoice_id = $invoice_id");
    while($row = mysqli_fetch_assoc($sql)) {
        $payment_id = intval($row['payment_id']);
        mysqli_query($mysqli,"DELETE FROM payments WHERE payment_id = $payment_id");
    }

    //unlink tickets from invoice
    mysqli_query($mysqli,"UPDATE tickets SET ticket_invoice_id = 0 WHERE ticket_invoice_id = $invoice_id");
    mysqli_commit($mysqli);

    logAction("Invoice", "Delete", "$session_name deleted invoice $invoice_prefix$invoice_number", $client_id);

    flash_alert("Invoice <strong>$invoice_prefix$invoice_number</strong> deleted", 'error');

    // Deleted from its own page: go to the list, not back to an invoice that no longer exists
    if (basename((string) parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH)) === 'invoice.php') {
        redirect("invoices.php?client_id=$client_id");
    }

    redirect();

}

if (isset($_POST['add_invoice_item'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_sales', 2);

    $invoice_id = intval($_POST['invoice_id']);
    $name = sanitizeInput($_POST['name']);
    $description = sanitizeInput($_POST['description']);
    $qty = floatval($_POST['qty']);
    if ($qty < 0) {
        flash_alert("Quantity can not be negative", 'error');
        redirect();
    }
    $price = floatval($_POST['price']);
    $tax_id = intval($_POST['tax_id']);
    $item_order = intval($_POST['item_order']);
    $product_id = intval($_POST['product_id']);

    $client_id = intval(getFieldById('invoices', $invoice_id, 'invoice_client_id'));

    enforceClientAccess();

    if ($block_reason = invoiceChangeBlockReason($mysqli, $invoice_id)) {
        flash_alert($block_reason, 'error');
        redirect();
    }

    // A negative quantity of a stocked product would ADD inventory and skip the stock check
    if ($product_id && $qty <= 0) {
        flash_alert("Quantity must be greater than zero for a product", 'error');
        redirect();
    }

    $subtotal = $price * $qty;

    // MSP-DS-129 (review R15): a negative price must not take the total below what was already paid
    $line_tax_pct = 0;
    if ($tax_id > 0) {
        $line_tax_pct = floatval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT tax_percent FROM taxes WHERE tax_id = $tax_id"))[0] ?? 0);
    }
    if ($line_block = invoiceLineBlockReason($mysqli, $invoice_id, $subtotal + $subtotal * $line_tax_pct / 100)) {
        flash_alert($line_block, 'error');
        redirect();
    }

    // Tax
    if ($tax_id > 0) {
        $sql = mysqli_query($mysqli,"SELECT * FROM taxes WHERE tax_id = $tax_id");
        $row = mysqli_fetch_assoc($sql);
        $tax_percent = floatval($row['tax_percent']);
        $tax_amount = $subtotal * $tax_percent / 100;
    } else {
        $tax_amount = 0;
    }

    $total = $subtotal + $tax_amount;

    // MSP-DS-56: the availability check, the stock decrement and the line INSERT are ONE transaction on the locked product row: two concurrent adds cannot both
    // take the last unit, and a line INSERT that fails (e.g. price x qty out of range) gives the stock back instead of losing it.
    $stock_product = $product_id && sanitizeInput(getFieldById('products', $product_id, 'product_type')) === 'product';
    try {
        mysqli_begin_transaction($mysqli);
        if ($stock_product && !productStockMove($mysqli, $product_id, -$qty, "QTY $qty - Invoice $invoice_id")) {
            mysqli_rollback($mysqli);
            flash_alert("Not Enough <strong>$name</strong> in stock", 'error');
            redirect();
        }
        $line_ok = mysqli_query($mysqli,"INSERT INTO invoice_items SET item_name = '$name', item_description = '$description', item_quantity = $qty, item_price = $price, item_subtotal = $subtotal, item_tax = $tax_amount, item_total = $total, item_order = $item_order, item_tax_id = $tax_id, item_product_id = $product_id, item_invoice_id = $invoice_id");
        if (!$line_ok) {
            throw new RuntimeException('line insert failed');
        }
        mysqli_commit($mysqli);
    } catch (RuntimeException | mysqli_sql_exception $e) {
        mysqli_rollback($mysqli);
        flash_alert("The line could not be added", 'error');
        redirect();
    }

    // Get Discount and Invoice Details
    $sql = mysqli_query($mysqli,"SELECT * FROM invoices WHERE invoice_id = $invoice_id");
    $row = mysqli_fetch_assoc($sql);
    $invoice_prefix = sanitizeInput($row['invoice_prefix']);
    $invoice_number = intval($row['invoice_number']);
    $invoice_discount = floatval($row['invoice_discount_amount']);

    //add up all line items
    $sql = mysqli_query($mysqli,"SELECT * FROM invoice_items WHERE item_invoice_id = $invoice_id");
    $invoice_total = 0;
    while($row = mysqli_fetch_assoc($sql)) {
        $item_total = floatval($row['item_total']);
        $invoice_total = $invoice_total + $item_total;
    }
    $new_invoice_amount = max(0, $invoice_total - $invoice_discount);

    mysqli_query($mysqli,"UPDATE invoices SET invoice_amount = $new_invoice_amount WHERE invoice_id = $invoice_id");

    invoiceSettleStatus($mysqli, $invoice_id);

    logAction("Invoice", "Edit", "$session_name added item $name to invoice $invoice_prefix$invoice_number", $client_id, $invoice_id);

    flash_alert("Item <strong>$name</strong> added to invoice");

    redirect();

}

if (isset($_POST['invoice_note'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_sales', 2);

    $invoice_id = intval($_POST['invoice_id']);
    $note = sanitizeInput($_POST['note']);

    // Get Invoice Details for logging
    $sql = mysqli_query($mysqli,"SELECT * FROM invoices WHERE invoice_id = $invoice_id");
    $row = mysqli_fetch_assoc($sql);
    $invoice_prefix = sanitizeInput($row['invoice_prefix']);
    $invoice_number = intval($row['invoice_number']);
    $client_id = intval($row['invoice_client_id']);

    enforceClientAccess();

    mysqli_query($mysqli,"UPDATE invoices SET invoice_note = '$note' WHERE invoice_id = $invoice_id");

    logAction("Invoice", "Edit", "$session_name added note to invoice $invoice_prefix$invoice_number", $client_id, $invoice_id);

    flash_alert("Notes added");

    redirect();

}

if (isset($_POST['edit_invoice_item'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_sales', 2);

    $item_id = intval($_POST['item_id']);
    $name = sanitizeInput($_POST['name']);
    $description = sanitizeInput($_POST['description']);
    $qty = floatval($_POST['qty']);
    if ($qty < 0) {
        flash_alert("Quantity can not be negative", 'error');
        redirect();
    }
    $price = floatval($_POST['price']);
    $tax_id = intval($_POST['tax_id']);
    $product_id = intval($_POST['product_id']);

    $subtotal = $price * $qty;

    if ($tax_id > 0) {
        $sql = mysqli_query($mysqli,"SELECT * FROM taxes WHERE tax_id = $tax_id");
        $row = mysqli_fetch_assoc($sql);
        $tax_percent = floatval($row['tax_percent']);
        $tax_amount = $subtotal * $tax_percent / 100;
    } else {
        $tax_amount = 0;
    }

    $total = $subtotal + $tax_amount;

    // Determine what type of line item
    $sql = mysqli_query($mysqli,"SELECT item_invoice_id, item_quantity, item_product_id FROM invoice_items WHERE item_id = $item_id");
    $row = mysqli_fetch_assoc($sql);
    $invoice_id = intval($row['item_invoice_id']);
    $old_item_quantity = floatval($row['item_quantity']);
    $old_item_product_id = intval($row['item_product_id']);

    //Get Discount Amount
    $sql = mysqli_query($mysqli,"SELECT * FROM invoices WHERE invoice_id = $invoice_id");
    $row = mysqli_fetch_assoc($sql);
    $invoice_prefix = sanitizeInput($row['invoice_prefix']);
    $invoice_number = intval($row['invoice_number']);
    $client_id = intval($row['invoice_client_id']);
    $invoice_discount = floatval($row['invoice_discount_amount']);

    enforceClientAccess();

    if ($block_reason = invoiceChangeBlockReason($mysqli, $invoice_id, true)) {   // MSP-DS-129: lines of an invoice with payments are frozen (the total could drop below what was paid)
        flash_alert($block_reason, 'error');
        redirect();
    }

    // MSP-DS-56: a stocked line's quantity change moves the DIFFERENCE through stock (same availability check as adding the line), in one transaction with the
    // line UPDATE: the stored line (re-read under its row lock) decides the product and the old quantity, not the posted product_id or the value read above.
    try {
        mysqli_begin_transaction($mysqli);
        $locked = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT item_quantity, item_product_id FROM invoice_items WHERE item_id = $item_id FOR UPDATE"));
        if (!$locked) {
            mysqli_rollback($mysqli);
            flash_alert("Item not found (it may already have been deleted)", 'error');
            redirect();
        }
        $old_item_quantity = floatval($locked['item_quantity']);
        $old_item_product_id = intval($locked['item_product_id']);
        if ($old_item_product_id > 0 && getFieldById('products', $old_item_product_id, 'product_type') === 'product') {
            $stock_delta = $qty - $old_item_quantity;
            $stock_note = $stock_delta > 0 ? "QTY +$stock_delta - Invoice $invoice_id (line edited)" : "Returned QTY " . abs($stock_delta) . " back to stock - Invoice $invoice_id (line edited)";
            if (!productStockMove($mysqli, $old_item_product_id, -$stock_delta, $stock_note)) {
                mysqli_rollback($mysqli);
                flash_alert("Not Enough <strong>$name</strong> in stock", 'error');
                redirect();
            }
        }
        if (!mysqli_query($mysqli,"UPDATE invoice_items SET item_name = '$name', item_description = '$description', item_quantity = $qty, item_price = $price, item_subtotal = $subtotal, item_tax = $tax_amount, item_total = $total, item_tax_id = $tax_id WHERE item_id = $item_id")) {
            throw new RuntimeException('line update failed');
        }
        mysqli_commit($mysqli);
    } catch (RuntimeException | mysqli_sql_exception $e) {
        mysqli_rollback($mysqli);
        flash_alert("The line could not be updated", 'error');
        redirect();
    }

    //Update Invoice Balances by tallying up invoice items
    $sql_invoice_total = mysqli_query($mysqli,"SELECT SUM(item_total) AS invoice_total FROM invoice_items WHERE item_invoice_id = $invoice_id");
    $row = mysqli_fetch_assoc($sql_invoice_total);
    $new_invoice_amount = max(0, floatval($row['invoice_total']) - $invoice_discount);

    mysqli_query($mysqli,"UPDATE invoices SET invoice_amount = $new_invoice_amount WHERE invoice_id = $invoice_id");

    logAction("Invoice", "Edit", "$session_name edited item $name on invoice $invoice_prefix$invoice_number", $client_id, $invoice_id);

    flash_alert("Item <strong>$name</strong> updated");

    redirect();

}

if (isset($_GET['delete_invoice_item'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_sales', 2);

    $item_id = intval($_GET['delete_invoice_item']);

    $sql = mysqli_query($mysqli,"SELECT * FROM invoice_items WHERE item_id = $item_id");
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flash_alert("Item not found (it may already have been deleted)", 'error');
        redirect();
    }
    $invoice_id = intval($row['item_invoice_id']);
    $item_name = sanitizeInput($row['item_name']);
    $item_quantity = floatval($row['item_quantity']);
    $item_product_id = intval($row['item_product_id']);
    $item_subtotal = floatval($row['item_subtotal']);
    $item_tax = floatval($row['item_tax']);
    $item_total = floatval($row['item_total']);

    $sql = mysqli_query($mysqli,"SELECT * FROM invoices WHERE invoice_id = $invoice_id");
    $row = mysqli_fetch_assoc($sql);
    $invoice_prefix = sanitizeInput($row['invoice_prefix']);
    $invoice_number = intval($row['invoice_number']);
    $client_id = intval($row['invoice_client_id']);

    enforceClientAccess();

    if ($block_reason = invoiceChangeBlockReason($mysqli, $invoice_id, true)) {   // MSP-DS-129: lines of an invoice with payments are frozen (the total could drop below what was paid)
        flash_alert($block_reason, 'error');
        redirect();
    }

    // MSP-DS-56: the line is read again under its row lock, deleted and its ORIGINAL (stored) quantity returned in one transaction: a concurrent edit or a
    // second delete of the same line cannot return stock twice or a stale quantity. Only stocked products ever left stock.
    mysqli_begin_transaction($mysqli);
    $locked = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT item_quantity, item_product_id, item_total FROM invoice_items WHERE item_id = $item_id FOR UPDATE"));
    if (!$locked) {
        mysqli_rollback($mysqli);
        flash_alert("Item not found (it may already have been deleted)", 'error');
        redirect();
    }
    $item_quantity = floatval($locked['item_quantity']);
    $item_product_id = intval($locked['item_product_id']);
    $item_total = floatval($locked['item_total']);
    $new_invoice_amount = max(0, floatval($row['invoice_amount']) - $item_total);

    mysqli_query($mysqli,"UPDATE invoices SET invoice_amount = $new_invoice_amount WHERE invoice_id = $invoice_id");
    mysqli_query($mysqli,"DELETE FROM invoice_items WHERE item_id = $item_id");
    if ($item_product_id && $item_quantity > 0 && getFieldById('products', $item_product_id, 'product_type') === 'product') {
        productStockMove($mysqli, $item_product_id, $item_quantity, "Returned QTY $item_quantity back to stock from Invoice $invoice_id");
    }
    mysqli_commit($mysqli);

    logAction("Invoice", "Delete", "$session_name removed item $item_name from invoice $invoice_prefix$invoice_number", $client_id, $invoice_id);

    flash_alert("Item <strong>$item_name</strong> removed from invoice", 'error');

    redirect();

}

if (isset($_GET['email_invoice'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_sales', 2);

    $invoice_id = intval($_GET['email_invoice']);

    $sql = mysqli_query($mysqli,"SELECT * FROM invoices
        LEFT JOIN clients ON invoice_client_id = client_id
        LEFT JOIN contacts ON clients.client_id = contacts.contact_client_id AND contact_primary = 1
        WHERE invoice_id = $invoice_id"
    );
    $row = mysqli_fetch_assoc($sql);

    $invoice_id = intval($row['invoice_id']);
    $invoice_prefix = sanitizeInput($row['invoice_prefix']);
    $invoice_number = intval($row['invoice_number']);
    $invoice_scope = sanitizeInput($row['invoice_scope']);
    $invoice_status = sanitizeInput($row['invoice_status']);
    $invoice_date = sanitizeInput(validateDate($row['invoice_date']));
    $invoice_due = sanitizeInput(validateDate($row['invoice_due']));
    $invoice_amount = floatval($row['invoice_amount']);
    $invoice_url_key = sanitizeInput($row['invoice_url_key']);
    $invoice_currency_code = sanitizeInput($row['invoice_currency_code']);
    $client_id = intval($row['client_id']);
    $client_name = sanitizeInput($row['client_name']);
    $contact_name = sanitizeInput($row['contact_name']);
    $contact_email = sanitizeInput($row['contact_email']);

    enforceClientAccess();

    $sql = mysqli_query($mysqli,"SELECT * FROM companies WHERE company_id = 1");
    $row = mysqli_fetch_assoc($sql);

    $company_name = sanitizeInput($row['company_name']);
    $company_country = sanitizeInput($row['company_country']);
    $company_address = sanitizeInput($row['company_address']);
    $company_city = sanitizeInput($row['company_city']);
    $company_state = sanitizeInput($row['company_state']);
    $company_zip = sanitizeInput($row['company_zip']);
    $company_phone = sanitizeInput(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));
    $company_email = sanitizeInput($row['company_email']);
    $company_website = sanitizeInput($row['company_website']);
    $company_logo = sanitizeInput($row['company_logo']);

    // Sanitize Config vars from get_settings.php
    $config_invoice_from_name = sanitizeInput($config_invoice_from_name);
    $config_invoice_from_email = sanitizeInput($config_invoice_from_email);

    $sql_payments = mysqli_query($mysqli,"SELECT * FROM payments, accounts WHERE payment_account_id = account_id AND payment_invoice_id = $invoice_id ORDER BY payment_id DESC");

    // Add up all the payments for the invoice and get the total amount paid to the invoice
    $sql_amount_paid = mysqli_query($mysqli,"SELECT SUM(payment_amount) AS amount_paid FROM payments WHERE payment_invoice_id = $invoice_id");
    $row = mysqli_fetch_assoc($sql_amount_paid);
    $amount_paid = floatval($row['amount_paid']);

    $balance = $invoice_amount - $amount_paid;

    if ($invoice_status == 'Paid') {
        $subject = "Invoice $invoice_prefix$invoice_number Receipt";
        $body = "Hello $contact_name,<br><br>Please click on the link below to see your invoice regarding \"$invoice_scope\" marked <b>paid</b>.<br><br><a href='https://$config_base_url/guest/guest_view_invoice.php?invoice_id=$invoice_id&url_key=$invoice_url_key'>Invoice Link</a><br><br><br>--<br>$company_name - Billing<br>$config_invoice_from_email<br>$company_phone";
    } else {
        $subject = "Invoice $invoice_prefix$invoice_number";
        $body = "Hello $contact_name,<br><br>Please view the details of your invoice regarding \"$invoice_scope\" below.<br><br>Invoice: $invoice_prefix$invoice_number<br>Issue Date: $invoice_date<br>Total: " . numfmt_format_currency($currency_format, $invoice_amount, $invoice_currency_code) . "<br>Balance Due: " . numfmt_format_currency($currency_format, $balance, $invoice_currency_code) . "<br>Due Date: $invoice_due<br><br><br>To view your invoice, please click <a href='https://$config_base_url/guest/guest_view_invoice.php?invoice_id=$invoice_id&url_key=$invoice_url_key'>here</a>.<br><br><br>--<br>$company_name - Billing<br>$config_invoice_from_email<br>$company_phone";
    }

    // Queue Mail
    $data[] = [
            'from' => $config_invoice_from_email,
            'from_name' => $config_invoice_from_name,
            'recipient' => $contact_email,
            'recipient_name' => $contact_name,
            'subject' => $subject,
            'body' => $body
    ];

    addToMailQueue($data);

    // Get Email ID for reference
    $email_id = mysqli_insert_id($mysqli);

    flash_alert("Invoice sent!");

    mysqli_query($mysqli,"INSERT INTO history SET history_status = 'Sent', history_description = 'Invoice sent by $session_name (mail queue ID: $email_id)', history_invoice_id = $invoice_id");

    // Don't change the status to sent if the status is anything but draft
    if ($invoice_status == 'Draft') {
        mysqli_query($mysqli,"UPDATE invoices SET invoice_status = 'Sent' WHERE invoice_id = $invoice_id");
    }

    // Invoice finalized (emailed / -> Sent): enqueue a one-way push to accounting.
    enqueueAccountingSync($mysqli, 'invoice', $invoice_id);

    logAction("Invoice", "Email", "$session_name Emailed $contact_email Invoice $invoice_prefix$invoice_number Email queued to Email ID: $email_id", $client_id, $invoice_id);

    // Send copies of the invoice to any additional billing contacts
    $sql_billing_contacts = mysqli_query(
        $mysqli,
        "SELECT contact_name, contact_email FROM contacts
        WHERE contact_billing = 1
        AND contact_email != '$contact_email'
        AND contact_email != ''
        AND contact_client_id = $client_id"
    );

    $data = [];

    while ($billing_contact = mysqli_fetch_assoc($sql_billing_contacts)) {
        $billing_contact_name = sanitizeInput($billing_contact['contact_name']);
        $billing_contact_email = sanitizeInput($billing_contact['contact_email']);

        $data[] = [
                'from' => $config_invoice_from_email,
                'from_name' => $config_invoice_from_name,
                'recipient' => $billing_contact_email,
                'recipient_name' => $billing_contact_name,
                'subject' => $subject,
                'body' => $body
        ];

        logAction("Invoice", "Email", "$session_name Emailed $billing_contact_email Invoice $invoice_prefix$invoice_number Email queued Email ID: $email_id", $client_id, $invoice_id);

    }

    addToMailQueue($data);

    redirect();

}

if (isset($_POST['export_invoices_csv'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_sales');

    if ($_POST['client_id']) {
        $client_id = intval($_POST['client_id']);
        $client_query = "1=1 AND invoice_client_id = $client_id";
        $client_name = getFieldById('clients', $client_id, 'client_name');
        $file_name_prepend = "$client_name-";
        enforceClientAccess();
    } else {
        $client_query = '1=1 ';
        $client_name = '';
        $file_name_prepend = "$session_company_name-";
    }

    $date_from = sanitizeInput($_POST['date_from']);
    $date_to = sanitizeInput($_POST['date_to']);
    if (!empty($date_from) && !empty($date_to)) {
        $date_query = "DATE(invoice_date) BETWEEN '$date_from' AND '$date_to'";
        $file_name_date = "$date_from-to-$date_to";
    }else{
        $date_query = "";
        $file_name_date = date('Y-m-d_H-i-s');
    }

    $sql = mysqli_query($mysqli,"SELECT * FROM invoices LEFT JOIN clients ON invoice_client_id = client_id WHERE $date_query AND $client_query $access_permission_query ORDER BY invoice_number ASC");

    $num_rows = mysqli_num_rows($sql);

    if ($num_rows > 0) {
        $delimiter = ",";
        $enclosure = '"';
        $escape    = '';   // empty: plain RFC 4180 quoting; a backslash escape lets  x\",=2+5  split into a formula cell (MSP-DS-99, review R21)
        $filename = sanitize_filename($file_name_prepend . "Invoices-$file_name_date.csv");

        //create a file pointer
        $f = fopen('php://memory', 'w');

        //set column headers
        $fields = array('Invoice Number', 'Scope', 'Amount', 'Issued Date', 'Due Date', 'Status');
        fputcsv($f, $fields, $delimiter, $enclosure, $escape);

        //output each row of the data, format line as csv and write to file pointer
        while($row = $sql->fetch_assoc()) {
            $lineData = array($row['invoice_prefix'] . $row['invoice_number'], $row['invoice_scope'], $row['invoice_amount'], $row['invoice_date'], $row['invoice_due'], $row['invoice_status'], $row['client_name']);
            fputcsv($f, csvSafeRow($lineData), $delimiter, $enclosure, $escape);
        }

        //move back to beginning of file
        fseek($f, 0);

        //set headers to download file rather than displayed
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '";');

        //output all remaining data on a file pointer
        fpassthru($f);
    }

    logAction("Invoice", "Export", "$session_name exported $num_rows invoices to CSV file");

    exit;

}

if (isset($_POST['link_invoice_to_ticket'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_sales', 2);

    $invoice_id = intval($_POST['invoice_id']);
    $ticket_id = intval($_POST['ticket_id']);

    $client_id = intval(getFieldById('invoices', $invoice_id, 'invoice_client_id'));

    enforceClientAccess();

    mysqli_query($mysqli,"UPDATE invoices SET invoice_ticket_id = $ticket_id WHERE invoice_id = $invoice_id");

    flash_alert("Invoice linked to ticket");

    redirect();

}

if (isset($_POST['add_ticket_to_invoice'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_sales', 2);

    $invoice_id = intval($_POST['invoice_id']);
    $ticket_id = intval($_POST['ticket_id']);

    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));

    enforceClientAccess();

    // MSP-DS-67: the invoice must belong to the ticket's own client
    $link_invoice_client_id = intval(getFieldById('invoices', $invoice_id, 'invoice_client_id'));
    if ($link_invoice_client_id !== $client_id) {
        flash_alert("That invoice belongs to a different client than the ticket.", 'error');
        redirect();
    }

    mysqli_query($mysqli,"UPDATE tickets SET ticket_invoice_id = $invoice_id WHERE ticket_id = $ticket_id");

    flash_alert("Ticket linked to invoice");

    redirect("post.php?add_ticket_to_invoice=$invoice_id");

}

if (isset($_GET['export_invoice_pdf'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_sales');

    $invoice_id = intval($_GET['export_invoice_pdf']);

    $sql = mysqli_query(
        $mysqli,
        "SELECT * FROM invoices
        LEFT JOIN clients ON invoice_client_id = client_id
        LEFT JOIN contacts ON clients.client_id = contacts.contact_client_id AND contact_primary = 1
        LEFT JOIN locations ON clients.client_id = locations.location_client_id AND location_primary = 1
        WHERE invoice_id = $invoice_id
        $access_permission_query
        LIMIT 1"
    );

    $row = mysqli_fetch_assoc($sql);
    $invoice_id = intval($row['invoice_id']);
    $invoice_prefix = nullable_htmlentities($row['invoice_prefix']);
    $invoice_number = intval($row['invoice_number']);
    $invoice_scope = nullable_htmlentities($row['invoice_scope']);
    $invoice_status = nullable_htmlentities($row['invoice_status']);
    $invoice_date = nullable_htmlentities($row['invoice_date']);
    $invoice_due = nullable_htmlentities($row['invoice_due']);
    $invoice_amount = floatval($row['invoice_amount']);
    $invoice_discount = floatval($row['invoice_discount_amount']);
    $invoice_currency_code = nullable_htmlentities($row['invoice_currency_code']);
    $invoice_note = nullable_htmlentities($row['invoice_note']);
    $invoice_url_key = nullable_htmlentities($row['invoice_url_key']);
    $invoice_created_at = nullable_htmlentities($row['invoice_created_at']);
    $category_id = intval($row['invoice_category_id']);
    $client_id = intval($row['client_id']);
    $client_name = nullable_htmlentities($row['client_name']);
    $location_address = nullable_htmlentities($row['location_address']);
    $location_city = nullable_htmlentities($row['location_city']);
    $location_state = nullable_htmlentities($row['location_state']);
    $location_zip = nullable_htmlentities($row['location_zip']);
    $location_country = nullable_htmlentities($row['location_country']);
    $contact_email = nullable_htmlentities($row['contact_email']);
    $contact_phone_country_code = nullable_htmlentities($row['contact_phone_country_code']);
    $contact_phone = nullable_htmlentities(formatPhoneNumber($row['contact_phone'], $contact_phone_country_code));
    $contact_extension = nullable_htmlentities($row['contact_extension']);
    $contact_mobile_country_code = nullable_htmlentities($row['contact_mobile_country_code']);
    $contact_mobile = nullable_htmlentities(formatPhoneNumber($row['contact_mobile'], $contact_mobile_country_code));
    $client_website = nullable_htmlentities($row['client_website']);
    $client_currency_code = nullable_htmlentities($row['client_currency_code']);
    $client_net_terms = intval($row['client_net_terms']);
    if ($client_net_terms == 0) {
        $client_net_terms = $config_default_net_terms;
    }

    enforceClientAccess();

    $sql = mysqli_query($mysqli, "SELECT * FROM companies WHERE company_id = 1");
    $row = mysqli_fetch_assoc($sql);
    $company_id = intval($row['company_id']);
    $company_name = nullable_htmlentities($row['company_name']);
    $company_country = nullable_htmlentities($row['company_country']);
    $company_address = nullable_htmlentities($row['company_address']);
    $company_city = nullable_htmlentities($row['company_city']);
    $company_state = nullable_htmlentities($row['company_state']);
    $company_zip = nullable_htmlentities($row['company_zip']);
    $company_phone_country_code = nullable_htmlentities($row['company_phone_country_code']);
    $company_phone = nullable_htmlentities(formatPhoneNumber($row['company_phone'], $company_phone_country_code));
    $company_email = nullable_htmlentities($row['company_email']);
    $company_website = nullable_htmlentities($row['company_website']);
    $company_tax_id = nullable_htmlentities($row['company_tax_id']);
    if ($config_invoice_show_tax_id && !empty($company_tax_id)) {
        $company_tax_id_display = "Tax ID: $company_tax_id";
    } else {
        $company_tax_id_display = "";
    }
    $company_logo = nullable_htmlentities($row['company_logo']);

    $sql_payments = mysqli_query($mysqli, "SELECT * FROM payments, accounts WHERE payment_account_id = account_id AND payment_invoice_id = $invoice_id ORDER BY payments.payment_id DESC");

    //Add up all the payments for the invoice and get the total amount paid to the invoice
    $sql_amount_paid = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS amount_paid FROM payments WHERE payment_invoice_id = $invoice_id");
    $row = mysqli_fetch_assoc($sql_amount_paid);
    $amount_paid = floatval($row['amount_paid']);

    $balance = $invoice_amount - $amount_paid;

    //check to see if overdue
    if ($invoice_status !== "Paid" && $invoice_status !== "Draft" && $invoice_status !== "Cancelled" && $invoice_status !== "Non-Billable") {
        $unixtime_invoice_due = strtotime($invoice_due) + 86400;
        if ($unixtime_invoice_due < time()) {
            $invoice_overdue = "Overdue";
        }
    }

    //Set Badge color based off of invoice status
    $invoice_badge_color = getInvoiceBadgeColor($invoice_status);

    require_once("../plugins/TCPDF/tcpdf.php");

    // Start TCPDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetMargins(10, 10, 10);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);

    // Logo + Right Columns
    $html = '<table width="100%" cellspacing="0" cellpadding="3">
    <tr>
        <td width="40%">';
    if (!empty($company_logo) && file_exists("../uploads/settings/$company_logo")) {
        $html .= '<img src="/uploads/settings/' . $company_logo . '" width="120">';
    }
    $html .= '</td>
        <td width="60%" align="right">
            <span style="font-size:18pt; font-weight:bold;">INVOICE</span><br>
            <span style="font-size:14pt;">' . $invoice_prefix . $invoice_number . '</span><br>';
    if (strtolower($invoice_status) === 'paid') {
        $html .= '<span style="color:green; font-weight:bold;">PAID</span><br>';
    }
    $html .= '</td>
    </tr>
    </table><br>';

    // Billing titles
    $html .= '<table width="100%" cellspacing="0" cellpadding="2">
    <tr>
        <td width="50%" style="font-size:14pt; font-weight:bold;">' . $company_name . '</td>
        <td width="50%" align="right" style="font-size:14pt; font-weight:bold;">' . $client_name . '</td>
    </tr>
    <tr>
        <td style="font-size:10pt; line-height:1.4;">' . nl2br("$company_address\n$company_city $company_state $company_zip\n$company_country\n$company_phone\n$company_website\n$company_tax_id_display") . '</td>
        <td style="font-size:10pt; line-height:1.4;" align="right">' . nl2br("$location_address\n$location_city $location_state $location_zip\n$location_country\n$contact_email\n$contact_phone") . '</td>
    </tr>
    </table><br>';

    // Date table
    $html .= '<table border="0" cellpadding="2" cellspacing="0" width="100%">
    <tr>
        <td width="60%"></td>
        <td width="20%" style="font-size:10pt;"><strong>Date:</strong></td>
        <td width="20%" style="font-size:10pt;" align="right">' . $invoice_date . '</td>
    </tr>
    <tr>
        <td></td>
        <td style="font-size:10pt;"><strong>Due:</strong></td>
        <td style="font-size:10pt;" align="right">' . $invoice_due . '</td>
    </tr>
    </table><br><br>';

    // Items header
    $html .= '
    <table border="0" cellpadding="5" cellspacing="0" width="100%">
    <tr style="background-color:#f0f0f0;">
        <th align="left" width="40%"><strong>Item</strong></th>
        <th align="center" width="10%"><strong>Qty</strong></th>
        <th align="right" width="15%"><strong>Price</strong></th>
        <th align="right" width="15%"><strong>Tax</strong></th>
        <th align="right" width="20%"><strong>Amount</strong></th>
    </tr>';

    // Load items
    $sub_total = 0;
    $total_tax = 0;

    $sql_items = mysqli_query($mysqli, "SELECT * FROM invoice_items WHERE item_invoice_id = $invoice_id ORDER BY item_order ASC");
    while ($item = mysqli_fetch_assoc($sql_items)) {
        $name = $item['item_name'];
        $desc = $item['item_description'];
        $qty = $item['item_quantity'];
        $price = $item['item_price'];
        $tax = $item['item_tax'];
        $total = $item['item_total'];

        $sub_total += $price * $qty;
        $total_tax += $tax;

        $html .= '
        <tr>
            <td><strong>' . $name . '</strong>
            <br><span style="font-style:italic; font-size:9pt;">' . nl2br($desc) . '</span>
            </td>
            <td align="center">' . number_format($qty, 2) . '</td>
            <td align="right">' . numfmt_format_currency($currency_format, $price, $invoice_currency_code) . '</td>
            <td align="right">' . numfmt_format_currency($currency_format, $tax, $invoice_currency_code) . '</td>
            <td align="right">' . numfmt_format_currency($currency_format, $total, $invoice_currency_code) . '</td>
        </tr>';
    }

    $html .= '</table><br><hr><br><br>';

    // Totals
    $html .= '<table width="100%" cellspacing="0" cellpadding="4">
    <tr>
        <td width="60%"><i style="font-size:9pt;">' . nl2br($invoice_note) . '</i></td>
        <td width="40%">
            <table width="100%" cellpadding="3" cellspacing="0">
                <tr><td>Subtotal:</td><td align="right">' . numfmt_format_currency($currency_format, $sub_total, $invoice_currency_code) . '</td></tr>';
    if ($invoice_discount > 0) {
        $html .= '<tr><td>Discount:</td><td align="right">-' . numfmt_format_currency($currency_format, $invoice_discount, $invoice_currency_code) . '</td></tr>';
    }
    if ($total_tax > 0) {
        $html .= '<tr><td>Tax:</td><td align="right">' . numfmt_format_currency($currency_format, $total_tax, $invoice_currency_code) . '</td></tr>';
    }
    $html .= '
    <tr><td>Total:</td><td align="right">' . numfmt_format_currency($currency_format, $invoice_amount, $invoice_currency_code) . '</td></tr>';
    if ($amount_paid > 0) {
        $html .= '<tr><td>Paid:</td><td align="right">' . numfmt_format_currency($currency_format, $amount_paid, $invoice_currency_code) . '</td></tr>';
    }
    $html .= '
    <tr><td><h3><strong>Balance:</strong></h3></td><td align="right"><h3><strong>' . numfmt_format_currency($currency_format, $balance, $invoice_currency_code) . '</strong></h3></td></tr>
    </table>
        </td>
    </tr>
    </table><br><br>';

    // Footer
    $html .= '<div style="text-align:center; font-size:9pt; color:gray;">' . nl2br($config_invoice_footer) . '</div>';

    $pdf->writeHTML($html, true, false, true, false, '');

    $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', "{$invoice_date}_{$company_name}_{$client_name}_Invoice_{$invoice_prefix}{$invoice_number}");
    $pdf->Output("$filename.pdf", 'I');

    exit;

}

if (isset($_GET['export_invoice_packing_slip'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_sales');

    $invoice_id = intval($_GET['export_invoice_packing_slip']);

    $sql = mysqli_query(
        $mysqli,
        "SELECT * FROM invoices
        LEFT JOIN clients ON invoice_client_id = client_id
        LEFT JOIN contacts ON clients.client_id = contacts.contact_client_id AND contact_primary = 1
        LEFT JOIN locations ON clients.client_id = locations.location_client_id AND location_primary = 1
        WHERE invoice_id = $invoice_id
        $access_permission_query
        LIMIT 1"
    );

    $row = mysqli_fetch_assoc($sql);
    $invoice_id = intval($row['invoice_id']);
    $invoice_prefix = nullable_htmlentities($row['invoice_prefix']);
    $invoice_number = intval($row['invoice_number']);
    $invoice_date = nullable_htmlentities($row['invoice_date']);
    $client_id = intval($row['client_id']);
    $client_name = nullable_htmlentities($row['client_name']);
    $location_address = nullable_htmlentities($row['location_address']);
    $location_city = nullable_htmlentities($row['location_city']);
    $location_state = nullable_htmlentities($row['location_state']);
    $location_zip = nullable_htmlentities($row['location_zip']);
    $location_country = nullable_htmlentities($row['location_country']);
    $contact_email = nullable_htmlentities($row['contact_email']);
    $contact_phone_country_code = nullable_htmlentities($row['contact_phone_country_code']);
    $contact_phone = nullable_htmlentities(formatPhoneNumber($row['contact_phone'], $contact_phone_country_code));
    $contact_extension = nullable_htmlentities($row['contact_extension']);

    enforceClientAccess();

    $sql = mysqli_query($mysqli, "SELECT * FROM companies WHERE company_id = 1");
    $row = mysqli_fetch_assoc($sql);
    $company_id = intval($row['company_id']);
    $company_name = nullable_htmlentities($row['company_name']);
    $company_country = nullable_htmlentities($row['company_country']);
    $company_address = nullable_htmlentities($row['company_address']);
    $company_city = nullable_htmlentities($row['company_city']);
    $company_state = nullable_htmlentities($row['company_state']);
    $company_zip = nullable_htmlentities($row['company_zip']);
    $company_phone_country_code = nullable_htmlentities($row['company_phone_country_code']);
    $company_phone = nullable_htmlentities(formatPhoneNumber($row['company_phone'], $company_phone_country_code));
    $company_email = nullable_htmlentities($row['company_email']);
    $company_website = nullable_htmlentities($row['company_website']);
    $company_logo = nullable_htmlentities($row['company_logo']);

    require_once("../plugins/TCPDF/tcpdf.php");

    // Start TCPDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetMargins(10, 10, 10);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);

    // Logo + Right Columns
    $html = '<table width="100%" cellspacing="0" cellpadding="3">
    <tr>
        <td width="40%">';
    if (!empty($company_logo) && file_exists("../uploads/settings/$company_logo")) {
        $html .= '<img src="/uploads/settings/' . $company_logo . '" width="120">';
    }
    $html .= '</td>
        <td width="60%" align="right">
            <span style="font-size:18pt; font-weight:bold;">Packing Slip</span><br>
            <span style="font-size:14pt;">' . $invoice_prefix . $invoice_number . '</span><br>';
    $html .= '</td>
    </tr>
    </table><br>';

    // Billing titles
    $html .= '<table width="100%" cellspacing="0" cellpadding="2">
    <tr>
        <td width="50%" style="font-size:14pt; font-weight:bold;">' . $company_name . '</td>
        <td width="50%" align="right" style="font-size:14pt; font-weight:bold;">' . $client_name . '</td>
    </tr>
    <tr>
        <td style="font-size:10pt; line-height:1.4;">' . nl2br("$company_address\n$company_city $company_state $company_zip\n$company_country\n$company_phone\n$company_website") . '</td>
        <td style="font-size:10pt; line-height:1.4;" align="right">' . nl2br("$location_address\n$location_city $location_state $location_zip\n$location_country\n$contact_email\n$contact_phone") . '</td>
    </tr>
    </table><br>';

    // Items header
    $html .= '
    <table border="0" cellpadding="5" cellspacing="0" width="100%">
    <tr style="background-color:#f0f0f0;">
        <th align="left" width="50%"><strong>Item</strong></th>
        <th align="center" width="40%"><strong>Qty</strong></th>
        <th align="right" width="10%"><strong>Picked?</strong></th>
    </tr>';

    // Load items
    $sub_total = 0;
    $total_tax = 0;

    $sql_items = mysqli_query($mysqli, "SELECT * FROM invoice_items WHERE item_invoice_id = $invoice_id ORDER BY item_order ASC");
    while ($item = mysqli_fetch_assoc($sql_items)) {
        $name = $item['item_name'];
        $qty = $item['item_quantity'];

        $html .= '
        <tr>
            <td><strong>' . $name . '</strong></td>
            <td align="center">' . number_format($qty, 2) . '</td>
            <td align="right">
                <table cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="border:0.5px solid #000; width:12px; height:5px; margin-top:2px"></td>
                    </tr>
                </table>
            </td>
        </tr>';
    }
    $html .= '</table><br><br><br>';


    // Picked/Checked by
    $html .= '
    <table width="100%" cellspacing="0" cellpadding="8" style="font-size:10pt; margin-top:20px;">
        <tr>
            <td width="50%" style="border:1px solid #000; height:60px;">
                <strong>Picked By:</strong><br><br>
            </td>
            <td width="50%" style="border:1px solid #000; height:60px;">
                <strong>Checked By:</strong><br><br>
            </td>
        </tr>
    </table>
    <br><br>';

    $pdf->writeHTML($html, true, false, true, false, '');

    $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', "{$invoice_date}_{$company_name}_{$client_name}_Invoice_{$invoice_prefix}{$invoice_number}");
    $pdf->Output("$filename.pdf", 'I');

    exit;

}

if (isset($_POST['bulk_edit_invoice_category'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_sales', 2);

    $category_id = intval($_POST['bulk_category_id']);

    // Get Category name for logging and Notification
    $category_name = sanitizeInput(getFieldById('categories', $category_id, 'category_name'));

    // Assign Income category to Selected Invoices
    if (isset($_POST['invoice_ids'])) {

        // Get Selected Count
        $count = count($_POST['invoice_ids']);

        foreach($_POST['invoice_ids'] as $invoice_id) {
            $invoice_id = intval($invoice_id);

            // Get Invoice Details for Logging
            $sql = mysqli_query($mysqli,"SELECT * FROM invoices WHERE invoice_id = $invoice_id");
            $row = mysqli_fetch_assoc($sql);
            $invoice_prefix = sanitizeInput($row['invoice_prefix']);
            $invoice_number = intval($row['invoice_number']);
            $invoice_scope = sanitizeInput($row['invoice_scope']);
            $client_id = intval($row['invoice_client_id']);

            enforceClientAccess();

            mysqli_query($mysqli,"UPDATE invoices SET invoice_category_id = $category_id WHERE invoice_id = $invoice_id");

            logAction("Invoice", "Edit", "$session_name assigned Invoice $invoice_prefix$invoice_number to category $category_name", $client_id, $invoice_id);

        } // End Assign Loop

        logAction("Invoice", "Bulk Edit", "$session_name assigned $count invoices to category $category_name");

        flash_alert("Assigned income category <strong>$category_name</strong> to <strong>$count</strong> invoice(s)");
    }

    redirect();

}
