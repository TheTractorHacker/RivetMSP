<?php

// Default Column Sortby Filter
$sort = "ticket_number";
$order = "DESC";

// Opening Tickets with no filter in the URL loads the agent's default saved view (personal, else the shared one). It is applied
// as a redirect to the same page with the view's filters in the URL, so what you see is always what the address bar says: an explicit
// filter or a stored view link wins, "Show all open" is just ?status=Open, and "Reset to my default" is ?default=1.
// The session is needed first, so the same includes inc_all.php would load are loaded here (they are require_once).
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once __DIR__ . '/includes/ticket_view_filters.php';

if (!isset($_GET['client_id']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && lookupUserPermission('module_support') >= 1) {
    $_tv_force = (($_GET['default'] ?? '') === '1');
    if ($_tv_force || !ticketFiltersAreExplicit($_GET)) {
        $_tv_default = ticketViewDefaultResolve($mysqli, intval($session_user_id));
        if ($_tv_default || $_tv_force) {
            $_tv_params = $_tv_default ? ticketViewHrefParams((string) $_tv_default['view']['ticket_saved_view_query']) : ['status' => 'Open'];
            if (isset($_GET['view']) && is_string($_GET['view']) && in_array($_GET['view'], ['list', 'kanban'], true)) {
                $_tv_params['view'] = $_GET['view'];
            }
            header('Location: tickets.php?' . ticketFiltersBuildQuery($_tv_params), true, 302);
            exit;
        }
    }
}

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_query = "AND ticket_client_id = $client_id";
    $client_url = "client_id=$client_id&";
} else {
    require_once "includes/inc_all.php";
    $client_query = '';
    $client_url = '';
}

// Perms
enforceUserPermission('module_support');

// The ticket filters, however the URL spelled them (old single values or the new lists), and the SQL for them: OR inside a
// group, AND between groups, integers and enum members only. ticket_list.php, ticket_kanban.php and the counters read these.
$ticket_filters = ticketFiltersNormalize($_GET, intval($session_user_id));
$_tf_sql = ticketFiltersSql($ticket_filters, intval($session_user_id));

$status_filter_id = $ticket_filters['status']['ids'][0] ?? '';
$status = ['open' => 'Open', 'closed' => 'Closed', 'all' => 'All', 'specific' => ''][$ticket_filters['status']['mode']];
$ticket_status_snippet = $_tf_sql['status'];

if (isset($_GET['billable']) && ($_GET['billable']) == '1') {
    if (isset($_GET['unbilled'])) {
        $billable = 1;
        $ticket_billable_snippet = "AND ticket_billable = 1 AND ticket_invoice_id = 0";
        $ticket_status_snippet = '1 = 1';
    }
} else {
    $billable = 0;
    $ticket_billable_snippet = '';
}

// Category, Board, Priority, On-site / Remote, Due (Overdue / Due today) and Assigned to: all from $_tf_sql
$category_query = $_tf_sql['category'];
$board_query = $_tf_sql['board'];
$priority_query = $_tf_sql['priority'];
$onsite_query = $_tf_sql['onsite'];
$stat_query = $_tf_sql['due'];
$ticket_assigned_query = $_tf_sql['assigned'];
$board_filter = count($ticket_filters['board']) === 1 ? $ticket_filters['board'][0] : '';
$onsite_filter = $ticket_filters['onsite'] === ['1'] ? 1 : ($ticket_filters['onsite'] === ['0'] ? 0 : '');

// Project filter
// Default - any (including tickets without a project)
$ticket_project_snippet = '';
$ticket_project_filter_id = '';
if (isset($_GET['project']) & !empty($_GET['project']) && $_GET['project'] > '0') {
    $ticket_project_snippet = 'AND ticket_project_id = ' . intval($_GET['project']);
    $ticket_project_filter_id = intval($_GET['project']);
}

// Tags Filter
$ticket_tag_filter = $ticket_filters['tags'];
$ticket_tag_query = $_tf_sql['tags'];

// Ticket client access overide - This is the only way to show tickets without a client to agents with restricted client access
$access_permission_query_overide = '';
if ($client_access_string) {
    $access_permission_query_overide = "AND ticket_client_id IN (0,$client_access_string)";
}

