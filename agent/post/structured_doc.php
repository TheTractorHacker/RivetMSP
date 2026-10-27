<?php

// Structured documentation (roadmap #22) - documents built from an admin-defined field layout.

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// rivetAudit() is defined in event_bus.php, which agent/post.php's glob does not load globally (the
// lesson already learned once this session for guest_post.php) - required directly.
require_once __DIR__ . '/../../includes/event_bus.php';

/** Writes every posted field_<id> value for one document, matching each field's own kind. Never throws on a missing field. */
function structuredDocSaveValues(\mysqli $mysqli, int $docId, int $typeId, array $post, int $sessionUserId): void
{
    $sql_fields = mysqli_query($mysqli, "SELECT * FROM structured_doc_fields WHERE field_type_id = $typeId AND field_archived_at IS NULL");
    while ($f = mysqli_fetch_assoc($sql_fields)) {
        $field_id = (int) $f['field_id'];
        $kind = $f['field_kind'];
        $raw = $post["field_$field_id"] ?? null;

        if ($kind === 'password') {
            // Blank means "keep what is already stored" - the field is never redisplayed, so a blank
            // submit is never "clear it" (same rule every other secret field in this app follows).
            if ($raw === null || $raw === '') {
                continue;
            }
            $enc = encryptSetting(sanitizeInput((string) $raw));
            mysqli_query($mysqli, "INSERT INTO structured_document_values SET doc_id = $docId, field_id = $field_id, value_enc = '" . mysqli_real_escape_string($mysqli, $enc) . "', value_text = NULL, updated_by = $sessionUserId
                ON DUPLICATE KEY UPDATE value_enc = '" . mysqli_real_escape_string($mysqli, $enc) . "', value_text = NULL, updated_by = $sessionUserId, updated_at = NOW()");
            continue;
        }

        if ($kind === 'checkbox') {
            $value = !empty($raw) ? '1' : '0';
        } else {
            $value = sanitizeInput((string) ($raw ?? ''));
        }
        $value_sql = "'" . mysqli_real_escape_string($mysqli, $value) . "'";
        mysqli_query($mysqli, "INSERT INTO structured_document_values SET doc_id = $docId, field_id = $field_id, value_text = $value_sql, updated_by = $sessionUserId
            ON DUPLICATE KEY UPDATE value_text = $value_sql, updated_by = $sessionUserId, updated_at = NOW()");
    }
}

if (isset($_POST['add_structured_doc'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $client_id = intval($_POST['client_id']);
    enforceClientAccess();

    $type_id = intval($_POST['type_id']);
    $type_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT type_name FROM structured_doc_types WHERE type_id = $type_id AND type_archived_at IS NULL"));
    if (!$type_row) {
        flash_alert('Document type not found', 'error');
        redirect();
    }
    $doc_name = sanitizeInput($_POST['doc_name']);

    mysqli_query($mysqli, "INSERT INTO structured_documents SET doc_type_id = $type_id, doc_client_id = $client_id, doc_name = '$doc_name', doc_created_by = $session_user_id, doc_updated_by = $session_user_id");
    $doc_id = mysqli_insert_id($mysqli);

    structuredDocSaveValues($mysqli, $doc_id, $type_id, $_POST, (int) $session_user_id);

    logAction("Structured Documentation", "Create", "$session_name created $type_row[type_name] \"$doc_name\"", $client_id, $doc_id);
    flash_alert("<strong>$doc_name</strong> created");
    redirect("structured_doc.php?id=$doc_id&client_id=$client_id");
}

if (isset($_POST['edit_structured_doc'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $doc_id = intval($_POST['doc_id']);
    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT doc_client_id, doc_type_id FROM structured_documents WHERE doc_id = $doc_id"));
    if (!$existing) {
        flash_alert('Document not found', 'error');
        redirect();
    }
    $client_id = (int) $existing['doc_client_id'];
    $type_id = (int) $existing['doc_type_id'];
    enforceClientAccess();

    $doc_name = sanitizeInput($_POST['doc_name']);
    mysqli_query($mysqli, "UPDATE structured_documents SET doc_name = '$doc_name', doc_updated_by = $session_user_id WHERE doc_id = $doc_id");

    structuredDocSaveValues($mysqli, $doc_id, $type_id, $_POST, (int) $session_user_id);

    logAction("Structured Documentation", "Edit", "$session_name edited \"$doc_name\"", $client_id, $doc_id);
    flash_alert("<strong>$doc_name</strong> updated");
    redirect("structured_doc.php?id=$doc_id&client_id=$client_id");
}

if (isset($_GET['delete_structured_doc'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 3);

    $doc_id = intval($_GET['delete_structured_doc']);
    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT doc_client_id, doc_name FROM structured_documents WHERE doc_id = $doc_id"));
    if (!$existing) {
        flash_alert('Document not found', 'error');
        redirect();
    }
    $client_id = (int) $existing['doc_client_id'];
    $doc_name = sanitizeInput($existing['doc_name']);
    enforceClientAccess();

    mysqli_query($mysqli, "UPDATE structured_documents SET doc_archived_at = NOW() WHERE doc_id = $doc_id");

    logAction("Structured Documentation", "Delete", "$session_name deleted \"$doc_name\"", $client_id, $doc_id);
    flash_alert("<strong>$doc_name</strong> deleted", 'error');
    redirect("structured_docs.php?client_id=$client_id");
}

// A lighter-weight reveal than the Credentials vault (no step-up password, no per-user reveal limit) -
// appropriate for a "flexible asset" password field, not a replacement for the vault. Still CSRF-protected,
// permission- and client-access-checked, and audited (matching this app's "every reveal is logged" rule).
if (isset($_POST['reveal_structured_doc_field'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 1);

    header('Content-Type: application/json');
    header('Cache-Control: no-store, private');

    $doc_id = intval($_POST['doc_id'] ?? 0);
    $field_id = intval($_POST['field_id'] ?? 0);
    $doc = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT doc_client_id, doc_name FROM structured_documents WHERE doc_id = $doc_id"));
    if (!$doc) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }
    $client_id = (int) $doc['doc_client_id'];
    // An inline check, not enforceClientAccess() - that refuses with a flash_alert + redirect, the wrong
    // shape for this JSON endpoint. Same rule it implements (admin or client 0 always allowed; otherwise a
    // user with no user_client_permissions rows at all is allowed everywhere, by this app's own "no
    // restriction configured" convention; otherwise the specific (user, client) row must exist), returning
    // a clean 403 instead of a redirect - matching agent/credential_reveal.php's own established shape
    // for this exact situation (includes/vault_reveal.php's vaultUserCanAccessClient()).
    if (empty($session_is_admin) && $client_id !== 0) {
        $has_any_restriction = (int) mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM user_client_permissions WHERE user_id = " . (int) $session_user_id))[0] > 0;
        $has_this_client = (int) mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM user_client_permissions WHERE user_id = " . (int) $session_user_id . " AND client_id = $client_id"))[0] > 0;
        if ($has_any_restriction && !$has_this_client) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'denied']);
            exit;
        }
    }

    $v = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT sdv.value_enc, sdf.field_label FROM structured_document_values sdv JOIN structured_doc_fields sdf ON sdf.field_id = sdv.field_id WHERE sdv.doc_id = $doc_id AND sdv.field_id = $field_id AND sdf.field_kind = 'password' LIMIT 1"));
    if (!$v || empty($v['value_enc'])) {
        echo json_encode(['ok' => false, 'error' => 'not_set']);
        exit;
    }
    $value = decryptSetting((string) $v['value_enc']);

    $field_label = sanitizeInput($v['field_label']);
    $doc_name = sanitizeInput($doc['doc_name']);
    logAction("Structured Documentation", "Reveal", "$session_name revealed \"$field_label\" on \"$doc_name\"", $client_id, $doc_id);
    rivetAudit('structured_doc.field_revealed', $session_user_id, 'structured_document', $doc_id, 'reveal', "$session_name revealed \"$field_label\" on \"$doc_name\"");

    echo json_encode(['ok' => true, 'value' => $value]);
    exit;
}
