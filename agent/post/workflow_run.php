<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../../includes/core_module_gates.php';

/**
 * Loads a run (with the client it belongs to) and enforces access; common to every action below.
 * A run is for a whole client (contact_id = 0) or for one of its contacts. Older runs have no client_id and fall back
 * to the contact's client.
 */
function loadWorkflowRunOrDie($mysqli, int $run_id): array {
    $run = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT wr.*, c.contact_name, COALESCE(wr.client_id, c.contact_client_id) AS run_client_id, clients.client_name
        FROM workflow_runs wr
        LEFT JOIN contacts c ON c.contact_id = wr.contact_id
        LEFT JOIN clients ON clients.client_id = COALESCE(wr.client_id, c.contact_client_id)
        WHERE wr.run_id = $run_id"));
    if (!$run) {
        flash_alert('Workflow run not found', 'error');
        redirect('workflow_runs.php');
        exit;
    }
    enforceClientAccess(intval($run['run_client_id']));
    return $run;
}

/** "Acme Corp" or "Acme Corp / Jane Doe" - how audit and log lines name the subject of a run. */
function workflowRunSubject(array $run): string {
    return ($run['client_name'] ?? 'client') . (!empty($run['contact_id']) ? ' / ' . ($run['contact_name'] ?? 'contact') : '');
}

/** Records the completion once, when a task action moves a run out of in_progress. */
function workflowRecordCompletionIfDone($mysqli, array $run_before, int $session_user_id, string $session_name): void {
    $run_id = intval($run_before['run_id']);
    $now = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT status FROM workflow_runs WHERE run_id = $run_id"));
    if ($run_before['status'] === 'in_progress' && $now && in_array($now['status'], ['completed', 'completed_with_exceptions'], true)) {
        $subject = workflowRunSubject($run_before);
        rivetAudit('workflow.completed', $session_user_id, 'workflow_run', $run_id, 'completed', "$session_name completed the {$run_before['type']} workflow for $subject", [
            'run_id' => $run_id, 'type' => $run_before['type'], 'status' => $now['status'],
            'client_id' => intval($run_before['run_client_id']), 'contact_id' => intval($run_before['contact_id']),
        ]);
        logAction("Client", "Edit", "$session_name completed the {$run_before['type']} workflow for $subject", intval($run_before['run_client_id']), intval($run_before['contact_id']));
    }
}