// Date filter: which ticket date column the range applies to (whitelisted; created by default) and the resulting
// sargable condition. $date_range was resolved by includes/filter_header.php.
$datefield = ticketDateFieldFromRequest($_GET);
$ticket_date_sql = dateRangeSqlBetween(ticketDateFields()[$datefield]['column'], $date_range);

// Main ticket query:
$query =
    "SELECT SQL_CALC_FOUND_ROWS * FROM tickets
    LEFT JOIN clients ON ticket_client_id = client_id
    LEFT JOIN contacts ON ticket_contact_id = contact_id
    LEFT JOIN users ON ticket_assigned_to = user_id
    LEFT JOIN assets ON ticket_asset_id = asset_id
    LEFT JOIN locations ON ticket_location_id = location_id
    LEFT JOIN vendors ON ticket_vendor_id = vendor_id
    LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
    LEFT JOIN categories ON ticket_category = category_id
    WHERE $ticket_status_snippet " . $ticket_assigned_query . "
    $category_query
    $board_query
    $priority_query
    $onsite_query
    $stat_query
    AND $ticket_date_sql
    AND (CONCAT(ticket_prefix,ticket_number) LIKE '%$q%' OR client_name LIKE '%$q%' OR ticket_subject LIKE '%$q%' OR ticket_status_name LIKE '%$q%' OR ticket_priority LIKE '%$q%' OR user_name LIKE '%$q%' OR contact_name LIKE '%$q%' OR asset_name LIKE '%$q%' OR vendor_name LIKE '%$q%' OR ticket_vendor_ticket_number LIKE '%q%')
    $ticket_billable_snippet
    $ticket_project_snippet
    $ticket_tag_query
    $access_permission_query_overide
    $client_query
    ORDER BY
        COALESCE(category_name, '') ASC,
        CASE
            WHEN '$sort' = 'ticket_priority' THEN
                CASE ticket_priority
                    WHEN 'High' THEN 1
                    WHEN 'Medium' THEN 2
                    WHEN 'Low' THEN 3
                    ELSE 4
                END
            ELSE NULL
        END $order,
        $sort $order
    LIMIT $record_from, $record_to";

$sql = mysqli_query($mysqli,$query);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

//Get Total tickets open
$sql_total_tickets_open = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_open FROM tickets WHERE ticket_resolved_at IS NULL $client_query $access_permission_query_overide");
$row = mysqli_fetch_assoc($sql_total_tickets_open);
$total_tickets_open = intval($row['total_tickets_open']);

//Get Total tickets closed
$sql_total_tickets_closed = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_closed FROM tickets WHERE ticket_resolved_at IS NOT NULL $client_query $access_permission_query_overide");
$row = mysqli_fetch_assoc($sql_total_tickets_closed);
$total_tickets_closed = intval($row['total_tickets_closed']);

//Get Unassigned tickets
$sql_total_tickets_unassigned = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_unassigned FROM tickets WHERE ticket_assigned_to = '0' AND ticket_resolved_at IS NULL $client_query $access_permission_query_overide");
$row = mysqli_fetch_assoc($sql_total_tickets_unassigned);
$total_tickets_unassigned = intval($row['total_tickets_unassigned']);

//Get Total tickets assigned to me
$sql_total_tickets_assigned = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_assigned FROM tickets WHERE ticket_assigned_to = $session_user_id AND ticket_resolved_at IS NULL $client_query $access_permission_query_overide");
$row = mysqli_fetch_assoc($sql_total_tickets_assigned);
$user_active_assigned_tickets = intval($row['total_tickets_assigned']);

//Get Overdue tickets (past due, still open)
$sql_total_tickets_overdue = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_overdue FROM tickets WHERE ticket_due_at IS NOT NULL AND ticket_due_at < NOW() AND ticket_resolved_at IS NULL $client_query $access_permission_query_overide");
$row = mysqli_fetch_assoc($sql_total_tickets_overdue);
$total_tickets_overdue = intval($row['total_tickets_overdue']);

//Get tickets due today (still open)
$sql_total_tickets_due_today = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_due_today FROM tickets WHERE ticket_due_at IS NOT NULL AND DATE(ticket_due_at) = CURDATE() AND ticket_resolved_at IS NULL $client_query $access_permission_query_overide");
$row = mysqli_fetch_assoc($sql_total_tickets_due_today);
$total_tickets_due_today = intval($row['total_tickets_due_today']);

