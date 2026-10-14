<?php

require_once __DIR__ . '/../../includes/date_range.php';
require_once __DIR__ . '/../../includes/date_range_picker.php';

/*
 * Saved ticket views: a form for choosing the filters a view uses, and the matching validator that turns the posted choices
 * back into the query string stored on the view (the same parameters agent/tickets.php already understands). Nothing is
 * stored from a form value without being checked here first.
 */

/** The most values any one filter group keeps (a hostile or runaway request cannot build an unbounded IN list). */
const TICKET_FILTER_GROUP_CAP = 50;

const TICKET_FILTER_PRIORITIES = ['Low', 'Medium', 'High'];

/** @return array<string,mixed> the filter choices found in a stored view query (or the current page's query) */
function ticketViewParseQuery(string $query): array
{
    parse_str($query, $p);

    return is_array($p) ? $p : [];
}

/**
 * Scalars out of a request value that may be one value or a list (the old single-value URLs and the new `name[]=` form),
 * trimmed, de-duplicated, capped. Nested arrays and non-scalars are dropped, never coerced.
 *
 * @param mixed $v
 * @return string[]
 */
function ticketFilterScalars($v): array
{
    $out = [];
    foreach ((is_array($v) ? $v : [$v]) as $x) {
        if (!is_scalar($x) || is_bool($x)) {
            continue;
        }
        $x = trim((string) $x);
        if ($x === '' || strlen($x) > 40) {
            continue;
        }
        $out[$x] = true;
        if (count($out) >= TICKET_FILTER_GROUP_CAP) {
            break;
        }
    }

    return array_map('strval', array_keys($out));
}

/**
 * Positive integer ids out of a request value (one or many).
 *
 * @param mixed $v
 * @return int[] sorted ascending, unique, capped
 */
function ticketFilterIds($v): array
{
    $ids = [];
    foreach (ticketFilterScalars($v) as $x) {
        if (ctype_digit($x) && (int) $x > 0 && (int) $x <= 2147483647) {
            $ids[(int) $x] = true;
        }
    }
    $ids = array_keys($ids);
    sort($ids);

    return $ids;
}

/**
 * Turn any ticket filter input (the URL's $_GET, or a saved view's parsed query; old single values or the new lists) into one
 * canonical shape. Nothing here touches the database; every value is an integer, an enum member or a fixed token.
 *
 *   status    => ['mode' => open|closed|all|specific, 'ids' => int[]]
 *   assigned  => string[] of 'me' | 'unassigned' | '<agent id>' (the viewer's own id collapses to 'me' when $uid is given)
 *   priority  => Low|Medium|High list (all three = no filter = [])
 *   board, category, tags => int[]
 *   onsite    => ['0'|'1'] (both = no filter = [])
 *   overdue, due_today => bool (OR-ed together when both are set)
 *
 * @param array<string,mixed> $src
 * @return array<string,mixed>
 */
function ticketFiltersNormalize(array $src, int $uid = 0): array
{
    // Status: Open / Closed / All, one status id, or a list of status ids.
    $st = $src['status'] ?? null;
    $status = ['mode' => 'open', 'ids' => []];
    if (is_array($st)) {
        $ids = ticketFilterIds($st);
        if ($ids) {
            $status = ['mode' => 'specific', 'ids' => $ids];
        }
    } elseif (is_scalar($st)) {
        $st = trim((string) $st);
        if ($st === 'Closed') {
            $status['mode'] = 'closed';
        } elseif ($st === 'All') {
            $status['mode'] = 'all';
        } elseif (ctype_digit($st) && (int) $st > 0) {
            $status = ['mode' => 'specific', 'ids' => ticketFilterIds($st)];
        }
    }

    // Assigned to: me, nobody, or agents; '0' (the dashboard's "unassigned" link) means nobody.
    $assigned = [];
    foreach (ticketFilterScalars($src['assigned'] ?? null) as $x) {
        if ($x === 'me' || ($uid > 0 && $x === (string) $uid)) {
            $assigned['me'] = true;
        } elseif ($x === 'unassigned' || $x === '0') {
            $assigned['unassigned'] = true;
        } elseif (ctype_digit($x) && (int) $x > 0 && (int) $x <= 2147483647) {
            $assigned[(string) (int) $x] = true;
        }
    }
    $assigned = array_map('strval', array_keys($assigned));
    sort($assigned, SORT_STRING);

    $priority = array_values(array_intersect(TICKET_FILTER_PRIORITIES, ticketFilterScalars($src['priority'] ?? null)));
    if (count($priority) === count(TICKET_FILTER_PRIORITIES)) {
        $priority = [];
    }

    $onsite = array_values(array_intersect(['0', '1'], ticketFilterScalars($src['onsite'] ?? null)));
    if (count($onsite) === 2) {
        $onsite = [];
    }

    $on = static fn ($k) => isset($src[$k]) && is_scalar($src[$k]) && !in_array(strtolower(trim((string) $src[$k])), ['', '0', 'false'], true);

    return [
        'status' => $status,
        'assigned' => $assigned,
        'priority' => $priority,
        'board' => ticketFilterIds($src['board'] ?? null),
        'category' => ticketFilterIds($src['category'] ?? null),
        'onsite' => $onsite,
        'overdue' => $on('overdue'),
        'due_today' => $on('due_today'),
        'tags' => ticketFilterIds($src['tags'] ?? null),
    ];
}

