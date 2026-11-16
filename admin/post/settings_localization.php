<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_localization'])) {

    validateCSRFToken($_POST['csrf_token']);

    $locale = sanitizeInput($_POST['locale']);
    $currency_code = sanitizeInput($_POST['currency_code']);
    $timezone = sanitizeInput($_POST['timezone']);

    // MSP-DS-40: only a real zone identifier is stored (an unknown one used to break every page, login and cron)
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        flash_alert("Invalid timezone", 'error');
        redirect();
    }

    mysqli_query($mysqli,"UPDATE companies SET company_locale = '$locale', company_currency = '$currency_code' WHERE company_id = 1");

    mysqli_query($mysqli,"UPDATE settings SET config_timezone = '$timezone' WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited localization settings");

    flash_alert("Company localization updated");

    redirect();

}
