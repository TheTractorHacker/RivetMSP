<?php
/*
 * Client Portal
 * OAuth Login via Microsoft IDP
 */

require_once '../config.php';
require_once '../functions.php';
require_once '../includes/security_sessions.php';

if (!isset($_SESSION)) {
    // HTTP Only cookies
    ini_set("session.cookie_httponly", true);
    if ($config_https_only) {
        // Tell client to only send cookie(s) over HTTPS
        ini_set("session.cookie_secure", true);
    }
    session_start();
}

// Set Timezone after session starts
require_once "../includes/inc_set_timezone.php";

$session_ip = sanitizeInput(getIP());
$session_user_agent = sanitizeInput($_SERVER['HTTP_USER_AGENT']);

$sql_settings = mysqli_query($mysqli, "SELECT config_azure_client_id, config_azure_client_secret FROM settings WHERE company_id = 1");
$settings = mysqli_fetch_assoc($sql_settings);

$client_id = $settings['config_azure_client_id'];
$client_secret = decryptSetting((string) ($settings['config_azure_client_secret'] ?? ''));

$redirect_uri = "https://$config_base_url/client/login_microsoft.php";

# https://docs.microsoft.com/en-us/azure/active-directory/develop/v2-oauth2-auth-code-flow
$auth_code_url = "https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize";
$token_grant_url = "https://login.microsoftonline.com/organizations/oauth2/v2.0/token";

// MSP-DS-146/147: the portal can be switched off, and every failure goes back to the portal login page at the site root
$portal_on = intval(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_client_portal_enable FROM settings WHERE company_id = 1"))['config_client_portal_enable'] ?? 0) === 1;
if (!$portal_on) {
    header('Location: /login.php');
    exit;
}

// Microsoft returned an error (user cancelled, consent refused): back to the login page
if (isset($_GET['error'])) {
    unset($_SESSION['portal_ms_state']);
    header('Location: /login.php');
    exit;
}

// Initial Login Request, via Microsoft
// Returns an authorization code if login was successful
// MSP-DS-147: response_mode=query, so the return is a GET that carries the (SameSite=Lax) session cookie; state is a random value, not the session id
if ($_SERVER['REQUEST_METHOD'] == "GET" && !isset($_GET['code'])) {

    $_SESSION['portal_ms_state'] = bin2hex(random_bytes(32));

    $params = array (
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'response_mode' =>'query',
        'scope' => 'https://graph.microsoft.com/User.Read',
        'state' => $_SESSION['portal_ms_state']);

    header('Location: '.$auth_code_url.'?'.http_build_query($params));
    exit;

}

// Login was successful, Microsoft has returned us an authorization code
// Request an access token using authorization code (& client secret) (server side)
$ms_state_ok = isset($_GET['code'], $_GET['state'], $_SESSION['portal_ms_state'])
    && is_string($_GET['state']) && hash_equals((string) $_SESSION['portal_ms_state'], $_GET['state']);
unset($_SESSION['portal_ms_state']);   // one use
if (isset($_GET['code']) && !$ms_state_ok) {
    header('Location: /login.php');
    exit;
}
if ($ms_state_ok) {

    $params = array (
        'client_id' =>$client_id,
        'code' => $_GET['code'],
        'redirect_uri' => $redirect_uri,
        'grant_type' => 'authorization_code',
        'client_secret' => $client_secret
    );

    // Send request via CURL (server side) so user cannot see the client secret
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $token_grant_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt(
        $ch,
        CURLOPT_POSTFIELDS,
        http_build_query($params)
    );
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    #curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); // DEBUG ONLY - WAMP

    $access_token_response = json_decode(curl_exec($ch), 1);

    // Check if we have an access token
    // If we do, send a request to Microsoft Graph API to get user info
    if (isset($access_token_response['access_token'])) {

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPHEADER, array ('Authorization: Bearer '.$access_token_response['access_token'],
            'Content-type: application/json'));
        curl_setopt($ch, CURLOPT_URL, "https://graph.microsoft.com/v1.0/me/");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        #curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); // DEBUG ONLY - WAMP

        $msgraph_response = json_decode(curl_exec($ch), 1);

        if (isset($msgraph_response['error'])) {
            // Something went wrong verifying the token/using the Graph API - quit
            error_log("Portal Microsoft login: Graph API error: " . json_encode($msgraph_response['error']));
            echo "Error with MS Graph API. Please try again or contact your administrator.";
            exit();

        } elseif (isset($msgraph_response['id'])) {

            $upn = mysqli_real_escape_string($mysqli, $msgraph_response["userPrincipalName"]);

            $sql = mysqli_query($mysqli, "SELECT * FROM users
                LEFT JOIN contacts ON user_id = contact_user_id
                LEFT JOIN clients ON contact_client_id = client_id
                WHERE user_email = '$upn'
                AND user_archived_at IS NULL
                AND client_archived_at IS NULL
                AND user_type = 2
                AND user_status = 1
                LIMIT 1"
            );
            $row = mysqli_fetch_assoc($sql);
            $client_id = intval($row['contact_client_id']);
            $user_id = intval($row['user_id']);
            $session_user_id = $user_id; // to pass the user_id to logAction function
            $contact_id = intval($row['contact_id']);
            $user_email = sanitizeInput($row['user_email']);
            $user_auth_method = sanitizeInput($row['user_auth_method']);

            if ($user_auth_method == 'azure') {

                session_regenerate_id(true);
                secPortalSessionBegin($_SESSION);   // MSP-DS-145 (review R30/R31): start the idle/absolute clocks, drop a stale password fingerprint from an earlier sign-in in this browser

                $_SESSION['client_logged_in'] = true;
                $_SESSION['client_id'] = $client_id;
                $_SESSION['user_id'] = $user_id;
                $_SESSION['user_type'] = 2;
                $_SESSION['contact_id'] = $contact_id;
                $_SESSION['csrf_token'] = randomString(32);
                $_SESSION['login_method'] = "azure";

                // Logging
                logAction("Client Login", "Success", "Client contact $upn successfully logged in via Entra", $client_id, $user_id);

                header("Location: index.php");

            } else {

                $_SESSION['login_message'] = 'Something went wrong with logging you in: Your account is not configured for Entra SSO. Please ensure you are setup in RivetMSP as a contact and have Entra SSO configured.';

                header("Location: index.php");
            }

        }

        header('Location: index.php');
        exit;

    } else {

        echo "Error getting access_token";
        exit;

    }

}

// Anything that did not complete a sign-in: back to the portal login page (it lives at the site root, not under /client/)
header('Location: /login.php');
exit;