/** Do any ticket filter keys appear in this request? (An explicit URL filter, even an empty one, beats the default view.) */
function ticketFiltersAreExplicit(array $src): bool
{
    foreach (['status', 'assigned', 'priority', 'onsite', 'board', 'category', 'tags', 'overdue', 'due_today', 'project', 'billable', 'q', 'canned_date', 'dtf', 'dtt', 'datefield', 'sort', 'order', 'page'] as $k) {
        if (array_key_exists($k, $src)) {
            return true;
        }
    }

    return false;
}

/**
 * Query-string parameters for a normalized filter set, in the canonical form (lists as `name[]`).
 *
 * @param array<string,mixed> $f from ticketFiltersNormalize()
 * @return array<string,mixed>
 */
function ticketFiltersToParams(array $f): array
{
    $q = [];
    $m = $f['status']['mode'];
    if ($m === 'closed') {
        $q['status'] = 'Closed';
    } elseif ($m === 'all') {
        $q['status'] = 'All';
    } elseif ($m === 'specific' && $f['status']['ids']) {
        $q['status'] = $f['status']['ids'];
    } else {
        $q['status'] = 'Open';
    }
    foreach (['assigned', 'priority', 'board', 'category', 'onsite'] as $k) {
        if ($f[$k]) {
            $q[$k] = $f[$k];
        }
    }
    if ($f['overdue']) {
        $q['overdue'] = 1;
    }
    if ($f['due_today']) {
        $q['due_today'] = 1;
    }
    if ($f['tags']) {
        $q['tags'] = $f['tags'];
    }

    return $q;
}

