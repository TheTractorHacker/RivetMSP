<?php

if (isset($_POST['add_contract'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $client_id = intval($_POST['contract_client_id']);
    $name = sanitizeInput($_POST['contract_name']);
    $type = sanitizeInput($_POST['contract_type']);
    $status = sanitizeInput($_POST['contract_status']);
    $value = is_numeric($_POST['contract_value'] ?? '') ? floatval($_POST['contract_value']) : 'NULL';
    $freq = sanitizeInput($_POST['contract_renewal_frequency'] ?? '');
    $start = sanitizeInput($_POST['contract_start_date']);
    $end = sanitizeInput($_POST['contract_end_date']);
    $renewal = sanitizeInput($_POST['contract_renewal_date']);
    $notes = sanitizeInput($_POST['contract_details']);
    $sla_lr = is_numeric($_POST['sla_low_response'] ?? '') ? intval($_POST['sla_low_response']) : 'NULL';
    $sla_lres = is_numeric($_POST['sla_low_resolution'] ?? '') ? intval($_POST['sla_low_resolution']) : 'NULL';
    $sla_mr = is_numeric($_POST['sla_medium_response'] ?? '') ? intval($_POST['sla_medium_response']) : 'NULL';
    $sla_mres = is_numeric($_POST['sla_medium_resolution'] ?? '') ? intval($_POST['sla_medium_resolution']) : 'NULL';
    $sla_hr = is_numeric($_POST['sla_high_response'] ?? '') ? intval($_POST['sla_high_response']) : 'NULL';
    $sla_hres = is_numeric($_POST['sla_high_resolution'] ?? '') ? intval($_POST['sla_high_resolution']) : 'NULL';
    $hours_remote = is_numeric($_POST['support_hours_included_remote'] ?? '') ? floatval($_POST['support_hours_included_remote']) : 'NULL';
    $hours_onsite = is_numeric($_POST['support_hours_included_onsite'] ?? '') ? floatval($_POST['support_hours_included_onsite']) : 'NULL';

    enforceClientAccess();

    $start_sql = $start ? "'$start'" : 'NULL';
    $end_sql = $end ? "'$end'" : 'NULL';
    $renewal_sql = $renewal ? "'$renewal'" : 'NULL';
    $value_sql = is_numeric($value) ? $value : 'NULL';
    $freq_sql = $freq ? "'$freq'" : 'NULL';

    mysqli_query($mysqli, "INSERT INTO contracts SET contract_client_id = $client_id, contract_name = '$name', contract_type = '$type', contract_status = '$status', contract_value = $value_sql, contract_renewal_frequency = $freq_sql, contract_start_date = $start_sql, contract_end_date = $end_sql, contract_renewal_date = $renewal_sql, contract_details = '$notes', contract_sla_low_response_time = $sla_lr, contract_sla_low_resolution_time = $sla_lres, contract_sla_medium_response_time = $sla_mr, contract_sla_medium_resolution_time = $sla_mres, contract_sla_high_response_time = $sla_hr, contract_sla_high_resolution_time = $sla_hres, contract_support_hours_included_remote = $hours_remote, contract_support_hours_included_onsite = $hours_onsite, contract_created_by = $session_user_id");

    logAction("Contract", "Add", "Added contract $name", $client_id);
    flash_alert("Contract <strong>$name</strong> created.");
    redirect();
}

/*
 * roadmap #24: pause every recurring invoice linked to a contract (recurring_invoice_contract_id) that is
 * still active, so nothing keeps billing against a contract that has ended. Logged the same way an admin
 * pausing it by hand would be (a history row + logAction), so there is a visible trail of WHY it stopped.
 */
function pauseRecurringInvoicesForContract(\mysqli $mysqli, int $contractId, string $reason): void
{
    $sql = mysqli_query($mysqli, "SELECT recurring_invoice_id, recurring_invoice_prefix, recurring_invoice_number, recurring_invoice_scope, recurring_invoice_client_id FROM recurring_invoices WHERE recurring_invoice_contract_id = $contractId AND recurring_invoice_status = 1 AND recurring_invoice_archived_at IS NULL");
    while ($row = mysqli_fetch_assoc($sql)) {
        $id = (int) $row['recurring_invoice_id'];
        $label = sanitizeInput($row['recurring_invoice_prefix'] . $row['recurring_invoice_number'] . ' - ' . $row['recurring_invoice_scope']);
        $clientId = (int) $row['recurring_invoice_client_id'];
        mysqli_query($mysqli, "UPDATE recurring_invoices SET recurring_invoice_status = 0 WHERE recurring_invoice_id = $id");
        // history_description is varchar(200) - truncated defensively since $reason can carry a contract name of arbitrary length.
        $description = mb_substr("Recurring Invoice paused automatically - $reason", 0, 200);
        mysqli_query($mysqli, "INSERT INTO history SET history_status = '0', history_description = '" . mysqli_real_escape_string($mysqli, $description) . "', history_recurring_invoice_id = $id");
        logAction("Recurring Invoice", "Edit", "Recurring invoice $label paused automatically - $reason", $clientId, $id);
    }
}

if (isset($_POST['edit_contract'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $contract_id = intval($_POST['contract_id']);

    // Confirm access to the contract's current client before allowing any edit
    enforceClientAccess(intval(getFieldById('contracts', $contract_id, 'contract_client_id')));

    // roadmap #24: captured before the UPDATE below, to tell "just turned Expired/Cancelled" (pause now)
    // from "was already Expired/Cancelled" (nothing newly changed, so nothing to pause again).
    $contract_status_before = (string) getFieldById('contracts', $contract_id, 'contract_status');

    $client_id = intval($_POST['contract_client_id']);
    $name = sanitizeInput($_POST['contract_name']);
    $type = sanitizeInput($_POST['contract_type']);
    $status = sanitizeInput($_POST['contract_status']);
    $freq = sanitizeInput($_POST['contract_renewal_frequency'] ?? '');
    $start = sanitizeInput($_POST['contract_start_date']);
    $end = sanitizeInput($_POST['contract_end_date']);
    $renewal = sanitizeInput($_POST['contract_renewal_date']);
    $notes = sanitizeInput($_POST['contract_details']);
    $sla_lr = is_numeric($_POST['sla_low_response'] ?? '') ? intval($_POST['sla_low_response']) : 'NULL';
    $sla_lres = is_numeric($_POST['sla_low_resolution'] ?? '') ? intval($_POST['sla_low_resolution']) : 'NULL';
    $sla_mr = is_numeric($_POST['sla_medium_response'] ?? '') ? intval($_POST['sla_medium_response']) : 'NULL';
    $sla_mres = is_numeric($_POST['sla_medium_resolution'] ?? '') ? intval($_POST['sla_medium_resolution']) : 'NULL';
    $sla_hr = is_numeric($_POST['sla_high_response'] ?? '') ? intval($_POST['sla_high_response']) : 'NULL';
    $sla_hres = is_numeric($_POST['sla_high_resolution'] ?? '') ? intval($_POST['sla_high_resolution']) : 'NULL';
    $hours_remote = is_numeric($_POST['support_hours_included_remote'] ?? '') ? floatval($_POST['support_hours_included_remote']) : 'NULL';
    $hours_onsite = is_numeric($_POST['support_hours_included_onsite'] ?? '') ? floatval($_POST['support_hours_included_onsite']) : 'NULL';

    // The form may also reassign the contract to a different client - confirm
    // access to that destination client too.
    enforceClientAccess();

    $start_sql = $start ? "'$start'" : 'NULL';
    $end_sql = $end ? "'$end'" : 'NULL';
    $renewal_sql = $renewal ? "'$renewal'" : 'NULL';
    $value_sql = is_numeric($_POST['contract_value'] ?? '') ? floatval($_POST['contract_value']) : 'NULL';
    $freq_sql = $freq ? "'$freq'" : 'NULL';

    mysqli_query($mysqli, "UPDATE contracts SET contract_name = '$name', contract_type = '$type', contract_status = '$status', contract_value = $value_sql, contract_renewal_frequency = $freq_sql, contract_start_date = $start_sql, contract_end_date = $end_sql, contract_renewal_date = $renewal_sql, contract_details = '$notes', contract_sla_low_response_time = $sla_lr, contract_sla_low_resolution_time = $sla_lres, contract_sla_medium_response_time = $sla_mr, contract_sla_medium_resolution_time = $sla_mres, contract_sla_high_response_time = $sla_hr, contract_sla_high_resolution_time = $sla_hres, contract_support_hours_included_remote = $hours_remote, contract_support_hours_included_onsite = $hours_onsite WHERE contract_id = $contract_id");

    // roadmap #24: only on the TRANSITION into Expired/Cancelled - re-saving an already-Expired contract
    // must not re-fire (and must not fight an admin who deliberately re-enabled the invoice since).
    if (in_array($status, ['Expired', 'Cancelled'], true) && !in_array($contract_status_before, ['Expired', 'Cancelled'], true)) {
        pauseRecurringInvoicesForContract($mysqli, $contract_id, "the linked contract \"$name\" was marked $status");
    }

    logAction("Contract", "Edit", "Updated contract $name", $client_id);
    flash_alert("Contract updated.");
    redirect();
}

if (isset($_GET['delete_contract'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $contract_id = intval($_GET['delete_contract']);

    // Derive the client from the contract itself, not the (attacker-controlled) URL
    $client_id = intval(getFieldById('contracts', $contract_id, 'contract_client_id'));
    enforceClientAccess();

    mysqli_query($mysqli, "UPDATE contracts SET contract_archived_at = NOW() WHERE contract_id = $contract_id");

    // roadmap #24: a deleted contract is gone, same as an Expired/Cancelled one - pause what was linked to it.
    pauseRecurringInvoicesForContract($mysqli, $contract_id, "the linked contract was deleted");

    logAction("Contract", "Delete", "Deleted contract #$contract_id", $client_id);
    flash_alert("Contract deleted.");
    redirect();
}

// ── Upload contract document ──────────────────────────────────────────────────
if (isset($_POST['upload_contract_document'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $contract_id = intval($_POST['contract_id']);
    $client_id   = intval(getFieldById('contracts', $contract_id, 'contract_client_id'));
    if ($client_id) enforceClientAccess();

    if (empty($_FILES['contract_document']['tmp_name'])) {
        flash_alert('No file selected.', 'error'); redirect();
    }

    $file      = $_FILES['contract_document'];
    $max_bytes = 20 * 1048576; // 20 MB

    if ($file['size'] > $max_bytes) {
        flash_alert('File too large (max 20 MB).', 'error'); redirect();
    }

    // Allowed MIME types
    $allowed_mime = [
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/png', 'image/jpeg', 'text/plain',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed_mime)) {
        flash_alert('File type not allowed.', 'error'); redirect();
    }

    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/contracts/$contract_id";
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0750, true);

    // Safe stored filename: doc_id will come from insert, use timestamp for now
    // The stored extension comes from the sniffed MIME type, never from the client-supplied name
    // (a text/plain polyglot named x.php must not be saved as .php)
    $ext_by_mime = [
        'application/pdf' => 'pdf', 'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'text/plain' => 'txt',
    ];
    $ext      = $ext_by_mime[$mime];
    $stored   = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest     = "$upload_dir/$stored";

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        flash_alert('File upload failed.', 'error'); redirect();
    }

    $orig_safe = sanitizeInput($file['name']);
    $mime_safe = sanitizeInput($mime);
    $size      = intval($file['size']);

    mysqli_query($mysqli, "INSERT INTO contract_documents SET
        doc_contract_id  = $contract_id,
        doc_filename     = '$stored',
        doc_original_name = '$orig_safe',
        doc_mime_type    = '$mime_safe',
        doc_size         = $size,
        doc_uploaded_by  = $session_user_id
    ");

    logAction('Contract', 'Upload', "$session_name uploaded $orig_safe to contract #$contract_id", $client_id);
    flash_alert("Document <strong>$orig_safe</strong> uploaded.");
    redirect();
}

// ── Serve (download) contract document ───────────────────────────────────────
if (isset($_GET['serve_contract_document'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support');

    $doc_id = intval($_GET['serve_contract_document']);
    $doc = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT d.*, c.contract_client_id FROM contract_documents d
         JOIN contracts c ON d.doc_contract_id = c.contract_id
         WHERE d.doc_id = $doc_id LIMIT 1"
    ));
    if (!$doc) { flash_alert('Document not found.', 'error'); redirect(); }

    $client_id = intval($doc['contract_client_id']);
    if ($client_id) enforceClientAccess();

    $path = $_SERVER['DOCUMENT_ROOT'] . "/uploads/contracts/" . intval($doc['doc_contract_id']) . "/" . basename($doc['doc_filename']);
    if (!is_file($path)) { flash_alert('File not found on server.', 'error'); redirect(); }

    $safe_name = preg_replace('/[^\w.\-]/', '_', $doc['doc_original_name']);
    header('Content-Type: ' . $doc['doc_mime_type']);
    header('Content-Disposition: inline; filename="' . $safe_name . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private');
    readfile($path);
    exit;
}

// ── Delete contract document ──────────────────────────────────────────────────
if (isset($_GET['delete_contract_document'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $doc_id = intval($_GET['delete_contract_document']);
    $doc = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT d.*, c.contract_client_id FROM contract_documents d
         JOIN contracts c ON d.doc_contract_id = c.contract_id
         WHERE d.doc_id = $doc_id LIMIT 1"
    ));
    if (!$doc) { redirect(); }

    $client_id = intval($doc['contract_client_id']);
    if ($client_id) enforceClientAccess();

    $path = $_SERVER['DOCUMENT_ROOT'] . "/uploads/contracts/" . intval($doc['doc_contract_id']) . "/" . basename($doc['doc_filename']);
    if (is_file($path)) @unlink($path);

    mysqli_query($mysqli, "DELETE FROM contract_documents WHERE doc_id = $doc_id");
    $orig_safe = sanitizeInput($doc['doc_original_name']);
    logAction('Contract', 'Delete', "$session_name deleted document $orig_safe from contract #{$doc['doc_contract_id']}", $client_id);
    flash_alert("Document <strong>$orig_safe</strong> deleted.", 'error');
    redirect();
}

