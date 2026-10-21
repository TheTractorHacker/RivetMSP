<?php
/*
 * Per-ticket "Count toward SLA" and "Send email notifications" switches (DB 2.6.105), from the ticket page and the ticket list's bulk action.
 * Same permission as changing a ticket's status or priority: module_support level 2 (edit), plus access to the ticket's own client (looked up
 * from the ticket, never trusted from the form). Client-portal users never reach agent/post.php. The work and the audit trail are in
 * includes/ticket_flags.php.
 */
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/** '1' => true, '0' => false, anything else (missing, array, other text) => null. */
function ticketFlagPostBool($v): ?bool
{
    if ($v === '1' || $v === 1) {
        return true;
    }
    if ($v === '0' || $v === 0) {
        return false;
    }

    return null;
}

if (isset($_POST['set_ticket_counts_sla']) || isset($_POST['set_ticket_send_email'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ticket_flags.php';

    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
    if ($client_id) {
        enforceClientAccess($client_id);
    }

    $on = ticketFlagPostBool($_POST['value'] ?? null);
    if ($on === null || !ticketFlagsReady($mysqli)) {
        flash_alert('Nothing was changed.', 'error');
        redirect();
    }

    if (isset($_POST['set_ticket_counts_sla'])) {
        $anchor = is_string($_POST['sla_anchor'] ?? null) ? $_POST['sla_anchor'] : '';
        $result = ticketSetCountsSla($mysqli, $ticket_id, $on, $anchor, $session_name);
        $messages = [
            'changed'   => $on ? ($anchor === 'now' ? 'This ticket now counts toward SLA, starting now.' : 'This ticket now counts toward SLA, from when it was created.') : 'This ticket no longer counts toward SLA.',
            'unchanged' => 'Nothing to change.',
            'invalid'   => 'Choose when the SLA starts: now or from ticket creation.',
            'missing'   => 'Ticket not found.',
        ];
    } else {
        $result = ticketSetSendEmail($mysqli, $ticket_id, $on, $session_name);
        $messages = [
            'changed'   => $on ? 'Email notifications are on for this ticket.' : 'Email notifications are off for this ticket. No email about it will be sent.',
            'unchanged' => 'Nothing to change.',
            'refused'   => 'This ticket was created from an email, so its email notifications always stay on.',
            'missing'   => 'Ticket not found.',
        ];
    }
    if ($result === 'changed') {
        customAction('ticket_update', $ticket_id);
    }
    flash_alert($messages[$result] ?? 'Nothing was changed.', $result === 'changed' || $result === 'unchanged' ? 'success' : 'error');
    redirect();
}

if (isset($_POST['bulk_set_ticket_send_email'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ticket_flags.php';

    $on = ticketFlagPostBool($_POST['bulk_send_email'] ?? null);
    $ids = is_array($_POST['ticket_ids'] ?? null) ? array_slice(array_values(array_unique(array_map('intval', $_POST['ticket_ids']))), 0, 500) : [];
    if ($on === null || $ids === [] || !ticketFlagsReady($mysqli)) {
        flash_alert('Nothing was changed.', 'error');
        redirect();
    }

    $count = ['changed' => 0, 'unchanged' => 0, 'refused' => 0, 'missing' => 0];
    foreach ($ids as $ticket_id) {
        $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
        if ($client_id) {
            enforceClientAccess($client_id);
        }
        $r = ticketSetSendEmail($mysqli, $ticket_id, $on, $session_name);
        $count[$r] = ($count[$r] ?? 0) + 1;
        if ($r === 'changed') {
            customAction('ticket_update', $ticket_id);
        }
    }

    logAction('Ticket', 'Bulk Edit', "$session_name turned email notifications " . ($on ? 'on' : 'off') . " for {$count['changed']} ticket(s)" . ($count['refused'] ? ", skipped {$count['refused']} created from email" : ''));
    $msg = "Email notifications turned <strong>" . ($on ? 'on' : 'off') . "</strong> for <strong>{$count['changed']}</strong> ticket(s).";
    if ($count['refused']) {
        $msg .= " {$count['refused']} ticket(s) created from an email were skipped: their email always stays on.";
    }
    flash_alert($msg);
    redirect();
}
