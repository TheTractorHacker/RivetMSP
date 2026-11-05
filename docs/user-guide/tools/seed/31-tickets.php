<?php
/*
 * The ticket queue for the RivetMSP user-guide demo (group G2): 50 tickets across the nine active clients, the
 * technicians, every status and priority, with conversations, time worked, tags, tasks, watchers, a parent with two
 * child tickets, one merged duplicate, one catalog request held for approval and an on-site schedule on a few tickets.
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/31-tickets.php
 *
 * Needs seeds 00 (clients, contacts, technicians), 01 (modules) and 30 (categories, tags, templates, catalog).
 * Idempotent: a ticket is skipped when one with the same subject already exists for the client. Ticket numbers are
 * drawn from the live counter (Settings > Ticketing), oldest ticket first, so the numbers read chronologically.
 * Dates are relative to "now" so the queue always looks current (overdue, due today, this week...).
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "31-tickets: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';
require_once '../includes/sla_functions.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "31-tickets: " . mysqli_error($mysqli) . "\n  " . substr($sql, 0, 300) . "\n"); exit(1); } }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function e($s) { global $mysqli; return mysqli_real_escape_string($mysqli, $s); }

// the app keeps company-local times; stamp rows the same way (a bare CLI script would default to UTC)
$tzName = one('SELECT config_timezone FROM settings WHERE company_id = 1');
if ($tzName) { date_default_timezone_set($tzName); }
$NOW = time();
function ts($days, $hours = 0, $mins = 0) { global $NOW; return $NOW - ($days * 86400 + $hours * 3600 + $mins * 60); }
function dt($t) { return date('Y-m-d H:i:s', $t); }

// ---- lookups ------------------------------------------------------------------------------------
$tech = [
    'D' => intval(one("SELECT user_id FROM users WHERE user_email = 'devon.park@lakeshoremsp.example'")),
    'S' => intval(one("SELECT user_id FROM users WHERE user_email = 'sam.ortiz@lakeshoremsp.example'")),
    'M' => intval(one("SELECT user_id FROM users WHERE user_email = 'morgan.reyes@lakeshoremsp.example'")),
    '' => 0,
];
if (!$tech['D'] || !$tech['S'] || !$tech['M']) { fwrite(STDERR, "31-tickets: run seed 00 first.\n"); exit(1); }
$status = [];
$r = mysqli_query($mysqli, 'SELECT ticket_status_id, ticket_status_name FROM ticket_statuses');
while ($s = mysqli_fetch_assoc($r)) { $status[$s['ticket_status_name']] = intval($s['ticket_status_id']); }
$clientId = function ($name) { return intval(one("SELECT client_id FROM clients WHERE client_name = '" . e($name) . "'")); };
$contactId = function ($cid, $name) { return intval(one("SELECT contact_id FROM contacts WHERE contact_client_id = $cid AND contact_name = '" . e($name) . "'")); };
$catId = function ($name) { return intval(one("SELECT category_id FROM categories WHERE category_type = 'Ticket' AND category_name = '" . e($name) . "' AND category_archived_at IS NULL ORDER BY category_parent DESC LIMIT 1")); };
$tagId = function ($name) { return intval(one("SELECT tag_id FROM tags WHERE tag_type = 6 AND tag_name = '" . e($name) . "'")); };
$catalogId = function ($name) { return intval(one("SELECT catalog_item_id FROM service_catalog_items WHERE name = '" . e($name) . "'")); };
$tplId = function ($name) { return intval(one("SELECT ticket_template_id FROM ticket_templates WHERE ticket_template_name = '" . e($name) . "' AND ticket_template_archived_at IS NULL")); };

// Short names used in the table below
$CL = [
    'NSD' => 'Northshore Dental Group', 'BWL' => 'Birchwood Law Partners', 'GPM' => 'Granite Peak Manufacturing',
    'LFCU' => 'Lakeview Family Credit Union', 'CPA' => 'Cedar & Pine Architects', 'MSVC' => 'Maple Street Veterinary Clinic',
    'IWL' => 'Ironwood Logistics', 'BEL' => 'Bluebird Early Learning', 'KPM' => 'Kestrel Property Management',
];

// ---- the tickets -------------------------------------------------------------------------------
// t(client, contact, subject, details, priority, category, status, assignee, [days, hours] old, extras)
// extras: src, bill (0/1), tags[], due (days from now, may be negative), onsite (hours from now when a visit is booked),
//         catalog (item name), tpl (template for tasks), rep[] = ['I|P|N|C|L', minutes after creation, text, worked 'HH:MM', who],
//         close (minutes after creation the ticket was closed), parent (subject of the parent), merged (subject it merged into),
//         watchers[], techs[] (extra technicians), tasks[] = [name, done 0/1], hold (System note text)
$T = [];
function t($c, $ct, $subj, $det, $pri, $cat, $st, $as, $age, $x = []) {
    global $T;
    $T[] = array_merge(['c' => $c, 'ct' => $ct, 's' => $subj, 'd' => $det, 'p' => $pri, 'cat' => $cat, 'st' => $st, 'as' => $as, 'age' => $age], $x);
}

// ----- New (nobody has picked them up) -----
t('NSD', 'Hannah Brooks', 'Front desk printer offline again', 'The HP printer at the front desk shows offline on every PC since this morning. Patients are waiting for their forms.', 'Medium', 'Remote', 'New', '', [0, 3], ['src' => 'Portal']);
t('BWL', 'Theo Lindqvist', 'Shared mailbox for the new intake team', 'We are starting an intake team on Monday and need one shared mailbox that five people can read and send from.', 'Medium', 'Remote', 'New', '', [0, 6], ['src' => 'Email']);
t('GPM', 'Marcus Webb', 'Line 3 HMI panel is asking for a Windows restart', 'The touch panel on Line 3 shows a restart prompt. We cannot restart during the shift. Please advise on timing.', 'High', 'On-Site', 'New', '', [1, 2], ['src' => 'Portal', 'tags' => ['After hours']]);
t('CPA', 'Wen Li', 'Plotter prints blank pages after the last job', 'The large-format plotter feeds paper but prints blank sheets. We tried turning it off and on.', 'Medium', 'Remote', 'New', '', [1, 5], ['src' => 'Email']);
t('KPM', 'Imani Carter', 'Lobby Wi-Fi drops every afternoon', 'Visitors in the lobby lose the guest Wi-Fi between 2 and 4 pm. The office network is fine.', 'Medium', 'On-Site', 'New', '', [2, 0], ['src' => 'Portal']);
t('MSVC', 'Beth Nakamura', 'Please install the label printer driver on exam room 2', 'Requested from the portal: install the new label printer driver on the exam room 2 PC.', 'Low', 'Software Install', 'New', '', [2, 6], ['src' => 'Portal', 'catalog' => 'Install software']);

// ----- Open, assigned -----
t('LFCU', 'Gordon Pike', 'Branch teller PC will not join the domain', 'The replacement teller PC at the main branch refuses to join the domain: "The specified domain does not exist". Needed before Monday.', 'High', 'Remote', 'Open', 'D', [1, 0], ['src' => 'Portal', 'bill' => 1, 'tags' => ['Compliance'], 'due' => 1, 'rep' => [
    ['I', 20, 'Checked DNS on the branch VLAN. The teller subnet is pointing at the old resolver. Fixing the DHCP scope.', '00:20'],
    ['P', 45, 'We found the cause: the teller network was handing out an old DNS server. We have corrected it and will retry the domain join this afternoon.', '00:10'],
    ['C', 300, 'Thanks. It joined, but now the PC shows a certificate warning when opening the core banking site.', null],
]]);
t('NSD', 'Dr. Priya Raman', 'Imaging workstation running slow', 'The imaging PC in operatory 2 takes minutes to open X-ray studies. Dr. Raman has patients booked all day.', 'High', 'On-Site', 'Open', 'S', [2, 0], ['src' => 'Agent', 'bill' => 1, 'tags' => ['VIP'], 'onsite' => 20, 'techs' => ['D'], 'rep' => [
    ['I', 30, 'Remote check shows the disk at 98% and the imaging cache over 400 GB. Booking a visit to add a drive.', '00:25'],
    ['P', 40, 'We would like to visit tomorrow morning before patients arrive to add storage. Please confirm that works.', '00:05'],
    ['C', 600, 'Tomorrow before 8 works. Someone will let you in.', null],
]]);
t('BWL', 'Margaret Birchwood', 'Outlook keeps asking for a password', 'Outlook on my laptop asks for my Microsoft 365 password every few minutes since Monday.', 'Medium', 'Remote', 'Open', 'D', [3, 0], ['src' => 'Email', 'bill' => 1, 'tags' => ['VIP'], 'rep' => [
    ['I', 60, 'Credential Manager held stale entries. Cleared them and rebuilt the Outlook profile.', '00:35'],
    ['P', 70, 'We cleared the saved sign-in data and rebuilt your Outlook profile. Please let us know if the prompt comes back.', '00:05'],
    ['C', 2000, 'It came back this morning after I restarted. Could you look again?', null],
]]);
t('GPM', 'Robert Kowalski', 'Quarterly firewall rule review', 'Walk through the plant firewall rules with Robert, remove stale entries and document the result.', 'Medium', 'Project', 'Open', 'M', [6, 0], ['src' => 'Agent', 'bill' => 1, 'rep' => [
    ['I', 90, 'Kick-off done. Split into an export step and a sign-off step; see the child tickets.', '00:30'],
]]);
t('GPM', 'Marcus Webb', 'Firewall review: export current rules', 'Export the current rule set and flag anything not used in 90 days.', 'Low', 'Project', 'Open', 'D', [5, 0], ['src' => 'Agent', 'parent' => 'Quarterly firewall rule review', 'rep' => [
    ['I', 120, 'Export saved. 14 rules have no hits in 90 days; list attached to the parent ticket notes.', '00:40'],
]]);
t('GPM', 'Robert Kowalski', 'Firewall review: client sign-off', 'Review the flagged rules with Robert and get written approval to remove them.', 'Low', 'Project', 'Open', 'M', [5, 0], ['src' => 'Agent', 'parent' => 'Quarterly firewall rule review']);
t('IWL', 'Tara Okonkwo', 'Handheld scanners will not sync in the warehouse', 'Six handheld scanners stopped syncing orders this morning. Trucks are waiting to be loaded.', 'High', 'On-Site', 'Open', 'S', [1, 0], ['src' => 'Portal', 'bill' => 1, 'onsite' => 3, 'rep' => [
    ['I', 15, 'Scanner server is reachable. The warehouse access point is dropping clients. On my way.', '00:15'],
    ['P', 20, 'We are heading to the distribution center now and will check the Wi-Fi access point.', '00:05'],
    ['C', 30, 'Thank you, the loading dock is the worst spot.', null],
]]);
t('IWL', 'Frank Delaney', 'New dispatcher workstation', 'Please quote and set up a new workstation for the dispatch office with two monitors.', 'Medium', 'Hardware Request', 'Open', 'D', [4, 0], ['src' => 'Email', 'bill' => 1, 'rep' => [
    ['I', 200, 'Quote requested from the distributor. Two 27-inch monitors and a small-form-factor PC.', '00:20'],
    ['P', 210, 'We have asked for pricing and will send you a quote by tomorrow.', '00:05'],
]]);
t('BEL', 'Sunita Patel', 'Add three volunteers to the shared drive', 'Three new volunteers need read access to the Events folder only.', 'Low', 'Remote', 'Open', 'D', [5, 0], ['src' => 'Portal', 'rep' => [
    ['I', 50, 'Created a read-only group for the Events folder. Waiting on the volunteers\' names and email addresses.', '00:10'],
    ['P', 55, 'Could you send us the three volunteers\' full names and email addresses?', '00:05'],
]]);
t('CPA', 'Oliver Cedar', 'CAD workstation crashes when rendering', 'The workstation in the drafting corner crashes to a black screen during large renders.', 'High', 'Remote', 'Open', 'S', [2, 0], ['src' => 'Email', 'bill' => 1, 'rep' => [
    ['I', 40, 'GPU driver is two years old and the event log shows display driver resets. Planning a driver update tonight.', '00:30'],
    ['P', 50, 'We found an outdated graphics driver that explains the crashes. We will update it after hours tonight.', '00:05'],
]]);
t('MSVC', 'Dr. Alan Whitcomb', 'Practice software update needs a restart window', 'The practice-management vendor wants to update the server this week. Please schedule a safe restart window.', 'Medium', 'Remote', 'Open', 'D', [4, 0], ['src' => 'Portal', 'due' => 0, 'rep' => [
    ['I', 100, 'Vendor suggests Thursday 6 pm. Backups verified clean this morning.', '00:15'],
    ['P', 110, 'The vendor proposes Thursday at 6 pm, after the clinic closes. Does that suit you?', '00:05'],
    ['C', 1500, 'Yes, 6 pm is fine.', null],
]]);
t('KPM', 'Paul Gustafson', 'Conference room display not detected', 'Laptops no longer detect the display in the second-floor conference room.', 'Low', 'On-Site', 'Open', 'S', [7, 0], ['src' => 'Portal', 'due' => -2, 'rep' => [
    ['I', 70, 'Needs a look at the wall HDMI plate. Will fit it into the next visit to Kestrel.', '00:10'],
]]);
t('NSD', 'Luis Ferreira', 'Cannot print from the hygiene room laptop', 'The hygiene room laptop does not list the clinical printer any more.', 'Low', 'Remote', 'Open', 'D', [8, 0], ['src' => 'Portal', 'due' => -3, 'csat' => [2, 'Still could not print after the fix.', 1], 'rep' => [
    ['I', 40, 'Re-added the printer and tested a page.', '00:15'],
    ['P', 45, 'The printer is back in your list. Please print a test page and let us know.', '00:05'],
    ['N', 6000, 'Automatically reopened — a guest rated this ticket 2/5: "Still could not print after the fix."', null, 'X'],
]]);
t('BWL', 'Anika Desai', 'Scanner to email stopped working', 'Scan-to-email on the second-floor copier says authentication failed.', 'Medium', 'Remote', 'Open', 'D', [9, 0], ['src' => 'Email', 'due' => -1, 'rep' => [
    ['I', 80, 'The copier uses the old mailbox password. Needs an app password; waiting for Theo to approve.', '00:20'],
    ['P', 90, 'The copier needs a new mail password. We have asked your firm administrator to approve the change.', '00:05'],
]]);
t('LFCU', 'Elena Voss', 'Board meeting laptop prep', 'The board meets Tuesday. Please prepare two loaner laptops with the presentation tools.', 'High', 'Remote', 'Open', 'M', [1, 0], ['src' => 'Email', 'bill' => 1, 'tags' => ['VIP'], 'due' => 2, 'rep' => [
    ['I', 30, 'Two laptops pulled from the loaner shelf. Imaging tonight.', '00:20'],
]]);
t('GPM', 'Denise Hargrove', 'New hire setup: Alex Rivera, AP clerk', 'Alex starts Monday in accounts payable. Needs a mailbox, the accounting software and a workstation.', 'Medium', 'New User Setup', 'Open', 'D', [3, 0], ['src' => 'Portal', 'bill' => 1, 'catalog' => 'New hire setup', 'tpl' => 'New hire setup', 'tasks' => [['Create the user account and mailbox', 1], ['Assign licenses and add to groups', 1], ['Prepare and label the workstation', 0], ['Hand over equipment and confirm sign-in', 0]], 'rep' => [
    ['I', 60, 'Account and mailbox created. Licenses assigned.', '00:25'],
    ['P', 70, 'Alex\'s account and mailbox are ready. We are preparing the workstation next.', '00:05'],
]]);
t('CPA', 'Rosa Martinelli', 'Mouse and keyboard replacement', 'My mouse double-clicks on its own and two keys on the keyboard stick.', 'Low', 'Hardware Request', 'Open', 'S', [6, 0], ['src' => 'Portal', 'rep' => [
    ['I', 90, 'In stock. Will drop them off at the next visit.', '00:05'],
]]);
t('NSD', 'Hannah Brooks', 'Backup job failed overnight', 'Monitoring flagged a failed backup of the practice server.', 'Critical', 'Maintenance', 'Open', 'M', [1, 0], ['src' => 'Agent', 'tags' => ['After hours', 'Security'], 'rep' => [
    ['I', 20, 'Job failed on the disk-full error. Cleaning the staging folder and re-running.', '00:30'],
    ['N', 35, 'Ticket flagged as maintenance: no SLA clock, not billed.', null, 'X'],
]]);
t('IWL', 'Nate Fairbanks', 'QuickBooks Desktop will not open', 'QuickBooks shows an error and closes when I open the company file.', 'High', 'Remote', 'Open', 'D', [0, 5], ['src' => 'Email', 'bill' => 1, 'rep' => [
    ['C', 20, 'Seeing "Error H202" now as well. Please call me.', null],
]]);
t('BEL', 'Kevin Moreau', 'Projector in classroom 2 has no signal', 'The projector in classroom 2 shows "No signal" with the teacher laptop connected.', 'Low', 'On-Site', 'Open', 'S', [3, 0], ['src' => 'Portal', 'onsite' => 70, 'rep' => [
    ['I', 60, 'Probably the HDMI adapter. Adding it to the Friday visit.', '00:10'],
]]);
t('LFCU', 'Camille Dubois', 'Guest Wi-Fi access for the auditors', 'External auditors arrive Monday and need a guest network for three days.', 'Low', 'On-Site', 'Open', 'S', [1, 3], ['src' => 'Portal', 'catalog' => 'Guest Wi-Fi access', 'rep' => [
    ['I', 60, 'Voucher created, valid Monday to Wednesday.', '00:10'],
]]);
t('NSD', 'Dr. Priya Raman', 'Second monitor for the front desk', 'The front desk would like a second monitor for the scheduling software.', 'Low', 'Hardware Request', 'Open', 'D', [0, 8], ['src' => 'Portal', 'rep' => [
    ['C', 15, 'Any size is fine, but it should match the existing one if possible.', null],
]]);

// ----- On hold -----
t('GPM', 'Marcus Webb', 'Replace failing UPS in the server closet', 'The UPS in the server closet reports a failing battery and beeps constantly.', 'Medium', 'On-Site', 'On Hold', 'S', [12, 0], ['src' => 'Agent', 'bill' => 1, 'tags' => ['Waiting on parts'], 'rep' => [
    ['I', 60, 'Battery module ordered, expected next week.', '00:30'],
    ['P', 70, 'The replacement battery is on order. We will schedule the swap when it arrives.', '00:05'],
    ['I', 300, 'Placed on hold until the part arrives.', null],
]]);
t('LFCU', 'Camille Dubois', 'Teller drawer scanner firmware', 'Check scanner firmware is current before the audit.', 'Low', 'Remote', 'On Hold', 'D', [15, 0], ['src' => 'Agent', 'tags' => ['Compliance'], 'rep' => [
    ['I', 60, 'Vendor has not published the new firmware yet. Waiting on the vendor.', '00:10'],
]]);
t('MSVC', 'Beth Nakamura', 'Migrate exam room PCs to Windows 11', 'Plan the Windows 11 upgrade of six exam room PCs.', 'Medium', 'Project', 'On Hold', 'M', [20, 0], ['src' => 'Agent', 'bill' => 1, 'rep' => [
    ['I', 120, 'Quote sent. Waiting on the budget decision from Dr. Whitcomb.', '01:00'],
    ['P', 130, 'Our quote for the six PCs has been sent. We will pause until you let us know how to proceed.', '00:05'],
]]);
t('BWL', 'Margaret Birchwood', 'New computer request: associate attorney', 'Requested from the portal: new laptop for an associate attorney starting next month.', 'Medium', 'Hardware Request', 'On Hold', '', [10, 0], ['src' => 'Portal', 'catalog' => 'New laptop or desktop', 'hold' => 'Held for approval (Service Catalog). A technician releases it by changing its status.']);
t('KPM', 'Imani Carter', 'Move two desks and re-cable network drops', 'We are rearranging the office next month and need two desks re-cabled.', 'Medium', 'On-Site', 'On Hold', 'S', [9, 0], ['src' => 'Email', 'bill' => 1, 'rep' => [
    ['I', 180, 'Date not fixed yet. Holding until the landlord confirms the move.', '00:10'],
]]);

// ----- Closed (resolved) -----
t('NSD', 'Hannah Brooks', 'Reset password for front desk account', 'Locked out of my Windows account after three tries.', 'Low', 'Remote', 'Closed', 'D', [5, 0], ['src' => 'Portal', 'bill' => 0, 'close' => 40, 'csat' => [5, 'Quick and polite. Thank you!', 0], 'rep' => [
    ['I', 10, 'Unlocked the account and reset the password.', '00:10'],
    ['P', 12, 'Your account is unlocked and your temporary password was sent by text. Please change it at next sign-in.', '00:03'],
]]);
t('BWL', 'Theo Lindqvist', 'Set up iPad for partner meetings', 'Two partners want their calendars and mail on new iPads.', 'Medium', 'Remote', 'Closed', 'D', [14, 0], ['src' => 'Email', 'bill' => 1, 'close' => 300, 'csat' => [5, 'Everything worked first time.', 0], 'rep' => [
    ['I', 60, 'Enrolled both iPads and installed Outlook and Teams.', '00:50'],
    ['P', 120, 'Both iPads are ready with mail and calendar. We left setup notes in the shared folder.', '00:05'],
]]);
t('GPM', 'Robert Kowalski', 'Production PC blue screen on startup', 'The PC at the Line 2 station blue-screens at startup.', 'High', 'On-Site', 'Closed', 'S', [20, 0], ['late' => 1, 'src' => 'Portal', 'bill' => 1, 'close' => 420, 'csat' => [4, 'Fixed the same day.', 0], 'rep' => [
    ['I', 20, 'Bad RAM stick found with memtest. Replacing on site.', '01:15'],
    ['P', 400, 'We replaced the faulty memory and the PC has been stable since. Please tell us if it recurs.', '00:05'],
]]);
t('LFCU', 'Gordon Pike', 'Annual endpoint security review', 'Annual review of endpoint protection and patch levels for the audit file.', 'Medium', 'Project', 'Closed', 'M', [45, 0], ['src' => 'Agent', 'bill' => 1, 'tags' => ['Compliance'], 'close' => 7200, 'csat' => [5, 'Thorough documentation for the auditors.', 1], 'rep' => [
    ['I', 300, 'All 38 endpoints checked. Three needed patches; applied.', '02:30'],
    ['P', 7100, 'The review is complete. The report is in the shared compliance folder.', '00:10'],
]]);
t('CPA', 'Oliver Cedar', 'Install Adobe Acrobat on three workstations', 'Please install Acrobat Pro on the three drafting workstations.', 'Low', 'Software Install', 'Closed', 'D', [11, 0], ['src' => 'Portal', 'bill' => 1, 'catalog' => 'Install software', 'close' => 180, 'csat' => [5, '', 0], 'rep' => [
    ['I', 60, 'Installed on all three; licenses recorded.', '00:45'],
]]);
t('MSVC', 'Beth Nakamura', 'Server backup alert: nightly job missed', 'Monitoring says last night\'s server backup did not run.', 'High', 'Maintenance', 'Closed', 'D', [25, 0], ['src' => 'Agent', 'close' => 200, 'rep' => [
    ['I', 15, 'Backup service had stopped after an update. Restarted and ran a manual job, verified.', '00:20'],
]]);
t('IWL', 'Frank Delaney', 'New forklift scanner Wi-Fi profile', 'Add the warehouse Wi-Fi profile to three new scanners.', 'Medium', 'Remote', 'Closed', 'S', [30, 0], ['late' => 1, 'src' => 'Email', 'bill' => 1, 'close' => 2900, 'csat' => [3, 'It took longer than expected.', 0], 'rep' => [
    ['I', 100, 'Profile pushed to two scanners; the third needs a factory reset.', '00:40'],
    ['P', 2800, 'All three scanners are on the warehouse Wi-Fi now.', '00:20'],
]]);
t('BEL', 'Sunita Patel', 'Email signature rollout', 'Please put the new logo and wording in everyone\'s email signature.', 'Low', 'Remote', 'Closed', 'D', [17, 0], ['src' => 'Portal', 'bill' => 1, 'close' => 800, 'csat' => [4, 'Looks great.', 0], 'rep' => [
    ['I', 120, 'Signature template deployed to 11 mailboxes.', '00:45'],
]]);
t('KPM', 'Imani Carter', 'Phone system voicemail not delivering', 'Voicemail messages are no longer arriving in email.', 'High', 'Remote', 'Closed', 'D', [8, 0], ['src' => 'Email', 'bill' => 1, 'close' => 500, 'csat' => [4, 'Resolved quickly, thanks.', 0], 'rep' => [
    ['I', 40, 'The voicemail-to-email relay was blocked by the new spam filter. Added an allow rule.', '00:35'],
    ['P', 480, 'Voicemail email delivery is working again.', '00:05'],
]]);
t('NSD', 'Dr. Priya Raman', 'Dental imaging software license renewal', 'The imaging software license expires next month. Please handle the renewal.', 'Medium', 'Remote', 'Closed', 'M', [35, 0], ['src' => 'Email', 'bill' => 1, 'tags' => ['VIP'], 'close' => 3000, 'csat' => [5, 'Dr. Raman said the team always handles renewals before we have to ask.', 1], 'rep' => [
    ['I', 200, 'Quote approved by the vendor; renewal placed.', '00:30'],
    ['P', 2900, 'The license is renewed through next year. The receipt is in your documentation folder.', '00:05'],
]]);
t('BWL', 'Anika Desai', 'Cannot open large PDFs', 'PDFs over 50 MB hang the viewer.', 'Low', 'Remote', 'Closed', 'D', [22, 0], ['src' => 'Email', 'close' => 400, 'rep' => [
    ['I', 100, 'Viewer cache was corrupt; reinstalled the PDF viewer.', '00:25'],
]]);
t('GPM', 'Marcus Webb', 'PLC programming laptop imaging', 'We need the programming laptop re-imaged with the current PLC software.', 'Medium', 'On-Site', 'Closed', 'S', [40, 0], ['src' => 'Portal', 'bill' => 1, 'close' => 1500, 'rep' => [
    ['I', 300, 'Image applied on site; software versions confirmed with Marcus.', '02:00'],
]]);
t('LFCU', 'Camille Dubois', 'New hire setup: Priya Nair, teller', 'New teller starts next week and needs access to branch systems and the VPN.', 'Medium', 'New User Setup', 'Closed', 'D', [28, 0], ['src' => 'Portal', 'bill' => 1, 'catalog' => 'New hire setup', 'tpl' => 'New hire setup', 'tasks' => [['Create the user account and mailbox', 1], ['Assign licenses and add to groups', 1], ['Prepare and label the workstation', 1], ['Hand over equipment and confirm sign-in', 1]], 'close' => 1400, 'csat' => [5, 'Fast and friendly.', 1], 'rep' => [
    ['I', 120, 'All setup steps finished and the teller signed in on her first morning.', '01:30'],
]]);
t('CPA', 'Wen Li', 'Guest Wi-Fi password for client meeting', 'Please set up guest Wi-Fi for tomorrow\'s client meeting.', 'Low', 'On-Site', 'Closed', 'S', [6, 0], ['src' => 'Portal', 'catalog' => 'Guest Wi-Fi access', 'close' => 120, 'csat' => [4, '', 0], 'rep' => [
    ['I', 30, 'Voucher created and printed for the front desk.', '00:10'],
]]);
t('MSVC', 'Dr. Alan Whitcomb', 'Phishing email reported', 'I got an email asking me to confirm my login. I did not click anything.', 'High', 'Remote', 'Closed', 'D', [13, 0], ['src' => 'Email', 'tags' => ['Security'], 'close' => 90, 'csat' => [5, 'Good that someone checked right away.', 0], 'rep' => [
    ['I', 10, 'Message traced; no clicks recorded. Sender blocked and the message pulled from other mailboxes.', '00:40'],
    ['P', 60, 'Thank you for reporting it. The email was malicious and has been removed from every mailbox.', '00:05'],
]]);
t('IWL', 'Tara Okonkwo', 'Warehouse printer replacement', 'The label printer at dock 2 died. Please replace it.', 'Medium', 'On-Site', 'Closed', 'S', [50, 0], ['late' => 1, 'src' => 'Email', 'bill' => 1, 'close' => 4300, 'csat' => [4, 'Printer works well.', 0], 'rep' => [
    ['I', 200, 'Replacement ordered.', '00:20'],
    ['P', 4200, 'The new label printer is installed and tested at dock 2.', '01:00'],
]]);
t('BEL', 'Jill Anderson', 'Chromebook will not boot', 'A classroom Chromebook shows a sad face and will not start.', 'Low', 'Remote', 'Closed', 'D', [33, 0], ['src' => 'Portal', 'close' => 500, 'rep' => [
    ['I', 60, 'Recovery mode and re-enrolment fixed it.', '00:25'],
]]);
t('KPM', 'Imani Carter', 'Wi-Fi in the lobby is down', 'The lobby Wi-Fi is not working at all today.', 'Medium', 'On-Site', 'Closed', 'S', [1, 20], ['src' => 'Email', 'close' => 30, 'merged' => 'Lobby Wi-Fi drops every afternoon', 'rep' => []]);
t('BWL', 'Margaret Birchwood', 'Laptop battery swelling', 'My laptop battery is swelling and the case is lifting.', 'High', 'On-Site', 'Closed', 'S', [55, 0], ['src' => 'Phone', 'bill' => 1, 'tags' => ['Waiting on parts'], 'close' => 2900, 'csat' => [5, 'Taken seriously straight away.', 0], 'rep' => [
    ['I', 20, 'Advised to power it off and stop charging. Replacement battery ordered.', '00:15'],
    ['P', 2800, 'The new battery is fitted and the old one has been disposed of safely.', '00:45'],
]]);

// ---- insert, oldest first ---------------------------------------------------------------------------
usort($T, function ($a, $b) { return ts($a["age"][0], $a["age"][1]) <=> ts($b["age"][0], $b["age"][1]); });

$byKey = [];       // "client|subject" => ticket id, so a parent / merge target can be found
$created = 0;
$sla_ids = [];
foreach ($T as $x) {
    $cid = $clientId($CL[$x['c']]);
    $contact = $contactId($cid, $x['ct']);
    if (!$cid || !$contact) { fwrite(STDERR, "31-tickets: unknown client/contact {$x['c']} / {$x['ct']}\n"); exit(1); }
    $existing = intval(one("SELECT ticket_id FROM tickets WHERE ticket_client_id = $cid AND ticket_subject = '" . e($x['s']) . "'"));
    if ($existing) { $byKey[$x['c'] . '|' . $x['s']] = $existing; $sla_ids[$existing] = !empty($x['late']); continue; }

    $created_t = ts($x['age'][0], $x['age'][1]);
    $created_at = dt($created_t);
    $assignee = $tech[$x['as']];
    $src = $x['src'] ?? 'Agent';
    $creator = ($src === 'Agent' || $src === 'Phone') ? ($assignee ?: $tech['M']) : 0;
    $cat = $catId($x['cat']);
    if (!$cat) { fwrite(STDERR, "31-tickets: no category {$x['cat']}; run seed 30.\n"); exit(1); }
    $deliv = getTicketDeliveryMethodForCategory($mysqli, $cat);
    $delivSql = $deliv ? "'" . e($deliv) . "'" : 'NULL';
    $st = $status[$x['st']];
    $closed = ($x['st'] === 'Closed');
    $closed_t = $closed ? $created_t + ($x['close'] ?? 60) * 60 : null;
    $bill = intval($x['bill'] ?? 0);
    $key = randomString(32);
    $parent = 'NULL';
    if (!empty($x['parent'])) { $p = $byKey[$x['c'] . '|' . $x['parent']] ?? 0; if ($p) { $parent = $p; } }
    $due = isset($x['due']) ? "'" . dt(strtotime(date('Y-m-d', $NOW + $x['due'] * 86400) . ' 17:00:00')) . "'" : 'NULL';
    $onsiteN = isset($x['onsite']) ? 1 : 0;
    $schedSql = isset($x['onsite']) ? "'" . dt($NOW + $x['onsite'] * 3600) . "'" : 'NULL';
    $schedEndSql = isset($x['onsite']) ? "'" . dt($NOW + $x['onsite'] * 3600 + 7200) . "'" : 'NULL';
    $catalog = !empty($x['catalog']) ? $catalogId($x['catalog']) : 0;
    $catalogSql = $catalog ? $catalog : 'NULL';
    $isMaint = ($x['cat'] === 'Maintenance');

    // number from the live counter
    q("UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number), config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1");
    $number = intval(mysqli_insert_id($mysqli));
    $prefix = e(one('SELECT config_ticket_prefix FROM settings WHERE company_id = 1'));

    // timestamps from the replies
    $rep = $x['rep'] ?? [];
    $first_resp = null; $last_t = null;
    foreach ($rep as $r) {
        $rt = $created_t + $r[1] * 60;
        if ($r[0] === 'P' && $first_resp === null) { $first_resp = $rt; }
        if ($last_t === null || $rt > $last_t) { $last_t = $rt; }
    }
    if ($closed_t && ($last_t === null || $closed_t > $last_t)) { $last_t = $closed_t; }
    $updatedSql = $last_t ? "'" . dt($last_t) . "'" : 'NULL';
    $firstSql = $first_resp ? "'" . dt($first_resp) . "'" : 'NULL';
    if ($closed && !$first_resp && !empty($rep)) { $firstSql = "'" . dt($created_t + $rep[0][1] * 60) . "'"; }
    $closedSql = $closed ? "'" . dt($closed_t) . "'" : 'NULL';
    $closedBy = $closed ? ($assignee ?: $tech['M']) : 0;

    q("INSERT INTO tickets SET ticket_prefix = '$prefix', ticket_number = $number, ticket_source = '" . e($src) . "', ticket_category = $cat,
        ticket_subject = '" . e($x['s']) . "', ticket_details = '" . e('<p>' . $x['d'] . '</p>') . "', ticket_priority = '" . e($x['p']) . "', ticket_status = $st,
        ticket_billable = $bill, ticket_onsite = $onsiteN, ticket_schedule = $schedSql, ticket_schedule_end = $schedEndSql, ticket_url_key = '$key',
        ticket_created_at = '$created_at', ticket_updated_at = $updatedSql, ticket_due_at = $due,
        ticket_first_response_at = $firstSql, ticket_resolved_at = $closedSql, ticket_closed_at = $closedSql, ticket_closed_by = $closedBy,
        ticket_created_by = $creator, ticket_assigned_to = $assignee, ticket_client_id = $cid, ticket_contact_id = $contact,
        ticket_parent_id = $parent, ticket_delivery_method = $delivSql, ticket_catalog_item_id = $catalogSql, ticket_rmm_sourced = 0");
    $id = intval(mysqli_insert_id($mysqli));
    $byKey[$x['c'] . '|' . $x['s']] = $id;
    $created++;

    // initial issue (what the ticket page shows as the first entry)
    $initType = $creator ? 'Internal' : 'Client';
    $initBy = $creator ?: $contact;
    q("INSERT INTO ticket_replies SET ticket_reply = '" . e('<strong>Initial Issue</strong><br><p>' . $x['d'] . '</p>') . "', ticket_reply_type = '$initType', ticket_reply_by = $initBy,
        ticket_reply_ticket_id = $id, ticket_reply_created_at = '$created_at'");
    q("UPDATE tickets SET ticket_initial_issue_reply_id = " . intval(mysqli_insert_id($mysqli)) . ", ticket_updated_at = " . $updatedSql . " WHERE ticket_id = $id");

    // held for approval (catalog item that needs it)
    if (!empty($x['hold'])) {
        q("INSERT INTO ticket_replies SET ticket_reply = '" . e($x['hold']) . "', ticket_reply_type = 'System', ticket_reply_by = 0, ticket_reply_ticket_id = $id, ticket_reply_created_at = '" . dt($created_t + 60) . "'");
    }
    // the conversation
    foreach ($rep as $r) {
        [$kind, $min, $text] = $r;
        $worked = $r[3] ?? null;
        $who = $r[4] ?? '';
        $rt = dt($created_t + $min * 60);
        $by = $assignee ?: $tech['M'];
        if ($who && isset($tech[$who])) { $by = $tech[$who]; }
        $type = ['I' => 'Internal', 'P' => 'Public', 'N' => 'Internal', 'C' => 'Client', 'L' => 'Labor'][$kind];
        $emailed = ($kind === 'P') ? 1 : 0;
        if ($kind === 'C') { $by = $contact; }
        if ($who === 'X') { $by = 0; }
        $workedSql = $worked ? "'" . $worked . ":00'" : 'NULL';
        q("INSERT INTO ticket_replies SET ticket_reply = '" . e('<p>' . $text . '</p>') . "', ticket_reply_type = '$type', ticket_reply_by = $by, ticket_reply_ticket_id = $id,
            ticket_reply_created_at = '$rt', ticket_reply_time_worked = $workedSql, ticket_reply_emailed = $emailed, ticket_reply_onsite = " . ($deliv === 'Onsite' && $kind !== 'C' ? 1 : 0));
    }
    if ($closed) {
        q("INSERT INTO ticket_replies SET ticket_reply = 'Ticket closed.', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $closedBy,
            ticket_reply_ticket_id = $id, ticket_reply_created_at = '" . dt($closed_t) . "'");
    }
    // history rows for the status the ticket now has
    q("INSERT INTO ticket_history SET ticket_history_status = 'New', ticket_history_description = 'Ticket created', ticket_history_ticket_id = $id, ticket_history_created_at = '$created_at'");
    // tags
    foreach (($x['tags'] ?? []) as $tn) { $tg = $tagId($tn); if ($tg) { q("INSERT IGNORE INTO ticket_tags SET ticket_tag_ticket_id = $id, ticket_tag_tag_id = $tg"); } }
    // extra technicians
    foreach (($x['techs'] ?? []) as $tc) { q("INSERT INTO ticket_techs SET tech_ticket_id = $id, tech_user_id = " . $tech[$tc] . ", tech_created_by = $assignee"); }
    // tasks
    foreach (($x['tasks'] ?? []) as $i => $tk) {
        $doneSql = $tk[1] ? "'" . dt($created_t + 3600 * ($i + 2)) . "'" : 'NULL';
        $doneBy = $tk[1] ? ($assignee ?: $tech['M']) : 'NULL';
        q("INSERT INTO tasks SET task_name = '" . e($tk[0]) . "', task_order = " . ($i + 1) . ", task_ticket_id = $id, task_completed_at = $doneSql, task_completed_by = $doneBy, task_progress = " . ($tk[1] ? 100 : 0) . ", task_created_at = '$created_at'");
    }
    // watchers
    foreach (($x['watchers'] ?? []) as $w) { q("INSERT INTO ticket_watchers SET watcher_name = '" . e($w[0]) . "', watcher_email = '" . e($w[1]) . "', watcher_ticket_id = $id"); }
    // CSAT
    if (!empty($x['csat'])) {
        [$rating, $comment, $pub] = $x['csat'];
        $ratedAt = dt(($closed_t ?: $last_t ?: $created_t) + 4 * 3600);
        $cSql = $comment !== '' ? "'" . e($comment) . "'" : 'NULL';
        q("UPDATE tickets SET ticket_csat_rating = $rating, ticket_csat_comment = $cSql, ticket_csat_rated_at = '$ratedAt', ticket_csat_public_approved = " . intval($pub) . ", ticket_updated_at = ticket_updated_at WHERE ticket_id = $id");
    }
    if ($isMaint) { q("UPDATE tickets SET ticket_counts_sla = 0, ticket_updated_at = ticket_updated_at WHERE ticket_id = $id"); }
    $sla_ids[$id] = !empty($x['late']);
}

// merged duplicate: point at the surviving ticket and leave the two system notes the merge screen writes
foreach ($T as $x) {
    if (empty($x['merged'])) { continue; }
    $dupe = $byKey[$x['c'] . '|' . $x['s']] ?? 0; $keep = $byKey[$x['c'] . '|' . $x['merged']] ?? 0;
    if (!$dupe || !$keep || intval(one("SELECT ticket_merged_into_id FROM tickets WHERE ticket_id = $dupe")) === $keep) { continue; }
    $kn = one("SELECT CONCAT(ticket_prefix, ticket_number) FROM tickets WHERE ticket_id = $keep");
    $dn = one("SELECT CONCAT(ticket_prefix, ticket_number) FROM tickets WHERE ticket_id = $dupe");
    $at = one("SELECT ticket_closed_at FROM tickets WHERE ticket_id = $dupe");
    q("UPDATE tickets SET ticket_merged_into_id = $keep, ticket_updated_at = ticket_updated_at WHERE ticket_id = $dupe");
    q("INSERT INTO ticket_replies SET ticket_reply = '" . e("Ticket $dn merged into $kn. Comment: same problem reported twice.") . "', ticket_reply_type = 'System', ticket_reply_by = {$tech['S']}, ticket_reply_ticket_id = $dupe, ticket_reply_created_at = '$at'");
    q("INSERT INTO ticket_replies SET ticket_reply = '" . e("Ticket $dn was merged into this ticket with comment: same problem reported twice.") . "', ticket_reply_type = 'System', ticket_reply_by = {$tech['S']}, ticket_reply_ticket_id = $keep, ticket_reply_created_at = '$at', ticket_reply_time_worked = '00:01:00'");
}

// SLA due dates from the default policy (business hours), then keep the counter ahead of the highest number
foreach ($sla_ids as $sid => $late) {
    $keepUpd = one("SELECT ticket_updated_at FROM tickets WHERE ticket_id = $sid");
    recalculateTicketSla($mysqli, $sid);
    // Older closed tickets were answered and closed inside their targets unless flagged 'late': move a due date out
    // to just after the answer / the close so the SLA meter reads Met (the stored due dates come from business hours).
    $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_sla_response_due, ticket_sla_resolution_due, ticket_first_response_at, ticket_resolved_at FROM tickets WHERE ticket_id = $sid"));
    if (!$late && $r) {
        if ($r['ticket_sla_response_due'] && $r['ticket_first_response_at'] && $r['ticket_first_response_at'] > $r['ticket_sla_response_due']) {
            q("UPDATE tickets SET ticket_sla_response_due = '" . dt(strtotime($r['ticket_first_response_at']) + 600) . "' WHERE ticket_id = $sid");
        }
        if ($r['ticket_sla_resolution_due'] && $r['ticket_resolved_at'] && $r['ticket_resolved_at'] > $r['ticket_sla_resolution_due']) {
            q("UPDATE tickets SET ticket_sla_resolution_due = '" . dt(strtotime($r['ticket_resolved_at']) + 1800) . "' WHERE ticket_id = $sid");
        }
    }
    q("UPDATE tickets SET ticket_updated_at = " . ($keepUpd ? "'" . $keepUpd . "'" : 'NULL') . " WHERE ticket_id = $sid");
}
q("UPDATE settings SET config_ticket_next_number = GREATEST(config_ticket_next_number, (SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets)) WHERE company_id = 1");

echo "31-tickets: $created tickets created, " . count($T) . " defined.\n";
