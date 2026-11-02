<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use RivetMSP\Mcp\PendingActions;

// Approve / reject an MCP write proposal (GitHub #28). The decision is made by the signed-in technician, as themselves: every
// permission, client-scope and self-approval check happens again inside PendingActions with $session_user_id, not with anything
// the form posts (only the action id and an optional note are read from it).
if (isset($_POST['approve_mcp_action']) || isset($_POST['reject_mcp_action'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $action_id = intval($_POST['action_id'] ?? 0);
    $pending = new PendingActions($mysqli);

    if (isset($_POST['approve_mcp_action'])) {
        $result = $pending->approve($action_id, (int) $session_user_id);
        logAction('MCP', $result['ok'] ? 'Approve' : 'Approve (refused)', "$session_name " . ($result['ok'] ? 'approved' : 'tried to approve') . " MCP action #$action_id: " . $result['message']);
    } else {
        $result = $pending->reject($action_id, (int) $session_user_id, (string) ($_POST['note'] ?? ''));
        logAction('MCP', 'Reject', "$session_name rejected MCP action #$action_id");
    }

    flash_alert($result['message'], $result['ok'] ? 'success' : 'error');
    redirect("mcp_actions.php?action_id=$action_id");
}
