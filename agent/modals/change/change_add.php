<?php
require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$linked_problem_id = intval($_GET['problem_id'] ?? 0);
$client_id = intval($_GET['client_id'] ?? 0);

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-exchange-alt me-2"></i>New Change</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <?php if ($linked_problem_id) { ?>
        <input type="hidden" name="linked_problem_id" value="<?= $linked_problem_id ?>">
    <?php } ?>

    <div class="modal-body">
        <div class="form-group">
            <label>Title <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="title" placeholder="e.g. Migrate file server to new hardware" maxlength="255" required autofocus>
        </div>
        <div class="form-group">
            <label>Client <span class="text-secondary">(optional - leave as Internal for a change with no client-facing side)</span></label>
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
                        <option <?php if ($client_id === $client_id_select) { echo "selected"; } ?> value="<?= $client_id_select ?>"><?= $client_name_select ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="change_add_requires_client_approval" name="requires_client_approval" value="1">
                <label class="form-check-label" for="change_add_requires_client_approval">Requires the client's own sign-off before it can proceed (a guest link is generated once this change is awaiting approval)</label>
            </div>
        </div>
        <div class="form-group">
            <label>Risk <strong class="text-danger">*</strong></label>
            <select class="form-control select2" name="risk" required>
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
            </select>
        </div>
        <div class="form-group">
            <label>Reason</label>
            <textarea class="form-control" name="reason" rows="2" placeholder="Why is this change needed?"></textarea>
        </div>
        <div class="form-group">
            <label>Impact</label>
            <textarea class="form-control" name="impact" rows="2" placeholder="What/who is affected while this is carried out?"></textarea>
        </div>
        <div class="form-group">
            <label>Implementation Plan</label>
            <textarea class="form-control" name="implementation_plan" rows="3" placeholder="Steps to carry out the change"></textarea>
        </div>
        <div class="form-group">
            <label>Rollback Plan</label>
            <textarea class="form-control" name="rollback_plan" rows="3" placeholder="How to undo this if it goes wrong"></textarea>
        </div>
        <div class="form-group">
            <label>Scheduled For</label>
            <input type="datetime-local" class="form-control" name="scheduled_at">
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_change" class="btn btn-primary"><i class="fa fa-check me-2"></i>Create Change</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php require_once '../../../includes/modal_footer.php'; ?>
