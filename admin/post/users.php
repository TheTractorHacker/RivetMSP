<?php

/*
 * ITFlow - GET/POST request handler for user (agent) management
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../../includes/security_policy.php';
require_once __DIR__ . '/../../includes/security_sessions.php';

if (isset($_POST['update_user_skills'])) {

    validateCSRFToken($_POST['csrf_token']);

    $user_id = intval($_POST['user_id']);

    // Mirrors agent/post/ticket.php's update_ticket_tags (delete-all-then-reinsert); only tag_type = 7
    // ("Technician") rows can ever be linked here, same scoping agent/modals/ticket/ticket_tags.php
    // applies with tag_type = 6 ("Ticket").
    mysqli_query($mysqli, "DELETE FROM user_tags WHERE user_tag_user_id = $user_id");

    if (isset($_POST['tags'])) {
        foreach ($_POST['tags'] as $tag) {
            $tag_id = intval($tag);
            $valid_tag = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT tag_id FROM tags WHERE tag_id = $tag_id AND tag_type = 7 LIMIT 1"));
            if ($valid_tag) {
                mysqli_query($mysqli, "INSERT INTO user_tags SET user_tag_user_id = $user_id, user_tag_tag_id = $tag_id");
            }
        }
    }

    logAction("User", "Edit", "$session_name updated skill tags", 0, 0);
    flash_alert("Skills updated");
    redirect();

}

if (isset($_POST['add_user'])) {

    validateCSRFToken($_POST['csrf_token']);

    require_once 'user_model.php';

    // MSP-DS-121: creating another administrator needs the acting administrator's own password (a stolen session alone is not enough)
    $new_role_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT role_is_admin FROM user_roles WHERE role_id = " . intval($role)));
    if ($new_role_row && intval($new_role_row['role_is_admin']) === 1
        && !secReauthPassword($mysqli, (int) $session_user_id, (string) ($_POST['admin_current_password'] ?? ''))) {
        flash_alert('Your current password is required (and must be correct) to create an administrator.', 'error');
        redirect();
    }

    // Reject a duplicate e-mail (logins look users up by e-mail) before inserting
    if (mysqli_num_rows(mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_email = '$email' LIMIT 1")) > 0) {
        flash_alert("A user with the email <strong>$email</strong> already exists", 'error');
        redirect();
    }

    // Staff password policy: length, not the name / email, optional breach check
    $policy_error = secPasswordPolicyError(trim((string) ($_POST['password'] ?? '')), ['name' => trim((string) ($_POST['name'] ?? '')), 'email' => trim((string) ($_POST['email'] ?? '')), 'username' => trim((string) ($_POST['email'] ?? ''))], $mysqli);
    if ($policy_error !== null) {
        flash_alert($policy_error, 'error');
        redirect();
    }

    $password = secPasswordHash(trim($_POST['password']));
    $user_specific_encryption_ciphertext = encryptUserSpecificKey(trim($_POST['password']));

    mysqli_query($mysqli, "INSERT INTO users SET user_name = '$name', user_email = '$email', user_password = '$password', user_specific_encryption_ciphertext = '$user_specific_encryption_ciphertext', user_role_id = $role");

    $user_id = mysqli_insert_id($mysqli);

    // Add Client Access Permissions if set
    if (isset($_POST['clients'])) {
        foreach($_POST['clients'] as $client_id) {
            $client_id = intval($client_id);
            mysqli_query($mysqli,"INSERT INTO user_client_permissions SET user_id = $user_id, client_id = $client_id");
        }
    }

    if (!file_exists("../uploads/users/$user_id/")) {
        mkdir("../uploads/users/$user_id");
    }

    // Check for and process image/photo
    $extended_alert_description = '';
    if (isset($_FILES['file']['tmp_name'])) {
        if ($new_file_name = checkFileUpload($_FILES['file'], array('jpg', 'jpeg', 'gif', 'png', 'webp'))) {

            $file_tmp_path = $_FILES['file']['tmp_name'];

            // directory in which the uploaded file will be moved
            $upload_file_dir = "../uploads/users/$user_id/";
            $dest_path = $upload_file_dir . $new_file_name;
            move_uploaded_file($file_tmp_path, $dest_path);

            // Set Avatar
            mysqli_query($mysqli, "UPDATE users SET user_avatar = '$new_file_name' WHERE user_id = $user_id");
            $extended_alert_description = '. File successfully uploaded.';
        }
    }

    // Create Settings
    mysqli_query($mysqli, "INSERT INTO user_settings SET user_id = $user_id, user_config_force_mfa = $force_mfa");

    $sql = mysqli_query($mysqli,"SELECT * FROM companies WHERE company_id = 1");
    $row = mysqli_fetch_assoc($sql);
    $company_name = sanitizeInput($row['company_name']);

    // Sanitize Config vars from load_global_settings.php
    $config_mail_from_name = sanitizeInput($config_mail_from_name);
    $config_mail_from_email = sanitizeInput($config_mail_from_email);
    $config_ticket_from_email = sanitizeInput($config_ticket_from_email);
    $config_login_key_secret = mysqli_real_escape_string($mysqli, $config_login_key_secret);
    $config_base_url = sanitizeInput($config_base_url);

    // Send user e-mail, if specified
    if (isset($_POST['send_email']) && !empty($config_smtp_host) && filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $subject = "Your new $company_name RivetMSP account";
        $body = "Hello $name,<br><br>An RivetMSP account has been setup for you. Please contact your administrator for your initial login credentials, then change your password upon first login.<br><br>Username: $email <br>Login URL: https://$config_base_url/login.php?key=$config_login_key_secret<br><br>--<br>$company_name - Support<br>$config_ticket_from_email";

        $data = [
            [
                'from' => $config_mail_from_email,
                'from_name' => $config_mail_from_name,
                'recipient' => $email,
                'recipient_name' => $name,
                'subject' => $subject,
                'body' => $body
            ]
        ];
        $mail = addToMailQueue($data);

        if ($mail !== true) {
            mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Mail', notification = 'Failed to send email to $email'");
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Mail', log_action = 'Error', log_description = 'Failed to send email to $email regarding $subject. $mail', log_ip = '$session_ip', log_user_agent = '$session_user_agent',  log_user_id = $session_user_id, log_entity_id = $user_id");
        }

    }

    logAction("User", "Create", "$session_name created user $name", 0, $user_id);

    flash_alert("User <strong>$name</strong> created" . $extended_alert_description);

    redirect();

}

if (isset($_POST['edit_user'])) {

    validateCSRFToken($_POST['csrf_token']);

    require_once 'user_model.php';

    $user_id = intval($_POST['user_id']);
    $new_password = trim($_POST['new_password']);

    // MSP-DS-121 (review R22/R23): the acting administrator's current password is needed for every change that could hand an attacker with a
    // stolen session a durable administrator account:
    //  - changing your own sign-in email, role, password, two-factor or Force-MFA setting (like the profile page),
    //  - promoting ANY user into an administrator role they did not already have,
    //  - changing the email or password of an account that is already an administrator (that is an account takeover).
    $target_now = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT u.user_email, u.user_role_id, r.role_is_admin AS cur_is_admin, COALESCE(s.user_config_force_mfa, 0) AS cur_force_mfa
        FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id LEFT JOIN user_settings s ON s.user_id = u.user_id WHERE u.user_id = $user_id"));
    $new_role_is_admin = intval(getFieldById('user_roles', intval($role), 'role_is_admin')) === 1;
    $role_changed = $target_now && intval($target_now['user_role_id']) !== intval($role);
    $email_differs = $target_now && strcasecmp((string) $target_now['user_email'], trim((string) ($_POST['email'] ?? ''))) !== 0;
    // MSP-DS-121 (complete): the same applies to ANY target, not only administrators: a new password or sign-in email on a technician's account, or a
    // role change, is an account takeover / privilege change a stolen admin session must not be able to make without the admin's password.
    $reauth_needed = ($new_role_is_admin && $role_changed)
        || $role_changed
        || ($target_now && ($new_password !== '' || $email_differs))
        // review RR12: removing ANYONE's second factor (TOTP seed, recovery codes, API tokens) or switching their Force MFA off is as sensitive as
        // the separate disable_2fa form, which already asks for the password
        || (($_POST['2fa'] ?? '') === 'disable')
        || ($target_now && intval($target_now['cur_force_mfa']) === 1 && !$force_mfa);
    if ($user_id === intval($session_user_id)) {
        $reauth_needed = $reauth_needed
            || $new_password !== ''
            || (($_POST['2fa'] ?? '') === 'disable')
            || $email_differs || $role_changed
            || ($target_now && intval($target_now['cur_force_mfa']) !== ($force_mfa ? 1 : 0));
    }
    if ($reauth_needed && !secReauthPassword($mysqli, (int) $session_user_id, (string) ($_POST['admin_current_password'] ?? ''))) {
        flash_alert('Your current password is required (and must be correct) to change a user\'s role, sign-in email or password, to disable anyone\'s 2FA or Force MFA, or to change your own two-factor or Force MFA.', 'error');
        redirect();
    }

    if ($new_password !== '') {
        $policy_error = secPasswordPolicyError($new_password, ['name' => trim((string) ($_POST['name'] ?? '')), 'email' => trim((string) ($_POST['email'] ?? '')), 'username' => trim((string) ($_POST['email'] ?? ''))], $mysqli);
        if ($policy_error !== null) {
            flash_alert($policy_error, 'error');
            redirect();
        }
    }

    // Before the edit: the client access this user has now, for the audit trail's access change event below.
    $audit_old_clients = [];
    $audit_res = mysqli_query($mysqli, "SELECT client_id FROM user_client_permissions WHERE user_id = $user_id ORDER BY client_id");
    while ($audit_res && ($audit_row = mysqli_fetch_row($audit_res))) { $audit_old_clients[] = intval($audit_row[0]); }

    // Reject an e-mail that belongs to a different user
    if (mysqli_num_rows(mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_email = '$email' AND user_id != $user_id LIMIT 1")) > 0) {
        flash_alert("A user with the email <strong>$email</strong> already exists", 'error');
        redirect();
    }

    // Update Client Access
    // MSP-DS-5: the edit form only lists clients that are not archived, so only those rows can be changed from it. A row for an archived or
    // deleted client is kept: dropping it could leave the user with no rows at all, which means "every client".
    mysqli_query($mysqli,"DELETE FROM user_client_permissions WHERE user_id = $user_id AND client_id IN (SELECT client_id FROM clients WHERE client_archived_at IS NULL)");
    if (isset($_POST['clients'])) {
        foreach($_POST['clients'] as $client_id) {
            $client_id = intval($client_id);
            mysqli_query($mysqli,"INSERT INTO user_client_permissions SET user_id = $user_id, client_id = $client_id");
        }
    }

    // Get current Avatar
    $sql = mysqli_query($mysqli, "SELECT user_avatar, user_role_id FROM users WHERE user_id = $user_id");
    $row = mysqli_fetch_assoc($sql);
    $existing_file_name = sanitizeInput($row['user_avatar']);
    $previous_role_id = intval($row['user_role_id']);

    $extended_log_description = '';
    if (!empty($_POST['2fa'])) {
        $two_fa = $_POST['2fa'];
    }

    if (!file_exists("../uploads/users/$user_id/")) {
        mkdir("../uploads/users/$user_id");
    }

    // Check for and process image/photo
    $extended_alert_description = '';
    if (isset($_FILES['file']['tmp_name'])) {
        if ($new_file_name = checkFileUpload($_FILES['file'], array('jpg', 'jpeg', 'gif', 'png', 'webp'))) {

            $file_tmp_path = $_FILES['file']['tmp_name'];

            // directory in which the uploaded file will be moved
            $upload_file_dir = "../uploads/users/$user_id/";
            $dest_path = $upload_file_dir . $new_file_name;
            move_uploaded_file($file_tmp_path, $dest_path);

            // Delete old file
            unlink("../uploads/users/$user_id/$existing_file_name");

            // Set Avatar
            mysqli_query($mysqli, "UPDATE users SET user_avatar = '$new_file_name' WHERE user_id = $user_id");
            $extended_alert_description = '. File successfully uploaded.';

        }
    }

    mysqli_query($mysqli, "UPDATE users SET user_name = '$name', user_email = '$email', user_role_id = $role WHERE user_id = $user_id");

    // Existing API tokens carry the old rights: any role change revokes them (nightly MSP-4)
    if ($previous_role_id !== intval($role)) {
        mysqli_query($mysqli, "DELETE FROM api_tokens WHERE token_user_id = $user_id");
    }

    $new_password_was_set = !empty($new_password);
    if (!empty($new_password)) {
        $new_password = secPasswordHash($new_password);
        $user_specific_encryption_ciphertext = encryptUserSpecificKey(trim($_POST['new_password']), $user_id);
        mysqli_query($mysqli, "UPDATE users SET user_password = '$new_password', user_specific_encryption_ciphertext = '$user_specific_encryption_ciphertext' WHERE user_id = $user_id");
        mysqli_query($mysqli, "DELETE FROM api_tokens WHERE token_user_id = $user_id");
        // An administrator-set password signs that user out everywhere and ends their remember-me cookies
        // (the administrator's own session is kept when they changed their own password here).
        secSessionsOnPasswordChange($mysqli, $user_id, $user_id === intval($session_user_id) ? intval($_SESSION['sec_row'] ?? 0) : null);
        //Extended Logging
        $extended_log_description .= ", password changed";
    }

    if (!empty($two_fa) && $two_fa == 'disable') {
        mysqli_query($mysqli, "UPDATE users SET user_token = '' WHERE user_id = '$user_id'");
        // A 2FA reset invalidates API tokens minted under the old second factor (nightly MSP-4)
        mysqli_query($mysqli, "DELETE FROM api_tokens WHERE token_user_id = $user_id");
        secRecoveryCodesDelete($mysqli, intval($user_id));
        mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'User', log_action = 'Modify', log_description = '$session_name disabled 2FA for $name', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
    }

    //Update User Settings
    mysqli_query($mysqli, "UPDATE user_settings SET user_config_force_mfa = $force_mfa WHERE user_id = $user_id");

    logAction("User", "Edit", "$session_name edited user $name", 0, $user_id);

    // Role and client-access changes get their own audit events (who, from what, to what). Recorded while audit recording is on.
    try {
        require_once __DIR__ . '/../../includes/event_bus.php';
        if ($previous_role_id !== intval($role)) {
            rivetAudit('user.role_changed', intval($session_user_id) ?: null, 'user', $user_id, 'update', "Role of $name changed",
                ['user_id' => $user_id, 'role_before' => $previous_role_id, 'role_after' => intval($role), 'role_before_name' => getFieldById('user_roles', $previous_role_id, 'role_name', 'raw'), 'role_after_name' => getFieldById('user_roles', intval($role), 'role_name', 'raw')]);
        }
        $audit_new_clients = array_values(array_unique(array_map('intval', $_POST['clients'] ?? [])));
        sort($audit_new_clients);
        if ($audit_old_clients !== $audit_new_clients) {
            rivetAudit('user.client_access_changed', intval($session_user_id) ?: null, 'user', $user_id, 'update', "Client access of $name changed",
                ['user_id' => $user_id, 'before' => $audit_old_clients, 'after' => $audit_new_clients, 'note' => 'empty = every client']);
        }
    } catch (\Throwable $e) {
        // auditing never breaks the action
    }

    // MSP-DS-121: the account's owner is told, at the address that was on file before this change, when an administrator changed their sign-in email, password, role or two-factor.
    $account_changes = [];
    if ($email_differs) { $account_changes[] = 'Your sign-in email was changed.'; }
    if ($new_password_was_set) { $account_changes[] = 'Your password was changed and all your sessions were signed out.'; }
    if ($role_changed) { $account_changes[] = 'Your role was changed.'; }
    if (($_POST['2fa'] ?? '') === 'disable') { $account_changes[] = 'Your two-factor authentication was removed.'; }
    if ($target_now && $user_id !== intval($session_user_id)) {
        secNotifyAccountChange((string) $target_now['user_email'], (string) $name, $account_changes, (string) $session_name);
    }

    flash_alert("User <strong>$name</strong> updated" . $extended_alert_description);

    redirect();

}

if (isset($_GET['activate_user'])) {

    validateCSRFToken($_GET['csrf_token']);

    $user_id = intval($_GET['activate_user']);

    $user_name = sanitizeInput(getFieldById('users', $user_id, 'user_name'));

    mysqli_query($mysqli, "UPDATE users SET user_status = 1 WHERE user_id = $user_id");

    logAction("User", "Activate", "$session_name activated user $user_name", 0, $user_id);

    flash_alert("User <strong>$user_name</strong> activated");

    redirect();

}

if (isset($_GET['disable_user'])) {

    validateCSRFToken($_GET['csrf_token']);

    $user_id = intval($_GET['disable_user']);

    $user_name = sanitizeInput(getFieldById('users', $user_id, 'user_name'));

    mysqli_query($mysqli, "UPDATE users SET user_status = 0 WHERE user_id = $user_id");

    // Tokens must not come back to life when the account is re-activated (nightly MSP-4)
    mysqli_query($mysqli, "DELETE FROM api_tokens WHERE token_user_id = $user_id");

    // MSP-DS-42: remember-me cookies and live sessions must not come back when the account is re-activated
    secSessionsOnPasswordChange($mysqli, $user_id, null);

    // Un-assign tickets
    mysqli_query($mysqli, "UPDATE tickets SET ticket_assigned_to = 0 WHERE ticket_assigned_to = $user_id AND ticket_closed_at IS NULL");
    mysqli_query($mysqli, "UPDATE recurring_tickets SET recurring_ticket_assigned_to = 0 WHERE recurring_ticket_assigned_to = $user_id");

    logAction("User", "Disable", "$session_name disabled user $user_name", 0, $user_id);

    flash_alert("User <strong>$user_name</strong> disabled", 'error');

    redirect();

}

// MSP-DS-121 (review R23): removing a second factor or a passkey is a POST with CSRF and the acting administrator's password (it used to be a GET link).
if (isset($_POST['disable_2fa'])) {
    validateCSRFToken($_POST['csrf_token']);
    $user_id   = intval($_POST['disable_2fa']);
    if (!secReauthPassword($mysqli, (int) $session_user_id, (string) ($_POST['admin_current_password'] ?? ''))) {
        flash_alert('Your current password is required (and must be correct) to disable two-factor authentication.', 'error');
        redirect();
    }
    $user_name = sanitizeInput(getFieldById('users', $user_id, 'user_name'));
    mysqli_query($mysqli, "UPDATE users SET user_token = NULL WHERE user_id = $user_id");
    mysqli_query($mysqli, "DELETE FROM api_tokens WHERE token_user_id = $user_id");
    secRecoveryCodesDelete($mysqli, $user_id);
    logAction("User", "Edit", "$session_name disabled 2FA for $user_name", 0, $user_id);
    flash_alert("2FA disabled for <strong>$user_name</strong>.", 'warning');
    redirect();
}

if (isset($_POST['delete_passkey'])) {
    validateCSRFToken($_POST['csrf_token']);
    $passkey_id = intval($_POST['delete_passkey']);
    $user_id    = intval($_POST['user_id']);
    if (!secReauthPassword($mysqli, (int) $session_user_id, (string) ($_POST['admin_current_password'] ?? ''))) {
        flash_alert('Your current password is required (and must be correct) to delete a passkey.', 'error');
        redirect();
    }
    $user_name  = sanitizeInput(getFieldById('users', $user_id, 'user_name'));
    mysqli_query($mysqli, "DELETE FROM user_passkeys WHERE passkey_id = $passkey_id AND passkey_user_id = $user_id");
    logAction("User", "Edit", "$session_name deleted a passkey for $user_name", 0, $user_id);
    flash_alert("Passkey deleted for <strong>$user_name</strong>.", 'warning');
    redirect();
}

if (isset($_GET['revoke_remember_me'])) {

    validateCSRFToken($_GET['csrf_token']);

    $user_id = intval($_GET['revoke_remember_me']);

    $user_name = sanitizeInput(getFieldById('users', $user_id, 'user_name'));

    mysqli_query($mysqli, "DELETE FROM remember_tokens WHERE remember_token_user_id = $user_id");

    logAction("User", "Edit", "$session_name revoked all remember me tokens for user $user_name", 0, $user_id);

    flash_alert("User <strong>$user_name</strong> remember me tokens revoked", 'error');

    redirect();

}

if (isset($_POST['archive_user'])) {

    validateCSRFToken($_POST['csrf_token']);

    $user_id = intval($_POST['user_id']);
    $ticket_assign = intval($_POST['ticket_assign']);
    $password = password_hash(randomString(), PASSWORD_DEFAULT);

    $user_name = sanitizeInput(getFieldById('users', $user_id, 'user_name'));

    // Un-assign / Re-assign tickets
    mysqli_query($mysqli, "UPDATE tickets SET ticket_assigned_to = $ticket_assign WHERE ticket_assigned_to = $user_id AND ticket_closed_at IS NULL AND ticket_resolved_at IS NULL");
    mysqli_query($mysqli, "UPDATE recurring_tickets SET recurring_ticket_assigned_to = $ticket_assign WHERE recurring_ticket_assigned_to = $user_id");

    // Archive user query
    mysqli_query($mysqli, "UPDATE users SET user_name = '$user_name (archived)', user_password = '$password', user_status = 0, user_specific_encryption_ciphertext = '', user_archived_at = NOW() WHERE user_id = $user_id");

    // Revoke every API/mobile session for the archived user
    mysqli_query($mysqli, "DELETE FROM api_tokens WHERE token_user_id = $user_id");

    // MSP-DS-42: remember-me cookies and live sessions of the archived user stop working for good
    secSessionsOnPasswordChange($mysqli, $user_id, null);

    logAction("User", "Archive", "$session_name archived user $user_name", 0, $user_id);

    flash_alert("User <strong>$user_name</strong> archived", 'error');

    redirect();

}

if (isset($_POST['restore_user'])) {

    validateCSRFToken($_POST['csrf_token']);

    $user_id = intval($_POST['user_id']);
    $new_password = trim($_POST['new_password']);
    $role = intval($_POST['role']);

    $user_name = getFieldById('users', $user_id, 'user_name');
    $user_name = sanitizeInput(str_replace(" (archived)", "", $user_name)); //Removed (archived) from user_name

    // MSP-DS-121 (review R22): restoring an account into an administrator role needs the acting administrator's password
    if ((intval(getFieldById('user_roles', $role, 'role_is_admin')) === 1 || $new_password !== '')
        && !secReauthPassword($mysqli, (int) $session_user_id, (string) ($_POST['admin_current_password'] ?? ''))) {
        flash_alert('Your current password is required (and must be correct) to restore a user as an administrator or with a new password.', 'error');
        redirect();
    }

    // MSP-DS-42: the password policy is checked before the account is reactivated
    if (!empty($new_password)) {
        $policy_error = secPasswordPolicyError($new_password, ['name' => $user_name, 'username' => $user_name], $mysqli);
        if ($policy_error !== null) {
            flash_alert($policy_error, 'error');
            redirect();
        }
    }

    // Restore user query
    mysqli_query($mysqli, "UPDATE users SET user_name = '$user_name', user_status = 1, user_role_id = $role, user_archived_at = NULL WHERE user_id = $user_id");

    // Cookies issued before the archive/disable never come back to life
    secSessionsOnPasswordChange($mysqli, $user_id, null);

    if (!empty($new_password)) {
        $new_password = secPasswordHash($new_password);
        $user_specific_encryption_ciphertext = encryptUserSpecificKey(trim($_POST['new_password']), $user_id);
        mysqli_query($mysqli, "UPDATE users SET user_password = '$new_password', user_specific_encryption_ciphertext = '$user_specific_encryption_ciphertext' WHERE user_id = $user_id");
        mysqli_query($mysqli, "DELETE FROM api_tokens WHERE token_user_id = $user_id");
        secSessionsOnPasswordChange($mysqli, $user_id, null);
        //Extended Logging
        $extended_log_description .= ", password changed";
    }

    logAction("User", "Restored", "$session_name restored user $user_name", 0, $user_id);

    flash_alert("User <strong>$user_name</strong> restored");

    redirect();

}

if (isset($_POST['export_users_csv'])) {

    validateCSRFToken($_POST['csrf_token']);

    //get records from database
    $sql = mysqli_query($mysqli, "SELECT * FROM users LEFT JOIN user_roles ON user_role_id = role_id ORDER BY user_name ASC");

    $count = mysqli_num_rows($sql);

    if ($count > 0) {
        $delimiter = ",";
        $enclosure = '"';
        $escape    = '';   // empty: plain RFC 4180 quoting; a backslash escape lets  x\",=2+5  split into a formula cell (MSP-DS-99, review R21)
        $filename = "Users-" . date('Y-m-d') . ".csv";

        //create a file pointer
        $f = fopen('php://memory', 'w');

        //set column headers
        $fields = array('Name', 'Email', 'Role', 'Status', 'Creation Date');
        fputcsv($f, $fields, $delimiter, $enclosure, $escape);

        //output each row of the data, format line as csv and write to file pointer
        while($row = $sql->fetch_assoc()) {

            $user_status = intval($row['user_status']);
            if ($user_status == 2) {
                $user_status_display = "Invited";
            } elseif ($user_status == 1) {
                $user_status_display = "Active";
            } else{
                $user_status_display = "Disabled";
            }

            $lineData = array($row['user_name'], $row['user_email'], $row['role_name'], $user_status_display, $row['user_created_at']);
            fputcsv($f, csvSafeRow($lineData), $delimiter, $enclosure, $escape);
        }

        //move back to beginning of file
        fseek($f, 0);

        //set headers to download file rather than displayed
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '";');

        //output all remaining data on a file pointer
        fpassthru($f);

        // Logging
        logAction("User", "Export", "$session_name exported $count user(s) to a CSV file");
    }
    exit;

}

if (isset($_POST['ir_reset_user_password'])) {

    // Incident response: allow mass reset of agent passwords

    validateCSRFToken($_POST['csrf_token']);

    // Confirm logged-in user password, for security
    $admin_password = (string) ($_POST['admin_password'] ?? '');

    // MSP-DS-116: counted and limited like every other password re-check
    if (!secReauthPassword($mysqli, (int) $session_user_id, $admin_password)) {
        flash_alert("Incorrect password.", 'error');
        redirect();
    }

    // Get agents, other than the current user. MSP-DS-122: client-portal logins (user_type 2) are not agents and are left alone.
    $sql_users = mysqli_query($mysqli, "SELECT * FROM users WHERE (user_archived_at IS NULL AND user_type = 1 AND user_id != $session_user_id)");

    // Reset passwords
    while ($row = mysqli_fetch_assoc($sql_users)) {
        $user_id = intval($row['user_id']);
        $user_email = sanitizeInput($row['user_email']);
        $new_password = randomString();
        $user_specific_encryption_ciphertext = encryptUserSpecificKey(trim($new_password), $user_id);

        echo $user_email . " -- " . $new_password; // Show
        $new_password = secPasswordHash($new_password);

        mysqli_query($mysqli, "UPDATE users SET user_password = '$new_password', user_specific_encryption_ciphertext = '$user_specific_encryption_ciphertext' WHERE user_id = $user_id");
        mysqli_query($mysqli, "DELETE FROM api_tokens WHERE token_user_id = $user_id");
        mysqli_query($mysqli, "DELETE FROM user_passkeys WHERE passkey_user_id = $user_id");   // MSP-DS-140: a passkey enrolled by an intruder must not survive the reset
        secSessionsOnPasswordChange($mysqli, $user_id, null);

        echo "<br><br>";
    }

    logAction("User", "Edit", "$session_name reset ALL user passwords");

    exit; // Stay on the plain text password page

}
