<?php
require_once "includes/inc_all_admin.php";
require_once "../includes/core_module_gates.php";

enforceUserPermission('module_client', 2);
if (!rivetModulePageOn('core.workflow.enabled', 'Workflows')) {
    require_once "../includes/footer.php";
    exit;
}

$sql = mysqli_query($mysqli, "SELECT wt.*,
    (SELECT COUNT(*) FROM workflow_template_tasks WHERE workflow_template_id = wt.workflow_template_id) AS task_count
    FROM workflow_templates wt
    WHERE wt.archived_at IS NULL
    ORDER BY wt.type ASC, wt.name ASC");

?>

<div class="card">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-tasks me-2"></i>Client Workflow Templates</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/workflow_template/workflow_template_add.php"><i class="fas fa-plus me-2"></i>New Template</button>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted">Onboarding/offboarding checklists you can start for a client (or for one of its contacts) from Organization &gt; Onboarding &amp; Offboarding. Manual-first: nothing here changes accounts or access on its own - it is a tracked checklist with an audit trail.</p>
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Tasks</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($sql)) { ?>
                    <tr>
                        <td><a href="workflow_template_details.php?id=<?= intval($row['workflow_template_id']) ?>"><?= nullable_htmlentities($row['name']) ?></a></td>
                        <td><span class="badge <?= $row['type'] === 'onboarding' ? 'text-bg-success' : 'text-bg-danger' ?>"><?= ucfirst($row['type']) ?></span></td>
                        <td><?= intval($row['task_count']) ?></td>
                        <td><?= $row['is_active'] ? '<span class="text-success">Active</span>' : '<span class="text-muted">Inactive</span>' ?></td>
                        <td class="text-end">
                            <a href="workflow_template_details.php?id=<?= intval($row['workflow_template_id']) ?>" class="btn btn-sm btn-default"><i class="fas fa-cog"></i></a>
                            <div class="dropdown d-inline-block">
                                <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    <a class="dropdown-item ajax-modal" href="#"
                                        data-modal-url="modals/workflow_template/workflow_template_edit.php?id=<?= intval($row['workflow_template_id']) ?>">
                                        <i class="fas fa-fw fa-edit me-2"></i>Edit
                                    </a>
                                    <a class="dropdown-item text-danger confirm-link"
                                        href="post.php?archive_workflow_template=<?= intval($row['workflow_template_id']) ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                        <i class="fas fa-fw fa-archive me-2"></i>Archive
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
