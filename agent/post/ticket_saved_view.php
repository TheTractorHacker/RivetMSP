<?php

// Ticket Saved Views (left sidebar on the ticket dashboard)

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../includes/ticket_view_filters.php';

/** Back to the page the agent came from (an explicit filter URL, so their default view is not re-applied over it). */
function ticketViewBack(): void
{
    redirect(!empty($_SERVER['HTTP_REFERER']) ? null : 'tickets.php?status=Open');
}

/** Can this agent edit the view? (their own, or a shared one when they have full support access.) Archived views are not editable. */
function ticketViewOwnedBy($mysqli, int $view_id, int $uid): bool
{
    $r = mysqli_query($mysqli, "SELECT ticket_saved_view_user_id FROM ticket_saved_views WHERE ticket_saved_view_id = $view_id AND ticket_saved_view_archived_at IS NULL LIMIT 1");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    if (!$row) {
        return false;
    }
    $owner = (int) $row['ticket_saved_view_user_id'];

    return $owner === $uid || ($owner === 0 && lookupUserPermission("module_support") === 3);
}

/**
 * The "Make this my default view" / "Default for everyone without their own" checkboxes of the add and edit forms.
 * Only acts when the form says it carries them (default_present), so a bare rename leaves defaults alone.
 */
function ticketViewApplyDefaultChoices($mysqli, int $view_id, string $name, int $uid): void
{
    global $session_name;
    if (!isset($_POST['default_present'])) {
        return;
    }
    $existing = ticketViewDefaultIds($mysqli, $uid);

    if (!empty($_POST['make_default'])) {
        if ($existing['personal'] !== $view_id && ticketViewDefaultSet($mysqli, $uid, $view_id, false)) {
            logAction("Ticket View", "Default", "$session_name made saved ticket view $name their default view", 0, $view_id);
        }
    } elseif ($existing['personal'] === $view_id) {
        ticketViewDefaultClear($mysqli, $uid, false, $view_id);
        logAction("Ticket View", "Default", "$session_name cleared their default ticket view ($name)", 0, $view_id);
    }

    // The shared default needs full support access and a shared view; anyone else's tick (or a forged post) is ignored.
    if (lookupUserPermission("module_support") === 3) {
        if (!empty($_POST['make_default_shared'])) {
            if ($existing['shared'] !== $view_id && ticketViewDefaultSet($mysqli, $uid, $view_id, true)) {
                logAction("Ticket View", "Default", "$session_name made saved ticket view $name the shared default view", 0, $view_id);
            }
        } elseif ($existing['shared'] === $view_id) {
            ticketViewDefaultClear($mysqli, $uid, true, $view_id);
            logAction("Ticket View", "Default", "$session_name cleared the shared default ticket view ($name)", 0, $view_id);
        }
    }
}

