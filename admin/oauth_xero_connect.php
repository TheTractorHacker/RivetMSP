<?php

/*
 * ITFlow - Start the Xero OAuth2 connect flow (roadmap #20).
 *
 * Mirrors oauth_quickbooks_connect.php's session state+expiry pattern exactly. Xero's
 * authorize endpoint and scope list are Xero-specific; everything else (state token, redirect
 * URI construction, admin-only gate) is identical.
 */

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../functions.php";
require_once __DIR__ . "/../includes/check_login.php";
require_once __DIR__ . "/../includes/accounting_functions.php";

$settings_accounting_path = '/admin/settings_accounting.php';

if (!isset($session_is_admin) || !$session_is_admin) {
    flash_alert("Admin access required.", 'error');
    redirect($settings_accounting_path);
}

$integration = getAccountingIntegration($mysqli);
if (!$integration || empty($integration['accounting_client_id'])) {
    flash_alert("Enter and save your Xero Client ID and Client Secret before connecting.", 'error');
    redirect($settings_accounting_path);
}

$client_id = (string) $integration['accounting_client_id'];

if (defined('BASE_URL') && !empty(BASE_URL)) {
    $base_url = rtrim((string) BASE_URL, '/');
} else {
    $base_url = 'https://' . rtrim((string) $config_base_url, '/');
}
$redirect_uri = $base_url . '/admin/oauth_xero_callback.php';

$state = bin2hex(random_bytes(20));
$_SESSION['xero_oauth_state'] = $state;
$_SESSION['xero_oauth_state_expires_at'] = time() + 600; // 10 minutes

// offline_access -> refresh token; the rest are the minimum scopes for contacts, items,
// invoices, quotes and payments (exactly the five entity types this integration pushes).
$authorize_url = 'https://login.xero.com/identity/connect/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id'     => $client_id,
    'redirect_uri'  => $redirect_uri,
    'scope'         => 'offline_access accounting.transactions accounting.contacts accounting.settings',
    'state'         => $state,
], '', '&');

redirect($authorize_url);
