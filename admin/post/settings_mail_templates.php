<?php
/*
 * Admin > Settings > Email templates (admin/settings_mail_templates.php). Loaded by admin/post.php only for an admin session
 * (its $session_is_admin check), from that page's Referer.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['save_mail_template'])) {
    validateCSRFToken($_POST['csrf_token'] ?? null);

    $mt_event = (string) ($_POST['event'] ?? '');
    $mt_defaults = mailTemplateDefaults();
    if (!isset($mt_defaults[$mt_event])) {
        flash_alert("Unknown email template.", "error");
        redirect();
    }
    $mt_subject = trim(is_string($_POST['subject'] ?? null) ? $_POST['subject'] : '');
    $mt_body = is_string($_POST['body'] ?? null) ? $_POST['body'] : '';
    $mt_enabled = isset($_POST['enabled']) ? 1 : 0;
    if ($mt_subject === '' || trim(mailTemplateStripBlocksComment($mt_body)) === '') {
        flash_alert("Subject and body cannot be empty.", "error");
        redirect();
    }
    if (mb_strlen($mt_subject) > MAIL_TEMPLATE_SUBJECT_MAX || strlen($mt_body) > MAIL_TEMPLATE_BODY_MAX) {
        flash_alert("That subject or body is too long.", "error");
        redirect();
    }
    // The stored HTML is always this server's compile of the blocks the builder sent (not the browser's copy of it).
    $mt_body = mailTemplateNormalizeBody($mt_body);

    $mt_event_esc = mysqli_real_escape_string($mysqli, $mt_event);
    $mt_subject_esc = mysqli_real_escape_string($mysqli, $mt_subject);
    $mt_body_esc = mysqli_real_escape_string($mysqli, $mt_body);
    $mt_user = max(1, (int) $session_user_id);   // never 0: 0 means "not customized" (mailTemplateSyncDefaults() may overwrite it)
    mysqli_query($mysqli, "INSERT INTO mail_templates (mail_template_event, mail_template_subject, mail_template_body, mail_template_enabled, mail_template_updated_at, mail_template_updated_by)
        VALUES ('$mt_event_esc', '$mt_subject_esc', '$mt_body_esc', $mt_enabled, NOW(), $mt_user)
        ON DUPLICATE KEY UPDATE mail_template_subject = '$mt_subject_esc', mail_template_body = '$mt_body_esc', mail_template_enabled = $mt_enabled, mail_template_updated_at = NOW(), mail_template_updated_by = $mt_user");

    logAction("Settings", "Edit", "$session_name edited the \"$mt_event_esc\" email template" . ($mt_enabled ? '' : ' and switched it off'));

    // The page already asked before saving; say it once more if the saved email still cannot do its job (nothing is blocked).
    $mt_warnings = mailTemplateSaveWarnings($mt_event, $mt_body);
    if ($mt_warnings) {
        flash_alert("Email template saved, but " . implode('; and ', $mt_warnings) . ".", "warning");
    } else {
        flash_alert("Email template saved.");
    }
    redirect();
}

if (isset($_POST['reset_mail_template'])) {
    validateCSRFToken($_POST['csrf_token'] ?? null);

    $mt_event = (string) ($_POST['event'] ?? '');
    if (!mailTemplateResetToDefault($mysqli, $mt_event)) {
        flash_alert("Unknown email template.", "error");
        redirect();
    }

    logAction("Settings", "Edit", "$session_name reset the \"" . mysqli_real_escape_string($mysqli, $mt_event) . "\" email template to its default text");
    flash_alert("Email template reset to the default text.");
    redirect();
}

if (isset($_POST['save_mail_template_header_footer'])) {
    validateCSRFToken($_POST['csrf_token'] ?? null);

    $mt_header = is_string($_POST['header_html'] ?? null) ? trim($_POST['header_html']) : '';
    $mt_footer = is_string($_POST['footer_html'] ?? null) ? trim($_POST['footer_html']) : '';
    if (strlen($mt_header) > MAIL_TEMPLATE_BODY_MAX || strlen($mt_footer) > MAIL_TEMPLATE_BODY_MAX) {
        flash_alert("That header or footer is too long.", "error");
        redirect();
    }
    $mt_header_sql = $mt_header === '' ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $mt_header) . "'";
    $mt_footer_sql = $mt_footer === '' ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $mt_footer) . "'";
    mysqli_query($mysqli, "UPDATE settings SET config_mail_template_header_html = $mt_header_sql, config_mail_template_footer_html = $mt_footer_sql WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited the shared email header/footer");
    flash_alert("Header and footer saved.");
    redirect();
}

// AJAX: the live preview, or (send_test=1) one real copy queued to the admin's own address through the normal mail queue. Renders the
// UNSAVED draft the admin is looking at, with sample data, through mailTemplateRenderWith() - the very function a real send ends in.
if (isset($_POST['preview_mail_template'])) {
    validateCSRFToken($_POST['csrf_token'] ?? null);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    $mt_event = (string) ($_POST['event'] ?? '');
    $mt_defaults = mailTemplateDefaults();
    if (!isset($mt_defaults[$mt_event])) {
        echo json_encode(['ok' => false, 'error' => 'Unknown email template.']);
        exit;
    }
    $mt_subject = trim(is_string($_POST['subject'] ?? null) ? $_POST['subject'] : '');
    $mt_body = is_string($_POST['body'] ?? null) ? $_POST['body'] : '';
    if ($mt_subject === '') { $mt_subject = $mt_defaults[$mt_event]['subject']; }
    if (trim(mailTemplateStripBlocksComment($mt_body)) === '') { $mt_body = $mt_defaults[$mt_event]['body']; }
    if (mb_strlen($mt_subject) > MAIL_TEMPLATE_SUBJECT_MAX || strlen($mt_body) > MAIL_TEMPLATE_BODY_MAX) {
        echo json_encode(['ok' => false, 'error' => 'That subject or body is too long.']);
        exit;
    }

    $mt_branding = mailTemplateBranding($mysqli);
    $mt_rendered = mailTemplateRenderWith($mysqli, $mt_subject, mailTemplateNormalizeBody($mt_body), mailTemplateSampleVars($mt_event, $mt_branding['company_name']));

    if (!empty($_POST['send_test'])) {
        $mt_me = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_email, user_name FROM users WHERE user_id = " . (int) $session_user_id));
        if (!$mt_me || !filter_var($mt_me['user_email'], FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'error' => 'Your own account has no valid email address on file.']);
            exit;
        }
        $mt_from = resolveTicketFromIdentity(0);
        // A test copy belongs to no ticket. addToMailQueue() would otherwise read the sample "[TCK-1042]" in the subject as a real ticket's
        // token (its fallback when no ticket_id is given), stamp that ticket on the queue row - so the ticket's email switch could drop the
        // test, and a reply to it would thread into that customer's ticket. Round brackets are never read as a ticket token.
        $mt_test_subject = '[TEST] ' . str_replace(['[', ']'], ['(', ')'], $mt_rendered['subject']);
        addToMailQueue([[
            'from' => $mt_from['email'] ?: $config_ticket_from_email,
            'from_name' => $mt_from['name'] ?: $config_ticket_from_name,
            'recipient' => $mt_me['user_email'],
            'recipient_name' => $mt_me['user_name'],
            'subject' => $mt_test_subject,
            'body' => '<div style="background:#fff3cd;border:1px solid #ffecb5;padding:.75rem;margin-bottom:1rem;border-radius:.375rem;">This is a test send of the "' . nullable_htmlentities(mailTemplateEvents()[$mt_event]) . '" email template, with sample data, not a real ticket.</div>' . $mt_rendered['body'],
        ]]);
        logAction("Settings", "View", "$session_name sent a test copy of the \"" . mysqli_real_escape_string($mysqli, $mt_event) . "\" email template to themselves");
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => true, 'subject' => $mt_rendered['subject'], 'body' => $mt_rendered['body']]);
    exit;
}
