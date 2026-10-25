<?php

require_once '../../../includes/modal_header.php';

if (!isset($session_is_admin) || !$session_is_admin) {
    exit;
}

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fa fa-fw fa-sitemap me-2"></i>New Document Type</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="modal-body">
        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="type_name" placeholder="e.g. Firewall" maxlength="100" required>
        </div>
        <div class="form-group">
            <label>Icon</label>
            <input type="text" class="form-control" name="type_icon" placeholder="fa-shield-alt" maxlength="40" value="fa-file-alt">
            <small class="form-text text-muted">A Font Awesome icon class, e.g. <code>fa-shield-alt</code>. Leave as-is for a plain document icon.</small>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_structured_doc_type" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
