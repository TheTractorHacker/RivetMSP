<?php

require_once '../../../includes/modal_header.php';

if (!isset($session_is_admin) || !$session_is_admin) {
    exit;
}

$field_id = intval($_GET['field_id'] ?? 0);
$f = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM structured_doc_fields WHERE field_id = $field_id LIMIT 1"));
if (!$f) {
    exit;
}
$type_id = intval($f['field_type_id']);
$field_label = nullable_htmlentities($f['field_label']);
$field_kind = nullable_htmlentities($f['field_kind']);
$field_options = nullable_htmlentities(implode("\n", (array) json_decode((string) $f['field_options_json'], true)));
$field_required = intval($f['field_required']);
$field_order = intval($f['field_order']);

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fa fa-fw fa-edit me-2"></i>Edit Field: <?= $field_label ?></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="field_id" value="<?= $field_id ?>">
    <div class="modal-body">
        <div class="form-group">
            <label>Label <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="field_label" value="<?= $field_label ?>" maxlength="150" required>
        </div>
        <div class="form-group">
            <label>Field Type</label>
            <input type="text" class="form-control" value="<?= ucfirst($field_kind) ?>" disabled>
            <small class="form-text text-muted">The field type cannot be changed once created (existing documents already store values in that shape) - archive this field and add a new one instead.</small>
        </div>
        <?php if ($field_kind === 'dropdown') { ?>
        <div class="form-group">
            <label>Dropdown options</label>
            <input type="text" class="form-control" name="field_options" value="<?= $field_options ?>" placeholder="One per line, or comma-separated">
        </div>
        <?php } ?>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="field_required" name="field_required" value="1" <?= $field_required ? 'checked' : '' ?>>
            <label class="form-check-label" for="field_required">Required</label>
        </div>
        <div class="form-group">
            <label>Order</label>
            <input type="number" class="form-control" name="field_order" value="<?= $field_order ?>" step="1">
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_structured_doc_field" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
