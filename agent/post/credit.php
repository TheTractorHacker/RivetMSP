<?php

/*
 * ITFlow - GET/POST request handler for credits
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_credit'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_sales', 2);

    $client_id = intval($_POST['client']);
    enforceClientAccess($client_id);

    require_once __DIR__ . '/../../includes/billing_guards.php';

    // A credit is a positive amount (a negative one would silently reduce the client's balance) and the type is a
    // fixed list (nightly MSP-5)
    $amount = parsePositiveMoney($_POST['amount'] ?? null);
    if ($amount === null) {
        flash_alert("Credit amount must be greater than zero", 'error');
        redirect();
    }
    $type = sanitizeInput($_POST['type']);
    if (!in_array($type, ['prepaid', 'manual', 'refund', 'promotion'], true)) {
        flash_alert("Invalid credit type", 'error');
        redirect();
    }
    $expire = sanitizeInput($_POST['expire']);
    $note = sanitizeInput($_POST['note']);

    mysqli_query($mysqli,"INSERT INTO credits SET credit_amount = $amount, credit_type = '$type', credit_note = '$note', credit_created_by = $session_user_id, credit_client_id = $client_id");
    
    $credit_id = mysqli_insert_id($mysqli);

    logAction("Credit", "Create", "$session_name added " . numfmt_format_currency($currency_format, $amount, $session_company_currency) . "", $client_id, $credit_id);

    flash_alert(numfmt_format_currency($currency_format, $amount, $session_company_currency) . " Credit Added");

    redirect();

}
