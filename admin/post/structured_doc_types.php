<?php

// Structured documentation field builder (roadmap #22) - admin-only, matching every other admin/post/*.php
// handler's convention (admin/post.php's own top-level $session_is_admin gate already covers this file;
// no separate permission call needed).

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/** A field's own slug: stable even if the label is edited later, so stored values never orphan. */
function structuredDocFieldKey(string $label, int $typeId, \mysqli $mysqli, int $excludeFieldId = 0): string
{
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $label), '_'));
    $base = $base !== '' ? substr($base, 0, 50) : 'field';
    $key = $base;
    $n = 1;
    while (true) {
        $exists = mysqli_fetch_row(mysqli_query($GLOBALS['mysqli'], "SELECT 1 FROM structured_doc_fields WHERE field_type_id = $typeId AND field_key = '" . mysqli_real_escape_string($GLOBALS['mysqli'], $key) . "' AND field_id != $excludeFieldId LIMIT 1"));
        if (!$exists) {
            return $key;
        }
        $key = $base . '_' . (++$n);
    }
}

if (isset($_POST['add_structured_doc_type'])) {
    validateCSRFToken($_POST['csrf_token']);
    $name = sanitizeInput($_POST['type_name']);
    $icon = preg_match('/^fa-[a-z0-9-]{1,37}$/', (string) ($_POST['type_icon'] ?? '')) ? sanitizeInput($_POST['type_icon']) : 'fa-file-alt';
    $next_order = (int) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(type_order), -1) + 1 FROM structured_doc_types"))[0] ?? 0);
    mysqli_query($mysqli, "INSERT INTO structured_doc_types SET type_name = '$name', type_icon = '$icon', type_order = $next_order, type_created_by = $session_user_id");
    $type_id = mysqli_insert_id($mysqli);
    logAction("Structured Documentation", "Create", "$session_name created document type \"$name\"");
    flash_alert("Document type <strong>$name</strong> created");
    redirect("structured_doc_types.php?type_id=$type_id");
}

if (isset($_GET['archive_structured_doc_type'])) {
    validateCSRFToken($_GET['csrf_token']);
    $type_id = intval($_GET['archive_structured_doc_type']);
    $name = sanitizeInput(getFieldById('structured_doc_types', $type_id, 'type_name'));
    mysqli_query($mysqli, "UPDATE structured_doc_types SET type_archived_at = NOW() WHERE type_id = $type_id");
    logAction("Structured Documentation", "Delete", "$session_name archived document type \"$name\"");
    flash_alert("Document type <strong>$name</strong> archived");
    redirect("structured_doc_types.php");
}

if (isset($_POST['add_structured_doc_field'])) {
    validateCSRFToken($_POST['csrf_token']);
    $type_id = intval($_POST['type_id']);
    $label = sanitizeInput($_POST['field_label']);
    $kind = in_array($_POST['field_kind'] ?? '', ['text', 'textarea', 'number', 'date', 'checkbox', 'dropdown', 'url', 'password'], true) ? $_POST['field_kind'] : 'text';
    $required = !empty($_POST['field_required']) ? 1 : 0;
    $order = intval($_POST['field_order'] ?? 0);
    $key = structuredDocFieldKey($label, $type_id, $mysqli);
    $options_json = 'NULL';
    if ($kind === 'dropdown') {
        $opts = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) ($_POST['field_options'] ?? '')))));
        $options_json = "'" . mysqli_real_escape_string($mysqli, json_encode(array_map('sanitizeInput', $opts))) . "'";
    }
    mysqli_query($mysqli, "INSERT INTO structured_doc_fields SET field_type_id = $type_id, field_key = '" . mysqli_real_escape_string($mysqli, $key) . "', field_label = '$label', field_kind = '$kind', field_options_json = $options_json, field_required = $required, field_order = $order");
    logAction("Structured Documentation", "Create", "$session_name added field \"$label\" to a document type");
    flash_alert("Field <strong>$label</strong> added");
    redirect("structured_doc_types.php?type_id=$type_id");
}

if (isset($_POST['edit_structured_doc_field'])) {
    validateCSRFToken($_POST['csrf_token']);
    $field_id = intval($_POST['field_id']);
    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT field_type_id, field_kind FROM structured_doc_fields WHERE field_id = $field_id"));
    if (!$existing) {
        flash_alert('Field not found', 'error');
        redirect('structured_doc_types.php');
    }
    $type_id = (int) $existing['field_type_id'];
    $label = sanitizeInput($_POST['field_label']);
    $required = !empty($_POST['field_required']) ? 1 : 0;
    $order = intval($_POST['field_order'] ?? 0);
    $options_sql = '';
    if ($existing['field_kind'] === 'dropdown') {
        $opts = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) ($_POST['field_options'] ?? '')))));
        $options_sql = ", field_options_json = '" . mysqli_real_escape_string($mysqli, json_encode(array_map('sanitizeInput', $opts))) . "'";
    }
    mysqli_query($mysqli, "UPDATE structured_doc_fields SET field_label = '$label', field_required = $required, field_order = $order $options_sql WHERE field_id = $field_id");
    logAction("Structured Documentation", "Edit", "$session_name edited field \"$label\"");
    flash_alert("Field <strong>$label</strong> updated");
    redirect("structured_doc_types.php?type_id=$type_id");
}

if (isset($_GET['archive_structured_doc_field'])) {
    validateCSRFToken($_GET['csrf_token']);
    $field_id = intval($_GET['archive_structured_doc_field']);
    $type_id = intval(getFieldById('structured_doc_fields', $field_id, 'field_type_id'));
    $label = sanitizeInput(getFieldById('structured_doc_fields', $field_id, 'field_label'));
    mysqli_query($mysqli, "UPDATE structured_doc_fields SET field_archived_at = NOW() WHERE field_id = $field_id");
    logAction("Structured Documentation", "Delete", "$session_name archived field \"$label\"");
    flash_alert("Field <strong>$label</strong> archived");
    redirect("structured_doc_types.php?type_id=$type_id");
}