/** http_build_query() with lists written `name[]=a&name[]=b` (readable and stable) instead of `name[0]=a`. */
function ticketFiltersBuildQuery(array $params): string
{
    $parts = [];
    foreach ($params as $k => $v) {
        if (is_array($v)) {
            foreach ($v as $x) {
                if (is_scalar($x)) {
                    $parts[] = rawurlencode((string) $k) . '%5B%5D=' . rawurlencode((string) $x);
                }
            }
        } elseif (is_scalar($v)) {
            $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
    }

    return implode('&', $parts);
}

/**
 * A comparable fingerprint of a filter set (same filters = same string, however the URL spelled them).
 *
 * @param array<string,mixed> $f
 */
function ticketFiltersSignature(array $f): string
{
    return json_encode([
        $f['status']['mode'] === 'specific' ? $f['status']['ids'] : $f['status']['mode'],
        $f['assigned'], $f['priority'], $f['board'], $f['category'], $f['onsite'], $f['overdue'], $f['due_today'], $f['tags'],
    ]);
}

/**
 * The SQL conditions for a normalized filter set. OR inside a group, AND between groups. Every value interpolated is an integer
 * or a member of a fixed enum, so nothing here needs escaping. Each condition starts with AND (the status one does not).
 *
 * @param array<string,mixed> $f
 * @return array{status:string,assigned:string,priority:string,onsite:string,board:string,category:string,due:string,tags:string}
 */
function ticketFiltersSql(array $f, int $uid): array
{
    $sql = ['status' => 'ticket_resolved_at IS NULL', 'assigned' => '', 'priority' => '', 'onsite' => '', 'board' => '', 'category' => '', 'due' => '', 'tags' => ''];

    $m = $f['status']['mode'];
    if ($m === 'closed') {
        $sql['status'] = 'ticket_resolved_at IS NOT NULL';
    } elseif ($m === 'all') {
        $sql['status'] = '1 = 1';
    } elseif ($m === 'specific' && $f['status']['ids']) {
        $sql['status'] = 'ticket_status IN (' . implode(',', array_map('intval', $f['status']['ids'])) . ')';
    }

    if ($f['assigned']) {
        $ids = [];
        foreach ($f['assigned'] as $t) {
            if ($t === 'me') {
                $ids[$uid] = true;
            } elseif ($t === 'unassigned') {
                $ids[0] = true;
            } else {
                $ids[(int) $t] = true;
            }
        }
        $ids = array_map('intval', array_keys($ids));
        sort($ids);
        $sql['assigned'] = 'AND ticket_assigned_to IN (' . implode(',', $ids) . ')';
    }

    if ($f['priority']) {
        $sql['priority'] = "AND ticket_priority IN ('" . implode("','", $f['priority']) . "')";
    }
    if ($f['onsite']) {
        $sql['onsite'] = 'AND ticket_onsite IN (' . implode(',', array_map('intval', $f['onsite'])) . ')';
    }
    if ($f['board']) {
        $in = implode(',', array_map('intval', $f['board']));
        $sql['board'] = "AND ticket_category IN (SELECT category_id FROM categories WHERE category_id IN ($in) OR category_parent IN ($in))";
    }
    if ($f['category']) {
        $sql['category'] = 'AND ticket_category IN (' . implode(',', array_map('intval', $f['category'])) . ')';
    }

    $due = [];
    if ($f['overdue']) {
        $due[] = '(ticket_due_at IS NOT NULL AND ticket_due_at < NOW() AND ticket_resolved_at IS NULL)';
    }
    if ($f['due_today']) {
        $due[] = '(ticket_due_at IS NOT NULL AND DATE(ticket_due_at) = CURDATE() AND ticket_resolved_at IS NULL)';
    }
    if ($due) {
        $sql['due'] = 'AND (' . implode(' OR ', $due) . ')';
    }

    if ($f['tags']) {
        $sql['tags'] = 'AND ticket_id IN (SELECT ticket_tag_ticket_id FROM ticket_tags WHERE ticket_tag_tag_id IN (' . implode(',', array_map('intval', $f['tags'])) . '))';
    }

    return $sql;
}

/** Active agents, for the "Assigned to" choice. @return array<int,string> */
function ticketViewAgents($mysqli): array
{
    $out = [];
    $res = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $out[(int) $r['user_id']] = $r['user_name'];
    }

    return $out;
}

/** Ticket boards (top-level ticket categories). @return array<int,string> */
function ticketViewBoards($mysqli): array
{
    $out = [];
    $res = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_parent = 0 AND category_archived_at IS NULL ORDER BY category_name");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $out[(int) $r['category_id']] = $r['category_name'];
    }

    return $out;
}

/** Ticket categories, boards included. @return array<int,string> */
function ticketViewCategories($mysqli): array
{
    $out = [];
    $res = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL ORDER BY category_name");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $out[(int) $r['category_id']] = $r['category_name'];
    }

    return $out;
}

/** Ticket statuses that are switched on. @return array<int,string> */
function ticketViewStatuses($mysqli): array
{
    $out = [];
    $res = mysqli_query($mysqli, "SELECT ticket_status_id, ticket_status_name FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $out[(int) $r['ticket_status_id']] = $r['ticket_status_name'];
    }

    return $out;
}

/** Keep only the ids that exist in $valid (an id => name list), in ascending order. @param int[] $ids @param array<int,string> $valid @return int[] */
function ticketViewKnownIds(array $ids, array $valid): array
{
    $ids = array_values(array_filter($ids, static fn ($i) => isset($valid[$i])));
    sort($ids);

    return $ids;
}

/**
 * Turn posted form fields into a validated view query string.
 *
 * @param array<string,mixed> $post
 */
