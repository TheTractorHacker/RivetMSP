<?php

/*
 * ITFlow - GET/POST request handler for revenue
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_revenue'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_financial', 2); // MSP-DS-60: the revenue ledger is a Financial module (page and modals already require it)

    $date = sanitizeInput($_POST['date']);
    $amount = floatval($_POST['amount']);
    $account = intval($_POST['account']);
    $category = intval($_POST['category']);
    $payment_method = sanitizeInput($_POST['payment_method']);
    $description = sanitizeInput($_POST['description']);
    $reference = sanitizeInput($_POST['reference']);

    if ($amount < 0) {
        flash_alert("Revenue amount can not be negative", 'error');
        redirect();
    }

    // Company currency / document prefixes are admin-entered settings loaded raw from the database: escape before use in SQL.
    $company_currency_sql = mysqli_real_escape_string($mysqli, (string) $session_company_currency);
    mysqli_query($mysqli,"INSERT INTO revenues SET revenue_date = '$date', revenue_amount = $amount, revenue_currency_code = '$company_currency_sql', revenue_payment_method = '$payment_method', revenue_reference = '$reference', revenue_description = '$description', revenue_category_id = $category, revenue_account_id = $account");

    $revenue_id = mysqli_insert_id($mysqli);

    logAction("Revenue", "Create", "$session_name added revenue $description", 0, $revenue_id);

    flash_alert("Revenue added");

    redirect();

}

if (isset($_POST['edit_revenue'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_financial', 2);

    $revenue_id = intval($_POST['revenue_id']);
    $date = sanitizeInput($_POST['date']);
    $amount = floatval($_POST['amount']);
    $account = intval($_POST['account']);
    $category = intval($_POST['category']);
    $payment_method = sanitizeInput($_POST['payment_method']);
    $description = sanitizeInput($_POST['description']);
    $reference = sanitizeInput($_POST['reference']);

    if ($amount < 0) {
        flash_alert("Revenue amount can not be negative", 'error');
        redirect();
    }

    // The revenue side of an account transfer is edited only through the transfer
    $transfer_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT transfer_id FROM transfers WHERE transfer_revenue_id = $revenue_id LIMIT 1"));
    if ($transfer_row) {
        flash_alert("This revenue is one side of an account transfer - edit the transfer instead", 'error');
        redirect();
    }

    mysqli_query($mysqli,"UPDATE revenues SET revenue_date = '$date', revenue_amount = $amount, revenue_payment_method = '$payment_method', revenue_reference = '$reference', revenue_description = '$description', revenue_category_id = $category, revenue_account_id = $account WHERE revenue_id = $revenue_id");

    logAction("Revenue", "Edit", "$session_name edited revenue $description", 0, $revenue_id);

    flash_alert("Revenue edited");

    redirect();

}

if (isset($_GET['delete_revenue'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_financial', 3);

    $revenue_id = intval($_GET['delete_revenue']);

    $transfer_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT transfer_id FROM transfers WHERE transfer_revenue_id = $revenue_id LIMIT 1"));
    if ($transfer_row) {
        flash_alert("This revenue is one side of an account transfer - delete the transfer instead", 'error');
        redirect();
    }

    // Get Revenue Details
    $revenue_description = sanitizeInput(getFieldById('revenues', $revenue_id, 'revenue_description'));

    mysqli_query($mysqli,"DELETE FROM revenues WHERE revenue_id = $revenue_id");

    logAction("Revenue", "Delete", "$session_name deleted revenue $revenue_description");

    flash_alert("Revenue deleted", 'error');

    redirect();

}