if (isset($_POST['start_client_workflow'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $workflow_template_id = intval($_POST['workflow_template_id'] ?? 0);
    $client_id = intval($_POST['client_id'] ?? 0);
    $contact_id = intval($_POST['contact_id'] ?? 0);

    $template = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM workflow_templates WHERE workflow_template_id = $workflow_template_id AND archived_at IS NULL AND is_active = 1"));
    if (!$template) {
        flash_alert('Workflow template not found', 'error');
        redirect('workflow_runs.php');
    }

    $contact = null;
    if ($contact_id) {
        $contact = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_id, contact_name, contact_client_id FROM contacts WHERE contact_id = $contact_id AND contact_archived_at IS NULL"));
        if (!$contact || ($client_id && intval($contact['contact_client_id']) !== $client_id)) {
            flash_alert('That contact does not belong to the selected client', 'error');
            redirect('workflow_runs.php');
        }
        $client_id = intval($contact['contact_client_id']);
    }

    $client = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_id = $client_id AND client_archived_at IS NULL"));
    if (!$client) {
        flash_alert('Select a client', 'error');
        redirect('workflow_runs.php');
    }
    enforceClientAccess($client_id);

    $run_id = rivetWorkflows()->startRun($workflow_template_id, $contact_id, $session_user_id);
    mysqli_query($mysqli, "UPDATE workflow_runs SET client_id = $client_id WHERE run_id = $run_id");

    $subject = $client['client_name'] . ($contact ? ' / ' . $contact['contact_name'] : '');
    rivetAudit(
        $template['type'] === 'onboarding' ? 'workflow.onboarding_started' : 'workflow.offboarding_started',
        $session_user_id, 'workflow_run', $run_id, 'started',
        "$session_name started workflow \"{$template['name']}\" for $subject",
        ['run_id' => $run_id, 'client_id' => $client_id, 'contact_id' => $contact_id, 'template_id' => $workflow_template_id]
    );
    logAction("Client", "Edit", "$session_name started workflow \"{$template['name']}\" for $subject", $client_id, $contact_id);

    flash_alert('Workflow started');
    redirect("workflow_run.php?run_id=$run_id");
}

if (isset($_POST['complete_workflow_run_task'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $run_id = intval($_POST['run_id']);
    $run = loadWorkflowRunOrDie($mysqli, $run_id);
    $run_task_id = intval($_POST['run_task_id']);
    // The task must belong to this run, and a cancelled run is frozen.
    $task = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT title FROM workflow_run_tasks WHERE run_task_id = $run_task_id AND run_id = $run_id"));
    if (!$task || $run['status'] === 'cancelled') {
        flash_alert('Task not found or workflow cancelled', 'error');
        redirect("workflow_run.php?run_id=$run_id");
    }

    rivetWorkflows()->completeTask($run_task_id, $session_user_id);

    $subject = workflowRunSubject($run);
    logAction("Client", "Edit", "$session_name completed workflow task \"{$task['title']}\" for $subject", intval($run['run_client_id']), intval($run['contact_id']));
    rivetEmitEvent('workflow.task_completed', ['run_id' => $run_id, 'run_task_id' => $run_task_id, 'task' => $task['title'], 'type' => $run['type'], 'client_id' => intval($run['run_client_id']), 'contact_id' => intval($run['contact_id']), 'actor_user_id' => $session_user_id]);
    workflowRecordCompletionIfDone($mysqli, $run, $session_user_id, $session_name);

    redirect("workflow_run.php?run_id=$run_id");
}

if (isset($_POST['skip_workflow_run_task'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $run_id = intval($_POST['run_id']);
    $run = loadWorkflowRunOrDie($mysqli, $run_id);
    $run_task_id = intval($_POST['run_task_id']);
    $reason = trim((string) ($_POST['skip_reason'] ?? ''));
    $task = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT title FROM workflow_run_tasks WHERE run_task_id = $run_task_id AND run_id = $run_id"));
    if (!$task || $run['status'] === 'cancelled' || $reason === '') {
        flash_alert('A reason is required to skip a task', 'error');
        redirect("workflow_run.php?run_id=$run_id");
    }

    rivetWorkflows()->skipTask($run_task_id, mb_substr($reason, 0, 500), $session_user_id);

    $subject = workflowRunSubject($run);
    logAction("Client", "Edit", "$session_name skipped workflow task \"{$task['title']}\" for $subject: $reason", intval($run['run_client_id']), intval($run['contact_id']));
    workflowRecordCompletionIfDone($mysqli, $run, $session_user_id, $session_name);

    redirect("workflow_run.php?run_id=$run_id");
}

if (isset($_POST['reopen_workflow_run_task'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $run_id = intval($_POST['run_id']);
    $run = loadWorkflowRunOrDie($mysqli, $run_id);
    $run_task_id = intval($_POST['run_task_id']);
    $task = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT title FROM workflow_run_tasks WHERE run_task_id = $run_task_id AND run_id = $run_id"));
    if (!$task || $run['status'] === 'cancelled') {
        flash_alert('Task not found or workflow cancelled', 'error');
        redirect("workflow_run.php?run_id=$run_id");
    }

    rivetWorkflows()->reopenTask($run_task_id);
    logAction("Client", "Edit", "$session_name reopened workflow task \"{$task['title']}\" for " . workflowRunSubject($run), intval($run['run_client_id']), intval($run['contact_id']));

    redirect("workflow_run.php?run_id=$run_id");
}

if (isset($_POST['cancel_workflow_run'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $run_id = intval($_POST['run_id']);
    $run = loadWorkflowRunOrDie($mysqli, $run_id);

    rivetWorkflows()->cancelRun($run_id);

    $subject = workflowRunSubject($run);
    rivetAudit('workflow.cancelled', $session_user_id, 'workflow_run', $run_id, 'cancelled', "$session_name cancelled the {$run['type']} workflow for $subject", ['run_id' => $run_id, 'client_id' => intval($run['run_client_id']), 'contact_id' => intval($run['contact_id'])]);
    logAction("Client", "Edit", "$session_name cancelled the {$run['type']} workflow for $subject", intval($run['run_client_id']), intval($run['contact_id']));

    flash_alert('Workflow cancelled');
    redirect("workflow_run.php?run_id=$run_id");
}