//Get open on-site tickets
$sql_total_tickets_onsite_open = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_onsite_open FROM tickets WHERE ticket_onsite = 1 AND ticket_resolved_at IS NULL $client_query $access_permission_query_overide");
$row = mysqli_fetch_assoc($sql_total_tickets_onsite_open);
$total_tickets_onsite_open = intval($row['total_tickets_onsite_open']);

//Get All tickets (open + closed)
$sql_total_tickets_all = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_all FROM tickets WHERE 1=1 $client_query $access_permission_query_overide");
$row = mysqli_fetch_assoc($sql_total_tickets_all);
$total_tickets_all = intval($row['total_tickets_all']);

// Saved Views sidebar (hidden in client-scoped view)
$_saved_views = [];
if (!$client_url) {
    $sql_saved_views = mysqli_query(
        $mysqli,
        "SELECT * FROM ticket_saved_views
         WHERE (ticket_saved_view_user_id = 0 OR ticket_saved_view_user_id = $session_user_id)
         AND ticket_saved_view_archived_at IS NULL
         ORDER BY ticket_saved_view_user_id ASC, ticket_saved_view_order ASC"
    );
    while ($row = mysqli_fetch_assoc($sql_saved_views)) $_saved_views[] = $row;
}

// Which saved view (if any) is this agent's default: marks the star in the list and drives "Reset to my default"
$_default_ids = ticketViewDefaultIds($mysqli, intval($session_user_id));
$_default_now = ticketViewDefaultResolve($mysqli, intval($session_user_id));
$_is_full_support = (lookupUserPermission("module_support") === 3);

$sql_categories_filter = mysqli_query(
    $mysqli,
    "SELECT * FROM categories
    WHERE category_type = 'Ticket'
    AND category_archived_at IS NULL
    ORDER BY category_name"
);

$sql_boards_filter = mysqli_query(
    $mysqli,
    "SELECT * FROM categories
    WHERE category_type = 'Ticket'
    AND category_parent = 0
    AND category_archived_at IS NULL
    ORDER BY category_name"
);

$sql_ticket_status_pill = mysqli_query($mysqli, "SELECT * FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order");

$sql_ticket_tags_filter = mysqli_query($mysqli, "SELECT * FROM tags WHERE tag_type = 6 ORDER BY tag_name ASC");

