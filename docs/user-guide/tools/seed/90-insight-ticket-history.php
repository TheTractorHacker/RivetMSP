<?php
/*
 * Twelve months of ticket history for the RivetMSP user-guide demo (group G9, Insight: dashboard and reports).
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/90-insight-ticket-history.php
 *
 * Seed 31 gives the queue about 11 weeks of tickets. The reports need a year, so this adds roughly 250 CLOSED tickets
 * for the nine active clients between 365 and 84 days ago (the queue from seed 31 starts about 81 days ago): the three
 * technicians, all priorities, Remote / On-site / Project / Maintenance work, replies with time worked on labor types,
 * first-response and resolution times (about nine in ten inside the default SLA policy), customer satisfaction ratings on
 * about four in ten tickets, a billable flag, and the included-support-hours contract on Bluebird's tickets.
 * Which tickets were invoiced is decided by seed 91 (it attaches them to time-and-materials invoices).
 *
 * Needs seeds 00, 01 and 30 (categories, SLA policy). Idempotent: every ticket carries a url key "hist90-NNN", and a ticket
 * whose key exists is skipped. Ticket numbers come from the live counter, so these (older) tickets have higher numbers
 * than the queue from seed 31. Dates are relative to "now", so the history always covers the last twelve months.
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "90-insight-ticket-history: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "90-insight-ticket-history: " . mysqli_error($mysqli) . "\n  " . substr($sql, 0, 300) . "\n"); exit(1); } return true; }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function e($s) { global $mysqli; return mysqli_real_escape_string($mysqli, (string) $s); }

$tzName = one('SELECT config_timezone FROM settings WHERE company_id = 1') ?: 'America/Chicago';
date_default_timezone_set($tzName);
q("SET time_zone = '" . (new DateTime('now', new DateTimeZone($tzName)))->format('P') . "'");
$NOW = time();
$today = strtotime(date('Y-m-d 00:00:00', $NOW));
function dt($t) { return date('Y-m-d H:i:s', $t); }

// ---- lookups -----------------------------------------------------------------------------------
$tech = [
    'D' => intval(one("SELECT user_id FROM users WHERE user_email = 'devon.park@lakeshoremsp.example'")),
    'S' => intval(one("SELECT user_id FROM users WHERE user_email = 'sam.ortiz@lakeshoremsp.example'")),
    'M' => intval(one("SELECT user_id FROM users WHERE user_email = 'morgan.reyes@lakeshoremsp.example'")),
];
if (!$tech['D'] || !$tech['S'] || !$tech['M']) { fwrite(STDERR, "90-insight-ticket-history: run seed 00 first.\n"); exit(1); }
$catId = function ($name) { return intval(one("SELECT category_id FROM categories WHERE category_type = 'Ticket' AND category_name = '" . e($name) . "' AND category_archived_at IS NULL ORDER BY category_id LIMIT 1")); };
$cat = ['Remote' => $catId('Remote'), 'On-Site' => $catId('On-Site'), 'Project' => $catId('Project'), 'Maintenance' => $catId('Maintenance'),
    'Service Requests' => $catId('Service Requests'), 'New User Setup' => $catId('New User Setup'), 'Hardware Request' => $catId('Hardware Request'), 'Software Install' => $catId('Software Install')];
foreach ($cat as $k => $v) { if (!$v) { fwrite(STDERR, "90-insight-ticket-history: no ticket category '$k'; run seed 30.\n"); exit(1); } }
$slaPolicy = intval(one('SELECT policy_id FROM sla_policies WHERE policy_is_default = 1 AND policy_archived_at IS NULL LIMIT 1'));
$prefix = (string) one('SELECT config_ticket_prefix FROM settings WHERE company_id = 1');

// clients: name => [tickets per month, app noun, place noun, contract id lookup, usual source mix]
$CL = [
    'Northshore Dental Group'        => [4.0, 'practice-management software', 'front desk'],
    'Birchwood Law Partners'         => [3.0, 'document management system', 'reception'],
    'Granite Peak Manufacturing'     => [5.0, 'plant ERP', 'production floor'],
    'Lakeview Family Credit Union'   => [4.0, 'core banking terminal', 'teller line'],
    'Cedar & Pine Architects'        => [2.0, 'CAD workstation', 'drafting studio'],
    'Maple Street Veterinary Clinic' => [2.0, 'clinic scheduling software', 'exam room'],
    'Ironwood Logistics'             => [4.0, 'dispatch software', 'warehouse'],
    'Bluebird Early Learning'        => [2.0, 'classroom Chromebooks', 'classroom'],
    'Kestrel Property Management'    => [1.5, 'property accounting software', 'leasing office'],
];
$clientRow = [];
foreach ($CL as $name => $x) {
    $cid = intval(one("SELECT client_id FROM clients WHERE client_name = '" . e($name) . "'"));
    if (!$cid) { fwrite(STDERR, "90-insight-ticket-history: client '$name' missing; run seed 00.\n"); exit(1); }
    $contacts = [];
    $r = mysqli_query($mysqli, "SELECT contact_id FROM contacts WHERE contact_client_id = $cid AND contact_archived_at IS NULL ORDER BY contact_id");
    while ($c = mysqli_fetch_row($r)) { $contacts[] = intval($c[0]); }
    $clientRow[$name] = ['id' => $cid, 'contacts' => $contacts, 'rate' => $x[0], 'app' => $x[1], 'place' => $x[2]];
}
// Bluebird's included-hours plan (and any other client with an active contract that covers the date) is attached to the ticket
$contractFor = function ($cid, $date) use ($mysqli) {
    $r = mysqli_fetch_row(mysqli_query($mysqli, "SELECT contract_id FROM contracts WHERE contract_client_id = $cid AND contract_status IN ('Active', 'Expired') AND contract_archived_at IS NULL
        AND (contract_support_hours_included_remote IS NOT NULL OR contract_support_hours_included_onsite IS NOT NULL)
        AND contract_start_date <= '$date' AND (contract_end_date IS NULL OR contract_end_date >= '$date') ORDER BY contract_id LIMIT 1"));
    return $r ? intval($r[0]) : null;
};

// ---- the stock of problems ---------------------------------------------------------------------
// category => [[subject, details]] ; {app} and {place} are replaced per client
$POOL = [
    'Remote' => [
        ['Password reset for {place} user', 'Locked out of the Windows account after three tries at the {place}.'],
        ['Outlook keeps asking for a password', 'Outlook prompts for the Microsoft 365 password every few minutes since this morning.'],
        ['{app} is very slow today', 'The {app} takes more than a minute to open a record. Everyone at the {place} is affected.'],
        ['Printer offline at the {place}', 'The shared printer shows offline on every PC at the {place}.'],
        ['Cannot connect to the VPN from home', 'The VPN client says the connection timed out. It worked yesterday.'],
        ['Mailbox is almost full', 'Outlook warns that the mailbox is at 98 percent. Please free up space or raise the limit.'],
        ['Shared drive permission request', 'A new colleague needs access to the shared drive folder. Please grant read and write.'],
        ['Wi-Fi keeps dropping on one laptop', 'One laptop drops off the office Wi-Fi every half hour. The other devices are fine.'],
        ['Teams meeting has no audio', 'Teams meeting joins fine but there is no sound from the speakers or the headset.'],
        ['Suspicious email received', 'An email asking me to confirm my password looks wrong. I did not click anything.'],
        ['Browser redirects to strange sites', 'Chrome keeps opening a search page we do not recognise.'],
        ['Scan to email stopped working', 'The copier says authentication failed when scanning to email.'],
    ],
    'On-Site' => [
        ['Workstation will not power on', 'The PC at the {place} will not power on. The power light does not come on.'],
        ['Network drop dead in the {place}', 'The wall port at the {place} has no link. We tried a second cable.'],
        ['Monitor flickering', 'The monitor flickers and goes black for a second every few minutes.'],
        ['Replace failing UPS battery', 'The UPS at the {place} beeps constantly and shows a replace battery warning.'],
        ['Access point needs repositioning', 'Wi-Fi is weak at the far end of the {place}. We would like an access point moved.'],
        ['Label printer needs a new roll and a test', 'The label printer prints blank labels after we changed the roll.'],
        ['Phone headset not working', 'The headset is not detected by the {app} softphone at the {place}.'],
        ['Rack tidy and cable labelling', 'The network closet is a mess. Please tidy and label the patch panel.'],
    ],
    'Project' => [
        ['Windows 11 upgrade for {place} PCs', 'Plan and carry out the Windows 11 upgrade of the PCs at the {place}.'],
        ['Firewall rule review', 'Review the firewall rules, remove stale entries and document the result.'],
        ['Move the server closet to the new rack', 'Move the switch and patch panel to the new wall rack with minimal downtime.'],
        ['Roll out multi-factor sign-in', 'Turn on multi-factor sign-in for all users and walk them through the first login.'],
        ['Migrate file shares to the cloud', 'Move the shared folders to SharePoint and map the new locations.'],
    ],
    'Maintenance' => [
        ['Backup job failed overnight', 'Monitoring flagged a failed overnight backup job.'],
        ['Disk space low on the file server', 'The file server data volume has less than 10 percent free.'],
        ['Windows updates pending reboot', 'Several workstations have been waiting for a restart after patching.'],
        ['Certificate expiring soon', 'A website certificate expires within two weeks and needs renewal.'],
        ['Antivirus definitions out of date', 'Two endpoints have not checked in for new antivirus definitions in days.'],
    ],
    'Service Requests' => [
        ['Install the {app} update', 'The vendor released an update to the {app}. Please schedule the install.'],
        ['Add a shared mailbox', 'We need a shared mailbox for the {place} team with five members.'],
        ['Move a phone extension', 'Please move the extension from the old desk to the new desk at the {place}.'],
        ['Order replacement toner', 'Please order two toner cartridges for the {place} printer.'],
        ['Add a second monitor', 'A second monitor for the {place} PC would help a lot. Please quote one.'],
    ],
    'New User Setup' => [
        ['New hire setup', 'A new colleague starts Monday at the {place}. Needs a mailbox, the {app} and a workstation.'],
        ['New hire: laptop and accounts', 'New starter needs a laptop, Microsoft 365 licence and building access card help.'],
    ],
    'Hardware Request' => [
        ['New laptop request', 'Please quote and set up a new laptop for the {place}.'],
        ['Keyboard and mouse replacement', 'The keyboard has sticky keys and the mouse double-clicks.'],
        ['Replace a failed hard drive', 'The drive in a workstation at the {place} reports SMART errors. Please replace it.'],
    ],
    'Software Install' => [
        ['Install PDF editor', 'Please install the licensed PDF editor on the PC at the {place}.'],
        ['Install the new {app} client', 'The {app} vendor sent a new client. Please install it on two PCs.'],
        ['Install a driver for the new scanner', 'The new scanner needs a driver on the PC at the {place}.'],
    ],
];
$CAT_WEIGHT = ['Remote' => 44, 'On-Site' => 17, 'Project' => 5, 'Maintenance' => 10, 'Service Requests' => 9, 'New User Setup' => 3, 'Hardware Request' => 6, 'Software Install' => 6];
$FIX_REMOTE = ['Cleared the cached credentials and signed in again. Confirmed with the user.', 'Restarted the service and checked the logs. Working again.', 'Updated the driver and tested a print. All good.',
    'Reset the password and enforced a change at next sign-in.', 'Applied the pending updates and rebooted. The problem is gone.', 'Removed the stale profile and rebuilt it. Mail is flowing.',
    'Adjusted the permissions on the folder and verified with the user.', 'Re-added the device to the network and tested the connection.'];
$FIX_ONSITE = ['Replaced the faulty part on site and tested the workstation with the user.', 'Re-terminated the network drop and verified the link at the patch panel.', 'Swapped the unit, tidied the cabling and confirmed everything is working.',
    'Repositioned the access point and checked signal strength in each corner.', 'Installed the new battery and ran a load test on the UPS.'];
$PUBLIC = ['We have looked into this and the fix is in place. Please let us know if you see it again.', 'Thanks for the report. We found the cause and have corrected it. Everything should be working now.',
    'We are on it and will update you shortly.', 'The issue is resolved. We left a short note on what changed in your ticket.', 'This is sorted. Please try again and tell us if anything looks different.'];
$COMMENTS = [5 => ['Fast and friendly, thank you!', 'Fixed within the hour, great service.', 'Very helpful technician.', ''], 4 => ['Good, thanks.', 'Solved, though it took a little follow-up.', ''],
    3 => ['It works but took longer than I hoped.', ''], 2 => ['Had to call back twice.', 'Slow to respond.'], 1 => ['Not fixed on the first visit.', 'Still waiting for a callback.']];
$TECH_MIX = ['D' => 40, 'S' => 33, 'M' => 27];
$SOURCES = ['Email' => 30, 'Portal' => 28, 'Phone' => 12, 'Agent' => 20, 'Recurring' => 0];
$SEASON = [1 => 1.10, 2 => 1.00, 3 => 1.15, 4 => 1.00, 5 => 1.05, 6 => 0.95, 7 => 0.85, 8 => 1.00, 9 => 1.10, 10 => 1.05, 11 => 1.00, 12 => 0.80];

mt_srand(9001);
function pick($weights) { $sum = array_sum($weights); $r = mt_rand(1, $sum * 100) / 100; foreach ($weights as $k => $w) { $r -= $w; if ($r <= 0) { return $k; } } return array_key_last($weights); }
function chance($p) { return mt_rand(1, 10000) <= $p * 10000; }

// ---- build the list (deterministic, so ids line up on every run) ---------------------------------
$planned = [];
for ($age = 365; $age >= 84; $age--) {
    $day = strtotime("-$age day", $today);
    $dow = (int) date('N', $day);
    if ($dow >= 6) { continue; }
    $mo = (int) date('n', $day);
    foreach ($CL as $name => $info) {
        $p = $info[0] / 21.7 * $SEASON[$mo];
        if (!chance(min(0.95, $p))) { continue; }
        $planned[] = ['client' => $name, 'day' => $day];
    }
}
$seq = 0;
$made = 0;
foreach ($planned as $pl) {
    $seq++;
    $name = $pl['client'];
    $c = $clientRow[$name];
    $key = sprintf('hist90-%03d', $seq);
    // draw everything first so skipping a row never shifts the random sequence
    $category = pick($CAT_WEIGHT);
    $entry = $POOL[$category][mt_rand(0, count($POOL[$category]) - 1)];
    $pri = pick(['Low' => 29, 'Medium' => 50, 'High' => 17, 'Critical' => 4]);
    if ($category === 'Maintenance') { $pri = chance(0.2) ? 'High' : 'Medium'; }
    $assignee = $tech[pick($TECH_MIX)];
    $src = pick($SOURCES);
    if ($category === 'Maintenance') { $src = 'Agent'; }
    $hour = mt_rand(8, 16); $minute = mt_rand(0, 59);
    $created_t = $pl['day'] + $hour * 3600 + $minute * 60;
    $contactId = $c['contacts'][mt_rand(0, count($c['contacts']) - 1)];
    $onsite = ($category === 'On-Site' || $category === 'Project' || ($category === 'Hardware Request' && chance(0.4)));
    // response / resolution against the default policy (calendar minutes, kept simple)
    $key_pri = ($pri === 'Critical') ? 'high' : strtolower($pri);
    $pol = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT policy_{$key_pri}_response AS r, policy_{$key_pri}_resolution AS s FROM sla_policies WHERE policy_id = " . max(1, $slaPolicy)));
    $respT = intval($pol['r'] ?? 240) * 60; $resoT = intval($pol['s'] ?? 1440) * 60;
    $lateResp = chance(0.09); $lateReso = chance(0.12);
    $respDelay = $lateResp ? (int) ($respT * mt_rand(120, 260) / 100) : (int) ($respT * mt_rand(5, 85) / 100);
    $resoDelay = $lateReso ? (int) ($resoT * mt_rand(110, 220) / 100) : (int) ($resoT * mt_rand(8, 90) / 100);
    if ($onsite) { $resoDelay = max($resoDelay, 3600 * mt_rand(3, 30)); }
    $resoDelay = max($resoDelay, $respDelay + 1200);
    $closed_t = $created_t + $resoDelay;
    $first_t = $created_t + $respDelay;
    $maint = ($category === 'Maintenance');
    $billable = $maint ? 0 : (chance(in_array($name, ['Cedar & Pine Architects', 'Maple Street Veterinary Clinic', 'Kestrel Property Management'], true) ? 0.75 : 0.35) ? 1 : 0);
    if (in_array($category, ['Project', 'Hardware Request'], true)) { $billable = 1; }
    $csatRate = chance(0.42);
    $rating = $csatRate ? pick([5 => 52, 4 => 28, 3 => 11, 2 => 5, 1 => 4]) : null;
    $comment = $csatRate ? $COMMENTS[$rating][mt_rand(0, count($COMMENTS[$rating]) - 1)] : '';
    $ratedAfter = mt_rand(1, 40) * 3600;
    // time worked: two work replies; on-site adds a travel reply
    $m1 = [10, 15, 20, 25, 30, 45][mt_rand(0, 5)]; $m2 = $onsite ? [45, 60, 90, 120, 180][mt_rand(0, 4)] : [10, 15, 20, 30, 45, 60][mt_rand(0, 5)];
    if ($category === 'Project') { $m2 = [180, 240, 360][mt_rand(0, 2)]; }
    $afterHours = chance(0.07);
    $fix = $onsite ? $FIX_ONSITE[mt_rand(0, count($FIX_ONSITE) - 1)] : $FIX_REMOTE[mt_rand(0, count($FIX_REMOTE) - 1)];
    $publicTxt = $PUBLIC[mt_rand(0, count($PUBLIC) - 1)];
    $clientThanks = chance(0.35);
    $travelMin = $onsite ? [30, 45, 60][mt_rand(0, 2)] : 0;

    if (intval(one("SELECT ticket_id FROM tickets WHERE ticket_url_key = '$key'"))) { continue; }

    $subject = str_replace(['{app}', '{place}'], [$c['app'], $c['place']], $entry[0]);
    $details = str_replace(['{app}', '{place}'], [$c['app'], $c['place']], $entry[1]);
    $subject = ucfirst($subject);
    $deliv = getTicketDeliveryMethodForCategory($mysqli, $cat[$category]);
    $delivSql = $deliv ? "'" . e($deliv) . "'" : 'NULL';
    $creator = ($src === 'Agent' || $src === 'Phone') ? $assignee : 0;
    $contract = $contractFor($c['id'], date('Y-m-d', $created_t));
    $contractSql = $contract ? $contract : 'NULL';
    $respDue = $created_t + $respT; $resoDue = $created_t + $resoT;

    q("UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number), config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1");
    $number = intval(mysqli_insert_id($mysqli));
    $csatSql = $csatRate ? "ticket_csat_rating = $rating, ticket_csat_comment = " . ($comment !== '' ? "'" . e($comment) . "'" : 'NULL') . ", ticket_csat_rated_at = '" . dt($closed_t + $ratedAfter) . "'," : '';
    q("INSERT INTO tickets SET ticket_prefix = '" . e($prefix) . "', ticket_number = $number, ticket_source = '" . e($src) . "', ticket_category = " . $cat[$category] . ",
        ticket_subject = '" . e($subject) . "', ticket_details = '" . e('<p>' . $details . '</p>') . "', ticket_priority = '" . e($pri) . "', ticket_status = 5,
        ticket_billable = $billable, ticket_onsite = " . ($onsite ? 1 : 0) . ", ticket_url_key = '$key', $csatSql
        ticket_created_at = '" . dt($created_t) . "', ticket_updated_at = '" . dt($closed_t) . "', ticket_first_response_at = '" . dt($first_t) . "',
        ticket_resolved_at = '" . dt($closed_t) . "', ticket_closed_at = '" . dt($closed_t) . "', ticket_closed_by = $assignee,
        ticket_created_by = $creator, ticket_assigned_to = $assignee, ticket_client_id = {$c['id']}, ticket_contact_id = $contactId,
        ticket_delivery_method = $delivSql, ticket_contract_id = $contractSql, ticket_rmm_sourced = " . ($maint ? 1 : 0) . ", ticket_counts_sla = " . ($maint ? 0 : 1) . ",
        ticket_sla_policy_id = " . ($slaPolicy && !$maint ? $slaPolicy : 'NULL') . ",
        ticket_sla_response_due = " . ($maint ? 'NULL' : "'" . dt($respDue) . "'") . ", ticket_sla_resolution_due = " . ($maint ? 'NULL' : "'" . dt($resoDue) . "'"));
    $id = intval(mysqli_insert_id($mysqli));

    $initType = $creator ? 'Internal' : 'Client';
    $initBy = $creator ?: $contactId;
    q("INSERT INTO ticket_replies SET ticket_reply = '" . e('<strong>Initial Issue</strong><br><p>' . $details . '</p>') . "', ticket_reply_type = '$initType', ticket_reply_by = $initBy,
        ticket_reply_ticket_id = $id, ticket_reply_created_at = '" . dt($created_t) . "'");
    q("UPDATE tickets SET ticket_initial_issue_reply_id = " . intval(mysqli_insert_id($mysqli)) . ", ticket_updated_at = '" . dt($closed_t) . "' WHERE ticket_id = $id");

    $lab1 = $afterHours ? 3 : ($onsite ? 2 : 1);
    $labMain = $category === 'Project' ? 4 : $lab1;
    $reply = function ($type, $by, $txt, $at, $mins = 0, $labor = null, $onsiteFlag = 0) use ($id) {
        $worked = $mins ? sprintf("'%02d:%02d:00'", intdiv($mins, 60), $mins % 60) : 'NULL';
        q("INSERT INTO ticket_replies SET ticket_reply = '" . e('<p>' . $txt . '</p>') . "', ticket_reply_type = '$type', ticket_reply_by = $by, ticket_reply_ticket_id = $id,
            ticket_reply_created_at = '" . dt($at) . "', ticket_reply_time_worked = $worked, ticket_reply_labor_type_id = " . ($labor ?: 'NULL') . ",
            ticket_reply_emailed = " . ($type === 'Public' ? 1 : 0) . ", ticket_reply_onsite = $onsiteFlag");
    };
    $reply('Public', $assignee, $publicTxt, $first_t, $m1, $afterHours ? 3 : 1);
    if ($travelMin) { $reply('Internal', $assignee, 'Travel to and from the client site.', $created_t + intdiv($resoDelay, 3), $travelMin, 5, 1); }
    $reply('Internal', $assignee, $fix, $closed_t - 900, $m2, $labMain, $onsite ? 1 : 0);
    if ($clientThanks) { $reply('Client', $contactId, 'Thank you, that is working now.', $closed_t - 300); }
    q("INSERT INTO ticket_replies SET ticket_reply = 'Ticket closed.', ticket_reply_type = 'System', ticket_reply_by = $assignee, ticket_reply_ticket_id = $id, ticket_reply_created_at = '" . dt($closed_t) . "'");
    q("INSERT INTO ticket_history SET ticket_history_status = 'New', ticket_history_description = 'Ticket created', ticket_history_ticket_id = $id, ticket_history_created_at = '" . dt($created_t) . "'");
    q("INSERT INTO ticket_history SET ticket_history_status = 'Closed', ticket_history_description = 'Ticket closed', ticket_history_ticket_id = $id, ticket_history_created_at = '" . dt($closed_t) . "'");
    $made++;
}

// Keep the counter ahead of the highest number (the live counter was used above, this is only a safety net)
q("UPDATE settings SET config_ticket_next_number = GREATEST(config_ticket_next_number, (SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets)) WHERE company_id = 1");

// The queue from seed 31 belongs to the same included-hours contracts: attach them so the Included Support Hours report has current data.
foreach (['Bluebird Early Learning', 'Maple Street Veterinary Clinic'] as $nm) {
    $cid = $clientRow[$nm]['id'];
    $r = mysqli_query($mysqli, "SELECT ticket_id, DATE(ticket_created_at) d FROM tickets WHERE ticket_client_id = $cid AND ticket_contract_id IS NULL AND ticket_url_key NOT LIKE 'hist90-%'");
    while ($t = mysqli_fetch_assoc($r)) {
        $ct = $contractFor($cid, $t['d']);
        if ($ct) { q("UPDATE tickets SET ticket_contract_id = $ct, ticket_updated_at = ticket_updated_at WHERE ticket_id = " . intval($t['ticket_id'])); }
    }
}

echo "90-insight-ticket-history: $made tickets created, " . count($planned) . " planned.\n";
