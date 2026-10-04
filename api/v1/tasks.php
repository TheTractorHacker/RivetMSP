<?php
// POST /api/v1/tasks/{id}/toggle    toggle a ticket task's completed state
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';

if ($method !== 'POST' || $id === null || $sub !== 'toggle') api_error(404, 'Not found');

$uid = $api_user_id;
api_require_module_permission($mysqli, $uid, 'module_support', 2);

// MSP edition: every task belongs to a ticket; its client is the ticket's client.
// A task with no ticket has no owner to scope against, so it is not reachable here.
$row = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT tasks.task_id, tasks.task_completed_at, t.ticket_client_id AS owner_client_id
     FROM tasks
     JOIN tickets t ON t.ticket_id = tasks.task_ticket_id
     WHERE tasks.task_id = $id LIMIT 1"
));
if (!$row) api_error(404, 'Task not found');

// Always checked, including client 0: a scoped user/key has no permission for it.
if (!api_client_scope_ok(intval($row['owner_client_id']))) api_error(403, 'Access denied');

if ($row['task_completed_at'] === null) {
    mysqli_query($mysqli, "UPDATE tasks SET task_completed_at = NOW(), task_completed_by = $uid WHERE task_id = $id");
} else {
    mysqli_query($mysqli, "UPDATE tasks SET task_completed_at = NULL, task_completed_by = NULL WHERE task_id = $id");
}

$updated = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT tasks.task_id, tasks.task_name, tasks.task_status, tasks.task_completed_at,
            tt.ticket_prefix, tt.ticket_number, u.user_name AS assigned_to_name
     FROM tasks
     LEFT JOIN tickets tt ON tt.ticket_id = tasks.task_ticket_id
     LEFT JOIN users u ON u.user_id = tt.ticket_assigned_to
     WHERE tasks.task_id = $id LIMIT 1"
));

api_response(200, [
    'id'            => intval($updated['task_id']),
    'name'          => $updated['task_name'],
    'status'        => $updated['task_status'],
    'progress'      => $updated['task_completed_at'] !== null ? 100 : 0,
    'due_at'        => null,
    'start_at'      => null,
    'milestone_id'  => null,
    'completed_at'  => $updated['task_completed_at'],
    'assigned_to'   => $updated['assigned_to_name'],
    'ticket_number' => $updated['ticket_number'] !== null ? $updated['ticket_prefix'] . $updated['ticket_number'] : null,
]);
