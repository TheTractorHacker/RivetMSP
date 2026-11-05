<?php
/*
 * Field work, part 3: projects, milestones, tasks and a project template (user guide page 09).
 * Idempotent; relative to "now" in America/Chicago. Depends on seeds 00 and 01 (and uses the categories / labor types seed 40 creates).
 */
if (php_sapi_name() !== 'cli') { exit("Run this from the command line.\n"); }
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) { fwrite(STDERR, "no config.php in '$app'. Set RIVETIT_APP_DIR.\n"); exit(1); }
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';
date_default_timezone_set('America/Chicago');
function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "40-field-work-billing: " . mysqli_error($mysqli) . "\n  " . substr($sql, 0, 300) . "\n"); exit(1); } return true; }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function e($s) { global $mysqli; return mysqli_real_escape_string($mysqli, (string) $s); }
function dt($ts) { return date('Y-m-d H:i:s', $ts); }
function at($daysAgo, $hour, $min = 0) { return mktime($hour, $min, 0, (int) date('n'), (int) date('j') - $daysAgo, (int) date('Y')); }
function uid($name) { $v = one("SELECT user_id FROM users WHERE user_name = '" . e($name) . "' LIMIT 1"); return $v ? (int) $v : 0; }
function cid($name) { $v = one("SELECT client_id FROM clients WHERE client_name = '" . e($name) . "' LIMIT 1"); if (!$v) { fwrite(STDERR, "40-field-work-billing: client '$name' missing (seed 00)\n"); exit(1); } return (int) $v; }
function contact_id($client_id, $name) { if ($name === '') return 0; $v = one("SELECT contact_id FROM contacts WHERE contact_client_id = " . (int) $client_id . " AND contact_name = '" . e($name) . "' LIMIT 1"); return $v ? (int) $v : 0; }

function ensure_category($name, $color, $extra = '') {
    $id = one("SELECT category_id FROM categories WHERE category_type = 'Ticket' AND category_name = '" . e($name) . "' AND category_archived_at IS NULL LIMIT 1");
    if ($id) return (int) $id;
    q("INSERT INTO categories SET category_name = '" . e($name) . "', category_type = 'Ticket', category_color = '" . e($color) . "', category_parent = 0 $extra");
    return (int) mysqli_insert_id($GLOBALS['mysqli']);
}
function ensure_tag($name, $type, $color) {
    $id = one("SELECT tag_id FROM tags WHERE tag_name = '" . e($name) . "' AND tag_type = " . (int) $type . " LIMIT 1");
    if ($id) return (int) $id;
    q("INSERT INTO tags SET tag_name = '" . e($name) . "', tag_type = " . (int) $type . ", tag_color = '" . e($color) . "'");
    return (int) mysqli_insert_id($GLOBALS['mysqli']);
}
function next_ticket_number() {
    q("UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number), config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1");
    return (int) mysqli_insert_id($GLOBALS['mysqli']);
}


