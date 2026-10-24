<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../../includes/core_module_gates.php';

function itsmParseDatetimeLocal(?string $value): ?string {
    if (!$value) {
        return null;
    }
    $ts = strtotime($value);
    return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
}

if (isset($_POST['add_change'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $title = sanitizeInput($_POST['title']);
    $risk = in_array($_POST['risk'] ?? '', ['low', 'medium', 'high'], true) ? $_POST['risk'] : 'low';
    $reason = sanitizeInput($_POST['reason'] ?? '');
    $impact = sanitizeInput($_POST['impact'] ?? '');
    $implementation_plan = sanitizeInput($_POST['implementation_plan'] ?? '');
    $rollback_plan = sanitizeInput($_POST['rollback_plan'] ?? '');
    $scheduled_at = itsmParseDatetimeLocal($_POST['scheduled_at'] ?? null);
    // Client approval (GitHub roadmap #5): a RivetMSP-only layer on top of RivetCore's ChangeService, which has no
    // client at all - see the DB 2.6.110 migration comment for why. A change with client_id = 0 ("Internal, no
    // client") is never client-access-checked anywhere below, matching every other optional-client entity.
    $change_client_id = intval($_POST['client_id'] ?? 0);
    if ($change_client_id > 0) {
        enforceClientAccess($change_client_id);
    }
    $requires_client_approval = !empty($_POST['requires_client_approval']) && $change_client_id > 0 ? 1 : 0;

    $change_id = rivetItsmChanges()->create(
        $title,
        $reason !== '' ? $reason : null,
        $impact !== '' ? $impact : null,
        $risk,
        $implementation_plan !== '' ? $implementation_plan : null,
        $rollback_plan !== '' ? $rollback_plan : null,
        $scheduled_at,
        $session_user_id
    );

    $change_url_key = $requires_client_approval ? randomString(32) : null;
    $stmt = $mysqli->prepare('UPDATE changes SET client_id = ?, requires_client_approval = ?, url_key = ? WHERE change_id = ?');
    $stmt->bind_param('iisi', $change_client_id, $requires_client_approval, $change_url_key, $change_id);
    $stmt->execute();
    $stmt->close();

    logAction("Support", "Create", "$session_name created change \"$title\"", $change_client_id, $change_id);
    rivetAudit('change.created', $session_user_id, 'change', $change_id, 'created', "$session_name created change \"$title\"");

    $linked_problem_id = intval($_POST['linked_problem_id'] ?? 0);
    if ($linked_problem_id) {
        rivetItsmProblems()->linkChange($linked_problem_id, $change_id);
        logAction("Support", "Edit", "$session_name linked change #$change_id to problem #$linked_problem_id", 0, $linked_problem_id);
        flash_alert("Change <strong>" . nullable_htmlentities($title) . "</strong> created and linked");
        redirect("problem_details.php?id=$linked_problem_id");
    }

    flash_alert("Change <strong>" . nullable_htmlentities($title) . "</strong> created");
    redirect("change_details.php?id=$change_id");
}

if (isset($_POST['edit_change'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $change_id = intval($_POST['change_id']);
    $title = sanitizeInput($_POST['title']);
    $risk = in_array($_POST['risk'] ?? '', ['low', 'medium', 'high'], true) ? $_POST['risk'] : 'low';
    $reason = sanitizeInput($_POST['reason'] ?? '');
    $impact = sanitizeInput($_POST['impact'] ?? '');
    $implementation_plan = sanitizeInput($_POST['implementation_plan'] ?? '');
    $rollback_plan = sanitizeInput($_POST['rollback_plan'] ?? '');

    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_id, requires_client_approval, url_key FROM changes WHERE change_id = $change_id"));
    if (!$existing) {
        flash_alert('Change not found.', 'error');
        redirect();
    }
    $old_client_id = (int) $existing['client_id'];
    if ($old_client_id > 0) {
        enforceClientAccess($old_client_id);
    }
    $change_client_id = intval($_POST['client_id'] ?? 0);
    if ($change_client_id > 0) {
        enforceClientAccess($change_client_id);
    }
    $requires_client_approval = !empty($_POST['requires_client_approval']) && $change_client_id > 0 ? 1 : 0;
    // A link, once generated, is never rotated just because the checkbox is re-saved - a guest link already shared
    // with the client must keep working. Only the first time client approval is actually turned on is a key made.
    $change_url_key = $requires_client_approval && empty($existing['url_key']) ? randomString(32) : $existing['url_key'];

    $stmt = $mysqli->prepare(
        "UPDATE changes SET title = ?, risk = ?, reason = ?, impact = ?, implementation_plan = ?, rollback_plan = ?, client_id = ?, requires_client_approval = ?, url_key = ? WHERE change_id = ?"
    );
    $stmt->bind_param('ssssssiisi', $title, $risk, $reason, $impact, $implementation_plan, $rollback_plan, $change_client_id, $requires_client_approval, $change_url_key, $change_id);
    $stmt->execute();
    $stmt->close();

    logAction("Support", "Edit", "$session_name edited change \"$title\"", $change_client_id, $change_id);

    flash_alert("Change <strong>" . nullable_htmlentities($title) . "</strong> updated");
    redirect("change_details.php?id=$change_id");
}

if (isset($_POST['set_change_status'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $change_id = intval($_POST['change_id']);
    $status = sanitizeInput($_POST['status']);
    $scheduled_at = itsmParseDatetimeLocal($_POST['scheduled_at'] ?? null);

    $change_client_id = (int) getFieldById('changes', $change_id, 'client_id');
    if ($change_client_id > 0) {
        enforceClientAccess($change_client_id);
    }

    try {
        rivetItsmChanges()->setStatus($change_id, $status, $scheduled_at);
    } catch (\InvalidArgumentException $e) {
        flash_alert($e->getMessage(), 'error');
        redirect("change_details.php?id=$change_id");
    }

    logAction("Support", "Edit", "$session_name moved change #$change_id to $status", $change_client_id, $change_id);
    rivetAudit('change.status_changed', $session_user_id, 'change', $change_id, 'status_changed', "$session_name moved change #$change_id to $status", ['status' => $status]);

    flash_alert("Change status updated to <strong>" . nullable_htmlentities($status) . "</strong>");
    redirect("change_details.php?id=$change_id");
}

if (isset($_POST['reschedule_change'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $change_id = intval($_POST['change_id']);
    $scheduled_at = itsmParseDatetimeLocal($_POST['scheduled_at'] ?? null);

    if (!$scheduled_at) {
        flash_alert('Invalid date/time', 'error');
        redirect("change_details.php?id=$change_id");
    }

    $change_client_id = (int) getFieldById('changes', $change_id, 'client_id');
    if ($change_client_id > 0) {
        enforceClientAccess($change_client_id);
    }

    rivetItsmChanges()->reschedule($change_id, $scheduled_at);

    logAction("Support", "Edit", "$session_name rescheduled change #$change_id to $scheduled_at", $change_client_id, $change_id);

    flash_alert('Change rescheduled');
    redirect("change_details.php?id=$change_id");
}

if (isset($_POST['send_change_approval_request'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $change_id = intval($_POST['change_id']);
    $change = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT changes.*, clients.client_name FROM changes LEFT JOIN clients ON clients.client_id = changes.client_id WHERE changes.change_id = $change_id"));
    if (!$change) {
        flash_alert('Change not found.', 'error');
        redirect();
    }
    $change_client_id = (int) $change['client_id'];
    if ($change_client_id === 0) {
        flash_alert('This change has no client to send an approval request to.', 'error');
        redirect("change_details.php?id=$change_id");
    }
    enforceClientAccess($change_client_id);

    $url_key = $change['url_key'];
    if (!$url_key || !$change['requires_client_approval']) {
        $url_key = $url_key ?: randomString(32);
        $stmt = $mysqli->prepare('UPDATE changes SET requires_client_approval = 1, url_key = ? WHERE change_id = ?');
        $stmt->bind_param('si', $url_key, $change_id);
        $stmt->execute();
        $stmt->close();
    }

    $contact = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_name, contact_email FROM contacts WHERE contact_client_id = $change_client_id AND contact_primary = 1 LIMIT 1"));
    if (empty($contact['contact_email'])) {
        flash_alert('This client has no primary contact with an email address on file.', 'error');
        redirect("change_details.php?id=$change_id");
    }

    $title_h = nullable_htmlentities($change['title']);
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
    $approval_url = "https://$config_base_url/guest/guest_view_change.php?change_id=$change_id&url_key=" . rawurlencode((string) $url_key);
    $body = "Hello " . $h($contact['contact_name'] ?: $change['client_name']) . ",<br><br>"
        . "A change titled <strong>" . $h($change['title']) . "</strong> needs your approval before it can proceed.<br><br>"
        . "<a href=\"" . $h($approval_url) . "\">Review and approve or reject this change</a><br><br>"
        . "This link is unique to this change. Do not share it with others.<br><br>"
        . "Thank you,<br>" . $h($session_company_name);

    addToMailQueue([[
        'from'           => $config_ticket_from_email,
        'from_name'      => $config_ticket_from_name ?: $session_company_name,
        'recipient'      => $contact['contact_email'],
        'recipient_name' => $contact['contact_name'] ?: $change['client_name'],
        'subject'        => "Approval needed: $title_h",
        'body'           => $body,
        'email_override' => true,   // not a ticket - the per-ticket "Send email notifications" switch does not apply
    ]]);

    logAction("Support", "Edit", "$session_name sent a client approval request for change \"" . $change['title'] . "\"", $change_client_id, $change_id);
    rivetAudit('change.client_approval_requested', $session_user_id, 'change', $change_id, 'client_approval_requested', "$session_name sent a client approval request for change \"" . $change['title'] . "\"");

    flash_alert('Approval request sent to <strong>' . nullable_htmlentities($contact['contact_email']) . '</strong>');
    redirect("change_details.php?id=$change_id");
}
