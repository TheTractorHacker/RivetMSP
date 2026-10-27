<?php

require_once '../../../includes/modal_header.php';

$client_id = intval($_GET['client_id'] ?? 0);
$type_id = intval($_GET['type_id'] ?? 0);
enforceClientAccess($client_id);

$type = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM structured_doc_types WHERE type_id = $type_id AND type_archived_at IS NULL LIMIT 1"));
if (!$type) {
    exit;
}
$type_name = nullable_htmlentities($type['type_name']);
$sql_fields = mysqli_query($mysqli, "SELECT * FROM structured_doc_fields WHERE field_type_id = $type_id AND field_archived_at IS NULL ORDER BY field_order ASC, field_id ASC");

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fa fa-fw fa-plus me-2"></i>New <?= $type_name ?></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="client_id" value="<?= $client_id ?>">
    <input type="hidden" name="type_id" value="<?= $type_id ?>">
    <div class="modal-body">
        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="doc_name" placeholder="e.g. HQ-FW-01" maxlength="200" required>
        </div>
        <?php while ($f = mysqli_fetch_assoc($sql_fields)) {
            $field_id = intval($f['field_id']);
            $kind = $f['field_kind'];
            $label = nullable_htmlentities($f['field_label']);
            $required = intval($f['field_required']);
            $name = "field_$field_id";
        ?>
            <div class="form-group">
                <label><?= $label ?><?php if ($required) { ?> <strong class="text-danger">*</strong><?php } ?></label>
                <?php if ($kind === 'textarea') { ?>
                    <textarea class="form-control" name="<?= $name ?>" rows="3" <?= $required ? 'required' : '' ?>></textarea>
                <?php } elseif ($kind === 'checkbox') { ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="<?= $name ?>" name="<?= $name ?>" value="1">
                        <label class="form-check-label" for="<?= $name ?>">Yes</label>
                    </div>
                <?php } elseif ($kind === 'dropdown') { ?>
                    <select class="form-control select2" name="<?= $name ?>" <?= $required ? 'required' : '' ?>>
                        <option value="">- Select -</option>
                        <?php foreach ((array) json_decode((string) $f['field_options_json'], true) as $opt) { ?>
                            <option value="<?= nullable_htmlentities($opt) ?>"><?= nullable_htmlentities($opt) ?></option>
                        <?php } ?>
                    </select>
                <?php } elseif ($kind === 'date') { ?>
                    <input type="date" class="form-control" name="<?= $name ?>" max="2999-12-31" <?= $required ? 'required' : '' ?>>
                <?php } elseif ($kind === 'number') { ?>
                    <input type="number" class="form-control" name="<?= $name ?>" step="any" <?= $required ? 'required' : '' ?>>
                <?php } elseif ($kind === 'password') { ?>
                    <input type="password" class="form-control" name="<?= $name ?>" autocomplete="new-password" <?= $required ? 'required' : '' ?>>
                <?php } else { ?>
                    <input type="<?= $kind === 'url' ? 'url' : 'text' ?>" class="form-control" name="<?= $name ?>" maxlength="2000" <?= $required ? 'required' : '' ?>>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_structured_doc" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
