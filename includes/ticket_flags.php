<?php
/*
 * Per-ticket "Count toward SLA" and "Send email notifications" switches, and the RMM ticket defaults behind them (DB 2.6.105).
 *
 *   tickets.ticket_rmm_sourced    1 for every ticket made by createTicketFromRmmAlert() (all four alert-to-ticket paths), else 0.
 *   tickets.ticket_counts_sla     0 = this ticket has no SLA at all: no due dates, never at risk, never breached, left out of SLA numbers.
 *                                 Beats everything else, including the per-status "pauses the SLA clock" flag (see recalculateTicketSla()).
 *   tickets.ticket_send_email     0 = no outbound email for this ticket, whatever triggers it. Enforced in ONE place: addToMailQueue().
 *   tickets.ticket_sla_started_at NULL = the SLA clock runs from ticket_created_at (as before); a time = "start SLA from now" was chosen.
 *
 * New RMM tickets get ticket_counts_sla / ticket_send_email from settings.config_rmm_alert_ticket_sla / config_rmm_alert_ticket_email (both off by
 * default) at creation time only; the per-ticket switches decide from then on. A ticket created from an inbound email always sends email: its
 * switch cannot be turned off by any path in this file (ticketSetSendEmail() refuses, the backfill skips it, addTicket() writes 1 literally).
 *
 * Every function here is safe before DB 2.6.105 has run: ticketFlagsReady() is false and everything behaves exactly as before the feature.
 */

/** Have the DB 2.6.105 columns been added yet? Cached once true (a column is never dropped again). */
function ticketFlagsReady($mysqli): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    $res = @mysqli_query($mysqli, "SHOW COLUMNS FROM tickets LIKE 'ticket_send_email'");
    $ready = (bool) ($res && mysqli_num_rows($res) > 0);

    return $ready;
}

