<?php

/*
 * Client Portal
 * Checks if the client is logged in or not
 */

if (!isset($_SESSION)) {
    // HTTP Only cookies
    ini_set("session.cookie_httponly", true);
    // MSP-DS-145 (review R30): the portal session file must outlive the 24 hour absolute limit enforced below
    ini_set("session.gc_maxlifetime", "86400");
    if ($config_https_only) {
        // Tell client to only send cookie(s) over HTTPS
        ini_set("session.cookie_secure", true);
    }
    session_start();
}

if (!isset($_SESSION['client_logged_in']) || !$_SESSION['client_logged_in']) {
    redirect("/login.php");
}

// MSP-DS-145 (review R30): a portal session ends after 8 hours without a page request or 24 hours in all. Background polls (the SSE
// stream, ?_bg=1) are checked but do not extend the idle timer; keepalive.php never reaches this file, so a left-open tab expires too.
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/security_sessions.php';
$portal_background = (defined('RIVET_BACKGROUND_REQUEST') || ($_GET['_bg'] ?? '') === '1' || ($_SERVER['HTTP_X_RIVET_BACKGROUND'] ?? '') === '1'
    || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/event-stream'));
if (!secPortalSessionAlive($_SESSION, time(), $portal_background)) {
    session_unset();
    session_destroy();
    redirect("/login.php");
}

// Set Timezone
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_set_timezone.php';

// User IP & UA
$session_ip = sanitizeInput(getIP());
$session_user_agent = sanitizeInput($_SERVER['HTTP_USER_AGENT']);


// Get info from session
$session_client_id = intval($_SESSION['client_id']);
$session_contact_id = intval($_SESSION['contact_id']);
$session_user_id = intval($_SESSION['user_id']);

// Load user session vars
$sql = mysqli_query($mysqli, "SELECT * FROM users WHERE users.user_id = $session_user_id");

$row = mysqli_fetch_assoc($sql);

$portal_session_version = (int) ($row['user_session_version'] ?? 0);   // MSP-DS-145 (0 before the column exists)
$session_avatar = $row['user_avatar'];
$session_user_type = intval($row['user_type']);
$session_user_status = intval($row['user_status']);
$session_user_archived_at = $row['user_archived_at'];

// Check user type is client aka 2
if ($session_user_type !== 2) {
    session_unset();
    session_destroy();
    redirect("/login.php");
}

// Check User is active
if ($session_user_status !== 1) {
    session_unset();
    session_destroy();
    redirect("/login.php");
}

// Check User is archived
if ($session_user_archived_at !== null) {
    session_unset();
    session_destroy();
    redirect("/login.php");
}

// MSP-DS-145: an existing portal session also ends when the portal is switched off, when the client is archived, and when this
// account's password changed since the session started (a reset or change signs every other browser out).
$portal_gate = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT s.config_client_portal_enable, c.client_archived_at, u.user_password
    FROM settings s, users u, clients c WHERE s.company_id = 1 AND u.user_id = $session_user_id AND c.client_id = $session_client_id LIMIT 1"));
$portal_pw_fingerprint = hash('sha256', (string) ($portal_gate['user_password'] ?? ''));
if (!isset($_SESSION['portal_pw_fp'])) {
    $_SESSION['portal_pw_fp'] = $portal_pw_fingerprint;
}
if (!$portal_gate
    || intval($portal_gate['config_client_portal_enable']) !== 1
    || $portal_gate['client_archived_at'] !== null
    || !hash_equals((string) $_SESSION['portal_pw_fp'], $portal_pw_fingerprint)
    // MSP-DS-145: revocation by version: a password change/reset/set by an administrator bumps users.user_session_version and every older session ends
    || !secPortalSessionVersionOk($_SESSION, $portal_session_version)) {
    session_unset();
    session_destroy();
    redirect("/login.php");
}

// Load company session vars
$sql = mysqli_query($mysqli, "SELECT * FROM companies WHERE company_id = 1");
$row = mysqli_fetch_assoc($sql);

$session_company_name = $row['company_name'];
$session_company_country = $row['company_country'];
$session_company_locale = $row['company_locale'];
$session_company_currency = $row['company_currency'];
$currency_format = numfmt_create($session_company_locale, NumberFormatter::CURRENCY);
$session_company_logo = $row['company_logo'];

// Load contact session vars
$contact_sql = mysqli_query($mysqli, "SELECT * FROM contacts WHERE contact_id = $session_contact_id AND contact_client_id = $session_client_id");
$contact = mysqli_fetch_assoc($contact_sql);

// The contact behind this session must still exist and be active: a deleted or archived contact ends its open portal sessions (MSP-DS-15)
if (!$contact || $contact['contact_archived_at'] !== null) {
    session_unset();
    session_destroy();
    redirect("/login.php");
}

$session_contact_name = sanitizeInput($contact['contact_name']);
$session_contact_initials = initials($session_contact_name);
$session_contact_title = sanitizeInput($contact['contact_title']);
$session_contact_email = sanitizeInput($contact['contact_email']);
$session_contact_photo = sanitizeInput($contact['contact_photo']);
$session_contact_pin = sanitizeInput($contact['contact_pin']);
$session_contact_primary = intval($contact['contact_primary']);

$session_contact_is_technical_contact = false;
$session_contact_is_billing_contact = false;
if ($contact['contact_technical'] == 1) {
    $session_contact_is_technical_contact = true;
}
if ($contact['contact_billing'] == 1) {
    $session_contact_is_billing_contact = true;
}

// Load client session vars
$client_sql = mysqli_query($mysqli, "SELECT * FROM clients WHERE client_id = $session_client_id");
$client = mysqli_fetch_assoc($client_sql);

$session_client_name = $client['client_name'];
