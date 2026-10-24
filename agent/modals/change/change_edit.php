<?php
require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$change_id = intval($_GET['id'] ?? 0);
$change = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM changes WHERE change_id = $change_id"));
if (!$change) {
    http_response_code(404);
    echo json_encode(['error' => 'Change not found.']);
    exit;
}
// Internal changes (client_id = 0) are not client-scoped at all, so enforceClientAccess() - which treats a missing/
// empty client_id as "deny" - must only run once this change actually belongs to a client.
if ((int) $change['client_id'] > 0) {
    enforceClientAccess((int) $change['client_id']);
}

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-exchange-alt me-2"></i>Edit Change</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="change_id" value="<?= $change_id ?>">

    <div class="modal-body">
        <div class="form-group">
            <label>Title <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="title" value="<?= nullable_htmlentities($change['title']) ?>" maxlength="255" required autofocus>
        </div>
        <div class="form-group">
            <label>Client <span class="text-secondary">(optional)</span></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                </div>
                <select class="form-control select2" name="client_id">
                    <option value="0">- Internal, no client -</option>
                    <?php
                    $sql = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL $access_permission_query ORDER BY client_name ASC");
                    while ($row = mysqli_fetch_assoc($sql)) {
                        $client_id_select = intval($row['client_id']);
                        $client_name_select = nullable_htmlentities($row['client_name']); ?>
                        <option <?php if ((int) $change['client_id'] === $client_id_select) { echo "selected"; } ?> value="<?= $client_id_select ?>"><?= $client_name_select ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="change_edit_requires_client_approval" name="requires_client_approval" value="1" <?= $change['requires_client_approval'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="change_edit_requires_client_approval">Requires the client's own sign-off before it can proceed</label>
            </div>
        </div>
        <div class="form-group">
            <label>Risk <strong class="text-danger">*</strong></label>
            <select class="form-control select2" name="risk" required>
                <?php foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'] as $val => $label) { ?>
                    <option value="<?= $val ?>" <?= $change['risk'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="form-group">
            <label>Reason</label>
            <textarea class="form-control" name="reason" rows="2"><?= nullable_htmlentities($change['reason']) ?></textarea>
        </div>
        <div class="form-group">
            <label>Impact</label>
            <textarea class="form-control" name="impact" rows="2"><?= nullable_htmlentities($change['impact']) ?></textarea>
        </div>
        <div class="form-group">
            <label>Implementation Plan</label>
            <textarea class="form-control" name="implementation_plan" rows="3"><?= nullable_htmlentities($change['implementation_plan']) ?></textarea>
        </div>
        <div class="form-group">
            <label>Rollback Plan</label>
            <textarea class="form-control" name="rollback_plan" rows="3"><?= nullable_htmlentities($change['rollback_plan']) ?></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_change" class="btn btn-primary"><i class="fa fa-check me-2"></i>Save Changes</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php require_once '../../../includes/modal_footer.php'; ?>
