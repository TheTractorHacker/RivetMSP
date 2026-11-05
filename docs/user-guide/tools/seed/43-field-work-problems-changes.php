<?php
/*
 * Field work, part 4: problems and changes (user guide page 10). Links existing tickets by subject when they exist (service-desk seeds).
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


function day($n) { return time() + $n * 86400; }
$me = uid('Morgan Reyes');

function mk_change($o) {
    global $mysqli, $me;
    $ex = one("SELECT change_id FROM changes WHERE title = '" . e($o['title']) . "'");
    if ($ex) return (int) $ex;
    $cl = !empty($o['client']) ? cid($o['client']) : 0;
    $approval = !empty($o['approval']) ? 1 : 0;
    q("INSERT INTO changes SET title = '" . e($o['title']) . "', reason = '" . e($o['reason']) . "', impact = '" . e($o['impact']) . "', risk = '{$o['risk']}',
        implementation_plan = '" . e($o['plan']) . "', rollback_plan = '" . e($o['rollback']) . "', scheduled_at = " . (isset($o['at']) ? "'" . dt($o['at']) . "'" : 'NULL') . ",
        status = '{$o['status']}', created_by = " . uid($o['by'] ?? 'Morgan Reyes') . ", created_at = '" . dt($o['created']) . "', client_id = $cl, requires_client_approval = $approval,
        client_decision = '" . ($o['decision'] ?? 'pending') . "', client_decided_at = " . (!empty($o['decided']) ? "'" . dt($o['decided']) . "'" : 'NULL') . ",
        url_key = " . ($approval ? "'" . bin2hex(random_bytes(16)) . "'" : 'NULL'));
    return (int) mysqli_insert_id($mysqli);
}
function mk_problem($o) {
    global $mysqli;
    $ex = one("SELECT problem_id FROM problems WHERE title = '" . e($o['title']) . "'");
    if ($ex) return (int) $ex;
    $chg = !empty($o['change']) ? (int) $o['change'] : 'NULL';
    q("INSERT INTO problems SET title = '" . e($o['title']) . "', description = '" . e($o['desc']) . "', status = '{$o['status']}', change_problem_id = $chg, created_by = " . uid($o['by'] ?? 'Morgan Reyes') . ",
        created_at = '" . dt($o['created']) . "', resolved_at = " . (!empty($o['resolved']) ? "'" . dt($o['resolved']) . "'" : 'NULL'));
    $id = (int) mysqli_insert_id($mysqli);
    foreach ($o['tickets'] as $subj) {
        q("UPDATE tickets SET ticket_problem_id = $id WHERE ticket_subject = '" . e($subj) . "' AND ticket_problem_id IS NULL");
    }
    return $id;
}

$c_fw = mk_change(['title' => 'Upgrade office firewall firmware', 'reason' => 'The current firmware has a known bug that drops idle VPN and Wi-Fi guest sessions. The vendor fixed it in the next release.',
    'impact' => 'About ten minutes without internet at each site while the firewall restarts. Remote staff reconnect automatically.', 'risk' => 'medium',
    'plan' => "1. Back up the firewall configuration.\n2. Upload the new firmware and schedule the reboot.\n3. Test VPN, guest Wi-Fi and the site-to-site tunnels.", 'rollback' => 'Boot from the previous firmware partition and restore the saved configuration.',
    'status' => 'scheduled', 'at' => day(4) - 3600 * 5 + 3600 * 0, 'created' => day(-6), 'by' => 'Devon Park']);
$c_sw = mk_change(['title' => 'Replace Plant 1 core switch', 'client' => 'Granite Peak Manufacturing', 'approval' => 1, 'decision' => 'pending', 'risk' => 'high',
    'reason' => 'The core switch is end of life and no longer receives security updates.', 'impact' => 'The plant network is down for about six hours during the weekend window. Production lines and the label printers are affected.',
    'plan' => "1. Stage and configure the new switch.\n2. Shut down production devices at the start of the window.\n3. Swap the switch and re-patch from the labelled patch sheet.\n4. Verify every line before the Monday shift.", 'rollback' => 'Re-patch the old switch, which stays racked beside the new one until Tuesday.',
    'status' => 'awaiting_approval', 'at' => day(35), 'created' => day(-3)]);
$c_bk = mk_change(['title' => 'Move nightly backups to 11 pm', 'reason' => 'Backups overlap with the late shift at two clients.', 'impact' => 'None for staff; backups finish earlier in the morning.', 'risk' => 'low',
    'plan' => 'Change the schedule on the backup server and watch the first three runs.', 'rollback' => 'Set the schedule back to 1 am.', 'status' => 'successful', 'at' => day(-14), 'created' => day(-20), 'by' => 'Sam Ortiz']);
$c_ssid = mk_change(['title' => 'Switch the office to the new Wi-Fi network name', 'client' => 'Northshore Dental Group', 'approval' => 1, 'decision' => 'approved', 'decided' => day(-2), 'risk' => 'medium',
    'reason' => 'The new access points use a new network name and stronger encryption.', 'impact' => 'Every device has to rejoin once. Plan on a few minutes per device.', 'plan' => 'Announce the change, enable the new name and walk the office rejoining devices.', 'rollback' => 'Turn the old network name back on.',
    'status' => 'approved', 'at' => day(9), 'created' => day(-5), 'by' => 'Devon Park']);
$c_patch = mk_change(['title' => 'Apply November Windows updates to servers', 'reason' => 'Monthly security patching.', 'impact' => 'Each server restarts once, outside business hours.', 'risk' => 'medium',
    'plan' => 'Patch the test server first, then the others one at a time.', 'rollback' => 'Uninstall the update and restore from last night\'s backup if needed.', 'status' => 'in_progress', 'at' => day(0) - 3600, 'created' => day(-4), 'by' => 'Sam Ortiz']);
$c_print = mk_change(['title' => 'Update print server drivers', 'client' => 'Birchwood Law Partners', 'risk' => 'low', 'reason' => 'New copier needs a newer driver package.', 'impact' => 'Printing is unavailable for a few minutes.',
    'plan' => 'Install the new driver package on the print server.', 'rollback' => 'Reinstall the previous package.', 'status' => 'rolled_back', 'at' => day(-9), 'created' => day(-12), 'by' => 'Devon Park']);
$c_old = mk_change(['title' => 'Decommission the old file server', 'client' => 'Cedar & Pine Architects', 'risk' => 'medium', 'reason' => 'Files now live in cloud storage.', 'impact' => 'Mapped drives to the old server stop working.',
    'plan' => 'Set the server read-only for 30 days, then power it off and wipe the drives.', 'rollback' => 'Power the server back on.', 'status' => 'draft', 'created' => day(-1), 'by' => 'Sam Ortiz']);

mk_problem(['title' => 'Wi-Fi and VPN sessions drop at several offices', 'desc' => "Several clients report Wi-Fi or VPN sessions that drop for a few seconds, usually in the afternoon. The firewall logs show idle sessions being cleared too early.\nSuspected cause: a firewall firmware bug that is fixed in the next release.",
    'status' => 'investigating', 'change' => $c_fw, 'created' => day(-8), 'by' => 'Devon Park', 'tickets' => ['Lobby Wi-Fi drops every afternoon', 'Wi-Fi in the lobby is down', 'Handheld scanners will not sync in the warehouse']]);
mk_problem(['title' => 'Scan-to-email stops after the mail server restarts', 'desc' => 'Copiers lose their mail relay settings after the nightly restart.', 'status' => 'open', 'created' => day(-3), 'by' => 'Sam Ortiz',
    'tickets' => ['Scanner to email stopped working']]);
mk_problem(['title' => 'Nightly backups miss their window', 'desc' => 'Two clients had backup jobs finish after the 6 am deadline. The schedule overlapped with late-shift activity.', 'status' => 'resolved', 'change' => $c_bk,
    'created' => day(-24), 'resolved' => day(-10), 'by' => 'Sam Ortiz', 'tickets' => ['Backup job failed overnight', 'Server backup alert: nightly job missed']]);
mk_problem(['title' => 'Outlook prompts for passwords after the mail rollout', 'desc' => 'Cached credentials from old profiles conflict with modern authentication.', 'status' => 'closed', 'created' => day(-60), 'resolved' => day(-45), 'by' => 'Morgan Reyes', 'tickets' => ['Outlook keeps asking for a password']]);
echo "43-field-work-problems-changes: done\n";
