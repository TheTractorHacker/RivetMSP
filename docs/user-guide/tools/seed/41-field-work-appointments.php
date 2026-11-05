<?php
/*
 * Field work, part 2: appointments, calendars and events (user guide page 08).
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
if (!$cat['On-Site']) { fwrite(STDERR, "41: run seed 40 first\n"); exit(1); }

/** Add an appointment (ticket_schedules row) once. */
function appt($ticket_id, $tech, $start, $mins, $onsite, $notes) {
    $t = $tech ? uid($tech) : 0;
    $s = dt($start);
    if (one("SELECT 1 FROM ticket_schedules WHERE schedule_ticket_id = $ticket_id AND schedule_start = '$s' AND schedule_tech_id = $t")) return;
    q("INSERT INTO ticket_schedules SET schedule_ticket_id = $ticket_id, schedule_start = '$s', schedule_end = '" . dt($start + $mins * 60) . "', schedule_onsite = $onsite,
        schedule_tech_id = $t, schedule_notes = '" . e($notes) . "', schedule_created_by = " . uid('Morgan Reyes'));
}
function d($days, $h, $m = 0) { return at(-$days, $h, $m); }

$jobs = [
  ['Northshore Dental Group','Hannah Brooks','Devon Park','On-Site','Quarterly check of the server closet','Walk through the server closet: UPS test, cabling, temperature, backup drive health.', 0, [[ 'Devon Park', d(1,9), 120, 1, 'Front desk has the closet key. Bring a spare UPS battery cartridge.' ]]],
  ['Lakeview Family Credit Union','Gordon Pike',null,'On-Site','Branch Wi-Fi coverage walk-through','Members report weak Wi-Fi in the West Branch waiting area. Survey signal strength and move an access point if needed.', 0, [[ 'Devon Park', d(3,10), 120, 1, 'Two technicians: one to survey, one to move cabling. Ask for Camille at the front.' ], ['Sam Ortiz', d(3,10), 120, 1, '']]],
  ['Cedar & Pine Architects','Wen Li','Sam Ortiz','Remote Support','Plotter calibration and driver update','The large-format plotter prints colours too dark since the last driver update. Calibrate remotely and roll the driver back if needed.', 1, [[ 'Sam Ortiz', d(0,10,30), 60, 0, 'Remote session. Ask Wen to keep the plotter powered on.' ]]],
  ['Kestrel Property Management','Imani Carter','Devon Park','On-Site','Lobby kiosk setup at the management office','Set up the new check-in kiosk in the lobby: locked-down browser, auto-start and a printer for visitor badges.', 1, [[ 'Devon Park', d(5,13), 180, 1, 'Building opens at 8; the lobby kiosk stand is already mounted.' ]]],
  ['Ironwood Logistics','Tara Okonkwo','Devon Park','On-Site','Re-survey dock scanner coverage','Handheld scanners drop off Wi-Fi at the far end of the Distribution Center. Walk the dock with a signal meter and note dead spots.', 1, [[ 'Devon Park', d(-2,9), 120, 1, 'Completed walk-through; dead spots marked on the floor plan.' ]]],
  ['Maple Street Veterinary Clinic','Beth Nakamura','Sam Ortiz','Remote Support','Practice software upgrade after hours','Upgrade the practice management software after closing. Keep the server on during the upgrade.', 0, [[ 'Sam Ortiz', d(1,17,30), 60, 0, 'After-hours rate applies. Clinic closes at 5:30.' ]]],
  ['Bluebird Early Learning','Sunita Patel','Sam Ortiz','Remote Support','Review Wi-Fi performance with the director','Short call to go over the Wi-Fi performance numbers for the learning center and agree on next steps.', 0, [[ 'Sam Ortiz', d(-4,15), 30, 0, 'Held by phone.' ]]],
  ['Northshore Dental Group','Hannah Brooks','Devon Park','On-Site','Follow-up visit for the operatory monitors','Check that the new operatory monitors still look sharp after a week of use and adjust the arms if needed.', 1, [[ 'Devon Park', d(8,8,30), 60, 1, 'Operatories 3 and 4 are free before the first patient at 9.' ]]],
];
foreach ($jobs as [$client,$contact,$tech,$catn,$subject,$details,$billable,$appts]) {
    $first = $appts[0][1];
    $status = ($first < time() - 86400*2) ? 5 : 2;
    $closed = $status == 5 ? $first + 7200 : null;
    [$id, $new] = mk_ticket([
        'client' => $client, 'contact' => $contact, 'tech' => $tech ?: 'Devon Park', 'category' => $cat[$catn], 'billable' => $billable, 'priority' => 'Medium',
        'subject' => $subject, 'details' => $details, 'created' => min($first - 86400*2, time() - 3600*30), 'closed' => $closed, 'status' => $status,
    ]);
    if ($tech === null) { q("UPDATE tickets SET ticket_assigned_to = 0, ticket_status = 1 WHERE ticket_id = $id AND ticket_status = 2"); }
    foreach ($appts as [$ut, $st, $mins, $onsite, $notes]) { appt($id, $ut, $st, $mins, $onsite, $notes); }
}
// the open label-printer job from seed 40 gets a visit this afternoon and one tomorrow
$printer = (int) one("SELECT ticket_id FROM tickets WHERE ticket_subject = 'Set up label printer at the shipping dock' LIMIT 1");
if ($printer) {
    appt($printer, 'Sam Ortiz', d(0,13), 120, 1, 'Confirm label size with the shipping supervisor, then finish the worksheet and sign out the outtake form.');
    appt($printer, 'Sam Ortiz', d(1,8,30), 60, 1, 'Spare time for ribbon swap if the labels smear.');
}
// per-technician calendar colours are already set on the users; make sure none is empty
q("UPDATE users SET user_color = '#2f80ed' WHERE user_name = 'Devon Park' AND (user_color IS NULL OR user_color = '')");
q("UPDATE users SET user_color = '#27ae60' WHERE user_name = 'Sam Ortiz' AND (user_color IS NULL OR user_color = '')");
q("UPDATE users SET user_color = '#9b51e0' WHERE user_name = 'Morgan Reyes' AND (user_color IS NULL OR user_color = '')");

// calendars and events
$cal = [];
foreach ([['Team meetings','#2f80ed'],['Maintenance windows','#eb5757'],['Client reviews','#27ae60']] as [$n,$c]) {
    $id = one("SELECT calendar_id FROM calendars WHERE calendar_name = '" . e($n) . "' LIMIT 1");
    if (!$id) { q("INSERT INTO calendars SET calendar_name = '" . e($n) . "', calendar_color = '$c'"); $id = mysqli_insert_id($mysqli); }
    $cal[$n] = (int) $id;
}
q("UPDATE settings SET config_default_calendar = {$cal['Team meetings']} WHERE company_id = 1 AND (config_default_calendar IS NULL OR config_default_calendar = 0)");
$events = [
  ['Team meetings','Weekly service desk huddle','Back office', 'Review the open ticket board, on-call hand-over and this week\'s visits.', d(0,8,30), 30, null],
  ['Team meetings','Weekly service desk huddle','Back office', 'Review the open ticket board, on-call hand-over and this week\'s visits.', d(7,8,30), 30, null],
  ['Team meetings','Weekly service desk huddle','Back office', 'Review the open ticket board, on-call hand-over and this week\'s visits.', d(-7,8,30), 30, null],
  ['Maintenance windows','Patch window: Granite Peak servers','Remote', 'Monthly Windows updates for the plant servers. Production floor is told in advance.', d(2,22), 180, 'Granite Peak Manufacturing'],
  ['Maintenance windows','Firewall firmware upgrade','Remote', 'Firmware upgrade on the office firewalls, one site at a time. About 10 minutes of downtime each.', d(4,21), 120, null],
  ['Client reviews','Quarterly review: Lakeview Family Credit Union','Main Branch conference room', 'Go through the quarter\'s tickets, uptime and the security review findings with Elena and Gordon.', d(6,14), 60, 'Lakeview Family Credit Union'],
  ['Client reviews','Quarterly review: Birchwood Law Partners','Video call', 'Walk through the laptop refresh plan and next year\'s budget.', d(-3,11), 60, 'Birchwood Law Partners'],
  ['Team meetings','Training: the new worksheet templates','Back office', 'Walk through the workstation setup and site sign-off worksheets.', d(9,15), 60, null],
];
foreach ($events as [$cn,$title,$loc,$desc,$start,$mins,$client]) {
    $s = dt($start);
    if (one("SELECT 1 FROM calendar_events WHERE event_title = '" . e($title) . "' AND event_start = '$s'")) continue;
    $cl = $client ? cid($client) : 0;
    q("INSERT INTO calendar_events SET event_title = '" . e($title) . "', event_location = '" . e($loc) . "', event_description = '" . e($desc) . "', event_start = '$s', event_end = '" . dt($start + $mins*60) . "',
        event_client_id = $cl, event_location_id = 0, event_calendar_id = {$cal[$cn]}");
}
echo "41-field-work-appointments: done\n";
