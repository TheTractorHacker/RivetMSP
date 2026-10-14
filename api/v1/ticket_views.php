<?php
// GET /api/v1/ticket-views   list saved ticket views, translated to /api/v1/tickets-compatible params
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
require_once __DIR__ . '/../../agent/includes/ticket_view_filters.php';
if ($method !== 'GET') api_error(405, 'Method not allowed');

$uid = intval($api_user_id);

// Saved views are an agent's own tool: they need read access to the support module, and a key locked to one client never sees them.
api_require_module_permission($mysqli, $uid, 'module_support', 1);
if (!empty($api_key_client_id)) api_error(403, 'Access denied');

// The agent's default view (personal, else the shared one), if it is still usable.
$default = ticketViewDefaultResolve($mysqli, $uid);
$default_id = $default ? intval($default['view']['ticket_saved_view_id']) : 0;

$views = [];
$sql = mysqli_query($mysqli,
    "SELECT ticket_saved_view_id, ticket_saved_view_name, ticket_saved_view_icon, ticket_saved_view_query
     FROM ticket_saved_views
     WHERE (ticket_saved_view_user_id = 0 OR ticket_saved_view_user_id = $uid)
     AND ticket_saved_view_archived_at IS NULL
     ORDER BY ticket_saved_view_user_id ASC, ticket_saved_view_order ASC"
);
while ($row = mysqli_fetch_assoc($sql)) {
    $q = ticketViewParseQuery($row['ticket_saved_view_query']);
    $f = ticketFiltersNormalize($q);

    // All values are strings so the Android client can deserialize this as Map<String, String>. A filter with several values is a
    // comma list (priority=high,medium), which GET /tickets accepts.
    $params = [];
    $params['status'] = ['closed' => 'closed', 'all' => 'all'][$f['status']['mode']] ?? 'open';
    if ($f['status']['mode'] === 'specific') {
        $params['status'] = 'all';
        $params['status_id'] = implode(',', $f['status']['ids']);
    }
    if ($f['assigned'] === ['me']) {
        $params['mine'] = '1';
    } elseif ($f['assigned']) {
        $params['assigned'] = implode(',', $f['assigned']);
    }
    if ($f['onsite']) {
        $params['onsite'] = implode(',', $f['onsite']);
    }
    if ($f['priority']) {
        $params['priority'] = strtolower(implode(',', $f['priority']));
    }
    if ($f['category']) {
        $params['category_id'] = implode(',', $f['category']);
    }
    if ($f['board']) {
        $params['board_id'] = implode(',', $f['board']);
    }
    if ($f['tags']) {
        $params['tag_id'] = implode(',', $f['tags']);
    }
    if (isset($q['search']) && is_scalar($q['search']) && $q['search'] !== '') {
        $params['search'] = (string) $q['search'];
    }
    if ($f['overdue']) $params['overdue'] = '1';
    if ($f['due_today']) $params['due_today'] = '1';

    $views[] = [
        'id'     => intval($row['ticket_saved_view_id']),
        'name'   => $row['ticket_saved_view_name'],
        'icon'   => $row['ticket_saved_view_icon'],
        'params' => $params,
        'is_default' => intval($row['ticket_saved_view_id']) === $default_id,
    ];
}

api_response(200, $views);
