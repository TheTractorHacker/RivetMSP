<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_portal_branding_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Display name: free text, optional. Blank clears the override (fall back to companies.company_name).
    $display_name = trim(sanitizeInput($_POST['config_portal_display_name'] ?? ''));
    $display_name_sql = $display_name !== ''
        ? "'" . mysqli_real_escape_string($mysqli, $display_name) . "'"
        : "NULL";

    // Accent: only accept a valid #RRGGBB value, otherwise clear it (NULL) so the admin accent is used.
    $accent_input = trim($_POST['config_portal_accent'] ?? '');
    $accent_sql = preg_match('/^#[0-9A-Fa-f]{6}$/', $accent_input)
        ? "'" . strtoupper(mysqli_real_escape_string($mysqli, $accent_input)) . "'"
        : "NULL";

    // Portal logo (separate from the company logo on Company Details - lets the portal use a different
    // mark than the admin UI, e.g. a lighter variant for the dark portal navbar).
    $sql = mysqli_query($mysqli, "SELECT config_portal_logo FROM settings WHERE company_id = 1");
    $row = mysqli_fetch_assoc($sql);
    $existing_file_name = sanitizeInput($row['config_portal_logo'] ?? '');

    if (isset($_FILES['file']['tmp_name']) && $_FILES['file']['tmp_name'] !== '') {
        if ($new_file_name = checkFileUpload($_FILES['file'], array('jpg', 'jpeg', 'png'))) {
            $upload_file_dir = "../uploads/settings/";
            move_uploaded_file($_FILES['file']['tmp_name'], $upload_file_dir . $new_file_name);

            if ($existing_file_name !== '' && file_exists($upload_file_dir . $existing_file_name)) {
                unlink($upload_file_dir . $existing_file_name);
            }

            mysqli_query($mysqli, "UPDATE settings SET config_portal_logo = '" . mysqli_real_escape_string($mysqli, $new_file_name) . "' WHERE company_id = 1");
        }
    }

    mysqli_query($mysqli, "UPDATE settings SET config_portal_display_name = $display_name_sql, config_portal_accent = $accent_sql WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited client portal branding settings");

    flash_alert("Client portal branding updated");

    redirect();

}

if (isset($_GET['remove_portal_logo'])) {

    validateCSRFToken($_GET['csrf_token']);

    $sql = mysqli_query($mysqli, "SELECT config_portal_logo FROM settings WHERE company_id = 1");
    $row = mysqli_fetch_assoc($sql);
    $portal_logo = sanitizeInput($row['config_portal_logo'] ?? ''); // FileSystem operation - already sanitized

    if ($portal_logo !== '' && file_exists("../uploads/settings/$portal_logo")) {
        unlink("../uploads/settings/$portal_logo");
    }

    mysqli_query($mysqli, "UPDATE settings SET config_portal_logo = NULL WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name removed the client portal logo");

    flash_alert("Removed portal logo", 'error');

    redirect();

}
