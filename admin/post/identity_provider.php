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

if (isset($_POST['edit_oidc_provider'])) {

    validateCSRFToken($_POST['csrf_token']);

    $oidc_enabled = (isset($_POST['oidc_enabled']) && $_POST['oidc_enabled'] === '1') ? 1 : 0;
    $oidc_link_by_email = (isset($_POST['oidc_link_by_email']) && $_POST['oidc_link_by_email'] === '1') ? 1 : 0;
    $oidc_require_verified_email = (isset($_POST['oidc_require_verified_email']) && $_POST['oidc_require_verified_email'] === '1') ? 1 : 0;
    $oidc_issuer = sanitizeInput(trim($_POST['oidc_issuer'] ?? ''));
    $oidc_client_id = sanitizeInput(trim($_POST['oidc_client_id'] ?? ''));
    $oidc_client_secret_raw = trim($_POST['oidc_client_secret'] ?? '');

    // Same "blank means keep current" rule as the Microsoft Entra secret above - the field is never
    // redisplayed, so a blank submit here is never "clear it".
    if ($oidc_client_secret_raw !== '') {
        $oidc_client_secret = encryptSetting($oidc_client_secret_raw);
        $stmt = mysqli_prepare($mysqli, "UPDATE settings SET config_oidc_enabled = ?, config_oidc_issuer = ?, config_oidc_client_id = ?, config_oidc_client_secret = ?, config_oidc_link_by_email = ?, config_oidc_require_verified_email = ? WHERE company_id = 1");
        mysqli_stmt_bind_param($stmt, "isssii", $oidc_enabled, $oidc_issuer, $oidc_client_id, $oidc_client_secret, $oidc_link_by_email, $oidc_require_verified_email);
    } else {
        $stmt = mysqli_prepare($mysqli, "UPDATE settings SET config_oidc_enabled = ?, config_oidc_issuer = ?, config_oidc_client_id = ?, config_oidc_link_by_email = ?, config_oidc_require_verified_email = ? WHERE company_id = 1");
        mysqli_stmt_bind_param($stmt, "issii", $oidc_enabled, $oidc_issuer, $oidc_client_id, $oidc_link_by_email, $oidc_require_verified_email);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    logAction("Settings", "Edit", "$session_name edited OpenID Connect provider settings");

    flash_alert("OpenID Connect settings updated");

    redirect();

}