function ticketViewQueryFromPost($mysqli, array $post): string
{
    $mode = (string) ($post['f_status_mode'] ?? 'open');
    $src = ['status' => 'Open'];
    if ($mode === 'closed') {
        $src['status'] = 'Closed';
    } elseif ($mode === 'all') {
        $src['status'] = 'All';
    } elseif ($mode === 'specific') {
        $ids = ticketViewKnownIds(ticketFilterIds($post['f_status'] ?? null), ticketViewStatuses($mysqli));
        if ($ids) {
            $src['status'] = $ids;
        }
    }

    // Assigned to: me, nobody and/or specific agents (any combination).
    $agents = ticketViewAgents($mysqli);
    $assigned = [];
    foreach (ticketFilterScalars($post['f_assigned'] ?? null) as $x) {
        if ($x === 'me' || $x === 'unassigned' || (ctype_digit($x) && isset($agents[(int) $x]))) {
            $assigned[] = $x;
        }
    }
    $src['assigned'] = $assigned;
    $src['priority'] = ticketFilterScalars($post['f_priority'] ?? null);
    $src['onsite'] = ticketFilterScalars($post['f_onsite'] ?? null);
    $src['board'] = ticketViewKnownIds(ticketFilterIds($post['f_board'] ?? null), ticketViewBoards($mysqli));
    $src['category'] = ticketViewKnownIds(ticketFilterIds($post['f_category'] ?? null), ticketViewCategories($mysqli));

    $due = ticketFilterScalars($post['f_due'] ?? null);
    if (in_array('overdue', $due, true)) {
        $src['overdue'] = 1;
    }
    if (in_array('due_today', $due, true)) {
        $src['due_today'] = 1;
    }

    $tags = ticketFilterIds($post['f_tags'] ?? null);
    if ($tags) {
        $valid = [];
        $res = mysqli_query($mysqli, 'SELECT tag_id FROM tags WHERE tag_type = 6 AND tag_id IN (' . implode(',', $tags) . ')');
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $valid[] = (int) $r['tag_id'];
        }
        sort($valid);
        $src['tags'] = $valid;
    }

    $q = ticketFiltersToParams(ticketFiltersNormalize($src));

    // Date range: presets are stored as the preset id only (so the view stays rolling: "Last 7 days" is always the last
    // 7 days); only an explicitly custom range stores dates. All time stores nothing. datefield only matters with a range.
    $range = dateRangeFromRequest([
        'canned_date' => $post['f_canned_date'] ?? '',
        'dtf' => $post['f_dtf'] ?? '',
        'dtt' => $post['f_dtt'] ?? '',
    ]);
    if (!$range->isAllTime()) {
        $q += dateRangeUrlParams($range);
        $field = ticketDateFieldFromRequest(['datefield' => $post['f_datefield'] ?? '']);
        if ($field !== 'created') {
            $q['datefield'] = $field;
        }
    }

    return ticketFiltersBuildQuery($q);
}

/** "Last 7 days (rolling)" / "Sep 1 – Sep 9 (fixed dates)" for a stored view query, or '' when it has no date range. */
function ticketViewDescribeDateRange(array $p): string
{
    $range = dateRangeFromRequest($p);
    if ($range->isAllTime()) {
        return '';
    }
    $text = $range->preset() === 'custom'
        ? dateRangeDisplayDates($range) . ' (fixed dates)'
        : $range->label() . ' (rolling)';
    $field = ticketDateFieldFromRequest($p);

    return 'Date range: ' . $text . ($field !== 'created' ? ' on ' . strtolower(ticketDateFields()[$field]['label']) : '');
}

/**
 * The URL parameters that open a stored view: its filters in the canonical list form (so old `assigned=me` / `priority=High`
 * views open exactly like new ones), plus the date range and the few other keys a view may carry.
 *
 * @return array<string,mixed>
 */
function ticketViewHrefParams(string $query): array
{
    $p = ticketViewParseQuery($query);
    $params = ticketFiltersToParams(ticketFiltersNormalize($p));
    foreach (['canned_date', 'dtf', 'dtt', 'datefield', 'q', 'project', 'billable', 'unbilled'] as $k) {
        if (isset($p[$k]) && is_scalar($p[$k]) && (string) $p[$k] !== '') {
            $params[$k] = (string) $p[$k];
        }
    }

    return $params;
}

/**
 * Is the stored view the one currently shown? Every filter is compared by content (a list compares sorted, `me` equals the viewer's own
 * id, an old single value equals the same one-item list). A view with a date range is only active while that range and field are selected.
 *
 * @param array<string,mixed> $current the page's request ($_GET)
 */
function ticketViewIsActive(string $query, array $current, int $uid): bool
{
    $view = ticketViewParseQuery($query);
    if (ticketFiltersSignature(ticketFiltersNormalize($view, $uid)) !== ticketFiltersSignature(ticketFiltersNormalize($current, $uid))) {
        return false;
    }
    if (isset($view['canned_date'])) {
        if (dateRangeFromRequest($view)->toQuery() !== dateRangeFromRequest($current)->toQuery()
            || ticketDateFieldFromRequest($view) !== ticketDateFieldFromRequest($current)) {
            return false;
        }
    }

    return true;
}

