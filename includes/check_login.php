<?php

require_once "session_init.php";
require_once "redirect_if_setup_enabled.php";
require_once "auth_check.php";
require_once "inc_set_timezone.php";
require_once "load_user_session.php";
require_once "load_company_settings.php";
require_once "load_global_settings.php";
require_once "detect_device_type.php";

// MFA enforcement (Admin > Settings > Security > Sign-in policy, or a user's own "require MFA" flag): an agent who must have two-factor,
// has not set it up and is out of the grace period can only reach the account pages needed to enrol. In grace, a one-time notice
// names the deadline. Client portal logins are handled by client/includes/check_login.php.
if (($session_user_type ?? 0) === 1) {
    secMfaGate($mysqli, intval($session_user_id), (string) parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH));
}
