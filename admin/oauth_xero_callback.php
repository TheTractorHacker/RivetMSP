<?php

/*
 * ITFlow - Xero OAuth2 callback (roadmap #20).
 *
 * Mirrors oauth_quickbooks_callback.php (state+expiry+hash_equals, curl token exchange,
 * encryptSetting on tokens) with one extra step QBO doesn't need: Xero's callback query string
 * carries only ?code&state (no tenant/company id at all, unlike QBO's realmId), so after the
 * token exchange this calls Xero's /connections endpoint to discover which tenant(s) the
 * authorization actually grants access to, and uses the first one. Supporting a tenant PICKER
 * for an app connected to multiple Xero organisations is intentionally out of scope for this
 * v1 (ITFlow's own accounting_integrations design is a single row per install, same as QBO's
 * one-realm-per-install model) - flagged here as a scope cut, not a silent gap.
 *
 * RIVETMSP_TEST_XERO_TOKEN_URL / RIVETMSP_TEST_XERO_CONNECTIONS_URL: test-only endpoint
 * overrides (tests/accounting_xero.php points these at a loopback fake-Xero server). Unset in
 * every real environment, where this hits the real Xero endpoints below.
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

$state  = sanitizeInput($_GET['state'] ?? '');
$code   = $_GET['code'] ?? '';
$error  = sanitizeInput($_GET['error'] ?? '');
$error_description = sanitizeInput($_GET['error_description'] ?? '');

$session_state         = $_SESSION['xero_oauth_state'] ?? '';
$session_state_expires = intval($_SESSION['xero_oauth_state_expires_at'] ?? 0);
unset($_SESSION['xero_oauth_state'], $_SESSION['xero_oauth_state_expires_at']);

if (!empty($error)) {
    $msg = "Xero OAuth authorization failed: $error";
    if (!empty($error_description)) {
        $msg .= " ($error_description)";
    }
    flash_alert($msg, 'error');
    redirect($settings_accounting_path);
}

if (empty($state) || empty($code) || empty($session_state) || !hash_equals($session_state, $state) || time() > $session_state_expires) {
    flash_alert("Xero OAuth callback validation failed. Please try connecting again.", 'error');
    redirect($settings_accounting_path);
}

$integration = getAccountingIntegration($mysqli);
if (!$integration || empty($integration['accounting_client_id'])) {
    flash_alert("Xero Client ID / Secret are not configured.", 'error');
    redirect($settings_accounting_path);
}

$accounting_id = intval($integration['accounting_id']);
$client_id     = (string) $integration['accounting_client_id'];
$client_secret = decryptSetting((string) ($integration['accounting_client_secret'] ?? ''));

if (empty($client_secret)) {
    flash_alert("Xero Client Secret is not configured.", 'error');
    redirect($settings_accounting_path);
}

if (defined('BASE_URL') && !empty(BASE_URL)) {
    $base_url = rtrim((string) BASE_URL, '/');
} else {
    $base_url = 'https://' . rtrim((string) $config_base_url, '/');
}
$redirect_uri = $base_url . '/admin/oauth_xero_callback.php';

$token_url       = getenv('RIVETMSP_TEST_XERO_TOKEN_URL') ?: 'https://identity.xero.com/connect/token';
$connections_url = getenv('RIVETMSP_TEST_XERO_CONNECTIONS_URL') ?: 'https://api.xero.com/connections';

// ── Exchange the authorization code for tokens (HTTP Basic auth with client creds) ──────────
$ch = curl_init($token_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_USERPWD, $client_id . ':' . $client_secret);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/x-www-form-urlencoded',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'grant_type'   => 'authorization_code',
    'code'         => $code,
    'redirect_uri' => $redirect_uri,
], '', '&'));
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$raw_body  = curl_exec($ch);
$curl_err  = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw_body === false || $http_code < 200 || $http_code >= 300) {
    $reason = !empty($curl_err) ? $curl_err : "HTTP $http_code";
    flash_alert("Xero OAuth token exchange failed: $reason", 'error');
    redirect($settings_accounting_path);
}

$json = json_decode($raw_body, true);
if (!is_array($json) || empty($json['refresh_token']) || empty($json['access_token'])) {
    flash_alert("Xero OAuth token exchange failed: refresh token or access token missing.", 'error');
    redirect($settings_accounting_path);
}

$access_token  = (string) $json['access_token'];
$refresh_token = (string) $json['refresh_token'];
$expires_at    = date('Y-m-d H:i:s', time() + intval($json['expires_in'] ?? 1800));

// ── Discover the connected tenant(s); use the first one (see header note) ───────────────────
$ch = curl_init($connections_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $access_token,
    'Accept: application/json',
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$conn_raw  = curl_exec($ch);
$conn_err  = curl_error($ch);
$conn_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($conn_raw === false || $conn_code < 200 || $conn_code >= 300) {
    $reason = !empty($conn_err) ? $conn_err : "HTTP $conn_code";
    flash_alert("Xero connected, but listing authorized organisations failed: $reason", 'error');
    redirect($settings_accounting_path);
}

$connections = json_decode($conn_raw, true);
if (!is_array($connections) || empty($connections[0]['tenantId'])) {
    flash_alert("Xero authorization did not grant access to any organisation.", 'error');
    redirect($settings_accounting_path);
}

$tenant_id   = (string) $connections[0]['tenantId'];
$tenant_name = (string) ($connections[0]['tenantName'] ?? $tenant_id);

$tenant_id_esc     = mysqli_real_escape_string($mysqli, $tenant_id);
$access_token_esc  = mysqli_real_escape_string($mysqli, encryptSetting($access_token));
$refresh_token_esc = mysqli_real_escape_string($mysqli, encryptSetting($refresh_token));
$expires_at_esc    = mysqli_real_escape_string($mysqli, $expires_at);

mysqli_query($mysqli, "UPDATE accounting_integrations SET
    accounting_provider = 'xero',
    accounting_realm_id = '$tenant_id_esc',
    accounting_access_token = '$access_token_esc',
    accounting_refresh_token = '$refresh_token_esc',
    accounting_token_expires_at = '$expires_at_esc',
    accounting_enabled = 1,
    accounting_connected_at = NOW()
    WHERE accounting_id = $accounting_id
");

accountingLog($mysqli, $accounting_id, 'integration', $accounting_id, 'connected', "Connected to Xero organisation \"$tenant_name\" (tenant $tenant_id)");
logAction("Settings", "Edit", "$session_name connected Xero (tenant $tenant_id)");
flash_alert("Xero connected successfully. Organisation: " . nullable_htmlentities($tenant_name));
redirect($settings_accounting_path);