/** "A, B or C" / "A" / "A or B". @param string[] $items */
function ticketViewJoinOr(array $items): string
{
    $items = array_values($items);
    if (count($items) <= 1) {
        return (string) ($items[0] ?? '');
    }
    $last = array_pop($items);

    return implode(', ', $items) . ' or ' . $last;
}

/** Human summary of a stored query, for showing what a view does. */
function ticketViewDescribe($mysqli, string $query): string
{
    $p = ticketViewParseQuery($query);
    if (!$p) {
        return 'Open tickets (no extra filters)';
    }
    $f = ticketFiltersNormalize($p);
    $parts = [];

    $m = $f['status']['mode'];
    if ($m === 'specific') {
        $all = ticketViewStatuses($mysqli);
        $names = [];
        foreach ($f['status']['ids'] as $id) {
            $names[] = $all[$id] ?? 'a removed status';
        }
        $parts[] = 'Status: ' . ticketViewJoinOr($names);
    } else {
        $parts[] = $m === 'closed' ? 'Closed tickets' : ($m === 'all' ? 'All tickets' : 'Open tickets');
    }

    if ($f['assigned']) {
        $agents = ticketViewAgents($mysqli);
        $names = [];
        foreach ($f['assigned'] as $t) {
            $names[] = $t === 'me' ? 'me' : ($t === 'unassigned' ? 'nobody (unassigned)' : ($agents[(int) $t] ?? 'an agent'));
        }
        $parts[] = 'Assigned to ' . ticketViewJoinOr($names);
    }
    if ($f['priority']) {
        $parts[] = ticketViewJoinOr($f['priority']) . ' priority';
    }
    if ($f['onsite']) {
        $parts[] = $f['onsite'] === ['1'] ? 'On-site' : 'Remote';
    }
    if ($f['board']) {
        $all = ticketViewBoards($mysqli);
        $parts[] = 'Board: ' . ticketViewJoinOr(array_map(static fn ($i) => $all[$i] ?? 'a removed board', $f['board']));
    }
    if ($f['category']) {
        $all = ticketViewCategories($mysqli);
        $parts[] = 'Category: ' . ticketViewJoinOr(array_map(static fn ($i) => $all[$i] ?? 'a removed category', $f['category']));
    }
    if ($f['overdue'] || $f['due_today']) {
        $parts[] = ticketViewJoinOr(array_filter([$f['overdue'] ? 'Overdue' : '', $f['due_today'] ? 'Due today' : '']));
    }
    if ($f['tags']) {
        $names = [];
        $res = mysqli_query($mysqli, 'SELECT tag_name FROM tags WHERE tag_type = 6 AND tag_id IN (' . implode(',', array_map('intval', $f['tags'])) . ') ORDER BY tag_name');
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $names[] = $r['tag_name'];
        }
        $parts[] = 'Tagged ' . ($names ? ticketViewJoinOr($names) : 'selected tags');
    }
    $dates = ticketViewDescribeDateRange($p);
    if ($dates !== '') {
        $parts[] = $dates;
    }

    return implode(' · ', $parts);
}

/**
 * One group of labelled checkboxes: a fieldset with a legend, a count badge ("Any" when nothing is ticked), and Select all / Clear.
 * js/ticket_view_filters.js keeps the badge current; the server renders the right count first, so it reads correctly without script.
 *
 * @param array<int,array{value:string,label:string,name?:string,html?:string,checked?:bool}> $options  `name` overrides the input name for one option, `checked` overrides the ticked state
 * @param string[] $selected values (as strings) that start ticked
 */