?>
    <link rel="stylesheet" href="../css/ticket_view_filters.css">
    <style>
        .popover {
            max-width: 600px;
        }
        .ticket-stat-box {
            display: flex;
            align-items: center;
            gap: .75rem;
            text-align: left;
            border-radius: var(--input-radius);
            padding: .6rem .85rem;
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-left: 4px solid var(--stat-color, var(--color-accent));
            color: var(--color-text);
            box-shadow: var(--card-shadow);
            transition: transform .1s ease-out, box-shadow .1s ease-out;
        }
        .ticket-stat-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 14px -4px rgba(16,24,40,.18);
            text-decoration: none;
            color: var(--color-text);
        }
        .ticket-stat-box.active {
            background: var(--stat-color, var(--color-accent));
            border-color: var(--stat-color, var(--color-accent));
            color: #fff;
        }
        .ticket-stat-box .ticket-stat-icon {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.1rem;
            height: 2.1rem;
            border-radius: 50%;
            font-size: 1rem;
            background: rgba(var(--stat-color-rgb, var(--color-accent-rgb)), .12);
            color: var(--stat-color, var(--color-accent));
        }
        .ticket-stat-box.active .ticket-stat-icon {
            background: rgba(255,255,255,.2);
            color: #fff;
        }
        .ticket-stat-box .ticket-stat-value {
            font-size: 1.3rem;
            font-weight: 700;
            display: block;
            line-height: 1.15;
        }
        .ticket-stat-box .ticket-stat-label {
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .03em;
            color: var(--color-text-muted);
        }
        .ticket-stat-box.active .ticket-stat-label {
            color: rgba(255,255,255,.85);
        }
        .ticket-saved-views .nav-link {
            color: inherit;
            border-radius: .25rem;
            padding: .4rem .6rem;
        }
        .ticket-saved-views .nav-link.active {
            background: var(--accent, #2563eb);
            color: #fff;
        }
        .ticket-saved-views .nav-link:hover {
            background: rgba(0,0,0,.15);
        }
        .ticket-saved-views .saved-view-actions {
            opacity: 0;
        }
        .ticket-saved-views .nav-item:hover .saved-view-actions {
            opacity: 1;
        }
        .table-tight td, .table-tight th {
            padding: .35rem .5rem;
        }
    </style>
    <div class="row">
        <?php if (!$client_url) { ?>
        <div class="col-lg-2 mb-3">
            <div class="card card-dark h-100">
                <div class="card-header py-2 d-flex align-items-center justify-content-between">
                    <h3 class="card-title mt-0 mb-0"><i class="fa fa-fw fa-thumbtack me-2"></i>Ticket Views</h3>
                    <button type="button" class="btn btn-sm btn-outline-secondary ajax-modal" title="Save current view"
                        data-modal-url="modals/ticket/ticket_saved_view_add.php?<?= htmlspecialchars(http_build_query($_GET)) ?>">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                <div class="card-body ticket-saved-views py-2">
                    <ul class="nav nav-pills flex-column">
                        <?php foreach ($_saved_views as $_view) {
                            $_view_id = intval($_view['ticket_saved_view_id']);
                            $_view_name = nullable_htmlentities($_view['ticket_saved_view_name']);
                            $_view_icon = nullable_htmlentities($_view['ticket_saved_view_icon']) ?: 'fa-filter';
                            $_view_href = ticketFiltersBuildQuery(ticketViewHrefParams((string) $_view['ticket_saved_view_query']));
                            $_view_active = ticketViewIsActive((string) $_view['ticket_saved_view_query'], $_GET, intval($session_user_id));
                            $_view_owner = intval($_view['ticket_saved_view_user_id']);
                            $_can_edit = ($_view_owner == $session_user_id) || ($_view_owner == 0 && $_is_full_support);
                            $_is_my_default = ($_default_ids['personal'] === $_view_id);
                            $_is_shared_default = ($_default_ids['shared'] === $_view_id && $_view_owner === 0);
                            $_csrf = $_SESSION['csrf_token'];
                        ?>
                        <li class="nav-item d-flex align-items-center">
                            <a class="nav-link flex-grow-1 <?= $_view_active ? 'active' : '' ?>" href="?<?= htmlspecialchars($_view_href, ENT_QUOTES) ?>" <?= $_view_active ? 'aria-current="page"' : '' ?>>
                                <i class="fas fa-fw <?= $_view_icon ?> me-2"></i><?= $_view_name ?>
                                <?php if ($_is_shared_default && !$_is_my_default) { ?><span class="badge text-bg-secondary ms-1" title="Opens by default for agents without a personal default">Shared default</span><?php } ?>
                            </a>
                            <?php if ($_is_my_default) { ?>
                                <a class="tvf-star is-default" href="post.php?clear_ticket_view_default=<?= $_view_id ?>&amp;csrf_token=<?= $_csrf ?>" title="Your default view: click to clear" aria-label="<?= $_view_name ?> is your default view. Clear default"><i class="fas fa-star"></i></a>
                            <?php } else { ?>
                                <a class="tvf-star" href="post.php?set_ticket_view_default=<?= $_view_id ?>&amp;csrf_token=<?= $_csrf ?>" title="Make this my default view" aria-label="Make <?= $_view_name ?> my default view"><i class="far fa-star"></i></a>
                            <?php } ?>
                            <?php if ($_can_edit || ($_is_full_support && $_view_owner === 0)) { ?>
                            <div class="dropdown saved-view-actions">
                                <button class="btn btn-sm btn-link text-secondary p-0 px-1" type="button" data-bs-toggle="dropdown" aria-label="Actions for <?= $_view_name ?>">
                                    <i class="fas fa-fw fa-ellipsis-v"></i>
                                </button>
                                <div class="dropdown-menu dropdown-menu-right">
                                    <?php if ($_can_edit) { ?>
                                    <a class="dropdown-item" href="post.php?move_ticket_saved_view_up=<?= $_view_id ?>&csrf_token=<?= $_csrf ?>"><i class="fas fa-fw fa-arrow-up me-2"></i>Move Up</a>
                                    <a class="dropdown-item" href="post.php?move_ticket_saved_view_down=<?= $_view_id ?>&csrf_token=<?= $_csrf ?>"><i class="fas fa-fw fa-arrow-down me-2"></i>Move Down</a>
                                    <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_saved_view_edit.php?id=<?= $_view_id ?>"><i class="fas fa-fw fa-edit me-2"></i>Edit</a>
                                    <?php } ?>
                                    <?php if ($_is_full_support && $_view_owner === 0) { ?>
                                        <?php if ($_is_shared_default) { ?>
                                    <a class="dropdown-item" href="post.php?clear_ticket_view_default=<?= $_view_id ?>&scope=shared&csrf_token=<?= $_csrf ?>"><i class="far fa-fw fa-star me-2"></i>Clear shared default</a>
                                        <?php } else { ?>
                                    <a class="dropdown-item" href="post.php?set_ticket_view_default=<?= $_view_id ?>&scope=shared&csrf_token=<?= $_csrf ?>"><i class="fas fa-fw fa-star me-2"></i>Make shared default</a>
                                        <?php } ?>
                                    <?php } ?>
                                    <?php if ($_can_edit) { ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger confirm-link" href="post.php?delete_ticket_saved_view=<?= $_view_id ?>&csrf_token=<?= $_csrf ?>"><i class="fas fa-fw fa-trash me-2"></i>Delete</a>
                                    <?php } ?>
                                </div>
                            </div>
                            <?php } ?>
                        </li>
                        <?php } ?>
                    </ul>
                    <div class="tvf-default-links small mt-2">
                        <?php if ($_default_now) { ?>
                        <div><a href="?default=1"><i class="fas fa-fw fa-star me-1"></i>Reset to my default</a>
                            <span class="text-muted">(<?= nullable_htmlentities($_default_now['view']['ticket_saved_view_name']) ?><?= $_default_now['scope'] === 'shared' ? ', shared' : '' ?>)</span></div>
                        <?php } ?>
                        <div><a href="?status=Open"><i class="fas fa-fw fa-folder-open me-1"></i>Show all open</a></div>
                    </div>

                    <?php if (mysqli_num_rows($sql_boards_filter)) {
                        mysqli_data_seek($sql_boards_filter, 0);
                    ?>
                    <hr>
                    <h6 class="text-secondary text-uppercase" style="font-size:.7rem;">Boards</h6>
                    <ul class="nav nav-pills flex-column">
                        <?php while ($_board = mysqli_fetch_assoc($sql_boards_filter)) {
                            $_board_id = intval($_board['category_id']);
                            $_board_name = nullable_htmlentities($_board['category_name']);
                        ?>
                        <li class="nav-item">
                            <a class="nav-link <?= ($board_filter === $_board_id) ? 'active' : '' ?>" href="?<?= $client_url ?>board%5B%5D=<?= $_board_id ?>&status=Open">
                                <i class="fas fa-fw fa-layer-group me-2"></i><?= $_board_name ?>
                            </a>
                        </li>
                        <?php } ?>
                    </ul>
                    <?php mysqli_data_seek($sql_boards_filter, 0); } ?>
                </div>
            </div>
        </div>
        <?php } ?>
        <div class="<?= $client_url ? 'col-12' : 'col-lg-10' ?>">

    <div class="row g-3 mb-3">
        <?php
        $_stats = [
            ['label' => 'All Tickets',  'value' => $total_tickets_all,           'href' => '?' . $client_url . 'status=All',                              'icon' => 'fa-list', 'color' => '#475569', 'rgb' => '71,85,105'],
            ['label' => 'Unassigned',   'value' => $total_tickets_unassigned,    'href' => '?' . $client_url . 'assigned=unassigned',                    'icon' => 'fa-user-slash', 'color' => '#F59E0B', 'rgb' => '245,158,11'],
            ['label' => 'Unresolved',   'value' => $total_tickets_open,          'href' => '?' . $client_url . 'status=Open',                            'icon' => 'fa-exclamation-circle', 'color' => '#3B82F6', 'rgb' => '59,130,246'],
            ['label' => 'Due Today',    'value' => $total_tickets_due_today,     'href' => '?' . $client_url . 'status=Open&due_today=1', 'active' => isset($_GET['due_today']), 'icon' => 'fa-clock', 'color' => '#D97706', 'rgb' => '217,119,6'],
            ['label' => 'Overdue',      'value' => $total_tickets_overdue,       'href' => '?' . $client_url . 'status=Open&overdue=1', 'active' => isset($_GET['overdue']), 'icon' => 'fa-fire', 'color' => '#EF4444', 'rgb' => '239,68,68'],
            ['label' => 'On-Site Open', 'value' => $total_tickets_onsite_open,   'href' => '?' . $client_url . 'status=Open&onsite=1', 'active' => $onsite_filter === 1, 'icon' => 'fa-map-marker-alt', 'color' => '#8B5CF6', 'rgb' => '139,92,246'],
            ['label' => 'My Active',    'value' => $user_active_assigned_tickets,'href' => '?' . $client_url . 'status=Open&assigned%5B%5D=me', 'icon' => 'fa-user-check', 'color' => 'var(--color-accent)', 'rgb' => 'var(--color-accent-rgb)'],
        ];
        foreach ($_stats as $_stat) {
        ?>
        <div class="col-6 col-md-4 col-xl">
            <a class="ticket-stat-box <?= !empty($_stat['active']) ? 'active' : '' ?>" href="<?= $_stat['href'] ?>" style="--stat-color: <?= $_stat['color'] ?>; --stat-color-rgb: <?= $_stat['rgb'] ?>;">
                <span class="ticket-stat-icon"><i class="fas fa-fw <?= $_stat['icon'] ?>"></i></span>
                <span>
                    <span class="ticket-stat-value"><?= $_stat['value'] ?></span>
                    <span class="ticket-stat-label"><?= $_stat['label'] ?></span>
                </span>
            </a>
        </div>
        <?php } ?>
    </div>

    <div class="card card-dark">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fa fa-fw fa-life-ring me-2"></i>Tickets
                <small class="ms-3">
                    <a href="?<?= $client_url ?>status=Open" class="badge rounded-pill p-1 <?= $status == 'Open' ? 'text-bg-light text-dark' : 'text-bg-secondary text-light' ?>"><strong><?= $total_tickets_open ?></strong> Open</a> |
                    <a href="?<?= $client_url ?>status=Closed" class="badge rounded-pill p-1 <?= $status == 'Closed' ? 'text-bg-light text-dark' : 'text-bg-secondary text-light' ?>"><strong><?= $total_tickets_closed ?></strong> Closed</a>
                </small>
            </h3>
            <?php if (lookupUserPermission("module_support") >= 2) { ?>
                <div class="card-tools">
                    <div class="btn-group">
                        <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/ticket/ticket_add_v2.php?<?= $client_url ?>" data-modal-size="lg">
                            <i class="fas fa-plus"></i><span class="d-none d-lg-inline ms-2">New Ticket</span>
                        </button>
                        <?php if ($num_rows[0] > 0) { ?>
                        <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
                        <div class="dropdown-menu">
                            <a class="dropdown-item text-dark ajax-modal" href="#"
                                data-modal-url="modals/ticket/ticket_export.php?<?= $client_url ?>">
                                <i class="fa fa-fw fa-download me-2"></i>Export
                            </a>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
        </div>
        <div class="card-body">
            <form autocomplete="off" class="ticket-filter-bar">
                <?php if ($client_url) { ?>
                    <input type="hidden" name="client_id" value="<?= $client_id ?>">
                <?php } ?>
                <input type="hidden" name="view" value="<?= nullable_htmlentities($_GET['view'] ?? 'list') ?>">

                <div class="filter-toolbar">
                <div class="row align-items-center filter-row-nowrap">
                    <?php
                    $_fo = ticketFilterOptions($mysqli);
                    $_sel = static fn (array $a) => array_map('strval', $a);
                    $_badge = static fn (array $a) => $a ? (string) count($a) : '';
                    $_st = $ticket_filters['status'];
                    $_st_badge = $_st['mode'] === 'specific' ? (string) count($_st['ids']) : ['open' => 'Open', 'closed' => 'Closed', 'all' => 'All'][$_st['mode']];
                    ?>
                    <div class="col-auto mb-2">
                        <?php ticketFilterDropdownOpen('tvfBoard', 'Board', $_badge($ticket_filters['board'])); ?>
                            <?php ticketFilterGroup('tvq', 'board[]', 'Board', $_fo['board'], $_sel($ticket_filters['board'])); ?>
                        <?php ticketFilterDropdownClose(); ?>
                    </div>
                    <div class="col-auto mb-2">
                        <?php ticketFilterDropdownOpen('tvfCategory', 'Category', $_badge($ticket_filters['category'])); ?>
                            <?php ticketFilterGroup('tvq', 'category[]', 'Category', $_fo['category'], $_sel($ticket_filters['category'])); ?>
                        <?php ticketFilterDropdownClose(); ?>
                    </div>
                    <div class="col-auto mb-2">
                        <?php ticketFilterDropdownOpen('tvfStatus', 'Status', $_st_badge, ['data-tvf-status' => '1']); ?>
                            <fieldset class="tvf-group form-group">
                                <legend class="tvf-legend">Show</legend>
                                <div class="tvf-options">
                                    <?php foreach (['Open' => 'All open', 'Closed' => 'All closed', 'All' => 'Everything'] as $_sv => $_sl) { ?>
                                    <div class="form-check"><input class="form-check-input" type="radio" id="tvqStatus<?= $_sv ?>" name="status" value="<?= $_sv ?>" data-short="<?= $_sv ?>" <?= $_st['mode'] === strtolower($_sv) ? 'checked' : '' ?>> <label class="form-check-label" for="tvqStatus<?= $_sv ?>"><?= $_sl ?></label></div>
                                    <?php } ?>
                                </div>
                            </fieldset>
                            <?php ticketFilterGroup('tvq', 'status[]', 'Specific statuses', $_fo['status'], $_sel($_st['ids'])); ?>
                        <?php ticketFilterDropdownClose(); ?>
                    </div>
                    <div class="col-auto mb-2">
                        <?php ticketFilterDropdownOpen('tvfPriority', 'Priority', $_badge($ticket_filters['priority'])); ?>
                            <?php ticketFilterGroup('tvq', 'priority[]', 'Priority', $_fo['priority'], $ticket_filters['priority']); ?>
                        <?php ticketFilterDropdownClose(); ?>
                    </div>
                    <div class="col-auto mb-2">
                        <?php dateRangePickerField($date_range, 'canned_date', ['id' => 'ticketDateRange']); ?>
                    </div>
                    <div class="col-auto mb-2">
                        <select class="form-control select2 auto-submit-select" name="datefield" data-placeholder="Date field" style="width:170px;" aria-label="Date field the range applies to">
                            <?php foreach (ticketDateFields() as $_df_key => $_df) { ?>
                                <option value="<?= $_df_key ?>" <?= $datefield === $_df_key ? 'selected' : '' ?>><?= nullable_htmlentities($_df['label']) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-auto mb-2" style="width:220px;">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search tickets...">
                            <div class="input-group-append">
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="col-auto mb-2">
                        <select class="form-control select2 auto-submit-select" name="tags[]" data-placeholder="Tags Filter" multiple style="width:160px;">
                            <?php
                            while ($row = mysqli_fetch_assoc($sql_ticket_tags_filter)) {
                                $tag_id = intval($row['tag_id']);
                                $tag_name = nullable_htmlentities($row['tag_name']);
                            ?>
                                <option value="<?php echo $tag_id; ?>" <?php if (in_array($tag_id, $ticket_tag_filter)) { echo "selected"; } ?>><?php echo $tag_name; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-auto mb-2">
                        <a href="?<?= $client_url ?>" class="btn btn-outline-secondary"><i class="fas fa-undo me-1"></i>Reset Filters</a>
                    </div>
                    <div class="col-auto mb-2">
                        <button class="btn btn-outline-dark" type="button" data-bs-toggle="collapse" data-bs-target="#advancedFilter"><i class="fas fa-sliders-h me-1"></i>More Filters</button>
                    </div>
                </div>
                </div>

                <?php if (in_array($datefield, ['resolved', 'closed'], true) && $status === 'Open' && !$date_range->isAllTime()) { ?>
                <div class="small text-muted mb-1"><i class="fas fa-info-circle me-1"></i>Only resolved tickets have a <?= $datefield ?> date. Set Status to "All Closed" to see them.</div>
                <?php } ?>

                <div class="row mt-1">
                    <div class="col-12">
                        <div class="btn-group">
                            <?php if (lookupUserPermission("module_support") >= 2) { ?>
                                <div class="dropdown ms-2" id="bulkActionButton" hidden>
                                    <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                        <i class="fas fa-fw fa-layer-group me-2"></i>Bulk Action (<span id="selectedCount">0</span>)
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket/ticket_bulk_assign.php"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-user-check me-2"></i>Assign Agent
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket/ticket_bulk_edit_category.php"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-layer-group me-2"></i>Set Category
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket/ticket_bulk_edit_priority.php"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-thermometer-half me-2"></i>Set Priority
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket/ticket_bulk_email.php"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-envelope me-2"></i>Email Notifications
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket/ticket_bulk_reply.php"
                                            data-modal-size="lg"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-paper-plane me-2"></i>Update/Reply
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket/ticket_bulk_add_project.php"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-project-diagram me-2"></i>Set Project
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket/ticket_bulk_merge.php"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-clone me-2"></i>Merge
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket/ticket_bulk_resolve.php"
                                            data-modal-size="lg"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-check me-2"></i>Resolve
                                        </a>
                                        <?php if (lookupUserPermission("module_support") === 3) { ?>
                                        <div class="dropdown-divider"></div>
                                        <button class="dropdown-item text-danger text-bold confirm-link" type="submit" form="bulkActions" name="bulk_delete_tickets">
                                            <i class="fas fa-fw fa-trash me-2"></i>Delete
                                        </button>
                                        <?php } ?>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>

                <div
                    class="collapse mt-3 <?= ($ticket_filters['assigned'] || $ticket_filters['onsite'] || $ticket_filters['overdue'] || $ticket_filters['due_today'] || $ticket_project_filter_id) ? 'show' : '' ?>"
                    id="advancedFilter"
                >
                    <div class="row">
                        <div class="col-md-4">
                            <?php ticketFilterGroup('tvq', 'assigned[]', 'Assigned to', $_fo['assigned'], $_sel($ticket_filters['assigned'])); ?>
                        </div>
                        <div class="col-md-2">
                            <?php ticketFilterGroup('tvq', 'onsite[]', 'On-site / remote', $_fo['onsite'], $ticket_filters['onsite']); ?>
                        </div>
                        <div class="col-md-2">
                            <?php ticketFilterGroup('tvq', 'due', 'Due', [
                                ['value' => '1', 'label' => 'Overdue', 'name' => 'overdue', 'checked' => $ticket_filters['overdue']],
                                ['value' => '1', 'label' => 'Due today', 'name' => 'due_today', 'checked' => $ticket_filters['due_today']],
                            ], []); ?>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Project</label>
                                <select class="form-control select2 auto-submit-select" name="project">
                                    <option value="" <?php if ($ticket_project_filter_id == "") { echo "selected"; } ?>>Any</option>

                                    <?php
                                    $sql_projects = mysqli_query($mysqli, "SELECT * FROM projects WHERE project_completed_at IS NULL and project_archived_at IS NULL ORDER BY project_name ASC");
                                    while ($row = mysqli_fetch_assoc($sql_projects)) {
                                        $project_id = intval($row['project_id']);
                                        $project_prefix = nullable_htmlentities($row['project_prefix']);
                                        $project_number = intval($row['project_number']);
                                        $project_name = nullable_htmlentities($row['project_name']);
                                        ?>
                                        <option <?php if ($ticket_project_filter_id == $project_id) { echo "selected"; } ?> value="<?php echo $project_id; ?>"><?php echo $project_prefix . $project_number . " - " . $project_name; ?></option>
                                        <?php
                                    }
                                    ?>

                                </select>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i>Apply filters</button>
                </div>
            </form>
        </div>
    </div>

<?php

if (isset($_GET["view"])) {
    if ($_GET["view"] == "list") {
        require_once "ticket_list.php";
    } elseif ($_GET["view"] == "kanban") {
        require_once "ticket_kanban.php";
    }
} else {
    // here we have to get default view setting
    if ($config_ticket_default_view === 0) {
        require_once "ticket_list.php";
    } elseif ($config_ticket_default_view === 2) {
        require_once "ticket_kanban.php";
    } else {
        require_once "ticket_list.php";
    }
}

?>
        </div>
    </div>

<script src="../js/bulk_actions.js"></script>
<script src="../js/ticket_view_filters.js"></script>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
$(function () {
    if ($.fn.select2) {
        $('#priorityFilterSelect').select2({
            templateResult: formatPriorityOption,
            templateSelection: formatPriorityOption
        });
    }

    function formatPriorityOption(option) {
        var dot = $(option.element).data('dot');
        if (!dot) {
            return option.text;
        }
        return $('<span><span class="priority-dot priority-dot-' + dot + '"></span>' + option.text + '</span>');
    }
});
</script>

<?php
require_once "../includes/footer.php";