if (isset($_POST['add_ticket_saved_view'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $name = sanitizeInput($_POST['name']);
    $icon = \RivetCore\Ui\IconCatalog::normalize($_POST['icon'] ?? '', 'fa-filter');
    // The filter form (validated, whitelisted) when present; otherwise the raw current-filters query as before.
    $query = isset($_POST['filters_present'])
        ? mysqli_real_escape_string($mysqli, ticketViewQueryFromPost($mysqli, $_POST))
        : mysqli_real_escape_string($mysqli, trim($_POST['query'] ?? '', '&'));

    // Full support access may share a view with every agent (and making it the shared default implies sharing it).
    $shared = ((intval($_POST['shared'] ?? 0) === 1 || !empty($_POST['make_default_shared'])) && lookupUserPermission("module_support") === 3) ? 0 : $session_user_id;

    $sql_order = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COALESCE(MAX(ticket_saved_view_order), -1) + 1 AS next_order FROM ticket_saved_views WHERE ticket_saved_view_user_id = $shared"));
    $order = intval($sql_order['next_order']);

    mysqli_query(
        $mysqli,
        "INSERT INTO ticket_saved_views SET
            ticket_saved_view_name = '$name',
            ticket_saved_view_icon = '$icon',
            ticket_saved_view_query = '$query',
            ticket_saved_view_user_id = $shared,
            ticket_saved_view_order = $order"
    );
    $new_view_id = mysqli_insert_id($mysqli);

    logAction("Ticket View", "Create", "$session_name created saved ticket view $name", 0, $new_view_id);

    ticketViewApplyDefaultChoices($mysqli, $new_view_id, $name, $session_user_id);

    flash_alert("Saved view <strong>$name</strong> created");

    ticketViewBack();

}

if (isset($_POST['edit_ticket_saved_view'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_saved_view_id = intval($_POST['ticket_saved_view_id']);
    $name = sanitizeInput($_POST['name']);
    $icon = \RivetCore\Ui\IconCatalog::normalize($_POST['icon'] ?? '', 'fa-filter');

    $owner_query = (lookupUserPermission("module_support") === 3)
        ? "(ticket_saved_view_user_id = 0 OR ticket_saved_view_user_id = $session_user_id)"
        : "ticket_saved_view_user_id = $session_user_id";

    // Only a submitted filter form changes the view's filters; a plain rename leaves them alone.
    $query_sql = isset($_POST['filters_present'])
        ? ", ticket_saved_view_query = '" . mysqli_real_escape_string($mysqli, ticketViewQueryFromPost($mysqli, $_POST)) . "'"
        : '';

    mysqli_query(
        $mysqli,
        "UPDATE ticket_saved_views SET
            ticket_saved_view_name = '$name',
            ticket_saved_view_icon = '$icon'$query_sql
         WHERE ticket_saved_view_id = $ticket_saved_view_id AND $owner_query"
    );

    if (ticketViewOwnedBy($mysqli, $ticket_saved_view_id, $session_user_id)) {
        ticketViewApplyDefaultChoices($mysqli, $ticket_saved_view_id, $name, $session_user_id);
    }

    flash_alert("Saved view <strong>$name</strong> updated");

    ticketViewBack();

}

if (isset($_GET['delete_ticket_saved_view'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_saved_view_id = intval($_GET['delete_ticket_saved_view']);

    $owner_query = (lookupUserPermission("module_support") === 3)
        ? "(ticket_saved_view_user_id = 0 OR ticket_saved_view_user_id = $session_user_id)"
        : "ticket_saved_view_user_id = $session_user_id";

    mysqli_query($mysqli, "UPDATE ticket_saved_views SET ticket_saved_view_archived_at = NOW() WHERE ticket_saved_view_id = $ticket_saved_view_id AND $owner_query");

    // A deleted view cannot stay anyone's default (resolution would skip it anyway; this keeps the table tidy).
    if (mysqli_affected_rows($mysqli) > 0) {
        ticketViewDefaultForgetView($mysqli, $ticket_saved_view_id);
        logAction("Ticket View", "Delete", "$session_name deleted saved ticket view #$ticket_saved_view_id", 0, $ticket_saved_view_id);
    }

    flash_alert("Saved view deleted", 'error');

    ticketViewBack();

}

if (isset($_GET['move_ticket_saved_view_up']) || isset($_GET['move_ticket_saved_view_down'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $direction = isset($_GET['move_ticket_saved_view_up']) ? 'up' : 'down';
    $ticket_saved_view_id = intval($_GET[$direction == 'up' ? 'move_ticket_saved_view_up' : 'move_ticket_saved_view_down']);

    $owner_query = (lookupUserPermission("module_support") === 3)
        ? "(ticket_saved_view_user_id = 0 OR ticket_saved_view_user_id = $session_user_id)"
        : "ticket_saved_view_user_id = $session_user_id";

    $current = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM ticket_saved_views WHERE ticket_saved_view_id = $ticket_saved_view_id AND $owner_query AND ticket_saved_view_archived_at IS NULL"));

    if ($current) {
        $current_order = intval($current['ticket_saved_view_order']);
        $current_owner = intval($current['ticket_saved_view_user_id']);
        $comparator = $direction == 'up' ? '<' : '>';
        $sort_dir = $direction == 'up' ? 'DESC' : 'ASC';

        $neighbor = mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT * FROM ticket_saved_views
             WHERE ticket_saved_view_user_id = $current_owner
             AND ticket_saved_view_archived_at IS NULL
             AND ticket_saved_view_order $comparator $current_order
             ORDER BY ticket_saved_view_order $sort_dir
             LIMIT 1"
        ));

        if ($neighbor) {
            $neighbor_id = intval($neighbor['ticket_saved_view_id']);
            $neighbor_order = intval($neighbor['ticket_saved_view_order']);

            mysqli_query($mysqli, "UPDATE ticket_saved_views SET ticket_saved_view_order = $neighbor_order WHERE ticket_saved_view_id = $ticket_saved_view_id");
            mysqli_query($mysqli, "UPDATE ticket_saved_views SET ticket_saved_view_order = $current_order WHERE ticket_saved_view_id = $neighbor_id");
        }
    }

    ticketViewBack();

}

if (isset($_GET['set_ticket_view_default']) || isset($_GET['clear_ticket_view_default'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 1);

    $setting = isset($_GET['set_ticket_view_default']);
    $view_id = intval($_GET[$setting ? 'set_ticket_view_default' : 'clear_ticket_view_default']);
    $shared = (($_GET['scope'] ?? 'personal') === 'shared');

    // The shared default is an administrative choice: full support access only.
    if ($shared) {
        enforceUserPermission('module_support', 3);
    }

    $view = ticketViewUsable($mysqli, $view_id, $session_user_id);
    $label = $view ? sanitizeInput($view['ticket_saved_view_name']) : "#$view_id";

    if ($setting) {
        if (ticketViewDefaultSet($mysqli, $session_user_id, $view_id, $shared)) {
            logAction("Ticket View", "Default", "$session_name made saved ticket view $label " . ($shared ? 'the shared default view' : 'their default view'), 0, $view_id);
            flash_alert($shared ? "<strong>$label</strong> is now the shared default view" : "<strong>$label</strong> is now your default view");
        } else {
            flash_alert("That view cannot be used as a default", 'error');
        }
    } else {
        ticketViewDefaultClear($mysqli, $session_user_id, $shared);
        logAction("Ticket View", "Default", "$session_name cleared " . ($shared ? 'the shared default ticket view' : 'their default ticket view'), 0, $view_id);
        flash_alert($shared ? "Shared default view cleared" : "Your default view cleared");
    }

    // Back to the page the agent clicked on (an explicit filter URL, so the default is not re-applied over it).
    ticketViewBack();

}
