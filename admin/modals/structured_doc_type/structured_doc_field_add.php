<?php

require_once '../../../includes/modal_header.php';

if (!isset($session_is_admin) || !$session_is_admin) {
    exit;
}

$type_id = intval($_GET['type_id'] ?? 0);
$type_name = nullable_htmlentities((string) mysqli_fetch_row(mysqli_query($mysqli, "SELECT type_name FROM structured_doc_types WHERE type_id = $type_id"))[0] ?? '');
$next_order = (int) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(field_order), -1) + 1 FROM structured_doc_fields WHERE field_type_id = $type_id"))[0] ?? 0);

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fa fa-fw fa-plus me-2"></i>New Field: <?= $type_name ?></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="type_id" value="<?= $type_id ?>">
    <div class="modal-body">
        <div class="form-group">
            <label>Label <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="field_label" placeholder="e.g. WAN IP Address" maxlength="150" required>
        </div>
        <div class="form-group">
            <label>Field Type <strong class="text-danger">*</strong></label>
            <select class="form-control select2" name="field_kind" required>
                <option value="text">Text (single line)</option>
                <option value="textarea">Text (multiple lines)</option>
                <option value="number">Number</option>
                <option value="date">Date</option>
                <option value="checkbox">Checkbox (yes/no)</option>
                <option value="dropdown">Dropdown</option>
                <option value="url">URL</option>
                <option value="password">Password (encrypted at rest)</option>
            </select>
        </div>
        <div class="form-group field-dropdown-options">
            <label>Dropdown options</label>
            <input type="text" class="form-control" name="field_options" placeholder="One per line, or comma-separated">
            <small class="form-text text-muted">Only used when Field Type is Dropdown.</small>
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="field_required" name="field_required" value="1">
            <label class="form-check-label" for="field_required">Required</label>
        </div>
        <div class="form-group">
            <label>Order</label>
            <input type="number" class="form-control" name="field_order" value="<?= $next_order ?>" step="1">
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_structured_doc_field" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Add Field</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
