<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// ── Encryption keys (Administration > Security > Keys). Admin only (the handler is only included for administrators and each action checks again),
// CSRF-checked, audited. Nothing here ever reads, shows or logs key material: kids, fingerprints and counts only. The same code runs from
// scripts/keys_cli.php and scripts/rewrap_cli.php (RivetMSP\Crypto\KeyAdmin, RewrapService).
if (isset($_POST['keys_action'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceAdminPermission();

    $keys_action = (string) $_POST['keys_action'];
    $keys_kid    = (string) ($_POST['kid'] ?? '');
    try {
        switch ($keys_action) {
            case 'add_key':
                $r = \RivetMSP\Crypto\KeyAdmin::addKey(true);
                $msg = "$session_name added encryption key {$r['kid']} (fingerprint {$r['fingerprint']}) and made it active";
                \RivetMSP\Core\CoreBridge::audit(true)?->log('crypto.key_added', $session_user_id, 'key', $r['kid'], 'rotate', $msg, ['kid' => $r['kid'], 'fingerprint' => $r['fingerprint']]);
                logAction("Settings", "Edit", $msg);
                flash_alert("Key {$r['kid']} added and active. New values use it; run the rewrap to move the existing ones, and refresh your offline copy of the key file.");
                break;

            case 'set_active':
                $r = \RivetMSP\Crypto\KeyAdmin::setActive($keys_kid);
                $msg = "$session_name made encryption key {$r['kid']} the active key";
                \RivetMSP\Core\CoreBridge::audit(true)?->log('crypto.key_activated', $session_user_id, 'key', $r['kid'], 'activate', $msg, ['kid' => $r['kid']]);
                logAction("Settings", "Edit", $msg);
                flash_alert("Key {$r['kid']} is now the active key.");
                break;

            case 'retire':
                $svc = new \RivetMSP\Crypto\RewrapService($mysqli, (int) $session_user_id);
                $usage = [];
                foreach ($svc->inventory()['totals'] as $label => $n) {
                    if (str_starts_with($label, 'v3:')) {
                        $usage[substr($label, 3)] = $n;
                    }
                }
                $r = \RivetMSP\Crypto\KeyAdmin::retire($keys_kid, $usage, false);   // the panel never forces
                $msg = "$session_name retired encryption key {$r['kid']}";
                \RivetMSP\Core\CoreBridge::audit(true)?->log('crypto.key_retired', $session_user_id, 'key', $r['kid'], 'retire', $msg, ['kid' => $r['kid']]);
                logAction("Settings", "Edit", $msg);
                flash_alert("Key {$r['kid']} retired. Refresh your offline copy of the key file.");
                break;

            case 'rewrap':
            case 'rewrap_dry':
                $dry = $keys_action === 'rewrap_dry';
                $svc = new \RivetMSP\Crypto\RewrapService($mysqli, (int) $session_user_id);
                $reports = $svc->run(null, $dry, 100, null, 20.0);   // 20 seconds a click; resumable, so click again until it reports 0
                $changed = 0; $bad = 0; $done = true;
                foreach ($reports as $rep) { $changed += $rep->rewrapped; $bad += $rep->failed + $rep->conflicts; $done = $done && $rep->completed; }
                \RivetMSP\Core\CoreBridge::audit(true)?->log('crypto.rewrap_requested', $session_user_id, 'key', 'rewrap', $dry ? 'dry_run' : 'run', "$session_name ran the encryption rewrap" . ($dry ? ' (dry run)' : ''), ['changed' => $changed, 'failed_or_conflicted' => $bad, 'completed' => $done]);
                logAction("Settings", "Edit", "$session_name ran the encryption rewrap" . ($dry ? ' (dry run)' : '') . ": $changed value(s) " . ($dry ? 'would change' : 'changed') . ", $bad failed or conflicted");
                flash_alert(($dry ? "Dry run: $changed value(s) would move to the active key." : "$changed value(s) moved to the active key.") . ($bad ? " $bad failed or conflicted: run again." : '') . ($done ? '' : ' Not finished: run it again.'), $bad ? 'warning' : 'success');
                break;

            default:
                flash_alert("Unknown key action", "error");
        }
    } catch (\Throwable $e) {
        flash_alert("Not done: " . $e->getMessage(), "error");
    }

    redirect();
}

if (isset($_POST['establish_canonical_vault_key'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Recover this admin's session master key (same recovery used by encryptUserSpecificKey()).
    $ciphertext = $_SESSION['user_encryption_session_ciphertext'] ?? '';
    $iv         = $_SESSION['user_encryption_session_iv'] ?? '';
    $sess_key   = $_COOKIE['user_encryption_session_key'] ?? '';
    $master_key = ($ciphertext && $iv && $sess_key)
        ? openssl_decrypt($ciphertext, 'aes-128-cbc', $sess_key, 0, $iv)
        : false;

    if (empty($master_key)) {
        flash_alert("Your vault must be unlocked (signed in with your password this session) to do this", "error");
        redirect();
    }

    setCanonicalVaultKey($mysqli, $master_key);

    logAction("Settings", "Edit", "$session_name established the canonical vault encryption key");

    flash_alert("Canonical vault encryption key established");

    redirect();

}

if (isset($_POST['edit_security_policy'])) {

    validateCSRFToken($_POST['csrf_token']);

    require_once __DIR__ . '/../../includes/security_policy.php';

    $before = secSettingsAll($mysqli, true);
    $new = [
        'mfa_policy'            => (string) ($_POST['mfa_policy'] ?? 'off'),
        'mfa_grace_days'        => intval($_POST['mfa_grace_days'] ?? 7),
        'remember_me_skips_mfa' => isset($_POST['remember_me_skips_mfa']) ? 1 : 0,
        'password_min_length'   => intval($_POST['password_min_length'] ?? 12),
        'password_hibp_check'   => isset($_POST['password_hibp_check']) ? 1 : 0,
        'session_idle_minutes'  => intval($_POST['session_idle_minutes'] ?? 480),
        'vault_stepup_minutes'  => intval($_POST['vault_stepup_minutes'] ?? 15),
        'vault_reveal_limit'    => intval($_POST['vault_reveal_limit'] ?? 30),
    ];
    $changes = [];
    foreach ($new as $k => $v) {
        $stored = secSettingNormalize($k, $v);
        if ($stored !== $before[$k]) {
            $changes[] = "$k: {$before[$k]} -> $stored";
        }
        secSettingSet($mysqli, $k, $v);
    }
    // The grace period for a newly required MFA policy starts when the policy is switched on (or widened).
    $rank = ['off' => 0, 'admins' => 1, 'all' => 2];
    $policy_after = secSettingNormalize('mfa_policy', $new['mfa_policy']);
    if (($rank[$policy_after] ?? 0) > ($rank[$before['mfa_policy']] ?? 0)) {
        secSettingSet($mysqli, 'mfa_policy_since', date('Y-m-d H:i:s'));
    }

    if ($changes) {
        logAction("Settings", "Edit", "$session_name changed the sign-in policy (" . implode('; ', $changes) . ")");
        secAudit('settings.security_policy_changed', $session_user_id, 'settings', 'security_policy', 'edit', "$session_name changed the sign-in policy", ['changes' => $changes]);
    }
    flash_alert('Sign-in policy saved');
    redirect();
}

if (isset($_POST['edit_security_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    $config_login_message = sanitizeInput($_POST['config_login_message']);
    $config_login_key_required = intval($_POST['config_login_key_required'] ?? 0);
    $config_login_key_secret = sanitizeInput($_POST['config_login_key_secret']);
    $config_login_remember_me_expire = intval($_POST['config_login_remember_me_expire']);
    $config_login_session_lifetime = max(60, min(129600, intval($_POST['config_login_session_lifetime'] ?? 10080)));
    $config_log_retention = intval($_POST['config_log_retention']);
    // A compliance preset (Settings > Compliance) is a minimum: a shorter retention is raised to it. 0 keeps everything.
    $security_retention_raised = false;
    $compliance_row = @mysqli_fetch_assoc(@mysqli_query($mysqli, "SELECT config_compliance_profile FROM settings WHERE company_id = 1"));
    if ($config_log_retention < 0) {
        $config_log_retention = 0;
    }
    if ($compliance_row && class_exists(\RivetCore\Compliance\RetentionPolicy::class)
        && \RivetCore\Compliance\RetentionPolicy::isBelowFloor((string) $compliance_row['config_compliance_profile'], $config_log_retention)) {
        $config_log_retention = \RivetCore\Compliance\RetentionPolicy::floorDays((string) $compliance_row['config_compliance_profile']);
        $security_retention_raised = true;
    }

    // Disallow turning on login key without a secret
    if (empty($config_login_key_secret)) {
        $config_login_key_required = 0;
    }

    // The login key secret is stored wrapped (encryptSetting); an empty one stays empty.
    require_once __DIR__ . '/../../includes/security_crypto.php';
    $config_login_key_secret_stored = mysqli_real_escape_string($mysqli, secWrapIfPlain(trim(strip_tags((string) ($_POST['config_login_key_secret'] ?? '')))));

    mysqli_query($mysqli,"UPDATE settings SET config_login_message = '$config_login_message', config_login_key_required = '$config_login_key_required', config_login_key_secret = '$config_login_key_secret_stored', config_login_remember_me_expire = $config_login_remember_me_expire, config_login_session_lifetime = $config_login_session_lifetime, config_log_retention = $config_log_retention WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited security settings");

    flash_alert($security_retention_raised ? "Security settings updated. Log retention was raised to " . $config_log_retention . " days, the minimum for your compliance preset." : "Security settings updated", $security_retention_raised ? "warning" : "success");

    redirect();

}