function ticketFilterGroup(string $idp, string $name, string $legend, array $options, array $selected, string $hint = ''): void
{
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $gid = preg_replace('/[^A-Za-z0-9_-]/', '', $idp . '_' . $name) ?: 'tvfg';
    $is_on = static fn (array $o): bool => array_key_exists('checked', $o) ? (bool) $o['checked'] : in_array((string) $o['value'], $selected, true);
    $count = 0;
    foreach ($options as $o) {
        if ($is_on($o)) {
            $count++;
        }
    }
    ?>
    <fieldset class="tvf-group form-group" data-tvf-group>
        <legend class="tvf-legend"><?= $h($legend) ?>
            <span class="badge <?= $count ? 'text-bg-primary' : 'text-bg-secondary' ?> tvf-count" data-tvf-count><?= $count ? $count : 'Any' ?></span>
        </legend>
        <?php if (count($options) > 1) { ?>
        <div class="tvf-tools">
            <button type="button" class="btn btn-link btn-sm p-0" data-tvf-all aria-label="Select all <?= $h($legend) ?>">Select all</button>
            <span aria-hidden="true">/</span>
            <button type="button" class="btn btn-link btn-sm p-0" data-tvf-clear aria-label="Clear <?= $h($legend) ?>">Clear</button>
        </div>
        <?php } ?>
        <div class="tvf-options">
            <?php foreach ($options as $i => $o) {
                $oid = $gid . '_' . $i;
                $oname = $o['name'] ?? $name; ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="<?= $h($oid) ?>" name="<?= $h($oname) ?>" value="<?= $h($o['value']) ?>" <?= $is_on($o) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="<?= $h($oid) ?>"><?= isset($o['html']) ? $o['html'] : $h($o['label']) ?></label>
                </div>
            <?php } ?>
            <?php if (!$options) { ?><span class="text-muted small">None available</span><?php } ?>
        </div>
        <?php if ($hint !== '') { ?><small class="form-text text-muted"><?= $h($hint) ?></small><?php } ?>
    </fieldset>
    <?php
}

/**
 * A toolbar button that opens a menu of checkbox choices (the tickets page's quick filters). The menu stays open while boxes are
 * ticked; closing it (or Apply) re-runs the page's filter form. $badge is the text on the button: a count, a name, or '' for none.
 * Call ticketFilterDropdownClose() after the menu content.
 */
function ticketFilterDropdownOpen(string $id, string $label, string $badge, array $attrs = []): void
{
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' ' . $h($k) . '="' . $h($v) . '"';
    }
    ?>
    <div class="dropdown tvf-dd" data-tvf-dropdown<?= $extra ?>>
        <button type="button" class="btn btn-outline-secondary dropdown-toggle tvf-toggle" id="<?= $h($id) ?>" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-haspopup="true">
            <?= $h($label) ?> <span class="badge <?= $badge !== '' ? 'text-bg-primary' : 'text-bg-secondary' ?> tvf-toggle-count" data-tvf-toggle-count<?= $badge === '' ? ' hidden' : '' ?>><?= $h($badge) ?></span>
        </button>
        <div class="dropdown-menu tvf-menu p-3" aria-labelledby="<?= $h($id) ?>">
    <?php
}

function ticketFilterDropdownClose(): void
{
    ?>
            <button type="submit" class="btn btn-primary btn-sm mt-1">Apply</button>
        </div>
    </div>
    <?php
}

/**
 * Checkbox options for each filter group, shared by the saved-view form and the tickets page's quick filters.
 *
 * @return array<string,array<int,array{value:string,label:string,html?:string}>>
 */
function ticketFilterOptions($mysqli): array
{
    $opt = static function (array $map): array {
        $o = [];
        foreach ($map as $v => $l) {
            $o[] = ['value' => (string) $v, 'label' => (string) $l];
        }

        return $o;
    };
    $dots = ['High' => 'high', 'Medium' => 'medium', 'Low' => 'low'];
    $priority = [];
    foreach (['High', 'Medium', 'Low'] as $pr) {
        $priority[] = ['value' => $pr, 'label' => $pr, 'html' => '<span class="priority-dot priority-dot-' . $dots[$pr] . '" aria-hidden="true"></span>' . $pr];
    }
    $assigned = [['value' => 'me', 'label' => 'Me'], ['value' => 'unassigned', 'label' => 'Unassigned']];
    foreach (ticketViewAgents($mysqli) as $id => $name) {
        $assigned[] = ['value' => (string) $id, 'label' => $name];
    }

    return [
        'assigned' => $assigned,
        'priority' => $priority,
        'board' => $opt(ticketViewBoards($mysqli)),
        'category' => $opt(ticketViewCategories($mysqli)),
        'onsite' => [['value' => '1', 'label' => 'On-site'], ['value' => '0', 'label' => 'Remote']],
        'status' => $opt(ticketViewStatuses($mysqli)),
    ];
}

/**
 * The filter form fields (no <form> tag, no buttons): drop it inside a modal body.
 *
 * @param array<string,mixed> $p current choices, as parsed from a view query
 */
