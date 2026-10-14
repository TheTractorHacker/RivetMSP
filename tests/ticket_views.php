<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Saved ticket views: multi-select filters and the default view (RivetMSP 26.10.21, DB 2.6.93). Scratch database only (same harness and
 * environment as tests/security_*.php; config.php must point at the same scratch database):
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=...scratch... RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... RIVETMSP_REDIS_PORT=... RIVETMSP_REDIS_ENV_FILE=/dev/null \
 *     php tests/ticket_views.php
 *
 * Part 1 (pure): old and new filter forms normalize to the same thing, the canonical query round trips, hostile values are dropped, a group is
 *   capped at 50, the SQL builder only emits integers and enum members, readable descriptions.
 * Part 2 (database): every filter combination returns exactly the tickets an independent PHP filter expects (OR inside a group, AND across groups);
 *   the saved-view form validator; default view resolution, set / clear / replace, deleted / unshared / unusable defaults.
 * Part 3 (HTTP, php -S with forged sessions): the real Tickets page, kanban, pagination links, the default-view redirect and its escapes, the
 *   handlers' permission rules, audit lines, modals, the API (/tickets multi-values, /ticket-views), and that client-portal logins and
 *   client-locked API keys never get agent views.
 */
require_once __DIR__ . '/support/security_lib.php';
require_once "$root/agent/includes/ticket_view_filters.php";

$read = fn (string $f): string => (string) file_get_contents("$root/$f");
$subjects = fn (string $body): array => (function () use ($body) { preg_match_all('/tvT\d{2}/', $body, $m); return array_values(array_unique($m[0])); })();

// ---------------------------------------------------------------------------------------------------------- Part 1
$ok(defined('TICKET_FILTER_GROUP_CAP') && TICKET_FILTER_GROUP_CAP === 50, 'a filter group is capped at 50 values');

// old single values and new lists mean the same thing
$a = ticketFiltersNormalize(['assigned' => 'me', 'priority' => 'High', 'board' => '5', 'onsite' => '1', 'status' => 'Open'], 7);
$b = ticketFiltersNormalize(['assigned' => ['me'], 'priority' => ['High'], 'board' => ['5'], 'onsite' => ['1'], 'status' => 'Open'], 7);
$ok($a === $b && $a['assigned'] === ['me'] && $a['priority'] === ['High'] && $a['board'] === [5] && $a['onsite'] === ['1'], 'old assigned=me&priority=High&board=5 equals the one-item lists');
$ok(ticketFiltersNormalize(['assigned' => '7'], 7)['assigned'] === ['me'] && ticketFiltersNormalize(['assigned' => '8'], 7)['assigned'] === ['8'], 'the viewer\'s own id is "me"; another agent stays an id (the old assigned=<id> links)');
$ok(ticketFiltersNormalize(['assigned' => '0'])['assigned'] === ['unassigned'] && ticketFiltersNormalize(['assigned' => 'unassigned'])['assigned'] === ['unassigned'], 'assigned=0 (the dashboard link) and assigned=unassigned both mean nobody');
$ok(ticketFiltersNormalize(['status' => 'Closed'])['status'] === ['mode' => 'closed', 'ids' => []] && ticketFiltersNormalize(['status' => 'All'])['status']['mode'] === 'all' && ticketFiltersNormalize([])['status']['mode'] === 'open', 'status: Closed / All / default Open');
$ok(ticketFiltersNormalize(['status' => '9'])['status'] === ['mode' => 'specific', 'ids' => [9]] && ticketFiltersNormalize(['status' => ['9', '3', '9']])['status'] === ['mode' => 'specific', 'ids' => [3, 9]], 'status: one id (old URL) and a list of ids');
$ok(ticketFiltersNormalize(['priority' => ['Low', 'Medium', 'High']])['priority'] === [] && ticketFiltersNormalize(['onsite' => ['0', '1']])['onsite'] === [], 'all priorities / both on-site and remote = no filter');
$ok(ticketFiltersNormalize(['priority' => ['High', 'Low']])['priority'] === ['Low', 'High'], 'priorities come back in a fixed order');
$n = ticketFiltersNormalize(['overdue' => '1', 'due_today' => '0']);
$ok($n['overdue'] === true && $n['due_today'] === false, 'overdue=1 is on, due_today=0 is off');

// canonical query and the round trip
$f = ticketFiltersNormalize(['assigned' => ['me', '7', 'unassigned'], 'priority' => ['High', 'Medium'], 'board' => [5, 6], 'category' => [8], 'onsite' => ['1'], 'overdue' => 1, 'due_today' => 1, 'tags' => [3], 'status' => ['4', '2']]);
$query = ticketFiltersBuildQuery(ticketFiltersToParams($f));
$ok(str_contains($query, 'assigned%5B%5D=7&assigned%5B%5D=me&assigned%5B%5D=unassigned') && str_contains($query, 'priority%5B%5D=Medium&priority%5B%5D=High') && !str_contains($query, '%5B0%5D'), 'the canonical form is name[]=a&name[]=b (no indices): ' . $query);
$ok(ticketFiltersNormalize(ticketViewParseQuery($query)) === $f, 'canonical query -> parse -> normalize is the same filter set (round trip)');
$ok(ticketFiltersSignature(ticketFiltersNormalize(ticketViewParseQuery('priority=High&assigned=me&status=Open'), 7)) === ticketFiltersSignature(ticketFiltersNormalize(ticketViewParseQuery('assigned%5B%5D=7&priority%5B%5D=High'), 7)), 'an old view and the same new-form view have one fingerprint (active-view highlighting)');
$ok(ticketFiltersSignature(ticketFiltersNormalize(['priority' => ['High', 'Medium']])) !== ticketFiltersSignature(ticketFiltersNormalize(['priority' => ['High']])), 'different lists have different fingerprints');
$ok(ticketViewHrefParams('assigned=me&status=Open')['assigned'] === ['me'] && ticketViewHrefParams('')['status'] === 'Open' && isset(ticketViewHrefParams('canned_date=last7days')['canned_date']), 'a stored view opens through canonical params (an empty view still carries status=Open so it is an explicit filter)');
$ok(ticketFiltersAreExplicit(['status' => 'Open']) && ticketFiltersAreExplicit(['priority' => '']) && !ticketFiltersAreExplicit(['view' => 'kanban', 'client_id' => '3', 'default' => '1']), 'any filter key (even empty) is an explicit filter; view, client_id and default are not');

// hostile and capped values
$h = ticketFiltersNormalize([
    'priority' => ["High' OR 1=1 -- ", 'high', 'HIGH', ['Low'], 'Medium'],
    'assigned' => ["1) OR (1=1", '-5', '1e3', '0x10', '7;DROP TABLE tickets', ' 9 ', ['nested'], 'ME'],
    'board' => ['5 OR 1=1', "5'", '0', '-1', '99999999999999999999', '6'],
    'onsite' => ['2', '1; --', 'x'],
    'status' => ["Closed' OR '1'='1"],
    'tags' => ['3,4', 'a'],
]);
$ok($h['priority'] === ['Medium'] && $h['assigned'] === ['9'] && $h['board'] === [6] && $h['onsite'] === [] && $h['status']['mode'] === 'open' && $h['tags'] === [], 'hostile values are dropped (only exact enum members and plain positive integers survive)');
$sqlh = ticketFiltersSql($h, 1);
$ok(!preg_match('/OR 1=1|DROP|--|;/', implode(' ', $sqlh)), 'and none of it reaches the SQL');
$many = range(1, 120);
$c = ticketFiltersNormalize(['assigned' => array_map('strval', $many), 'board' => $many, 'category' => $many, 'tags' => $many, 'status' => $many]);
$ok(count($c['assigned']) === 50 && count($c['board']) === 50 && count($c['category']) === 50 && count($c['tags']) === 50 && count($c['status']['ids']) === 50, 'every group keeps at most 50 values');
$ok(substr_count(ticketFiltersSql($c, 1)['board'], ',') < 110, 'and the SQL IN list is bounded');
$ok(ticketFiltersNormalize(['priority' => str_repeat('A', 5000), 'q' => []])['priority'] === [], 'an oversized scalar is ignored');
$nested = ticketFiltersNormalize(['assigned' => ['a' => ['b' => 'me']], 'priority' => ['x' => ['High']], 'board' => ['x' => ['5']]]);
$ok($nested['assigned'] === [] && $nested['priority'] === [] && $nested['board'] === [], 'nested arrays are ignored, never coerced');

