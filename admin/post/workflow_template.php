<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../../includes/core_module_gates.php';

if (isset($_POST['add_workflow_template'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $name = sanitizeInput($_POST['name']);
    $type = in_array($_POST['type'] ?? '', ['onboarding', 'offboarding'], true) ? $_POST['type'] : 'onboarding';
    $description = sanitizeInput($_POST['description'] ?? '');

    mysqli_query($mysqli, "INSERT INTO workflow_templates SET name = '$name', type = '$type', description = '$description', created_by = $session_user_id");
    $workflow_template_id = mysqli_insert_id($mysqli);

    logAction("Settings", "Create", "$session_name created client workflow template $name");
    rivetAudit('workflow.template_created', $session_user_id, 'workflow_template', $workflow_template_id, 'created', "$session_name created client workflow template $name");

    flash_alert("Template <strong>$name</strong> created");
    redirect("workflow_template_details.php?id=$workflow_template_id");
}

if (isset($_POST['edit_workflow_template'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $workflow_template_id = intval($_POST['workflow_template_id']);
    $name = sanitizeInput($_POST['name']);
    $type = in_array($_POST['type'] ?? '', ['onboarding', 'offboarding'], true) ? $_POST['type'] : 'onboarding';
    $description = sanitizeInput($_POST['description'] ?? '');

    mysqli_query($mysqli, "UPDATE workflow_templates SET name = '$name', type = '$type', description = '$description' WHERE workflow_template_id = $workflow_template_id");

    logAction("Settings", "Edit", "$session_name edited client workflow template $name");

    flash_alert("Template <strong>$name</strong> updated");
    redirect("workflow_template_details.php?id=$workflow_template_id");
}

if (isset($_GET['archive_workflow_template'])) {

    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_client', 2);

    $workflow_template_id = intval($_GET['archive_workflow_template']);
    $name = sanitizeInput(getFieldById('workflow_templates', $workflow_template_id, 'name'));

    mysqli_query($mysqli, "UPDATE workflow_templates SET archived_at = NOW() WHERE workflow_template_id = $workflow_template_id");

    logAction("Settings", "Archive", "$session_name archived client workflow template $name");

    flash_alert("Template <strong>$name</strong> archived", 'error');
    redirect("workflow_templates.php");
}

if (isset($_POST['add_workflow_template_task'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $workflow_template_id = intval($_POST['workflow_template_id']);
    $title = sanitizeInput($_POST['title']);
    $category = sanitizeInput($_POST['category'] ?? '');
    $default_owner = sanitizeInput($_POST['default_owner'] ?? '');
    $instructions = sanitizeInput($_POST['instructions'] ?? '');
    $required = isset($_POST['required']) ? 1 : 0;

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM workflow_template_tasks WHERE workflow_template_id = $workflow_template_id"));
    $sort_order = intval($row['next_order']);

    mysqli_query($mysqli, "INSERT INTO workflow_template_tasks SET
        workflow_template_id = $workflow_template_id, title = '$title', category = '$category',
        default_owner = '$default_owner', instructions = '$instructions', required = $required, sort_order = $sort_order");

    flash_alert("Task added");
    redirect("workflow_template_details.php?id=$workflow_template_id");
}

if (isset($_POST['delete_workflow_template_task'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $workflow_template_id = intval($_POST['workflow_template_id']);
    $template_task_id = intval($_POST['template_task_id']);

    mysqli_query($mysqli, "DELETE FROM workflow_template_tasks WHERE template_task_id = $template_task_id AND workflow_template_id = $workflow_template_id");

    flash_alert("Task removed");
    redirect("workflow_template_details.php?id=$workflow_template_id");
}

if (isset($_POST['move_workflow_template_task_up']) || isset($_POST['move_workflow_template_task_down'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $workflow_template_id = intval($_POST['workflow_template_id']);
    $template_task_id = intval($_POST['template_task_id']);
    $direction = isset($_POST['move_workflow_template_task_up']) ? 'up' : 'down';

    $current = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM workflow_template_tasks WHERE template_task_id = $template_task_id"));

    if ($current) {
        $comparison = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';
        $neighbor = mysqli_fetch_assoc(mysqli_query($mysqli, "
            SELECT * FROM workflow_template_tasks
            WHERE workflow_template_id = $workflow_template_id AND sort_order $comparison {$current['sort_order']}
            ORDER BY sort_order $order LIMIT 1
        "));

        if ($neighbor) {
            $current_id = intval($current['template_task_id']);
            $current_order = intval($current['sort_order']);
            $neighbor_id = intval($neighbor['template_task_id']);
            $neighbor_order = intval($neighbor['sort_order']);

            mysqli_query($mysqli, "UPDATE workflow_template_tasks SET sort_order = $neighbor_order WHERE template_task_id = $current_id");
            mysqli_query($mysqli, "UPDATE workflow_template_tasks SET sort_order = $current_order WHERE template_task_id = $neighbor_id");
        }
    }

    redirect("workflow_template_details.php?id=$workflow_template_id");
}