function ticketViewFilterFields($mysqli, array $p, string $idp = 'tvf'): void
{
    $f = ticketFiltersNormalize($p);
    $opts = ticketFilterOptions($mysqli);
    $mode = $f['status']['mode'];
    $sel_tags = array_map('strval', $f['tags']);
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $ids = static fn (array $a) => array_map('strval', $a);
    ?>
    <fieldset class="tvf-group form-group" data-tvf-group>
        <legend class="tvf-legend">Show</legend>
        <div class="d-flex flex-wrap gap-3">
            <?php foreach (['open' => 'Open tickets', 'closed' => 'Closed / resolved', 'all' => 'All tickets', 'specific' => 'Specific statuses'] as $v => $l) { ?>
                <div class="form-check"><input class="form-check-input" type="radio" id="<?= $h($idp . '_mode_' . $v) ?>" name="f_status_mode" value="<?= $h($v) ?>" <?= $mode === $v ? 'checked' : '' ?>> <label class="form-check-label" for="<?= $h($idp . '_mode_' . $v) ?>"><?= $h($l) ?></label></div>
            <?php } ?>
        </div>
    </fieldset>
    <?php
    ticketFilterGroup($idp, 'f_status[]', 'Specific statuses', $opts['status'], $ids($f['status']['ids']), 'Used only when "Specific statuses" is chosen above. New statuses you create appear here automatically.');
    ticketFilterGroup($idp, 'f_assigned[]', 'Assigned to', $opts['assigned'], $ids($f['assigned']), 'Tick any combination; a ticket matches if it is assigned to any ticked choice.');
    ticketFilterGroup($idp, 'f_priority[]', 'Priority', $opts['priority'], $f['priority']);
    ticketFilterGroup($idp, 'f_board[]', 'Board', $opts['board'], $ids($f['board']));
    ticketFilterGroup($idp, 'f_category[]', 'Category', $opts['category'], $ids($f['category']));
    ticketFilterGroup($idp, 'f_onsite[]', 'On-site / remote', $opts['onsite'], $f['onsite'], 'Tick both (or neither) to include every ticket.');
    ticketFilterGroup($idp, 'f_due[]', 'Due', [['value' => 'overdue', 'label' => 'Overdue'], ['value' => 'due_today', 'label' => 'Due today']],
        array_values(array_filter([$f['overdue'] ? 'overdue' : '', $f['due_today'] ? 'due_today' : ''])));

    $tag_opts = [];
    $tag_res = mysqli_query($mysqli, "SELECT tag_id, tag_name FROM tags WHERE tag_type = 6 ORDER BY tag_name");
    while ($tag_res && ($t = mysqli_fetch_assoc($tag_res))) {
        $tag_opts[] = ['value' => (string) $t['tag_id'], 'label' => $t['tag_name']];
    }
    if ($tag_opts) {
        ticketFilterGroup($idp, 'f_tags[]', 'Tags', $tag_opts, $sel_tags, 'A ticket with any ticked tag.');
    }
    $range = dateRangeFromRequest($p);
    $sel_field = ticketDateFieldFromRequest($p);
    ?>
    <div class="row">
        <div class="form-group col-md-7">
            <label>Date range</label>
            <div><?php dateRangePickerField($range, 'f_canned_date', ['autosubmit' => false, 'id' => $idp . '_daterange']); ?></div>
            <small class="form-text text-muted">A preset such as "Last 7 days" stays rolling: the view always shows the last 7 days, not the days it was saved on.</small>
        </div>
        <div class="form-group col-md-5">
            <label for="<?= $idp ?>_datefield">Date field</label>
            <select class="form-control" id="<?= $idp ?>_datefield" name="f_datefield">
                <?php foreach (ticketDateFields() as $k => $fld) { ?>
                    <option value="<?= $h($k) ?>" <?= $sel_field === $k ? 'selected' : '' ?>><?= $h($fld['label']) ?></option>
                <?php } ?>
            </select>
        </div>
    </div>
    <input type="hidden" name="filters_present" value="1">
    <?php
}

/* ------------------------------------------------------------------------------------------------------------------------------
 * Default view: one personal default per agent, one shared default for agents without a personal one (table ticket_view_defaults,
 * DB 2.6.93). Every function degrades to "no default" while the table does not exist yet, so the code can ship before the update runs.
 * ---------------------------------------------------------------------------------------------------------------------------- */

function ticketViewDefaultsReady($mysqli): bool
{
    static $ready = [];
    $key = spl_object_id($mysqli);
    if (!isset($ready[$key])) {
        $r = mysqli_query($mysqli, "SHOW TABLES LIKE 'ticket_view_defaults'");
        $ready[$key] = $r && mysqli_num_rows($r) === 1;
    }

    return $ready[$key];
}

