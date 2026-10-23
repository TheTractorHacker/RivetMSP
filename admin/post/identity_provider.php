<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_identity_provider'])) {

    validateCSRFToken($_POST['csrf_token']);

    $azure_client_id = sanitizeInput($_POST['azure_client_id']);
    $azure_client_secret_raw = trim($_POST['azure_client_secret'] ?? '');

    // The secret is never redisplayed to the browser (admin/identity_provider.php leaves the field blank), so a blank submit here
    // means "keep what is already stored", not "clear it" - only encrypt and store a new value when one was actually typed.
    if ($azure_client_secret_raw !== '') {
        $azure_client_secret = encryptSetting($azure_client_secret_raw);
        $stmt = mysqli_prepare($mysqli, "UPDATE settings SET config_azure_client_id = ?, config_azure_client_secret = ? WHERE company_id = 1");
        mysqli_stmt_bind_param($stmt, "ss", $azure_client_id, $azure_client_secret);
    } else {
        $stmt = mysqli_prepare($mysqli, "UPDATE settings SET config_azure_client_id = ? WHERE company_id = 1");
        mysqli_stmt_bind_param($stmt, "s", $azure_client_id);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    logAction("Settings", "Edit", "$session_name edited identity provider settings");

    flash_alert("Identity Provider Settings updated");

    redirect();

}
