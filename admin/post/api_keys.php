<?php

/*
 * ITFlow - GET/POST request handler for API settings
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['set_api_rate_limit'])) {

    validateCSRFToken($_POST['csrf_token']);

    $per_minute = max(0, min(100000, intval($_POST['api_rate_limit_per_minute'] ?? 120)));
    mysqli_query($mysqli, "UPDATE settings SET config_api_rate_limit_per_minute = $per_minute WHERE company_id = 1");

    logAction("API Key", "Edit", "$session_name set the API rate limit to $per_minute requests per minute per key");
    if (function_exists('rivetAudit')) {
        rivetAudit('api.rate_limit_changed', (int) $session_user_id, 'settings', 'api_rate_limit', 'update', 'API rate limit changed', ['per_minute' => $per_minute]);
    }

    flash_alert($per_minute === 0 ? "API rate limit turned off" : "API rate limit set to <strong>$per_minute</strong> requests per minute per key");

    redirect();

}

if (isset($_POST['add_api_key'])) {

    validateCSRFToken($_POST['csrf_token']);

    $name = sanitizeInput($_POST['name']);
    $expire = sanitizeInput($_POST['expire']);
    $client_id = intval($_POST['client']);
    $permission = ($_POST['permission'] ?? 'write') === 'read' ? 'read' : 'write';
    $secret_raw = trim($_POST['key']); // API Key (plaintext - used transiently then hashed)
    $secret = hash('sha256', $secret_raw); // Store only the hash

    // Credential decryption password
    $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
    $apikey_specific_encryption_ciphertext = encryptUserSpecificKey(trim($_POST['password']));

    mysqli_query($mysqli,"INSERT INTO api_keys SET api_key_name = '$name', api_key_secret = '$secret', api_key_decrypt_hash = '$apikey_specific_encryption_ciphertext', api_key_expire = '$expire', api_key_client_id = $client_id, api_key_permission = '$permission'");

    $api_key_id = mysqli_insert_id($mysqli);

    logAction("API Key", "Create", "$session_name created API key $name set to expire on $expire", $client_id, $api_key_id);

    flash_alert("API Key <strong>$name</strong> created");

    redirect();

}

if (isset($_GET['revoke_api_key'])) {

    validateCSRFToken($_GET['csrf_token']);

    $api_key_id = intval($_GET['revoke_api_key']);

    // Get API Key Name
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,"SELECT api_key_name, api_key_client_id FROM api_keys WHERE api_key_id = $api_key_id"));
    $api_key_name = sanitizeInput($row['api_key_name']);
    $client_id = intval($row['api_key_client_id']);

    mysqli_query($mysqli,"UPDATE api_keys SET api_key_expire = NOW() WHERE api_key_id = $api_key_id");

    logAction("API Key", "Revoke", "$session_name revoked API key $api_key_name", $client_id);

    flash_alert("API Key <strong>$api_key_name</strong> revoked", 'error');

    redirect();

}

if (isset($_GET['delete_api_key'])) {

    validateCSRFToken($_GET['csrf_token']);

    $api_key_id = intval($_GET['delete_api_key']);

    // Get API Key Name
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,"SELECT api_key_name, api_key_client_id FROM api_keys WHERE api_key_id = $api_key_id"));
    $api_key_name = sanitizeInput($row['api_key_name']);
    $client_id = intval($row['api_key_client_id']);

    mysqli_query($mysqli,"DELETE FROM api_keys WHERE api_key_id = $api_key_id");

    logAction("API Key", "Delete", "$session_name deleted API key $api_key_name", $client_id);

    flash_alert("API Key <strong>$api_key_name</strong> deleted", 'error');

    redirect();

}

if (isset($_POST['bulk_delete_api_keys'])) {

    validateCSRFToken($_POST['csrf_token']);

    if (isset($_POST['api_key_ids'])) {

        $count = count($_POST['api_key_ids']);

        // Cycle through array and delete each record
        foreach ($_POST['api_key_ids'] as $api_key_id) {

            $api_key_id = intval($api_key_id);

            // Get API Key Name
            $row = mysqli_fetch_assoc(mysqli_query($mysqli,"SELECT api_key_name, api_key_client_id FROM api_keys WHERE api_key_id = $api_key_id"));
            $api_key_name = sanitizeInput($row['api_key_name']);
            $client_id = intval($row['api_key_client_id']);

            mysqli_query($mysqli, "DELETE FROM api_keys WHERE api_key_id = $api_key_id");

            logAction("API Key", "Delete", "$session_name deleted API key $api_key_name", $client_id);

        }

        logAction("API Key", "Bulk Delete", "$session_name deleted $count API key(s)");

        flash_alert("Deleted <strong>$count</strong> API keys(s)", 'error');

    }

    redirect();

}