/** The saved view row when it exists, is not deleted, and this agent may use it (their own, or a shared one); otherwise null. */
function ticketViewUsable($mysqli, int $view_id, int $uid): ?array
{
    if ($view_id <= 0) {
        return null;
    }
    $r = mysqli_query($mysqli, "SELECT * FROM ticket_saved_views WHERE ticket_saved_view_id = $view_id AND ticket_saved_view_archived_at IS NULL AND (ticket_saved_view_user_id = 0 OR ticket_saved_view_user_id = $uid) LIMIT 1");

    return ($r && ($row = mysqli_fetch_assoc($r))) ? $row : null;
}

/**
 * The agent's default view marks: ['personal' => view id|null, 'shared' => view id|null] (raw rows, validity not checked).
 *
 * @return array{personal:?int,shared:?int}
 */
function ticketViewDefaultIds($mysqli, int $uid): array
{
    $out = ['personal' => null, 'shared' => null];
    if (!ticketViewDefaultsReady($mysqli)) {
        return $out;
    }
    $res = mysqli_query($mysqli, "SELECT user_id, view_id, scope FROM ticket_view_defaults WHERE (scope = 'personal' AND user_id = $uid) OR (scope = 'shared' AND user_id = 0)");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $out[$r['scope']] = (int) $r['view_id'];
    }

    return $out;
}

/**
 * Which saved view opens by default for this agent: their personal default, else the shared default, else none. A default that was
 * deleted, or that this agent can no longer use, is skipped (the next one in the order applies).
 *
 * @return array{view:array,scope:string,skipped:bool}|null  `skipped` = a personal default was set but is unusable now
 */
function ticketViewDefaultResolve($mysqli, int $uid): ?array
{
    $ids = ticketViewDefaultIds($mysqli, $uid);
    $skipped = false;
    foreach (['personal', 'shared'] as $scope) {
        if ($ids[$scope] === null) {
            continue;
        }
        $view = ticketViewUsable($mysqli, $ids[$scope], $uid);
        // A shared default must itself be a shared view.
        if ($view && $scope === 'shared' && (int) $view['ticket_saved_view_user_id'] !== 0) {
            $view = null;
        }
        if ($view) {
            return ['view' => $view, 'scope' => $scope, 'skipped' => $skipped];
        }
        $skipped = true;
    }

    return null;
}

/**
 * Make a view the agent's personal default, or (with $shared) the shared default. Replaces the previous one of that kind.
 * The caller has already checked that the agent may set a shared default (support module level 3). Returns false when the view is not usable.
 */
function ticketViewDefaultSet($mysqli, int $uid, int $view_id, bool $shared): bool
{
    if (!ticketViewDefaultsReady($mysqli)) {
        return false;
    }
    $view = ticketViewUsable($mysqli, $view_id, $uid);
    if (!$view || ($shared && (int) $view['ticket_saved_view_user_id'] !== 0)) {
        return false;
    }
    $owner = $shared ? 0 : $uid;
    $scope = $shared ? 'shared' : 'personal';
    mysqli_query($mysqli, "INSERT INTO ticket_view_defaults SET user_id = $owner, view_id = $view_id, scope = '$scope', set_at = NOW() ON DUPLICATE KEY UPDATE view_id = VALUES(view_id), set_at = NOW()");

    return mysqli_errno($mysqli) === 0;
}

/** Clear the agent's personal default (or the shared one). With $only_view_id, only when it currently points at that view. */
function ticketViewDefaultClear($mysqli, int $uid, bool $shared, ?int $only_view_id = null): void
{
    if (!ticketViewDefaultsReady($mysqli)) {
        return;
    }
    $owner = $shared ? 0 : $uid;
    $scope = $shared ? 'shared' : 'personal';
    $only = $only_view_id !== null ? ' AND view_id = ' . (int) $only_view_id : '';
    mysqli_query($mysqli, "DELETE FROM ticket_view_defaults WHERE user_id = $owner AND scope = '$scope'$only");
}

/** Forget every default that points at a deleted view. */
function ticketViewDefaultForgetView($mysqli, int $view_id): void
{
    if (ticketViewDefaultsReady($mysqli)) {
        mysqli_query($mysqli, 'DELETE FROM ticket_view_defaults WHERE view_id = ' . (int) $view_id);
    }
}