$lt=[]; $r=mysqli_query($mysqli,'SELECT labor_type_id, labor_type_name FROM labor_types'); while($x=mysqli_fetch_assoc($r)){ $lt[$x['labor_type_name']]=(int)$x['labor_type_id']; }
/** Create (or find) a ticket. Returns [id, created?]. */
function mk_ticket(array $t) {
    global $mysqli, $cat_remote, $cat_onsite, $cat_proj, $cat_maint, $config_ticket_prefix;
    $client = cid($t['client']);
    $existing = one("SELECT ticket_id FROM tickets WHERE ticket_client_id = $client AND ticket_subject = '" . e($t['subject']) . "' LIMIT 1");
    if ($existing) return [(int) $existing, false];
    $num = next_ticket_number();
    $prefix = one("SELECT config_ticket_prefix FROM settings WHERE company_id = 1");
    $contact = contact_id($client, $t['contact'] ?? '');
    $assigned = uid($t['tech'] ?? '');
    $created = $t['created'];
    $closed = $t['closed'] ?? null;
    $status = (int) ($t['status'] ?? ($closed ? 5 : 2));
    $cat = $t['category'];
    $billable = (int) ($t['billable'] ?? 0);
    $creator = uid($t['creator'] ?? 'Morgan Reyes');
    q("INSERT INTO tickets SET ticket_prefix = '" . e($prefix) . "', ticket_number = $num, ticket_source = 'Agent', ticket_category = " . (int) $cat . ",
        ticket_subject = '" . e($t['subject']) . "', ticket_details = '" . e($t['details']) . "', ticket_priority = '" . e($t['priority'] ?? 'Medium') . "',
        ticket_status = $status, ticket_billable = $billable, ticket_billable_overridden = " . (int) ($t['overridden'] ?? 0) . ",
        ticket_created_at = '" . dt($created) . "', ticket_updated_at = '" . dt($t['updated'] ?? ($closed ?: $created + 3600)) . "',
        ticket_first_response_at = '" . dt($created + 900) . "',
        ticket_resolved_at = " . ($closed ? "'" . dt($closed) . "'" : 'NULL') . ", ticket_closed_at = " . ($closed ? "'" . dt($closed) . "'" : 'NULL') . ",
        ticket_closed_by = " . ($closed ? $assigned : 0) . ", ticket_created_by = $creator, ticket_assigned_to = $assigned,
        ticket_client_id = $client, ticket_contact_id = $contact, ticket_url_key = '" . bin2hex(random_bytes(16)) . "'");
    $id = (int) mysqli_insert_id($mysqli);
    // the customer's own words are the "Initial Issue" reply
    $by = $contact ?: 0;
    q("INSERT INTO ticket_replies SET ticket_reply = '" . e('<p>' . $t['details'] . '</p>') . "', ticket_reply_type = 'Client', ticket_reply_by = $by, ticket_reply_ticket_id = $id,
        ticket_reply_created_at = '" . dt($created) . "'");
    q("UPDATE tickets SET ticket_initial_issue_reply_id = " . (int) mysqli_insert_id($mysqli) . " WHERE ticket_id = $id");
    foreach (($t['replies'] ?? []) as $r) {
        $mins = (int) ($r['mins'] ?? 0);
        $time = $mins > 0 ? sprintf('%02d:%02d:00', intdiv($mins, 60), $mins % 60) : '00:00:00';
        $ltid = !empty($r['labor']) ? (int) $GLOBALS['lt'][$r['labor']] : 'NULL';
        q("INSERT INTO ticket_replies SET ticket_reply = '" . e('<p>' . $r['text'] . '</p>') . "', ticket_reply_type = '" . e($r['type'] ?? 'Internal') . "',
            ticket_reply_time_worked = " . ($mins > 0 ? "'$time'" : 'NULL') . ", ticket_reply_by = " . uid($r['by'] ?? $t['tech']) . ", ticket_reply_ticket_id = $id,
            ticket_reply_onsite = " . (int) ($r['onsite'] ?? 0) . ", ticket_reply_labor_type_id = $ltid, ticket_reply_emailed = " . (int) (($r['type'] ?? '') === 'Public' ? 1 : 0) . ",
            ticket_reply_created_at = '" . dt($r['at']) . "'");
    }
    if ($closed) {
        q("INSERT INTO ticket_replies SET ticket_reply = 'Ticket closed.', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $assigned,
            ticket_reply_ticket_id = $id, ticket_reply_created_at = '" . dt($closed) . "'");
    }
    foreach (($t['charges'] ?? []) as $c) {
        $ltc = !empty($c['labor']) ? (int) $GLOBALS['lt'][$c['labor']] : 0;
        $total = round($c['qty'] * $c['price'], 2);
        q("INSERT INTO ticket_charges SET charge_ticket_id = $id, charge_labor_type_id = $ltc, charge_name = '" . e($c['name']) . "', charge_description = " . (empty($c['desc']) ? 'NULL' : "'" . e($c['desc']) . "'") . ",
            charge_quantity = {$c['qty']}, charge_unit_price = {$c['price']}, charge_total = $total, charge_created_by = $assigned, charge_created_at = '" . dt($c['at'] ?? $created + 7200) . "'");
    }
    return [$id, true];
}


$cat = [];
foreach (['Remote Support','On-Site','Projects','Maintenance'] as $n) { $cat[$n] = (int) one("SELECT category_id FROM categories WHERE category_type='Ticket' AND category_name='" . e($n) . "' LIMIT 1"); }
if (!$cat['Projects']) { fwrite(STDERR, "42: run seed 40 first\n"); exit(1); }

// ---- project template: Laptop refresh -> two ticket templates with task templates -------------------------
$pt = one("SELECT project_template_id FROM project_templates WHERE project_template_name = 'Laptop refresh' LIMIT 1");
if (!$pt) {
    q("INSERT INTO project_templates SET project_template_name = 'Laptop refresh', project_template_description = 'Replace staff laptops: prepare, deploy and retire the old machines'");
    $pt = mysqli_insert_id($mysqli);
    $tts = [
        ['Prepare and image new laptops', 'Order, unbox, image and enrol the replacement laptops. Record serial numbers.', ['Confirm the laptop list with the client', 'Image and enrol each laptop', 'Record serial numbers on the assets']],
        ['Deploy laptops and migrate data', 'Visit each user, move their files and profile to the new laptop and collect the old one.', ['Schedule hand-over slots with staff', 'Migrate files and browser profiles', 'Collect the old laptops']],
    ];
    $ord = 1;
    foreach ($tts as [$name, $det, $tasks]) {
        q("INSERT INTO ticket_templates SET ticket_template_name = '" . e("Laptop refresh: $name") . "', ticket_template_subject = '" . e($name) . "', ticket_template_details = '" . e("<p>$det</p>") . "'");
        $ttid = mysqli_insert_id($mysqli);
        q("INSERT INTO project_template_ticket_templates SET ticket_template_id = $ttid, project_template_id = $pt, ticket_template_order = " . $ord++);
        $to = 1;
        foreach ($tasks as $tn) { q("INSERT INTO task_templates SET task_template_name = '" . e($tn) . "', task_template_order = " . $to++ . ", task_template_ticket_template_id = $ttid"); }
    }
}

function next_project_number() {
    q("UPDATE settings SET config_project_next_number = LAST_INSERT_ID(config_project_next_number), config_project_next_number = config_project_next_number + 1 WHERE company_id = 1");
    return (int) mysqli_insert_id($GLOBALS['mysqli']);
}
function mk_project($o) {
    global $mysqli;
    $cl = !empty($o['client']) ? cid($o['client']) : 0;
    $ex = one("SELECT project_id FROM projects WHERE project_name = '" . e($o['name']) . "' AND project_client_id = $cl");
    if ($ex) return [(int) $ex, false];
    $n = next_project_number();
    $prefix = one("SELECT config_project_prefix FROM settings WHERE company_id = 1");
    q("INSERT INTO projects SET project_prefix = '" . e($prefix) . "', project_number = $n, project_name = '" . e($o['name']) . "', project_description = '" . e($o['desc']) . "',
        project_due = '" . date('Y-m-d', $o['due']) . "', project_manager = " . uid($o['manager']) . ", project_created_at = '" . dt($o['created']) . "',
        project_completed_at = " . (!empty($o['completed']) ? "'" . dt($o['completed']) . "'" : 'NULL') . ", project_archived_at = " . (!empty($o['archived']) ? "'" . dt($o['archived']) . "'" : 'NULL') . ",
        project_client_id = $cl, project_start = " . (!empty($o['start']) ? "'" . date('Y-m-d', $o['start']) . "'" : 'NULL') . ",
        project_estimated_hours = " . (!empty($o['hours']) ? $o['hours'] : 'NULL') . ", project_budget_amount = " . (!empty($o['budget']) ? $o['budget'] : 'NULL') . ", project_hourly_rate = " . (!empty($o['rate']) ? $o['rate'] : 'NULL'));
    return [(int) mysqli_insert_id($mysqli), true];
}
function mk_milestone($pid, $name, $desc, $due, $order, $done = false) {
    q("INSERT INTO project_milestones SET milestone_project_id = $pid, milestone_name = '" . e($name) . "', milestone_description = '" . e($desc) . "', milestone_due = '" . date('Y-m-d', $due) . "', milestone_order = $order,
        milestone_status = '" . ($done ? 'completed' : 'open') . "', milestone_completed_at = " . ($done ? "'" . dt(time() - 86400*6) . "'" : 'NULL'));
    return (int) mysqli_insert_id($GLOBALS['mysqli']);
}
function mk_task($pid, $name, $o = []) {
    $ms = !empty($o['ms']) ? (int) $o['ms'] : 'NULL';
    $as = !empty($o['who']) ? uid($o['who']) : 'NULL';
    $done = !empty($o['done']);
    $prog = $done ? 100 : (int) ($o['progress'] ?? 0);
    $status = !empty($o['status']) ? "'" . e($o['status']) . "'" : 'NULL';
    q("INSERT INTO tasks SET task_name = '" . e($name) . "', task_project_id = $pid, task_milestone_id = $ms, task_assigned_to = $as, task_start = " . (isset($o['start']) ? "'" . date('Y-m-d', $o['start']) . "'" : 'NULL') . ",
        task_due = " . (isset($o['due']) ? "'" . date('Y-m-d', $o['due']) . "'" : 'NULL') . ", task_completion_estimate = " . (int) ($o['est'] ?? 0) . ", task_progress = $prog, task_status = $status,
        task_order = " . (int) ($o['order'] ?? 0) . ", task_completed_at = " . ($done ? "'" . dt(time() - 86400 * 5) . "'" : 'NULL') . ", task_completed_by = " . ($done ? $as : 'NULL'));
}
function day($n) { return time() + $n * 86400; }
function link_ticket($tid, $pid) { q("UPDATE tickets SET ticket_project_id = $pid WHERE ticket_id = $tid"); }
function ticket_task($tid, $name, $done) { q("INSERT INTO tasks SET task_name = '" . e($name) . "', task_ticket_id = $tid, task_completed_at = " . ($done ? "'" . dt(time() - 86400 * 4) . "'" : 'NULL') . ", task_progress = " . ($done ? 100 : 0)); }

// ---- P1: Northshore Wi-Fi upgrade (open, with budget, milestones and a Gantt) ------------------------------------
[$p1, $new] = mk_project(['client' => 'Northshore Dental Group', 'name' => 'Office Wi-Fi upgrade', 'desc' => 'Replace the office access points and add coverage for the Sun Prairie satellite', 'due' => day(25), 'start' => day(-20), 'manager' => 'Devon Park',
    'created' => day(-21), 'hours' => 40, 'budget' => 5400, 'rate' => 135]);
if ($new) {
    $m1 = mk_milestone($p1, 'Site survey', 'Measure coverage and decide access point positions', day(-8), 1, true);
    $m2 = mk_milestone($p1, 'Install access points', 'Mount and cable the new access points at both locations', day(7), 2);
    $m3 = mk_milestone($p1, 'Cutover and hand-over', 'Move devices to the new network and train the office manager', day(20), 3);
    mk_task($p1, 'Walk the main office and record signal levels', ['ms' => $m1, 'who' => 'Devon Park', 'start' => day(-19), 'due' => day(-15), 'est' => 120, 'done' => true, 'order' => 1]);
    mk_task($p1, 'Walk the Sun Prairie satellite', ['ms' => $m1, 'who' => 'Devon Park', 'start' => day(-14), 'due' => day(-10), 'est' => 90, 'done' => true, 'order' => 2]);
    mk_task($p1, 'Order six access points and PoE injectors', ['ms' => $m2, 'who' => 'Morgan Reyes', 'start' => day(-9), 'due' => day(-3), 'est' => 30, 'done' => true, 'order' => 3]);
    mk_task($p1, 'Mount access points, main office', ['ms' => $m2, 'who' => 'Devon Park', 'start' => day(-2), 'due' => day(4), 'est' => 240, 'progress' => 60, 'status' => 'In Progress', 'order' => 4]);
    mk_task($p1, 'Mount access points, Sun Prairie', ['ms' => $m2, 'who' => 'Sam Ortiz', 'start' => day(5), 'due' => day(8), 'est' => 120, 'status' => 'Blocked', 'order' => 5]);
    mk_task($p1, 'Move devices to the new SSID', ['ms' => $m3, 'who' => 'Sam Ortiz', 'start' => day(12), 'due' => day(18), 'est' => 180, 'order' => 6]);
    mk_task($p1, 'Hand-over session with the office manager', ['ms' => $m3, 'who' => 'Devon Park', 'start' => day(19), 'due' => day(20), 'est' => 60, 'order' => 7]);
    mk_task($p1, 'Update the network diagram', ['who' => 'Morgan Reyes', 'start' => day(20), 'due' => day(24), 'est' => 60, 'order' => 8]);
    [$a, ] = mk_ticket(['client' => 'Northshore Dental Group', 'contact' => 'Hannah Brooks', 'tech' => 'Devon Park', 'category' => $cat['Projects'], 'billable' => 1, 'subject' => 'Wi-Fi upgrade: site survey', 'priority' => 'Low',
        'details' => 'Survey signal strength in both locations before the new access points are ordered.', 'created' => day(-20), 'closed' => day(-9),
        'replies' => [['type' => 'Internal', 'text' => 'Survey done at both sites. Heat maps saved to the client files; six access points are enough.', 'mins' => 210, 'onsite' => 1, 'at' => day(-10), 'labor' => 'Project Labor']]]);
    [$b, ] = mk_ticket(['client' => 'Northshore Dental Group', 'contact' => 'Hannah Brooks', 'tech' => 'Devon Park', 'category' => $cat['Projects'], 'billable' => 1, 'subject' => 'Wi-Fi upgrade: install access points', 'priority' => 'Low',
        'details' => 'Mount, cable and adopt the new access points at the main office and the Sun Prairie satellite.', 'created' => day(-8),
        'replies' => [['type' => 'Internal', 'text' => 'Main office: four of four access points mounted and powered. Waiting on the cable run to the Sun Prairie closet.', 'mins' => 275, 'onsite' => 1, 'at' => day(-1), 'labor' => 'Project Labor']]]);
    [$c, ] = mk_ticket(['client' => 'Northshore Dental Group', 'contact' => 'Hannah Brooks', 'tech' => 'Sam Ortiz', 'category' => $cat['Projects'], 'billable' => 1, 'subject' => 'Wi-Fi upgrade: cutover and hand-over', 'priority' => 'Low', 'status' => 1,
        'details' => 'Move every device to the new network and run a short hand-over for the office manager.', 'created' => day(-8)]);
    foreach ([$a, $b, $c] as $t) { link_ticket($t, $p1); }
    ticket_task($a, 'Record signal levels in every room', true); ticket_task($b, 'Mount main office access points', true); ticket_task($b, 'Mount Sun Prairie access points', false); ticket_task($c, 'Move devices to the new SSID', false);
}

// ---- P2: Granite Peak switch replacement (no milestones) -------------------------------------------------------
[$p2, $new] = mk_project(['client' => 'Granite Peak Manufacturing', 'name' => 'Plant 1 core switch replacement', 'desc' => 'Replace the end-of-life core switch and re-patch the production network', 'due' => day(40), 'start' => day(10), 'manager' => 'Morgan Reyes',
    'created' => day(-5), 'hours' => 24]);
if ($new) {
    mk_task($p2, 'Confirm the shutdown window with the plant manager', ['who' => 'Morgan Reyes', 'start' => day(1), 'due' => day(6), 'est' => 30, 'status' => 'In Progress', 'progress' => 25, 'order' => 1]);
    mk_task($p2, 'Stage and configure the new switch', ['who' => 'Devon Park', 'start' => day(10), 'due' => day(20), 'est' => 360, 'order' => 2]);
    mk_task($p2, 'Replace the switch during the weekend window', ['who' => 'Devon Park', 'start' => day(34), 'due' => day(35), 'est' => 480, 'order' => 3]);
    [$a, ] = mk_ticket(['client' => 'Granite Peak Manufacturing', 'contact' => 'Robert Kowalski', 'tech' => 'Morgan Reyes', 'category' => $cat['Projects'], 'billable' => 1, 'subject' => 'Core switch replacement: plan the shutdown window', 'priority' => 'Medium',
        'details' => 'Agree the weekend window with production and list which lines need to be told.', 'created' => day(-5),
        'replies' => [['type' => 'Internal', 'text' => 'Draft plan sent to Robert. He is checking the production calendar for the last weekend of the month.', 'mins' => 40, 'at' => day(-2), 'labor' => 'Project Labor']]]);
    link_ticket($a, $p2);
}

// ---- P3: Birchwood laptop refresh, created from the template -----------------------------------------------------
[$p3, $new] = mk_project(['client' => 'Birchwood Law Partners', 'name' => 'Laptop refresh', 'desc' => 'Replace twelve attorney laptops', 'due' => day(30), 'manager' => 'Sam Ortiz', 'created' => day(-3), 'hours' => 30]);
if ($new) {
    $r = mysqli_query($mysqli, "SELECT * FROM ticket_templates t JOIN project_template_ticket_templates p ON p.ticket_template_id = t.ticket_template_id WHERE p.project_template_id = $pt ORDER BY p.ticket_template_order");
    $client = cid('Birchwood Law Partners');
    while ($tt = mysqli_fetch_assoc($r)) {
        $num = next_ticket_number();
        $prefix = one("SELECT config_ticket_prefix FROM settings WHERE company_id = 1");
        q("INSERT INTO tickets SET ticket_prefix = '" . e($prefix) . "', ticket_number = $num, ticket_source = 'Agent', ticket_category = {$cat['Projects']}, ticket_subject = '" . e($tt['ticket_template_subject']) . "',
            ticket_details = '" . e($tt['ticket_template_details']) . "', ticket_priority = 'Low', ticket_status = 1, ticket_created_by = " . uid('Sam Ortiz') . ", ticket_client_id = $client, ticket_project_id = $p3,
            ticket_created_at = '" . dt(day(-3)) . "', ticket_url_key = '" . bin2hex(random_bytes(16)) . "'");
        $tid = mysqli_insert_id($mysqli);
        $tr = mysqli_query($mysqli, "SELECT * FROM task_templates WHERE task_template_ticket_template_id = " . (int) $tt['ticket_template_id'] . " ORDER BY task_template_order");
        while ($task = mysqli_fetch_assoc($tr)) { q("INSERT INTO tasks SET task_name = '" . e($task['task_template_name']) . "', task_order = " . (int) $task['task_template_order'] . ", task_ticket_id = $tid"); }
    }
}

// ---- P4: a finished project (Closed list) and P5 an archived one -------------------------------------------------
[$p4, $new] = mk_project(['client' => 'Lakeview Family Credit Union', 'name' => 'Main Branch network refresh', 'desc' => 'Replace switches and cabling at the Main Branch', 'due' => day(-30), 'start' => day(-80), 'manager' => 'Devon Park',
    'created' => day(-85), 'completed' => day(-28), 'hours' => 50, 'budget' => 7500, 'rate' => 165]);
if ($new) {
    $m = mk_milestone($p4, 'Cabling and switches', 'New cable runs, patch panel and switches', day(-40), 1, true);
    mk_task($p4, 'Replace the patch panel and re-terminate cables', ['ms' => $m, 'who' => 'Devon Park', 'start' => day(-70), 'due' => day(-55), 'est' => 600, 'done' => true, 'order' => 1]);
    mk_task($p4, 'Swap switches and verify every port', ['ms' => $m, 'who' => 'Devon Park', 'start' => day(-54), 'due' => day(-45), 'est' => 360, 'done' => true, 'order' => 2]);
    [$a, ] = mk_ticket(['client' => 'Lakeview Family Credit Union', 'contact' => 'Gordon Pike', 'tech' => 'Devon Park', 'category' => $cat['Projects'], 'billable' => 1, 'subject' => 'Main Branch network refresh: cabling and switches', 'priority' => 'Medium',
        'details' => 'Replace the patch panel and the access switches at the Main Branch.', 'created' => day(-80), 'closed' => day(-30),
        'replies' => [['type' => 'Internal', 'text' => 'All 48 ports re-terminated, tested and labelled. Old switches wiped and collected.', 'mins' => 1980, 'onsite' => 1, 'at' => day(-31), 'labor' => 'Project Labor']]]);
    link_ticket($a, $p4);
}
[$p5, $new] = mk_project(['client' => 'Cedar & Pine Architects', 'name' => 'Studio file server migration', 'desc' => 'Move project files from the old server to cloud storage', 'due' => day(-120), 'start' => day(-170), 'manager' => 'Sam Ortiz',
    'created' => day(-175), 'completed' => day(-118), 'archived' => day(-100), 'hours' => 35]);
if ($new) {
    mk_task($p5, 'Copy and verify project folders', ['who' => 'Sam Ortiz', 'start' => day(-160), 'due' => day(-130), 'est' => 900, 'done' => true, 'order' => 1]);
}
// ---- P6: an internal project with no client --------------------------------------------------------------------
[$p6, $new] = mk_project(['name' => 'Service desk playbook refresh', 'desc' => 'Rewrite the first-response checklists for the service desk', 'due' => day(45), 'manager' => 'Morgan Reyes', 'created' => day(-2)]);
if ($new) {
    mk_task($p6, 'Collect the ten most common ticket types', ['who' => 'Morgan Reyes', 'due' => day(10), 'est' => 120, 'order' => 1]);
    mk_task($p6, 'Draft checklists for each type', ['who' => 'Sam Ortiz', 'due' => day(30), 'est' => 480, 'order' => 2]);
}
echo "42-field-work-projects: done\n";