/** The RMM alert ticket defaults from settings: ['sla' => bool, 'email' => bool]. Both false (the safe defaults) before DB 2.6.105. */
function rmmAlertTicketFlagDefaults($mysqli): array
{
    $res = @mysqli_query($mysqli, "SELECT config_rmm_alert_ticket_sla, config_rmm_alert_ticket_email FROM settings WHERE company_id = 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;

    return ['sla' => (int) ($row['config_rmm_alert_ticket_sla'] ?? 0) === 1, 'email' => (int) ($row['config_rmm_alert_ticket_email'] ?? 0) === 1];
}

/**
 * THE email gate (called only from addToMailQueue()): may outbound email about this ticket be queued? True for a ticket that does not exist (nothing
 * to suppress) and before DB 2.6.105. Read fresh every time: the switch can change within one request.
 */
function ticketSendsEmail($mysqli, int $ticket_id): bool
{
    if ($ticket_id <= 0 || !ticketFlagsReady($mysqli)) {
        return true;
    }
    $stmt = mysqli_prepare($mysqli, "SELECT ticket_send_email FROM tickets WHERE ticket_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $ticket_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $row === null || (int) $row['ticket_send_email'] === 1;
}

/** Was this ticket created from an inbound email (addTicket(), ticket_source 'Email')? Those always send email. */
function ticketIsEmailOrigin(array $ticket): bool
{
    return (string) ($ticket['ticket_source'] ?? '') === 'Email';
}

/** One ticket's switch state, source, status name, number and client (or null). */
function ticketFlagsLoad($mysqli, int $ticket_id): ?array
{
    if ($ticket_id <= 0 || !ticketFlagsReady($mysqli)) {
        return null;
    }
    $stmt = mysqli_prepare($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_client_id, ticket_source, ticket_rmm_sourced, ticket_counts_sla,
        ticket_send_email, ticket_sla_started_at, ticket_created_at, ticket_status_name
        FROM tickets LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE ticket_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $ticket_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $row ?: null;
}

/**
 * Audit one switch change the way every other ticket edit is audited (agent/post/ticket.php): a ticket_history row (shown on the ticket's
 * history; its own created_at is the "when") and a logAction() line ("who" is the session user). Prepared statement for the history row.
 */
function ticketFlagAudit($mysqli, array $t, string $description): void
{
    $status = (string) ($t['ticket_status_name'] ?? '');
    $desc = mb_substr($description, 0, 255);
    $tid = (int) $t['ticket_id'];
    $stmt = mysqli_prepare($mysqli, "INSERT INTO ticket_history SET ticket_history_status = ?, ticket_history_description = ?, ticket_history_ticket_id = ?");
    mysqli_stmt_bind_param($stmt, 'ssi', $status, $desc, $tid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    logAction('Ticket', 'Edit', $description . ' for ticket ' . $t['ticket_prefix'] . $t['ticket_number'], (int) $t['ticket_client_id'], $tid);
}

/**
 * Turn a ticket's "Send email notifications" switch on or off. Returns 'changed', 'unchanged', 'refused' (an inbound-email ticket cannot be switched
 * off) or 'missing'. Turning it off also cancels this ticket's mail that is already queued but not sent yet (including failed mail still waiting for a
 * retry), the same way Administration > Mail queue > Cancel does, so a retry can never deliver mail for a ticket whose email is off.
 */
function ticketSetSendEmail($mysqli, int $ticket_id, bool $on, string $actor): string
{
    $t = ticketFlagsLoad($mysqli, $ticket_id);
    if ($t === null) {
        return 'missing';
    }
    $old = (int) $t['ticket_send_email'] === 1;
    if ($old === $on) {
        return 'unchanged';
    }
    if (!$on && ticketIsEmailOrigin($t)) {
        return 'refused';
    }
    $v = $on ? 1 : 0;
    $stmt = mysqli_prepare($mysqli, "UPDATE tickets SET ticket_send_email = ? WHERE ticket_id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $v, $ticket_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $cancelled = $on ? 0 : ticketCancelQueuedMail($mysqli, $ticket_id);
    ticketFlagAudit($mysqli, $t, "$actor turned email notifications " . ($on ? 'on (was off)' : 'off (was on)') . ($cancelled > 0 ? ", cancelling $cancelled queued email(s)" : ''));

    return 'changed';
}

/** Cancel this ticket's unsent mail (queued, or failed with retries left). Returns how many. */
function ticketCancelQueuedMail($mysqli, int $ticket_id): int
{
    $stmt = mysqli_prepare($mysqli, "UPDATE email_queue SET email_status = 2, email_attempts = 99, email_failed_at = NOW()
        WHERE email_ticket_id = ? AND (email_status = 0 OR (email_status = 2 AND email_attempts < 99))");
    mysqli_stmt_bind_param($stmt, 'i', $ticket_id);
    mysqli_stmt_execute($stmt);
    $n = max(0, mysqli_stmt_affected_rows($stmt));
    mysqli_stmt_close($stmt);

    return $n;
}

/**
 * Turn a ticket's "Count toward SLA" switch on or off. $anchor when turning on: 'now' (the SLA clock starts now; time and pauses before now do not
 * count) or 'created' (as if SLA had always been on: from ticket_created_at, with the pauses already recorded). Turning off clears the due dates and
 * the resolved policy (recalculateTicketSla() does that for a ticket with ticket_counts_sla = 0). Returns 'changed', 'unchanged', 'invalid' or 'missing'.
 */
function ticketSetCountsSla($mysqli, int $ticket_id, bool $on, string $anchor, string $actor): string
{
    require_once __DIR__ . '/sla_functions.php';
    $t = ticketFlagsLoad($mysqli, $ticket_id);
    if ($t === null) {
        return 'missing';
    }
    if ($on && !in_array($anchor, ['now', 'created'], true)) {
        return 'invalid';
    }
    $old = (int) $t['ticket_counts_sla'] === 1;
    if ($old === $on) {
        return 'unchanged';
    }
    if ($on) {
        if ($anchor === 'now') {
            // Start the clock now: no paused time before now carries over, and a pause already running restarts its count from now.
            $stmt = mysqli_prepare($mysqli, "UPDATE tickets SET ticket_counts_sla = 1, ticket_sla_started_at = NOW(), ticket_sla_paused_seconds = 0,
                ticket_sla_paused_at = IF(ticket_sla_paused_at IS NULL, NULL, NOW()) WHERE ticket_id = ?");
        } else {
            $stmt = mysqli_prepare($mysqli, "UPDATE tickets SET ticket_counts_sla = 1, ticket_sla_started_at = NULL WHERE ticket_id = ?");
        }
        mysqli_stmt_bind_param($stmt, 'i', $ticket_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        // Pause first (a ticket sitting in a "pauses the SLA" status must not start counting), then the due dates.
        slaSyncPause($mysqli, $ticket_id);
        recalculateTicketSla($mysqli, $ticket_id);
        ticketFlagAudit($mysqli, $t, "$actor turned Count toward SLA on (was off), " . ($anchor === 'now' ? 'starting the SLA from now' : 'starting the SLA from ticket creation'));
    } else {
        $stmt = mysqli_prepare($mysqli, "UPDATE tickets SET ticket_counts_sla = 0 WHERE ticket_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $ticket_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        recalculateTicketSla($mysqli, $ticket_id);
        ticketFlagAudit($mysqli, $t, "$actor turned Count toward SLA off (was on), clearing the SLA due dates");
    }

    return 'changed';
}

/**
 * Which existing tickets the RMM backfill treats as RMM alert tickets: a ticket an RMM alert links to (rmm_alerts.ticket_id is written ONLY by
 * createTicketFromRmmAlert()), or one whose ticket_source is one of the three fixed RMM values createTicketFromRmmAlert()'s callers pass. Not the
 * "Automation: <rule>" source: event-bus automation rules (includes/event_bus.php) write that for tickets that have nothing to do with RMM, while
 * an automation rule's RMM ticket is caught by the rmm_alerts link. Never an inbound-email ticket.
 */
function rmmTicketBackfillWhereSql(): string
{
    return "(ticket_id IN (SELECT ticket_id FROM rmm_alerts WHERE ticket_id IS NOT NULL) OR ticket_source IN ('RMM Alert', 'RMM Automation', 'RMM Escalation'))
        AND (ticket_source IS NULL OR ticket_source <> 'Email')";
}

/**
 * Label existing RMM alert tickets: ticket_rmm_sourced = 1 and ticket_send_email = 0. LABEL ONLY: nothing SLA-related is read or written (no
 * counts_sla, due dates, pauses), so no timer of an old ticket changes. $dry counts what would change and writes nothing.
 * @return array{matched:int, label:int, email:int, cancelled:int}
 */
function rmmTicketDefaultsBackfill($mysqli, bool $dry): array
{
    $out = ['matched' => 0, 'label' => 0, 'email' => 0, 'cancelled' => 0];
    if (!ticketFlagsReady($mysqli)) {
        return $out;
    }
    $w = rmmTicketBackfillWhereSql();
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS m, SUM(ticket_rmm_sourced = 0) AS l, SUM(ticket_send_email = 1) AS e FROM tickets WHERE $w"));
    $out['matched'] = (int) ($row['m'] ?? 0);
    $out['label'] = (int) ($row['l'] ?? 0);
    $out['email'] = (int) ($row['e'] ?? 0);
    if ($dry) {
        return $out;
    }
    $ids = [];
    $res = mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE $w AND ticket_send_email = 1");
    while ($res && ($r = mysqli_fetch_row($res))) {
        $ids[] = (int) $r[0];
    }
    mysqli_query($mysqli, "UPDATE tickets SET ticket_rmm_sourced = 1 WHERE $w AND ticket_rmm_sourced = 0");
    mysqli_query($mysqli, "UPDATE tickets SET ticket_send_email = 0 WHERE $w AND ticket_send_email = 1");
    foreach ($ids as $id) {
        $out['cancelled'] += ticketCancelQueuedMail($mysqli, $id);
    }

    return $out;
}