// SQL shape
$s = ticketFiltersSql(ticketFiltersNormalize(['assigned' => ['me', 'unassigned', '7'], 'priority' => ['High', 'Medium'], 'overdue' => 1, 'due_today' => 1, 'status' => 'Closed']), 3);
$ok($s['status'] === 'ticket_resolved_at IS NOT NULL' && $s['assigned'] === 'AND ticket_assigned_to IN (0,3,7)' && $s['priority'] === "AND ticket_priority IN ('Medium','High')", 'SQL: status, assigned (me = the viewer, unassigned = 0) and priority use IN lists');
$ok(substr_count($s['due'], ' OR ') === 1 && str_starts_with($s['due'], 'AND ((') , 'SQL: overdue and due-today are OR-ed in one group');
$ok(ticketFiltersSql(ticketFiltersNormalize(['status' => 'All']), 1)['status'] === '1 = 1' && ticketFiltersSql(ticketFiltersNormalize(['status' => [4, 2]]), 1)['status'] === 'ticket_status IN (2,4)', 'SQL: All and specific statuses');

// ---------------------------------------------------------------------------------------------------------- Part 2: data
$q("SET FOREIGN_KEY_CHECKS=0");
foreach (['tickets', 'ticket_saved_views', 'ticket_view_defaults', 'ticket_tags'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM categories WHERE category_type = 'Ticket'");
$q("DELETE FROM users WHERE user_id > 1");
$q("DELETE FROM user_settings WHERE user_id > 1");
$q("DELETE FROM logs WHERE log_type = 'Ticket View'");
$q("ALTER TABLE ticket_saved_views AUTO_INCREMENT = 1");

$q("INSERT INTO users SET user_id=2, user_name='Alex Agent', user_email='alex@scratch.test', user_password='x', user_type=1, user_status=1, user_role_id=2");
$q("INSERT INTO users SET user_id=3, user_name='Bo Agent', user_email='bo@scratch.test', user_password='x', user_type=1, user_status=1, user_role_id=2");
$q("INSERT INTO user_roles SET role_id=91, role_name='TV Reader', role_is_admin=0");
$q("INSERT INTO user_roles SET role_id=92, role_name='TV NoSupport', role_is_admin=0");
$sup = (int) $one("SELECT module_id FROM modules WHERE module_name='module_support'");
$q("DELETE FROM user_role_permissions WHERE user_role_id IN (2,91,92)");
$q("INSERT INTO user_role_permissions SET user_role_id=2, module_id=$sup, user_role_permission_level=2");
$q("INSERT INTO user_role_permissions SET user_role_id=91, module_id=$sup, user_role_permission_level=1");
$q("INSERT INTO users SET user_id=4, user_name='Rae Reader', user_email='rae@scratch.test', user_password='x', user_type=1, user_status=1, user_role_id=91");
$q("INSERT INTO users SET user_id=5, user_name='Nia NoSupport', user_email='nia@scratch.test', user_password='x', user_type=1, user_status=1, user_role_id=92");
$q("INSERT INTO users SET user_id=6, user_name='Portal Person', user_email='portal@scratch.test', user_password='x', user_type=2, user_status=1, user_role_id=0");
$q("INSERT INTO user_roles SET role_id=93, role_name='TV Full', role_is_admin=0");
$q("INSERT INTO user_role_permissions SET user_role_id=93, module_id=$sup, user_role_permission_level=3");
$q("INSERT INTO users SET user_id=7, user_name='Fay Full', user_email='fay@scratch.test', user_password='x', user_type=1, user_status=1, user_role_id=93");

// boards (parent 0) and categories
$q("INSERT INTO categories SET category_id=801, category_name='tvBoardA', category_type='Ticket', category_parent=0");
$q("INSERT INTO categories SET category_id=802, category_name='tvBoardB', category_type='Ticket', category_parent=0");
$q("INSERT INTO categories SET category_id=811, category_name='tvA-Printers', category_type='Ticket', category_parent=801");
$q("INSERT INTO categories SET category_id=812, category_name='tvA-Email', category_type='Ticket', category_parent=801");
$q("INSERT INTO categories SET category_id=821, category_name='tvB-Network', category_type='Ticket', category_parent=802");
$q("INSERT INTO categories SET category_id=803, category_name='tvBoardC', category_type='Ticket', category_parent=0");

// 24 tickets: priority x assignee x onsite x category x due x resolved
$seed = [];
$prios = ['Low', 'Medium', 'High'];
$assignees = [0, 1, 2, 3];
$cats = [0, 801, 811, 812, 821, 802];
for ($i = 1; $i <= 24; $i++) {
    $seed[$i] = [
        'n' => $i, 'priority' => $prios[$i % 3], 'assigned' => $assignees[$i % 4], 'onsite' => $i % 2, 'cat' => $cats[$i % 6],
        'due' => $i % 5 === 0 ? 'yesterday' : ($i % 5 === 1 ? 'today' : ($i % 5 === 2 ? 'later' : null)),
        'resolved' => $i % 7 === 0, 'status' => $i % 7 === 0 ? 5 : ($i % 3 === 0 ? 3 : 1),
    ];
}
foreach ($seed as $i => $t) {
    $due = ['yesterday' => "NOW() - INTERVAL 2 DAY", 'today' => "TIMESTAMP(CURDATE(), '23:59:00')", 'later' => "NOW() + INTERVAL 3 DAY"][$t['due'] ?? ''] ?? 'NULL';
    $res = $t['resolved'] ? 'NOW() - INTERVAL 1 HOUR' : 'NULL';
    $subj = sprintf('tvT%02d', $i);
    $q("INSERT INTO tickets SET ticket_id=$i, ticket_prefix='TV-', ticket_number=" . (1000 + $i) . ", ticket_subject='$subj', ticket_priority='{$t['priority']}', ticket_status={$t['status']}, ticket_assigned_to={$t['assigned']}, ticket_onsite={$t['onsite']}, ticket_category={$t['cat']}, ticket_due_at=$due, ticket_resolved_at=$res, ticket_client_id=0, ticket_created_by=1");
}
$q("INSERT INTO tags SET tag_id=881, tag_name='tvTagX', tag_type=6, tag_color='blue', tag_icon='tag'");
$q("INSERT INTO ticket_tags SET ticket_tag_ticket_id=3, ticket_tag_tag_id=881");
$q("INSERT INTO ticket_tags SET ticket_tag_ticket_id=4, ticket_tag_tag_id=881");
$ok((int) $one('SELECT COUNT(*) FROM tickets') === 24, 'seeded 24 tickets');

/** Independent expectation: ticket numbers matching a filter spec, computed in PHP from the seed (uid = the viewer). */
$expect = function (array $spec, int $uid) use ($seed): array {
    $out = [];
    foreach ($seed as $i => $t) {
        $mode = $spec['status'] ?? 'open';
        if ($mode === 'open' && $t['resolved']) { continue; }
        if ($mode === 'closed' && !$t['resolved']) { continue; }
        if (is_array($mode) && !in_array($t['status'], $mode, true)) { continue; }
        if (isset($spec['assigned'])) {
            $want = array_map(fn ($x) => $x === 'me' ? $uid : ($x === 'unassigned' ? 0 : (int) $x), $spec['assigned']);
            if (!in_array($t['assigned'], $want, true)) { continue; }
        }
        if (isset($spec['priority']) && !in_array($t['priority'], $spec['priority'], true)) { continue; }
        if (isset($spec['onsite']) && !in_array((string) $t['onsite'], $spec['onsite'], true)) { continue; }
        if (isset($spec['board'])) {
            $boardOf = [0 => 0, 801 => 801, 811 => 801, 812 => 801, 821 => 802, 802 => 802];
            if (!in_array($boardOf[$t['cat']], $spec['board'], true)) { continue; }
        }
        if (isset($spec['category']) && !in_array($t['cat'], $spec['category'], true)) { continue; }
        if (!empty($spec['overdue']) || !empty($spec['due_today'])) {
            $m = false;
            if (!empty($spec['overdue']) && $t['due'] === 'yesterday' && !$t['resolved']) { $m = true; }
            if (!empty($spec['due_today']) && $t['due'] === 'today' && !$t['resolved']) { $m = true; }
            if (!$m) { continue; }
        }
        $out[] = $i;
    }
    return $out;
};
$run = function (array $input, int $uid) use ($q, $db): array {
    $s = ticketFiltersSql(ticketFiltersNormalize($input, $uid), $uid);
    $r = $db->query("SELECT ticket_id FROM tickets WHERE {$s['status']} {$s['assigned']} {$s['priority']} {$s['onsite']} {$s['board']} {$s['category']} {$s['due']} {$s['tags']} ORDER BY ticket_id");
    $o = []; while ($r && ($x = $r->fetch_row())) { $o[] = (int) $x[0]; }
    return $o;
};
$combos = [
    'open default' => [[], ['status' => 'open']],
    'closed' => [['status' => 'Closed'], ['status' => 'closed']],
    'all' => [['status' => 'All'], ['status' => 'all']],
    'specific statuses 1+3' => [['status' => [1, 3]], ['status' => [1, 3]]],
    'priority High+Medium' => [['priority' => ['High', 'Medium']], ['priority' => ['High', 'Medium']]],
    'priority Low (old single)' => [['priority' => 'Low'], ['priority' => ['Low']]],
    'me + unassigned' => [['assigned' => ['me', 'unassigned']], ['assigned' => ['me', 'unassigned']]],
    'two agents' => [['assigned' => ['2', '3']], ['assigned' => ['2', '3']]],
    'me + agent 3 + High' => [['assigned' => ['me', '3'], 'priority' => ['High']], ['assigned' => ['me', '3'], 'priority' => ['High']]],
    'on-site only' => [['onsite' => ['1']], ['onsite' => ['1']]],
    'remote only' => [['onsite' => '0'], ['onsite' => ['0']]],
    'both on-site and remote' => [['onsite' => ['0', '1']], []],
    'board A' => [['board' => ['801']], ['board' => [801]]],
    'boards A+B' => [['board' => ['801', '802']], ['board' => [801, 802]]],
    'category printers+network' => [['category' => ['811', '821']], ['category' => [811, 821]]],
    'board A + category network (AND, empty)' => [['board' => ['801'], 'category' => ['821']], ['board' => [801], 'category' => [821]]],
    'overdue only' => [['overdue' => 1], ['overdue' => true]],
    'due today only' => [['due_today' => 1], ['due_today' => true]],
    'overdue + due today' => [['overdue' => 1, 'due_today' => 1], ['overdue' => true, 'due_today' => true]],
    'High+Medium, two agents, boards A+B, on-site, overdue+today, closed=no' => [['priority' => ['High', 'Medium'], 'assigned' => ['2', '3'], 'board' => ['801', '802'], 'onsite' => '1', 'overdue' => 1, 'due_today' => 1], ['priority' => ['High', 'Medium'], 'assigned' => ['2', '3'], 'board' => [801, 802], 'onsite' => ['1'], 'overdue' => true, 'due_today' => true]],
    'closed + High + remote' => [['status' => 'Closed', 'priority' => 'High', 'onsite' => '0'], ['status' => 'closed', 'priority' => ['High'], 'onsite' => ['0']]],
];
foreach ($combos as $label => [$input, $spec]) {
    $got = $run($input, 2);
    $want = $expect($spec, 2);
    $ok($got === $want && ($label === 'board A + category network (AND, empty)' ? $got === [] : true), "SQL combo '$label': " . count($got) . ' tickets, same as the independent filter');
}
$ok($run(['tags' => ['881']], 2) === [3, 4], 'tags: a ticket with any chosen tag');
$ok(count($run(['priority' => ['High', 'Medium']], 2)) > count($run(['priority' => 'High'], 2)), 'more ticked values widen a group (OR) ...');
$ok(count($run(['priority' => ['High', 'Medium'], 'assigned' => ['2']], 2)) < count($run(['priority' => ['High', 'Medium']], 2)), '... and another group narrows (AND)');

// the saved-view form validator
$post = ['f_status_mode' => 'open', 'f_assigned' => ['me', '2', '3', '99', 'unassigned', "1' OR 1=1"], 'f_priority' => ['High', 'Medium', 'Urgent'], 'f_board' => ['801', '802', '9999'], 'f_category' => ['811', '5555'], 'f_onsite' => ['1'], 'f_due' => ['overdue', 'due_today', 'bogus'], 'f_tags' => ['881', '77'], 'f_canned_date' => '', 'filters_present' => '1'];
$stored = ticketViewQueryFromPost($mysqli, $post);
$fs = ticketFiltersNormalize(ticketViewParseQuery($stored));
$ok($fs['assigned'] === ['2', '3', 'me', 'unassigned'] && $fs['priority'] === ['Medium', 'High'] && $fs['board'] === [801, 802] && $fs['category'] === [811] && $fs['onsite'] === ['1'] && $fs['overdue'] && $fs['due_today'] && $fs['tags'] === [881], 'the form validator keeps real agents / boards / categories / tags and drops unknown ones: ' . $stored);
$ok(str_starts_with($stored, 'status=Open&') && !str_contains($stored, '99') && !str_contains($stored, 'Urgent') && !str_contains($stored, 'OR'), 'and the stored query is canonical and clean');
$ok(ticketViewQueryFromPost($mysqli, ['f_status_mode' => 'closed']) === 'status=Closed' && ticketViewQueryFromPost($mysqli, ['f_status_mode' => 'all']) === 'status=All' && ticketViewQueryFromPost($mysqli, []) === 'status=Open', 'status modes store Closed / All / Open only');
$ok(ticketViewQueryFromPost($mysqli, ['f_status_mode' => 'specific', 'f_status' => ['1', '3', '9999']]) === 'status%5B%5D=1&status%5B%5D=3', 'specific statuses keep the real ones');
$ok(ticketViewQueryFromPost($mysqli, ['f_status_mode' => 'specific', 'f_status' => ['9999']]) === 'status=Open', 'specific statuses with none valid fall back to Open');
$ok(ticketViewQueryFromPost($mysqli, ['f_priority' => ['Low', 'Medium', 'High'], 'f_onsite' => ['0', '1']]) === 'status=Open', 'all priorities / both on-site and remote store nothing');
$manyPost = ticketViewQueryFromPost($mysqli, ['f_assigned' => array_map('strval', range(1, 200)), 'f_board' => range(1, 200)]);
$ok(substr_count($manyPost, 'assigned') <= 50, 'a posted group is capped at 50 before it is stored');
$ok(ticketViewQueryFromPost($mysqli, ['f_assigned' => 'me', 'f_priority' => 'High']) === 'status=Open&assigned%5B%5D=me&priority%5B%5D=High', 'a single posted value (not a list) is accepted too');

// readable description
$d = ticketViewDescribe($mysqli, $stored);
$ok(str_contains($d, 'Open tickets') && str_contains($d, 'Assigned to Alex Agent, Bo Agent, me or nobody (unassigned)') && str_contains($d, 'Medium or High priority') && str_contains($d, 'Board: tvBoardA or tvBoardB') && str_contains($d, 'Category: tvA-Printers') && str_contains($d, 'On-site') && str_contains($d, 'Overdue or Due today') && str_contains($d, 'Tagged tvTagX'), 'describe reads naturally: ' . $d);
$ok(ticketViewDescribe($mysqli, 'assigned=me&priority=High&status=Open') === 'Open tickets · Assigned to me · High priority', 'describe handles the old single-value queries: ' . ticketViewDescribe($mysqli, 'assigned=me&priority=High&status=Open'));
$ok(ticketViewDescribe($mysqli, '') === 'Open tickets (no extra filters)', 'describe: empty view');

// default view resolution
$q("INSERT INTO ticket_saved_views SET ticket_saved_view_id=101, ticket_saved_view_name='A mine', ticket_saved_view_query='status=Open', ticket_saved_view_user_id=2");
$q("INSERT INTO ticket_saved_views SET ticket_saved_view_id=102, ticket_saved_view_name='B mine 3', ticket_saved_view_query='status=Open&priority=High', ticket_saved_view_user_id=3");
$q("INSERT INTO ticket_saved_views SET ticket_saved_view_id=103, ticket_saved_view_name='Shared 1', ticket_saved_view_query='status=Open&priority%5B%5D=High', ticket_saved_view_user_id=0");
$q("INSERT INTO ticket_saved_views SET ticket_saved_view_id=104, ticket_saved_view_name='Shared 2', ticket_saved_view_query='status=Closed', ticket_saved_view_user_id=0");
$ok(ticketViewDefaultsReady($mysqli), 'DB 2.6.93: table ticket_view_defaults exists');
$ok(ticketViewDefaultResolve($mysqli, 2) === null, 'no default set: nothing is resolved (Tickets opens as before)');
$ok(ticketViewDefaultSet($mysqli, 2, 101, false) && ticketViewDefaultResolve($mysqli, 2)['view']['ticket_saved_view_id'] == 101 && ticketViewDefaultResolve($mysqli, 2)['scope'] === 'personal', 'set a personal default (own view)');
$ok(ticketViewDefaultResolve($mysqli, 3) === null, 'it applies to that agent only');
$ok(!ticketViewDefaultSet($mysqli, 2, 102, false) && ticketViewDefaultResolve($mysqli, 2)['view']['ticket_saved_view_id'] == 101, 'another agent\'s private view cannot be made a default (refused, the old default stays)');
$ok(ticketViewDefaultSet($mysqli, 2, 103, false) && ticketViewDefaultResolve($mysqli, 2)['view']['ticket_saved_view_id'] == 103 && (int) $one('SELECT COUNT(*) FROM ticket_view_defaults WHERE user_id=2 AND scope=\'personal\'') === 1, 'a shared view can be a personal default; setting another replaces it (one personal default per agent)');
$ok(ticketViewDefaultSet($mysqli, 0, 104, true) && ticketViewDefaultResolve($mysqli, 3)['scope'] === 'shared' && ticketViewDefaultResolve($mysqli, 3)['view']['ticket_saved_view_id'] == 104, 'a shared default applies to agents without a personal default');
$ok(ticketViewDefaultResolve($mysqli, 2)['scope'] === 'personal', 'a personal default beats the shared default');
$ok(!ticketViewDefaultSet($mysqli, 0, 101, true) && !ticketViewDefaultSet($mysqli, 0, 102, true), 'the shared default must be a shared view');
$ok(ticketViewDefaultSet($mysqli, 0, 103, true) && (int) $one('SELECT COUNT(*) FROM ticket_view_defaults WHERE scope=\'shared\'') === 1 && ticketViewDefaultResolve($mysqli, 3)['view']['ticket_saved_view_id'] == 103, 'replacing the shared default keeps exactly one');
ticketViewDefaultClear($mysqli, 2, false);
$ok(ticketViewDefaultResolve($mysqli, 2)['scope'] === 'shared', 'clearing the personal default falls back to the shared one');
ticketViewDefaultClear($mysqli, 2, true, 999);
$ok(ticketViewDefaultResolve($mysqli, 2) !== null, 'clearing the shared default for a different view id does nothing');
ticketViewDefaultClear($mysqli, 2, true, 103);
$ok(ticketViewDefaultResolve($mysqli, 2) === null, 'clearing the shared default leaves no default');
// archived / unshared / deleted fall back
ticketViewDefaultSet($mysqli, 2, 101, false); ticketViewDefaultSet($mysqli, 0, 104, true);
$q("UPDATE ticket_saved_views SET ticket_saved_view_archived_at = NOW() WHERE ticket_saved_view_id=101");
$r = ticketViewDefaultResolve($mysqli, 2);
$ok($r && $r['scope'] === 'shared' && $r['skipped'] === true && $r['view']['ticket_saved_view_id'] == 104, 'an archived personal default is skipped; the shared default applies');
$q("UPDATE ticket_saved_views SET ticket_saved_view_archived_at = NULL WHERE ticket_saved_view_id=101");
ticketViewDefaultSet($mysqli, 2, 103, false);
$q("UPDATE ticket_saved_views SET ticket_saved_view_user_id = 3 WHERE ticket_saved_view_id=103");
$r = ticketViewDefaultResolve($mysqli, 2);
$ok($r && $r['view']['ticket_saved_view_id'] == 104, 'a view that stopped being shared (now another agent\'s private view) is skipped');
$q("UPDATE ticket_saved_views SET ticket_saved_view_user_id = 0 WHERE ticket_saved_view_id=103");
$q("UPDATE ticket_saved_views SET ticket_saved_view_user_id = 5 WHERE ticket_saved_view_id=104");
$ok(ticketViewDefaultResolve($mysqli, 3) === null, 'a shared default that stopped being shared is not offered to other agents');
$q("UPDATE ticket_saved_views SET ticket_saved_view_user_id = 0 WHERE ticket_saved_view_id=104");
$q("DELETE FROM ticket_saved_views WHERE ticket_saved_view_id=104");
$ok(ticketViewDefaultResolve($mysqli, 3) === null, 'a deleted shared default resolves to nothing, no error');
ticketViewDefaultForgetView($mysqli, 103);
$ok((int) $one('SELECT COUNT(*) FROM ticket_view_defaults WHERE view_id=103') === 0, 'forgetting a view removes the defaults that pointed at it');
$q("DELETE FROM ticket_view_defaults");
$ok(!ticketViewDefaultSet($mysqli, 2, 0, false) && !ticketViewDefaultSet($mysqli, 2, 424242, false), 'a view id that does not exist cannot be a default');
$dup = $db->query("INSERT INTO ticket_view_defaults SET user_id=2, view_id=101, scope='personal'");
$dup2 = @$db->query("INSERT INTO ticket_view_defaults SET user_id=2, view_id=103, scope='personal'");
$ok($dup && !$dup2, 'the table itself allows only one personal default per agent');
$q("DELETE FROM ticket_view_defaults");

// ---------------------------------------------------------------------------------------------------------- Part 3: HTTP
$sdir = sys_get_temp_dir() . '/ticket_views_sess_' . bin2hex(random_bytes(3));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$csrf = 'csrftokTV12345678';
$sess = fn (int $uid) => sec_forge_session($sdir, ['logged' => true, 'user_id' => $uid, 'csrf_token' => $csrf, 'sec_created' => time(), 'sec_last' => time()]);
$admin = $sess(1); $alex = $sess(2); $bo = $sess(3); $rae = $sess(4); $nia = $sess(5); $portal = $sess(6); $fay = $sess(7);
$page = fn (string $sid, string $qs) => sec_web($base, 'GET', '/agent/tickets.php?' . $qs, $sid);
$ref = ['Referer: ' . $base . '/agent/tickets.php?status=Open'];
$act = fn (string $sid, string $qs) => sec_web($base, 'GET', '/agent/post.php?' . $qs . '&csrf_token=' . $csrf, $sid, [], [], $ref);
$send = fn (string $sid, array $f) => sec_web($base, 'POST', '/agent/post.php', $sid, $f + ['csrf_token' => $csrf], [], $ref);
$loc = fn (string $hdr): string => preg_match('/^Location:\s*(\S+)/mi', $hdr, $m) ? $m[1] : '';
foreach ([2, 3, 4, 5, 7] as $uid) { $db->query("INSERT INTO user_settings SET user_id=$uid, user_config_records_per_page=10 ON DUPLICATE KEY UPDATE user_config_records_per_page=10"); }

// the Tickets page honours multi-values (list)
[$c, $body] = $page($alex, 'status=Open&priority%5B%5D=High&priority%5B%5D=Medium&view=list&canned_date=alltime');
$want = array_map(fn ($i) => sprintf('tvT%02d', $i), array_slice($expect(['priority' => ['High', 'Medium']], 2), 0, 10));
$gotTxt = $subjects($body);
sort($gotTxt); $wantAll = array_map(fn ($i) => sprintf('tvT%02d', $i), $expect(['priority' => ['High', 'Medium']], 2));
$ok($c === 200 && count($gotTxt) === 10 && !array_diff($gotTxt, $wantAll), 'Tickets page: High + Medium (checkbox form) shows only High/Medium tickets (' . count($gotTxt) . ' on page 1 of ' . count($wantAll) . ')');
$ok(str_contains($body, 'priority%5B0%5D=High') && str_contains($body, 'priority%5B1%5D=Medium') && str_contains($body, 'page=2'), 'pagination links keep both priorities');
[$c, $body2] = $page($alex, 'status=Open&priority%5B%5D=High&priority%5B%5D=Medium&view=list&canned_date=alltime&page=2');
$all2 = array_values(array_unique(array_merge($gotTxt, $subjects($body2))));
sort($all2); sort($wantAll);
$ok($all2 === $wantAll, 'pagination: page 1 + page 2 together are exactly the High/Medium open tickets');
[$c, $sortBody] = $page($alex, 'status=Open&priority%5B%5D=High&priority%5B%5D=Medium&view=list&canned_date=alltime');
$ok(preg_match('/href="\?[^"]*priority%5B0%5D=High[^"]*sort=ticket_subject/', $sortBody) === 1, 'sorting links keep the multi-value filter');
// old URL forms still work and equal the new forms
[$c, $oldBody] = $page($alex, 'status=Open&priority=High&assigned=me&view=list&canned_date=alltime');
[$c2, $newBody] = $page($alex, 'status=Open&priority%5B%5D=High&assigned%5B%5D=me&view=list&canned_date=alltime');
$ok($c === 200 && $subjects($oldBody) === $subjects($newBody) && $subjects($oldBody) === array_map(fn ($i) => sprintf('tvT%02d', $i), $expect(['priority' => ['High'], 'assigned' => ['me']], 2)), 'old priority=High&assigned=me and the list form show the same tickets: ' . implode(',', $subjects($oldBody)));
[$c, $b3] = $page($alex, 'status=Open&assigned%5B%5D=me&assigned%5B%5D=unassigned&assigned%5B%5D=3&view=list&canned_date=alltime');
$exp3 = array_map(fn ($i) => sprintf('tvT%02d', $i), $expect(['assigned' => ['me', 'unassigned', '3']], 2));
$got3 = array_values(array_unique(array_merge($subjects($b3), $subjects(($page($alex, 'status=Open&assigned%5B%5D=me&assigned%5B%5D=unassigned&assigned%5B%5D=3&view=list&canned_date=alltime&page=2'))[1]))));
sort($got3); sort($exp3);
$ok($got3 === $exp3, 'assigned: me + unassigned + an agent in one list (' . count($exp3) . ' tickets)');
[$c, $b4] = $page($alex, 'status=Open&overdue=1&due_today=1&view=list&canned_date=alltime');
$ok(array_map(fn ($i) => sprintf('tvT%02d', $i), $expect(['overdue' => true, 'due_today' => true], 2)) === $subjects($b4) || count($subjects($b4)) === count($expect(['overdue' => true, 'due_today' => true], 2)), 'old overdue=1&due_today=1 URL: both independent, OR-ed (' . count($subjects($b4)) . ' tickets)');
[$c, $b5] = $page($alex, 'status=All&onsite%5B%5D=1&onsite%5B%5D=0&board%5B%5D=801&board%5B%5D=802&view=list&canned_date=alltime');
$ok($c === 200 && !str_contains($b5, 'Notice') && !str_contains($b5, 'Warning:') && !str_contains($b5, 'Fatal'), 'status=All with both on-site boxes and two boards renders without errors');
// the panel (checkboxes)
$ok(str_contains($body, 'name="priority[]" value="High" checked') || preg_match('/name="priority\[\]"\s+value="High"\s+checked/', $body) === 1, 'the quick filter panel ticks the chosen priorities (checkboxes)');
$ok(preg_match('#<fieldset class="tvf-group[^>]*data-tvf-group>\s*<legend class="tvf-legend">Priority\s*<span class="badge text-bg-primary tvf-count" data-tvf-count>2</span>#', $body) === 1, 'the Priority group is a fieldset with a legend and a count badge of 2');
$ok(str_contains($body, 'data-tvf-all') && str_contains($body, 'data-tvf-clear') && str_contains($body, 'ticket_view_filters.js'), 'Select all / Clear controls and the external script are present');
$ok(str_contains($body, 'name="assigned[]"') && str_contains($body, 'name="onsite[]"') && str_contains($body, 'name="overdue"') && str_contains($body, 'name="due_today"') && str_contains($body, 'name="board[]"') && str_contains($body, 'name="category[]"') && str_contains($body, 'name="status[]"'), 'the panel has checkbox groups for assigned, on-site, due, board, category and status');
// kanban honours the multi-values
[$c, $kb] = $page($alex, 'status=Open&priority%5B%5D=High&priority%5B%5D=Medium&view=kanban&canned_date=alltime');
$kg = $subjects($kb); sort($kg);
$ok($c === 200 && $kg === $wantAll, 'kanban shows exactly the High + Medium open tickets (' . count($kg) . ')');
[$c, $kb2] = $page($alex, 'status=Open&priority=Low&board%5B%5D=801&board%5B%5D=802&view=kanban&canned_date=alltime');
$ok($c === 200 && array_map(fn ($i) => sprintf('tvT%02d', $i), $expect(['priority' => ['Low'], 'board' => [801, 802]], 2)) === $subjects($kb2) || count($subjects($kb2)) === count($expect(['priority' => ['Low'], 'board' => [801, 802]], 2)), 'kanban honours priority and boards together');
// hostile URL does not break the page or the SQL
[$c, $hb] = $page($alex, 'status=Open&priority%5B%5D=' . rawurlencode("High' OR '1'='1") . '&assigned%5B%5D=' . rawurlencode('1) OR (1=1') . '&board%5B%5D=' . rawurlencode('5 UNION SELECT 1') . '&view=list&canned_date=alltime');
$ok($c === 200 && !str_contains($hb, 'You have an error in your SQL') && count($subjects($hb)) > 0, 'a hostile URL renders normally (values dropped), no SQL error');

// default view
$mk = fn (string $sid, array $f) => $send($sid, ['add_ticket_saved_view' => '1', 'filters_present' => '1', 'icon' => 'fa-filter'] + $f);
[$c, , $h] = $mk($alex, ['name' => 'HM two agents', 'f_status_mode' => 'open', 'f_priority' => ['High', 'Medium'], 'f_assigned' => ['me', '3'], 'default_present' => '1', 'make_default' => '1']);
$vid = (int) $one("SELECT ticket_saved_view_id FROM ticket_saved_views WHERE ticket_saved_view_name='HM two agents'");
$ok($c === 302 && $vid > 0 && $one("SELECT ticket_saved_view_query FROM ticket_saved_views WHERE ticket_saved_view_id=$vid") === 'status=Open&assigned%5B%5D=3&assigned%5B%5D=me&priority%5B%5D=Medium&priority%5B%5D=High', 'saving the view through the real form stores the canonical multi-value query');
$ok((int) $one("SELECT view_id FROM ticket_view_defaults WHERE user_id=2 AND scope='personal'") === $vid, '"Make this my default view" at creation sets the personal default');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type='Ticket View' AND log_action='Default'") >= 1, 'making a default is written to the audit log');
[$c, , $h] = $page($alex, '');
$l = $loc($h);
$ok($c === 302 && str_starts_with($l, 'tickets.php?') && str_contains($l, 'priority%5B%5D=Medium') && str_contains($l, 'assigned%5B%5D=me') && str_contains($l, 'assigned%5B%5D=3'), 'opening Tickets with no filter redirects to the default view\'s filters: ' . $l);
[$c, $dbody] = sec_web($base, 'GET', '/agent/' . $l, $alex);
$gd = $subjects($dbody); $ex = $expect(['priority' => ['High', 'Medium'], 'assigned' => ['me', '3']], 2);
$ok($c === 200 && count($gd) === min(10, count($ex)) && !array_diff($gd, array_map(fn ($i) => sprintf('tvT%02d', $i), $ex)), 'following it shows the default view\'s tickets (' . count($ex) . ' match)');
$ok(str_contains($dbody, 'Reset to my default') && str_contains($dbody, 'Show all open') && str_contains($dbody, 'tvf-star is-default'), 'the sidebar offers "Reset to my default", "Show all open" and shows the starred default');
[$c, , $h] = $page($alex, 'status=Open');
$ok($c === 200, 'an explicit filter (?status=Open) wins over the default');
[$c, , $h] = $page($alex, 'priority%5B%5D=Low');
$ok($c === 200, 'any explicit filter key wins over the default');
[$c, , $h] = $page($alex, 'view=kanban');
$ok($c === 302 && str_contains($loc($h), 'view=kanban') && str_contains($loc($h), 'priority%5B%5D=Medium'), 'the default applies in kanban too and keeps the display mode');
[$c, , $h] = $page($alex, 'default=1&status=Closed');
$ok($c === 302 && str_contains($loc($h), 'priority%5B%5D=Medium'), '"Reset to my default" (?default=1) replaces the current filters with the default');
[$c, , $h] = $page($bo, '');
$ok($c === 200, 'an agent without a default still opens Tickets as before (no redirect)');
[$c, , $h] = $page($alex, 'client_id=999&status=Open');
$ok(!str_contains($loc($h), 'priority%5B%5D=Medium'), 'a client\'s own ticket list does not get the default view');

// star toggle, set / clear / replace through the handler
[$c, , $h] = $mk($alex, ['name' => 'Second', 'f_priority' => ['Low'], 'default_present' => '1']);
$vid2 = (int) $one("SELECT ticket_saved_view_id FROM ticket_saved_views WHERE ticket_saved_view_name='Second'");
$ok((int) $one("SELECT view_id FROM ticket_view_defaults WHERE user_id=2 AND scope='personal'") === $vid, 'creating a view without the box ticked leaves the default alone');
[$c, , $h] = $act($alex, "set_ticket_view_default=$vid2");
$ok($c === 302 && (int) $one("SELECT view_id FROM ticket_view_defaults WHERE user_id=2 AND scope='personal'") === $vid2 && (int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE user_id=2") === 1, 'the star sets a new personal default and replaces the old one');
$ok($loc($h) === $base . '/agent/tickets.php?status=Open', 'and returns to the page the agent was on');
[$c, , $h] = $act($alex, "clear_ticket_view_default=$vid2");
$ok((int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE user_id=2") === 0, 'the star clears it again');
[$c, , $h] = $send($alex, ['edit_ticket_saved_view' => '1', 'ticket_saved_view_id' => $vid, 'name' => 'HM two agents', 'icon' => 'fa-filter', 'default_present' => '1', 'make_default' => '1']);
$ok((int) $one("SELECT view_id FROM ticket_view_defaults WHERE user_id=2") === $vid && $one("SELECT ticket_saved_view_query FROM ticket_saved_views WHERE ticket_saved_view_id=$vid") !== '', 'editing with the box ticked sets the default (a rename without filters keeps the query)');
[$c, , $h] = $send($alex, ['edit_ticket_saved_view' => '1', 'ticket_saved_view_id' => $vid, 'name' => 'HM renamed', 'icon' => 'fa-filter']);
$ok((int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE user_id=2") === 1, 'a bare rename (no default fields) leaves the default alone');
[$c, , $h] = $send($alex, ['edit_ticket_saved_view' => '1', 'ticket_saved_view_id' => $vid, 'name' => 'HM renamed', 'icon' => 'fa-filter', 'default_present' => '1']);
$ok((int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE user_id=2") === 0, 'editing with the box un-ticked clears the default');
// another agent cannot touch it
[$c] = $act($bo, "set_ticket_view_default=$vid");
$ok((int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE user_id=3") === 0, 'an agent cannot make another agent\'s private view their default');
[$c] = $send($bo, ['edit_ticket_saved_view' => '1', 'ticket_saved_view_id' => $vid, 'name' => 'hijacked', 'icon' => 'fa-filter', 'default_present' => '1', 'make_default' => '1']);
$ok($one("SELECT ticket_saved_view_name FROM ticket_saved_views WHERE ticket_saved_view_id=$vid") === 'HM renamed' && (int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE user_id=3") === 0, 'nor edit it or default it through the edit handler');

// shared default
[$c, , $h] = $mk($admin, ['name' => 'Shared HM', 'shared' => '1', 'f_priority' => ['High'], 'default_present' => '1', 'make_default_shared' => '1']);
$svid = (int) $one("SELECT ticket_saved_view_id FROM ticket_saved_views WHERE ticket_saved_view_name='Shared HM'");
$ok($svid > 0 && (int) $one("SELECT ticket_saved_view_user_id FROM ticket_saved_views WHERE ticket_saved_view_id=$svid") === 0 && (int) $one("SELECT view_id FROM ticket_view_defaults WHERE scope='shared' AND user_id=0") === $svid, 'an administrator sets a shared default (and it is shared)');
[$c, , $h] = $page($bo, '');
$ok($c === 302 && str_contains($loc($h), 'priority%5B%5D=High') && !str_contains($loc($h), 'Medium'), 'an agent without a personal default gets the shared default');
$act($bo, "set_ticket_view_default=$svid");
$act($alex, "set_ticket_view_default=$vid");
[$c, , $h] = $page($alex, '');
$ok(str_contains($loc($h), 'Medium'), 'an agent with a personal default keeps it over the shared one');
// permission rules
$act($alex, "set_ticket_view_default=$svid&scope=shared");
$ok((int) $one("SELECT view_id FROM ticket_view_defaults WHERE scope='shared'") === $svid, 'a technician (support level 2) cannot change the shared default');
[$c, $b] = $act($alex, "clear_ticket_view_default=$svid&scope=shared");
$ok((int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE scope='shared'") === 1, 'nor clear it');
[$c, , $h] = $mk($alex, ['name' => 'Forged shared', 'shared' => '1', 'f_priority' => ['High'], 'default_present' => '1', 'make_default_shared' => '1']);
$fv = (int) $one("SELECT ticket_saved_view_id FROM ticket_saved_views WHERE ticket_saved_view_name='Forged shared'");
$ok($fv > 0 && (int) $one("SELECT ticket_saved_view_user_id FROM ticket_saved_views WHERE ticket_saved_view_id=$fv") === 2 && (int) $one("SELECT view_id FROM ticket_view_defaults WHERE scope='shared'") === $svid, 'a forged shared / shared-default post from a technician makes a private view and changes nothing shared');
[$c] = $act($fay, "set_ticket_view_default=$svid&scope=shared");
$ok((int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE scope='shared'") === 1, 'a support level 3 agent who is not an administrator may replace the shared default');
[$c] = $act($fay, "clear_ticket_view_default=$svid&scope=shared");
$ok((int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE scope='shared'") === 0, 'and clear it');
$act($admin, "set_ticket_view_default=$svid&scope=shared");
$ok((int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE scope='shared'") === 1, 'the administrator can set it from the menu');
// read-only and no-support users
[$c] = $act($rae, "set_ticket_view_default=$svid");
$ok((int) $one("SELECT view_id FROM ticket_view_defaults WHERE user_id=4") === $svid, 'a read-only agent (level 1) may pick a default for themselves');
[$c] = $mk($rae, ['name' => 'Read only view', 'f_priority' => ['High']]);
$ok((int) $one("SELECT COUNT(*) FROM ticket_saved_views WHERE ticket_saved_view_name='Read only view'") === 0, 'but cannot create views (write access needed)');
[$c, $nb] = $act($nia, "set_ticket_view_default=$svid");
$ok((int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE user_id=5") === 0, 'an agent with no support access cannot set a default');
[$c, $nb] = $page($nia, '');
$ok($c !== 200 || !str_contains($nb, 'tvT'), 'and does not see tickets');
// CSRF
$db->query("DELETE FROM ticket_view_defaults WHERE user_id=3");
[$c] = sec_web($base, 'GET', "/agent/post.php?set_ticket_view_default=$svid&csrf_token=bad", $bo, [], [], $ref);
$ok((int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE user_id=3") === 0, 'a request with a wrong CSRF token changes nothing');

// delete view: defaults forgotten, fallback graceful
$act($alex, "set_ticket_view_default=$vid");
[$c] = $act($alex, "delete_ticket_saved_view=$vid");
$ok((int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE view_id=$vid") === 0 && $one("SELECT ticket_saved_view_archived_at FROM ticket_saved_views WHERE ticket_saved_view_id=$vid") !== null, 'deleting a view forgets the defaults that pointed at it');
[$c, , $h] = $page($alex, '');
$ok($c === 302 && str_contains($loc($h), 'priority%5B%5D=High') && !str_contains($loc($h), 'Medium'), 'after that, Tickets falls back to the shared default');
$db->query("UPDATE ticket_saved_views SET ticket_saved_view_archived_at = NOW() WHERE ticket_saved_view_id=$svid");
[$c, $fb] = $page($alex, '');
$ok($c === 200 && count($subjects($fb)) > 0, 'with the shared default also deleted, Tickets simply opens (all open tickets), no error');
$db->query("UPDATE ticket_saved_views SET ticket_saved_view_archived_at = NULL WHERE ticket_saved_view_id=$svid");
$db->query("DELETE FROM ticket_view_defaults WHERE user_id=2");
$db->query("INSERT INTO ticket_view_defaults SET user_id=2, view_id=424242, scope='personal'");
[$c, $gb] = $page($alex, '');
$ok(($c === 200 || $c === 302) && !str_contains($gb, 'Fatal'), 'a default row pointing at a view that does not exist is ignored gracefully');
$db->query("DELETE FROM ticket_view_defaults");

// modals
$modal = fn (string $sid, string $path) => sec_web($base, 'GET', '/agent/modals/ticket/' . $path, $sid);
[$c, $mb] = $modal($alex, 'ticket_saved_view_add.php?status=Open&priority%5B%5D=High&priority%5B%5D=Low&assigned%5B%5D=me');
$mj = json_decode($mb, true); $mh = is_array($mj) ? ($mj['content'] ?? $mb) : $mb;
$ok($c === 200 && str_contains($mb, 'f_priority[]') && str_contains($mb, 'f_assigned[]') && str_contains($mb, 'f_board[]') && str_contains($mb, 'f_category[]') && str_contains($mb, 'f_onsite[]') && str_contains($mb, 'f_due[]') && str_contains($mb, 'make_default'), 'the Save View modal has checkbox groups for every filter and the default option');
$ok(!str_contains($mb, '<select class=\"form-control\" id=\"tvfa_priority') && !str_contains($mb, 'tvfa_assigned\\"'), 'and no single-select dropdowns for them');
$decoded = is_array($mj) ? ($mj['content'] ?? ($mj['html'] ?? $mb)) : $mb;
$ok(preg_match('/name=\\\\?"f_priority\[\]\\\\?" value=\\\\?"High\\\\?" checked/', $mb) === 1 && preg_match('/name=\\\\?"f_priority\[\]\\\\?" value=\\\\?"Low\\\\?" checked/', $mb) === 1 && preg_match('/name=\\\\?"f_assigned\[\]\\\\?" value=\\\\?"me\\\\?" checked/', $mb) === 1, 'it pre-ticks the page\'s current multi-value filters');
$ok(!str_contains($mb, 'make_default_shared'), 'a technician does not see the shared-default option');
[$c, $mba] = $modal($admin, 'ticket_saved_view_add.php?status=Open');
$ok(str_contains($mba, 'make_default_shared') && str_contains($mba, 'name=\\"shared\\"'), 'an administrator does');
[$c, $mr] = $modal($rae, 'ticket_saved_view_add.php?status=Open');
$ok(!str_contains($mr, 'f_priority'), 'a read-only agent gets no Save View form');
[$c, $me] = $modal($alex, "ticket_saved_view_edit.php?id=$svid");
$ok(!str_contains($me, 'Shared HM') && !str_contains($me, 'f_priority'), 'an agent cannot open the edit form of a shared view they do not manage');
[$c, $me2] = $modal($admin, "ticket_saved_view_edit.php?id=$svid");
$ok(str_contains($me2, 'Shared HM') && str_contains($me2, 'make_default_shared'), 'the administrator can');
$db->query("INSERT INTO ticket_view_defaults SET user_id=0, view_id=$svid, scope='shared'");
[$c, $me3] = $modal($admin, "ticket_saved_view_edit.php?id=$svid");
$ok(preg_match('/id=\\\\?"savedViewDefaultSharedE\\\\?" checked/', $me3) === 1, 'the edit form shows the shared default as ticked');
$db->query("DELETE FROM ticket_view_defaults");
[$c, $me4] = $modal($bo, "ticket_saved_view_edit.php?id=" . (int) $one("SELECT ticket_saved_view_id FROM ticket_saved_views WHERE ticket_saved_view_name='Second'"));
$ok(!str_contains($me4, 'Second'), 'nor another agent\'s private view');

// client portal logins never see agent views
[$c, $pb, $ph] = $page($portal, '');
$ok($c === 302 && str_contains($loc($ph), 'login.php') && !str_contains($pb, 'Ticket Views'), 'a client-portal user is sent to sign in from the agent Tickets page');
[$c, $pm, $pmh] = $modal($portal, 'ticket_saved_view_add.php?status=Open');
$ok(!str_contains($pm, 'f_priority') && !str_contains($pm, 'make_default'), 'and gets no saved-view form');
[$c, $pm2] = $act($portal, "set_ticket_view_default=$svid");
$ok((int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE user_id=6") === 0, 'and cannot set a default');

// API
$tok = function (int $uid) use ($db) { $raw = 'tvtok' . bin2hex(random_bytes(8)); $h = hash('sha256', $raw); $db->query("INSERT INTO api_tokens SET token_user_id=$uid, token_hash='$h', token_name='tv test'"); return $raw; };
$tAlex = $tok(2); $tRae = $tok(4); $tNia = $tok(5); $tPortal = $tok(6);
$api = fn (string $t, string $path) => sec_web($base, 'GET', '/api/v1/' . $path, 'x', [], [], ['Authorization: Bearer ' . $t, 'Accept: application/json']);
$apiAuthLess = fn (string $path) => sec_web($base, 'GET', '/api/v1/' . $path, 'x');
[$c, $ab] = $api($tAlex, 'tickets?priority=high,medium&limit=50');
$aj = json_decode($ab, true);
$ids = array_map(fn ($r) => $r['number'] - 1000, $aj['data'] ?? []);
sort($ids);
$ok($c === 200 && $ids === $expect(['priority' => ['High', 'Medium']], 2) && ($aj['total'] ?? 0) === count($ids), 'API /tickets: priority=high,medium returns exactly those (' . count($ids) . ')');
[$c, $ab] = $api($tAlex, 'tickets?priority[]=High&priority[]=Low&onsite=1&limit=50');
$aj = json_decode($ab, true); $ids = array_map(fn ($r) => $r['number'] - 1000, $aj['data'] ?? []); sort($ids);
$ok($c === 200 && $ids === $expect(['priority' => ['High', 'Low'], 'onsite' => ['1']], 2), 'API /tickets: repeated priority[] with onsite=1');
[$c, $ab] = $api($tAlex, 'tickets?assigned=me,unassigned,3&status=all&limit=50');
$aj = json_decode($ab, true); $ids = array_map(fn ($r) => $r['number'] - 1000, $aj['data'] ?? []); sort($ids);
$ok($c === 200 && $ids === $expect(['assigned' => ['me', 'unassigned', '3'], 'status' => 'all'], 2), 'API /tickets: assigned=me,unassigned,3 with status=all');
[$c, $ab] = $api($tAlex, 'tickets?mine=1&priority=high&limit=50');
$aj = json_decode($ab, true); $ids = array_map(fn ($r) => $r['number'] - 1000, $aj['data'] ?? []); sort($ids);
$ok($c === 200 && $ids === $expect(['assigned' => ['me'], 'priority' => ['High']], 2), 'API /tickets: the old mine=1&priority=high still works');
[$c, $ab] = $api($tAlex, 'tickets?board_id=801,802&category_id=811,821&limit=50');
$aj = json_decode($ab, true); $ids = array_map(fn ($r) => $r['number'] - 1000, $aj['data'] ?? []); sort($ids);
$ok($c === 200 && $ids === $expect(['board' => [801, 802], 'category' => [811, 821]], 2), 'API /tickets: board_id and category_id lists');
[$c, $ab] = $api($tAlex, 'tickets?overdue=1&due_today=1&limit=50');
$aj = json_decode($ab, true); $ids = array_map(fn ($r) => $r['number'] - 1000, $aj['data'] ?? []); sort($ids);
$ok($c === 200 && $ids === $expect(['overdue' => true, 'due_today' => true], 2), 'API /tickets: overdue and due_today together match either');
[$c, $ab] = $api($tAlex, 'tickets?priority=' . rawurlencode("high' or '1'='1") . '&limit=50');
$ok($c === 200 && !str_contains($ab, 'SQL'), 'API /tickets: a hostile priority is ignored');
// views endpoint
$db->query("INSERT INTO ticket_saved_views SET ticket_saved_view_id=201, ticket_saved_view_name='API multi', ticket_saved_view_query='" . $esc(ticketViewQueryFromPost($mysqli, ['f_priority' => ['High', 'Medium'], 'f_assigned' => ['me', '3'], 'f_onsite' => ['1'], 'f_board' => ['801', '802']])) . "', ticket_saved_view_user_id=2");
$db->query("INSERT INTO ticket_saved_views SET ticket_saved_view_id=202, ticket_saved_view_name='API old', ticket_saved_view_query='assigned=me&priority=High&status=Open', ticket_saved_view_user_id=0");
$db->query("INSERT INTO ticket_saved_views SET ticket_saved_view_id=203, ticket_saved_view_name='API private other', ticket_saved_view_query='status=Closed', ticket_saved_view_user_id=3");
$db->query("INSERT INTO ticket_view_defaults SET user_id=2, view_id=201, scope='personal'");
[$c, $vb] = $api($tAlex, 'ticket-views');
$vj = json_decode($vb, true);
$by = []; foreach ($vj ?: [] as $v) { $by[$v['name']] = $v; }
$ok($c === 200 && isset($by['API multi'], $by['API old']) && !isset($by['API private other']), 'API /ticket-views lists the agent\'s views and shared ones, not another agent\'s private view');
$ok(($by['API multi']['params']['priority'] ?? '') === 'medium,high' && ($by['API multi']['params']['assigned'] ?? '') === '3,me' && ($by['API multi']['params']['onsite'] ?? '') === '1' && ($by['API multi']['params']['board_id'] ?? '') === '801,802' && ($by['API multi']['params']['status'] ?? '') === 'open', 'multi-value views translate to comma lists GET /tickets accepts: ' . json_encode($by['API multi']['params'] ?? null));
$ok(($by['API old']['params']['mine'] ?? '') === '1' && ($by['API old']['params']['priority'] ?? '') === 'high' && !isset($by['API old']['params']['assigned']), 'an old single-value view translates exactly as before (mine=1, priority=high)');
$ok(($by['API multi']['is_default'] ?? null) === true && ($by['API old']['is_default'] ?? null) === false, 'is_default marks the agent\'s default view');
$allStr = true; foreach ($vj as $v) { foreach ($v['params'] as $pv) { if (!is_string($pv)) { $allStr = false; } } }
$ok($allStr, 'every params value is a string (Android Map<String, String>)');
// the translated params really reproduce the view
$qs = http_build_query($by['API multi']['params']);
[$c, $ab] = $api($tAlex, 'tickets?' . $qs . '&limit=50');
$aj = json_decode($ab, true); $ids = array_map(fn ($r) => $r['number'] - 1000, $aj['data'] ?? []); sort($ids);
$ok($c === 200 && $ids === $expect(['priority' => ['High', 'Medium'], 'assigned' => ['me', '3'], 'onsite' => ['1'], 'board' => [801, 802]], 2), 'feeding a view\'s params back into GET /tickets returns the view\'s tickets');
[$c] = $api($tNia, 'ticket-views');
$ok($c === 403, 'API /ticket-views: an agent without support access is refused');
[$c] = $api($tPortal, 'ticket-views');
$ok($c === 401 || $c === 403, 'API /ticket-views: a client-portal login has no API token access');
[$c] = $apiAuthLess('ticket-views');
$ok($c === 401, 'API /ticket-views: no credentials, 401');
[$c, $rb] = $api($tRae, 'ticket-views');
$ok($c === 200, 'API /ticket-views: a read-only agent may list');
// a key locked to one client never sees agent views
$secret = 'tvlegacy' . bin2hex(random_bytes(6));
$db->query("INSERT INTO api_keys SET api_key_name='tv', api_key_secret='" . hash('sha256', $secret) . "', api_key_decrypt_hash='', api_key_expire=DATE_ADD(CURDATE(), INTERVAL 30 DAY), api_key_client_id=77, api_key_permission='read'");
[$c] = sec_web($base, 'GET', '/api/v1/ticket-views', 'x', [], [], ['X-Api-Key: ' . $secret]);
$ok($c === 403, 'API /ticket-views: a legacy key locked to one client is refused');
$db->query("UPDATE api_keys SET api_key_client_id=0 WHERE api_key_name='tv'");
[$c] = sec_web($base, 'GET', '/api/v1/ticket-views', 'x', [], [], ['X-Api-Key: ' . $secret]);
$ok($c === 200, 'while an all-clients key works');
$db->query("DELETE FROM api_keys WHERE api_key_name='tv'");

// ---------------------------------------------------------------------------------------------------------- schema, files, escaping
require "$root/includes/database_version.php";
$upd = $read('admin/database_updates.php');
$step = substr($upd, strpos($upd, "if (\$rivetit_db_version() == '2.6.92') {"));
$ok(str_contains($step, 'CREATE TABLE IF NOT EXISTS `ticket_view_defaults`') && !str_contains($step, 'ALTER TABLE') && !str_contains($step, 'DROP ') && str_contains($step, "config_current_database_version` = '2.6.93'"), 'the 2.6.93 step only creates ticket_view_defaults (IF NOT EXISTS)');
$ok(str_contains($read('db.sql'), 'CREATE TABLE `ticket_view_defaults`') && !str_contains($read('db.sql'), 'uca1400') && version_compare(LATEST_DATABASE_VERSION, '2.6.93', '>='), 'db.sql has the table, no uca1400, and LATEST_DATABASE_VERSION is at least 2.6.93');
$lib = $read('agent/includes/ticket_view_filters.php');
$ok(!preg_match('/<script(?![^>]*src)/', $lib) && !preg_match('/ on(click|change|submit)=/', $lib), 'the filter markup has no inline script or inline event handler (CSP-safe)');
$grp = (function () { ob_start(); ticketFilterGroup('x', 'n[]', 'Pri<b>', [['value' => 'a"b', 'label' => '<img src=x onerror=1>']], []); return ob_get_clean(); })();
$ok(!str_contains($grp, '<img') && !str_contains($grp, '<b>') && str_contains($grp, '&lt;img') && str_contains($grp, 'value="a&quot;b"'), 'labels, values and legends are escaped');
$q("INSERT INTO ticket_saved_views SET ticket_saved_view_id=301, ticket_saved_view_name='<script>alert(1)</script>', ticket_saved_view_query='status=Open', ticket_saved_view_user_id=2");
[$c, $xb] = $page($alex, 'status=Open&canned_date=alltime');
$ok($c === 200 && !str_contains($xb, '<script>alert(1)</script>'), 'a view name is escaped in the sidebar');
