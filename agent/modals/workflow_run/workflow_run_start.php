<?php
require_once '../../../includes/modal_header.php';

enforceUserPermission('module_client', 2);

$preselect_client_id = intval($_GET['client_id'] ?? 0);
$preselect_contact_id = intval($_GET['contact_id'] ?? 0);

$sql_templates = mysqli_query($mysqli, "SELECT workflow_template_id, name, type FROM workflow_templates WHERE is_active = 1 AND archived_at IS NULL ORDER BY type ASC, name ASC");
$sql_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL $access_permission_query ORDER BY client_name ASC");
$sql_contacts = mysqli_query($mysqli, "SELECT contact_id, contact_name, contact_client_id, client_name FROM contacts
    LEFT JOIN clients ON clients.client_id = contacts.contact_client_id
    WHERE contact_archived_at IS NULL AND client_archived_at IS NULL $access_permission_query ORDER BY contact_name ASC");

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-tasks me-2"></i>Start Onboarding / Offboarding</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <div class="modal-body">
        <?php if (mysqli_num_rows($sql_templates) === 0) { ?>
            <p class="text-muted">No workflow templates exist yet. An administrator can create one under Administration &gt; Templates &gt; Client Workflows.</p>
        <?php } ?>
        <div class="form-group">
            <label>Workflow <strong class="text-danger">*</strong></label>
            <select class="form-control select2" name="workflow_template_id" required>
                <option value="">Select workflow</option>
                <?php while ($t = mysqli_fetch_assoc($sql_templates)) { ?>
                    <option value="<?= intval($t['workflow_template_id']) ?>">[<?= ucfirst($t['type']) ?>] <?= nullable_htmlentities($t['name']) ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="form-group">
            <label>Client <strong class="text-danger">*</strong></label>
            <select class="form-control select2" name="client_id" required>
                <option value="">Select client</option>
                <?php while ($c = mysqli_fetch_assoc($sql_clients)) { ?>
                    <option value="<?= intval($c['client_id']) ?>" <?= $preselect_client_id === intval($c['client_id']) ? 'selected' : '' ?>><?= nullable_htmlentities($c['client_name']) ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="form-group">
            <label>Specific person (optional)</label>
            <select class="form-control select2" name="contact_id">
                <option value="0">- Whole client -</option>
                <?php while ($p = mysqli_fetch_assoc($sql_contacts)) { ?>
                    <option value="<?= intval($p['contact_id']) ?>" <?= $preselect_contact_id === intval($p['contact_id']) ? 'selected' : '' ?>><?= nullable_htmlentities($p['contact_name']) ?> (<?= nullable_htmlentities($p['client_name']) ?>)</option>
                <?php } ?>
            </select>
            <small class="text-muted">Pick a contact to track a new starter or leaver; it must belong to the client above.</small>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="start_client_workflow" class="btn btn-primary"><i class="fa fa-play me-2"></i>Start</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php require_once '../../../includes/modal_footer.php'; ?>
