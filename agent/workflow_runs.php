<?php

// Default Column Sortby Filter
$sort = "wr.started_at";
$order = "DESC";

require_once "includes/inc_all.php";
require_once "../includes/core_module_gates.php";

enforceUserPermission('module_client');
if (!rivetModulePageOn('core.workflow.enabled', 'Workflows')) {
    require_once "../includes/footer.php";
    exit;
}

$status_filter = in_array($_GET['status'] ?? '', ['in_progress', 'completed', 'completed_with_exceptions', 'cancelled'], true) ? $_GET['status'] : '';
$type_filter = in_array($_GET['type'] ?? '', ['onboarding', 'offboarding'], true) ? $_GET['type'] : '';
$status_query = $status_filter !== '' ? "AND wr.status = '$status_filter'" : '';
$type_query = $type_filter !== '' ? "AND wr.type = '$type_filter'" : '';

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS wr.*, clients.client_name, c.contact_name, wt.name AS template_name,
        (SELECT COUNT(*) FROM workflow_run_tasks t WHERE t.run_id = wr.run_id) AS task_total,
        (SELECT COUNT(*) FROM workflow_run_tasks t WHERE t.run_id = wr.run_id AND t.status != 'pending') AS task_done
     FROM workflow_runs wr
     LEFT JOIN contacts c ON c.contact_id = wr.contact_id
     LEFT JOIN clients ON clients.client_id = COALESCE(wr.client_id, c.contact_client_id)
     LEFT JOIN workflow_templates wt ON wt.workflow_template_id = wr.workflow_template_id
     WHERE (clients.client_name LIKE '%$q%' OR c.contact_name LIKE '%$q%' OR wt.name LIKE '%$q%')
     $status_query
     $type_query
     $access_permission_query
     ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

$status_badge_map = [
    'in_progress' => 'text-bg-primary',
    'completed' => 'text-bg-success',
    'completed_with_exceptions' => 'text-bg-warning',
    'cancelled' => 'text-bg-secondary',
];

$start_modal_url = "modals/workflow_run/workflow_run_start.php" . (!empty($_GET['client_id']) ? '?client_id=' . intval($_GET['client_id']) : '');

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-tasks me-2"></i>Onboarding &amp; Offboarding</h3>
        <?php if (lookupUserPermission('module_client') >= 2) { ?>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="<?= $start_modal_url ?>"><i class="fas fa-play me-2"></i>Start Workflow</button>
        </div>
        <?php } ?>
    </div>
    <div class="card-body">
        <form autocomplete="off">
            <div class="row align-items-center">
                <div class="col-auto mb-2">
                    <select class="form-control select2 auto-submit-select" name="type" data-placeholder="Type" style="width:150px;">
                        <option value="">All Types</option>
                        <option value="onboarding" <?= $type_filter === 'onboarding' ? 'selected' : '' ?>>Onboarding</option>
                        <option value="offboarding" <?= $type_filter === 'offboarding' ? 'selected' : '' ?>>Offboarding</option>
                    </select>
                </div>
                <div class="col-auto mb-2">
                    <select class="form-control select2 auto-submit-select" name="status" data-placeholder="Status" style="width:200px;">
                        <option value="">All Statuses</option>
                        <?php foreach ($status_badge_map as $val => $badge) { ?>
                            <option value="<?= $val ?>" <?= $status_filter === $val ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $val)) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col mb-2">
                    <div class="input-group">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search client, contact or template">
                        <div class="input-group-append">
                            <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <hr>
        <div class="table-responsive">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?>">
                <tr>
                    <th>Client</th>
                    <th>For</th>
                    <th>Type</th>
                    <th>Template</th>
                    <th>Progress</th>
                    <th>Status</th>
                    <th>
                        <a class="text-dark" href="?<?= $url_query_strings_sort ?>&sort=wr.started_at&order=<?= $disp ?>">
                            Started <?php if ($sort == 'wr.started_at') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($sql)) {
                    $run_id = intval($row['run_id']);
                    ?>
                    <tr>
                        <td><?= nullable_htmlentities($row['client_name'] ?? '-') ?></td>
                        <td><?= $row['contact_id'] ? nullable_htmlentities($row['contact_name'] ?? 'Contact') : '<span class="text-muted">Whole client</span>' ?></td>
                        <td><span class="badge <?= $row['type'] === 'onboarding' ? 'text-bg-success' : 'text-bg-danger' ?>"><?= ucfirst($row['type']) ?></span></td>
                        <td><?= nullable_htmlentities($row['template_name'] ?? '-') ?></td>
                        <td><?= intval($row['task_done']) ?> / <?= intval($row['task_total']) ?></td>
                        <td><span class="badge <?= $status_badge_map[$row['status']] ?? 'text-bg-secondary' ?>"><?= ucwords(str_replace('_', ' ', $row['status'])) ?></span></td>
                        <td><?= nullable_htmlentities($row['started_at']) ?></td>
                        <td class="text-center"><a class="btn btn-secondary btn-sm" href="workflow_run.php?run_id=<?= $run_id ?>"><i class="fas fa-eye"></i></a></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php require_once "../includes/filter_footer.php"; ?>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
