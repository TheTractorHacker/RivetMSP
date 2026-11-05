<?php
/*
 * Field work, part 1: ticket billing and worksheets (user guide page 06).
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/40-field-work-billing.php
 *
 * Idempotent: every row is looked up by name / subject first. Creates
 *   - labor types (Administration > Ticketing > Labor types) and the ticket categories the field-work pages use
 *   - technician skill tags (used by auto-dispatch, page 08) and who holds them
 *   - worksheet templates (Administration > Templates > Worksheet templates)
 *   - a set of billable tickets with logged time and charges: two closed ones that are "Ready to bill", one with an
 *     invoice, one with a quote, one open job with a worksheet and an unsigned outtake form, one closed job with a
 *     signed worksheet and a signed outtake form, one non-billable ticket
 * Ticket numbers come from settings.config_ticket_next_number like the app does, so it can run before or after the
 * service-desk seeds. Dates are relative to "now" in the company time zone (America/Chicago).
 */
if (php_sapi_name() !== 'cli') { exit("Run this from the command line.\n"); }
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) { fwrite(STDERR, "40-field-work-billing: no config.php in '$app'. Set RIVETIT_APP_DIR.\n"); exit(1); }
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

/** Small hand-drawn looking signature as a PNG data URI (GD). */
function signature_png($seed) {
    $w = 500; $h = 150;
    $im = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($im, 255, 255, 255);
    $ink = imagecolorallocate($im, 20, 30, 90);
    imagefill($im, 0, 0, $white);
    imagesetthickness($im, 3);
    mt_srand(crc32($seed));
    $x = 40; $y = 80;
    for ($i = 0; $i < 46; $i++) {
        $nx = $x + mt_rand(5, 11);
        $ny = 80 + (int) (28 * sin($i / 2.3 + mt_rand(0, 10) / 10)) + mt_rand(-6, 6);
        imageline($im, $x, $y, $nx, $ny, $ink);
        $x = $nx; $y = $ny;
    }
    imageline($im, 36, 118, $x + 10, 104, $ink);
    ob_start(); imagepng($im); $png = ob_get_clean();
    return 'data:image/png;base64,' . base64_encode($png);
}

// ---- 1. labor types -------------------------------------------------------------------------------
$labor = [
    ['Remote Support', 110.00, '#2f80ed', 1], ['On-Site Support', 150.00, '#f2994a', 2], ['After-Hours', 195.00, '#eb5757', 3],
    ['Project Labor', 135.00, '#27ae60', 4], ['Travel', 75.00, '#9b51e0', 5],
];
$lt = [];
foreach ($labor as [$n, $rate, $color, $ord]) {
    $id = one("SELECT labor_type_id FROM labor_types WHERE labor_type_name = '" . e($n) . "' LIMIT 1");
    if (!$id) { q("INSERT INTO labor_types SET labor_type_name = '" . e($n) . "', labor_type_rate = $rate, labor_type_color = '$color', labor_type_order = $ord"); $id = mysqli_insert_id($mysqli); }
    $lt[$n] = (int) $id;
}

// ---- 2. ticket categories + technician skills ------------------------------------------------------
$cat_remote = ensure_category('Remote Support', '#2f80ed');
$cat_onsite = ensure_category('On-Site', '#f2994a');
$cat_proj   = ensure_category('Projects', '#27ae60');
$cat_maint  = ensure_category('Maintenance', '#828282');
$skill_hw   = ensure_tag('Hardware and printers', 7, '#f2994a');
$skill_net  = ensure_tag('Networking', 7, '#2f80ed');
$skill_m365 = ensure_tag('Microsoft 365', 7, '#9b51e0');
foreach (['Devon Park' => [$skill_hw, $skill_net], 'Sam Ortiz' => [$skill_m365, $skill_net], 'Morgan Reyes' => [$skill_net]] as $uname => $tags) {
    $u = uid($uname);
    foreach ($tags as $t) {
        if ($u && !one("SELECT 1 FROM user_tags WHERE user_tag_user_id = $u AND user_tag_tag_id = $t")) q("INSERT INTO user_tags SET user_tag_user_id = $u, user_tag_tag_id = $t");
    }
}
// On-Site work asks for the hardware skill (auto-dispatch matches on it); Remote Support asks for Microsoft 365
q("UPDATE categories SET category_required_skill_tag_id = $skill_hw WHERE category_id = $cat_onsite AND category_required_skill_tag_id IS NULL");
q("UPDATE categories SET category_required_skill_tag_id = $skill_m365 WHERE category_id = $cat_remote AND category_required_skill_tag_id IS NULL");

// ---- 3. worksheet templates ------------------------------------------------------------------------
$templates = [
    'Workstation setup checklist' => ['Imaging, joining and handing over a new PC', [
        ['Hardware', 'heading', '', 0], ['Serial number recorded', 'text', '', 1], ['BIOS and firmware updated', 'checkbox', '', 0],
        ['Software', 'heading', '', 0], ['Windows fully patched', 'checkbox', '', 0], ['Domain / Entra joined', 'checkbox', '', 0],
        ['Standard apps installed', 'checkbox', '', 0], ['Endpoint protection reporting', 'checkbox', '', 0],
        ['Hand-over', 'heading', '', 0], ['User profile and printers tested', 'select', "Yes\nNo\nNot applicable", 0], ['Notes for the next technician', 'textarea', '', 0],
    ]],
    'On-site visit sign-off' => ['Customer confirms the work was done (required worksheet)', [
        ['Visit summary', 'heading', '', 0], ['Work completed', 'textarea', '', 1], ['Parts left on site', 'text', '', 0],
        ['Customer check', 'heading', '', 0], ['Everything works as expected', 'checkbox', '', 0], ['Customer signature', 'signature', '', 1],
    ]],
    'Network closet inspection' => ['Annual walk-through of a wiring closet or server room', [
        ['Environment', 'heading', '', 0], ['Temperature acceptable', 'checkbox', '', 0], ['UPS tested', 'checkbox', '', 0], ['Cabling labelled and tidy', 'checkbox', '', 0],
        ['Findings', 'heading', '', 0], ['Overall condition', 'select', "Good\nFair\nNeeds attention", 0], ['Recommendations', 'textarea', '', 0],
    ]],
];
$tmpl = []; $tfield = [];
foreach ($templates as $name => [$desc, $fields]) {
    $id = one("SELECT worksheet_template_id FROM worksheet_templates WHERE worksheet_template_name = '" . e($name) . "' AND worksheet_template_archived_at IS NULL LIMIT 1");
    if (!$id) {
        q("INSERT INTO worksheet_templates SET worksheet_template_name = '" . e($name) . "', worksheet_template_description = '" . e($desc) . "', worksheet_template_created_by = " . uid('Morgan Reyes'));
        $id = mysqli_insert_id($mysqli);
        $order = 1;
        foreach ($fields as [$fname, $ftype, $fopts, $freq]) {
            q("INSERT INTO worksheet_template_fields SET field_template_id = $id, field_name = '" . e($fname) . "', field_type = '$ftype', field_options = " . ($fopts === '' ? 'NULL' : "'" . e($fopts) . "'") . ", field_order = " . $order++ . ", field_required = $freq");
        }
    }
    $tmpl[$name] = (int) $id;
    $r = mysqli_query($mysqli, "SELECT field_id, field_name FROM worksheet_template_fields WHERE field_template_id = $id");
    while ($f = mysqli_fetch_assoc($r)) { $tfield[$name][$f['field_name']] = (int) $f['field_id']; }
}

// ---- 4. tickets ------------------------------------------------------------------------------------
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

$H = 3600;

// 4a. Closed, billable, not invoiced yet: "Ready to bill"
[$t_ups, $new] = mk_ticket([
    'client' => 'Northshore Dental Group', 'contact' => 'Hannah Brooks', 'tech' => 'Devon Park', 'category' => $cat_onsite, 'billable' => 1, 'priority' => 'High',
    'subject' => 'Mount and connect two new operatory monitors', 'details' => 'We bought two 24 inch monitors for operatories 3 and 4. Please mount them on the wall arms and connect them to the chairside PCs.',
    'created' => at(9, 8, 40), 'closed' => at(6, 15, 20),
    'replies' => [
        ['type' => 'Internal', 'text' => 'Checked the chairside PC models: both have a free DisplayPort output. Ordered two wall arms and short cables so everything is on hand for the visit.', 'mins' => 45, 'at' => at(8, 10, 5), 'labor' => 'Remote Support'],
        ['type' => 'Public', 'text' => 'Hi Hannah, the arms and cables arrived. I can be on site Thursday morning; the work takes about two hours and the operatories need to be empty.', 'mins' => 0, 'at' => at(8, 10, 30)],
        ['type' => 'Internal', 'text' => 'On site. Mounted both monitors, routed and clipped the cables, set display scaling and tested the imaging viewer on each screen.', 'mins' => 135, 'onsite' => 1, 'at' => at(6, 14, 50), 'labor' => 'On-Site Support'],
        ['type' => 'Public', 'text' => 'Both operatory monitors are mounted and working. The imaging viewer opens on the new screens at full size.', 'mins' => 0, 'at' => at(6, 15, 10)],
    ],
    'charges' => [
        ['name' => 'On-Site Support', 'labor' => 'On-Site Support', 'qty' => 2.5, 'price' => 150.00, 'desc' => 'Mount and connect two operatory monitors'],
        ['name' => 'Travel', 'labor' => 'Travel', 'qty' => 0.5, 'price' => 75.00],
        ['name' => 'Wall-mount monitor arm', 'qty' => 2, 'price' => 49.00, 'desc' => 'Single-arm VESA mount'],
    ],
]);
// 4b. Closed, billable, remote
[$t_outlook, ] = mk_ticket([
    'client' => 'Birchwood Law Partners', 'contact' => 'Theo Lindqvist', 'tech' => 'Sam Ortiz', 'category' => $cat_remote, 'billable' => 1, 'priority' => 'Medium',
    'subject' => 'Set up scan-to-folder for the new copier', 'details' => 'The new copier was installed yesterday. We would like scans to land in the shared Scans folder on the file server.',
    'created' => at(7, 9, 15), 'closed' => at(4, 11, 40),
    'replies' => [
        ['type' => 'Internal', 'text' => 'Created a service account with write access to the Scans folder, set the SMB path on the copier and ran a test scan from the panel. Documented the settings.', 'mins' => 70, 'at' => at(4, 11, 20), 'labor' => 'Remote Support'],
        ['type' => 'Public', 'text' => 'Scan-to-folder is working on the new copier. Scans land in the Scans folder on the file server within a few seconds.', 'mins' => 0, 'at' => at(4, 11, 35)],
    ],
    'charges' => [['name' => 'Remote Support', 'labor' => 'Remote Support', 'qty' => 1.5, 'price' => 110.00, 'desc' => 'Copier scan-to-folder setup']],
]);
// 4c. Closed and invoiced (the invoice is added below)
[$t_teller, $new_teller] = mk_ticket([
    'client' => 'Lakeview Family Credit Union', 'contact' => 'Gordon Pike', 'tech' => 'Devon Park', 'category' => $cat_onsite, 'billable' => 1, 'priority' => 'High',
    'subject' => 'Teller workstation will not boot after update', 'details' => 'Teller station 3 at the Main Branch stopped at a blue recovery screen after last night\'s update. The branch cannot serve walk-ins at that window.',
    'created' => at(12, 8, 5), 'closed' => at(11, 13, 10),
    'replies' => [
        ['type' => 'Internal', 'text' => 'Boot repair failed. Pulled the drive image, replaced the system SSD and restored the teller image from last week. Peripherals and core banking client tested.', 'mins' => 150, 'onsite' => 1, 'at' => at(11, 12, 40), 'labor' => 'On-Site Support'],
        ['type' => 'Public', 'text' => 'Teller station 3 is back in service on a new SSD with last week\'s image. Nothing was lost; all files are on the network share.', 'mins' => 0, 'at' => at(11, 13, 5)],
    ],
    'charges' => [
        ['name' => 'On-Site Support', 'labor' => 'On-Site Support', 'qty' => 2.5, 'price' => 150.00],
        ['name' => '500 GB SSD', 'qty' => 1, 'price' => 79.00],
    ],
]);
// 4d. Open, billable, a quote is attached
[$t_quote, ] = mk_ticket([
    'client' => 'Maple Street Veterinary Clinic', 'contact' => 'Beth Nakamura', 'tech' => 'Sam Ortiz', 'category' => $cat_proj, 'billable' => 1, 'priority' => 'Low',
    'subject' => 'Price a workstation for the third exam room', 'details' => 'We are adding a third exam room next month and need a PC, monitor and label printer set up like the other two. Can you send a price?',
    'created' => at(5, 10, 30), 'status' => 2,
    'replies' => [['type' => 'Internal', 'text' => 'Matched the spec of exam room 2: small form factor PC, 24 inch monitor, label printer, mounting arm. Quote drafted from this ticket.', 'mins' => 25, 'at' => at(5, 11, 10), 'labor' => 'Remote Support']],
]);
// 4e. Open, billable, onsite job with a worksheet and an unsigned outtake form
[$t_printer, ] = mk_ticket([
    'client' => 'Granite Peak Manufacturing', 'contact' => 'Marcus Webb', 'tech' => 'Sam Ortiz', 'category' => $cat_onsite, 'billable' => 1, 'priority' => 'Medium',
    'subject' => 'Set up label printer at the shipping dock', 'details' => 'The new thermal label printer for the shipping dock arrived. Please connect it to the dock PC and test it with the shipping software.',
    'created' => at(2, 8, 20), 'status' => 2, 'updated' => at(0, 9, 5),
    'replies' => [
        ['type' => 'Internal', 'text' => 'Drivers installed, printer given a reserved address on the plant network. First test label printed. Waiting on the customer to confirm the label size.', 'mins' => 50, 'onsite' => 1, 'at' => at(1, 14, 0), 'labor' => 'On-Site Support'],
    ],
    'charges' => [
        ['name' => 'On-Site Support', 'labor' => 'On-Site Support', 'qty' => 1.0, 'price' => 150.00],
        ['name' => 'Thermal transfer ribbon, 4 pack', 'qty' => 1, 'price' => 46.00],
    ],
]);
// 4f. Closed, with a finalized and signed worksheet and a signed outtake form
[$t_display, $new_display] = mk_ticket([
    'client' => 'Birchwood Law Partners', 'contact' => 'Margaret Birchwood', 'tech' => 'Devon Park', 'category' => $cat_onsite, 'billable' => 1, 'priority' => 'Medium',
    'subject' => 'Swap the boardroom projector for a wall display', 'details' => 'Please replace the boardroom projector with the new wall display and hand the loan laptop back to Margaret once it has been checked.',
    'created' => at(15, 9, 0), 'closed' => at(13, 16, 30),
    'replies' => [
        ['type' => 'Internal', 'text' => 'New 65 inch display mounted and calibrated. Wireless casting tested from three laptops; projector removed.', 'mins' => 95, 'onsite' => 1, 'at' => at(13, 16, 0), 'labor' => 'On-Site Support'],
        ['type' => 'Public', 'text' => 'The boardroom wall display is in and tested. The old projector has been taken away for recycling.', 'mins' => 0, 'at' => at(13, 16, 20)],
    ],
    'charges' => [['name' => 'On-Site Support', 'labor' => 'On-Site Support', 'qty' => 1.5, 'price' => 150.00]],
]);
// 4g. Not billable (covered by the managed plan)
[$t_nonbill, ] = mk_ticket([
    'client' => 'Bluebird Early Learning', 'contact' => 'Sunita Patel', 'tech' => 'Sam Ortiz', 'category' => $cat_maint, 'billable' => 0, 'overridden' => 1, 'priority' => 'Low',
    'subject' => 'Classroom printer shows offline', 'details' => 'The printer in the Sunflower classroom shows offline on every computer again.',
    'created' => at(3, 13, 10), 'closed' => at(3, 14, 0),
    'replies' => [['type' => 'Internal', 'text' => 'Printer had picked up a new address after a router restart. Reserved the address so it stays put. Covered by the managed services plan, so marked not billable.', 'mins' => 20, 'at' => at(3, 13, 50)]],
]);

// ---- 5. worksheets and outtake forms ----------------------------------------------------------------
function add_worksheet($ticket_id, $tname, $outtake, $answers, $finalize, $signed_name = '', $creator = 'Sam Ortiz') {
    global $mysqli, $tmpl, $tfield;
    $existing = one("SELECT worksheet_id FROM ticket_worksheets WHERE worksheet_ticket_id = $ticket_id AND worksheet_template_id = " . (int) $tmpl[$tname]);
    if ($existing) return (int) $existing;
    $token = $outtake ? "'" . bin2hex(random_bytes(32)) . "'" : 'NULL';
    q("INSERT INTO ticket_worksheets SET worksheet_ticket_id = $ticket_id, worksheet_template_id = " . (int) $tmpl[$tname] . ", worksheet_created_by = " . uid($creator) . ",
        worksheet_is_outtake = " . (int) $outtake . ", worksheet_sign_token = $token, worksheet_created_at = '" . dt(time() - 86400) . "'");
    $id = (int) mysqli_insert_id($mysqli);
    foreach ($answers as $field => $val) {
        $fid = $tfield[$tname][$field] ?? 0;
        if ($fid) q("INSERT INTO ticket_worksheet_responses SET response_worksheet_id = $id, response_field_id = $fid, response_value = '" . e($val) . "'");
    }
    if ($finalize) q("UPDATE ticket_worksheets SET worksheet_completed_at = '" . dt(time() - 80000) . "' WHERE worksheet_id = $id");
    if ($signed_name !== '') {
        q("UPDATE ticket_worksheets SET worksheet_signed_name = '" . e($signed_name) . "', worksheet_signed_at = '" . dt(time() - 79000) . "', worksheet_signature = '" . e(signature_png($signed_name)) . "' WHERE worksheet_id = $id");
    }
    return $id;
}
// open printer job: a workstation checklist, half filled in (not finalized)
add_worksheet($t_printer, 'Workstation setup checklist', 0, [
    'Serial number recorded' => 'SN-48213-DK', 'BIOS and firmware updated' => '1', 'Windows fully patched' => '1', 'Domain / Entra joined' => '1',
], false);
// Quote ticket: nothing. Display ticket: signed required worksheet
$ws_signed = add_worksheet($t_display, 'On-site visit sign-off', 1, [
    'Work completed' => 'Replaced the ceiling projector with a 65 inch wall display, re-ran the wall cable and tested casting from three laptops.',
    'Parts left on site' => 'Spare HDMI cable', 'Everything works as expected' => '1', 'Customer signature' => signature_png('Margaret Birchwood'),
], true, 'Margaret Birchwood', 'Devon Park');

// outtake forms: one unsigned on the open printer job, one signed (PDF made by the app's own signing code) on the display job
if (!one("SELECT 1 FROM ticket_outtake_forms WHERE outtake_ticket_id = $t_printer")) {
    q("INSERT INTO ticket_outtake_forms SET outtake_ticket_id = $t_printer, outtake_created_by = " . uid('Sam Ortiz') . ", outtake_sign_token = '" . bin2hex(random_bytes(32)) . "',
        outtake_tech_notes = 'Installed the thermal label printer at the shipping dock, reserved its network address and printed a test label. Left one spare ribbon pack with the shipping supervisor.',
        outtake_created_at = '" . dt(time() - 3600) . "'");
}
if (!one("SELECT 1 FROM ticket_outtake_forms WHERE outtake_ticket_id = $t_display")) {
    q("INSERT INTO ticket_outtake_forms SET outtake_ticket_id = $t_display, outtake_created_by = " . uid('Devon Park') . ", outtake_sign_token = '" . bin2hex(random_bytes(32)) . "',
        outtake_tech_notes = 'Replaced the boardroom projector with a wall display and checked the loan laptop. Old projector removed for recycling.',
        outtake_created_at = '" . dt(at(13, 15, 0)) . "'");
    $oid = (int) mysqli_insert_id($mysqli);
    $_SERVER['DOCUMENT_ROOT'] = $app;
    try {
        require_once $app . '/includes/outtake_functions.php';
        $res = signOuttakeForm($mysqli, $oid, 'Margaret Birchwood', signature_png('Margaret Birchwood outtake'));
        if (empty($res['ok'])) { fwrite(STDERR, "40-field-work-billing: signOuttakeForm failed: " . ($res['error'] ?? '?') . "\n"); }
    } catch (Throwable $ex) {
        fwrite(STDERR, "40-field-work-billing: signing helper unavailable (" . $ex->getMessage() . "), marking signed directly\n");
        q("UPDATE ticket_outtake_forms SET outtake_signed_name = 'Margaret Birchwood', outtake_signed_at = '" . dt(at(13, 16, 10)) . "', outtake_signature = '" . e(signature_png('Margaret Birchwood outtake')) . "' WHERE outtake_id = $oid");
    }
}

// ---- 6. an invoice and a quote created from tickets ---------------------------------------------------
$inc_cat = (int) one("SELECT category_id FROM categories WHERE category_type = 'Income' AND category_archived_at IS NULL ORDER BY category_id LIMIT 1");
if ($inc_cat && !one("SELECT ticket_invoice_id FROM tickets WHERE ticket_id = $t_teller AND ticket_invoice_id > 0")) {
    $client = cid('Lakeview Family Credit Union');
    q("UPDATE settings SET config_invoice_next_number = LAST_INSERT_ID(config_invoice_next_number), config_invoice_next_number = config_invoice_next_number + 1 WHERE company_id = 1");
    $inv_no = (int) mysqli_insert_id($mysqli);
    $prefix = one("SELECT config_invoice_prefix FROM settings WHERE company_id = 1");
    $date = date('Y-m-d', at(10, 9));
    $terms = (int) one("SELECT client_net_terms FROM clients WHERE client_id = $client") ?: 30;
    $currency = one("SELECT company_currency FROM companies WHERE company_id = 1") ?: 'USD';
    $qty = 2.5; $price = 165.00; $total = $qty * $price;
    q("INSERT INTO invoices SET invoice_prefix = '" . e($prefix) . "', invoice_number = $inv_no, invoice_scope = 'Ticket teller workstation will not boot after update', invoice_date = '$date',
        invoice_due = DATE_ADD('$date', INTERVAL $terms DAY), invoice_currency_code = '" . e($currency) . "', invoice_category_id = $inc_cat, invoice_status = 'Draft',
        invoice_amount = $total, invoice_url_key = '" . bin2hex(random_bytes(16)) . "', invoice_client_id = $client");
    $inv_id = (int) mysqli_insert_id($mysqli);
    q("INSERT INTO invoice_items SET item_name = 'Support [Hourly]', item_description = 'Ticket: " . e(one("SELECT CONCAT(ticket_prefix, ticket_number) FROM tickets WHERE ticket_id = $t_teller")) . " - Teller workstation will not boot after update\nWork Performed: Support (see ticket notes)\n\nTime Spent: 2:30',
        item_quantity = $qty, item_price = $price, item_subtotal = $total, item_tax = 0, item_total = $total, item_order = 1, item_tax_id = 0, item_invoice_id = $inv_id");
    q("INSERT INTO history SET history_status = 'Draft', history_description = 'Invoice created from Ticket', history_invoice_id = $inv_id");
    q("UPDATE tickets SET ticket_invoice_id = $inv_id WHERE ticket_id = $t_teller");
    q("INSERT INTO ticket_replies SET ticket_reply = 'Created invoice <a href=\"invoice.php?invoice_id=$inv_id\">" . e($prefix) . "$inv_no</a> for this ticket.', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00',
        ticket_reply_by = " . uid('Jules Whitaker') . ", ticket_reply_ticket_id = $t_teller, ticket_reply_created_at = '" . dt(at(10, 9, 5)) . "'");
}
if ($inc_cat && !one("SELECT ticket_quote_id FROM tickets WHERE ticket_id = $t_quote AND ticket_quote_id > 0")) {
    $client = cid('Maple Street Veterinary Clinic');
    q("UPDATE settings SET config_quote_next_number = LAST_INSERT_ID(config_quote_next_number), config_quote_next_number = config_quote_next_number + 1 WHERE company_id = 1");
    $q_no = (int) mysqli_insert_id($mysqli);
    $prefix = one("SELECT config_quote_prefix FROM settings WHERE company_id = 1");
    $currency = one("SELECT company_currency FROM companies WHERE company_id = 1") ?: 'USD';
    $date = date('Y-m-d', at(4, 9)); $expire = date('Y-m-d', at(-26, 9));
    $price = 1485.00;
    q("INSERT INTO quotes SET quote_prefix = '" . e($prefix) . "', quote_number = $q_no, quote_scope = 'Exam room 3 workstation', quote_date = '$date', quote_expire = '$expire', quote_amount = $price,
        quote_currency_code = '" . e($currency) . "', quote_category_id = $inc_cat, quote_status = 'Draft', quote_url_key = '" . bin2hex(random_bytes(16)) . "', quote_client_id = $client");
    $qid = (int) mysqli_insert_id($mysqli);
    q("INSERT INTO quote_items SET item_name = 'Exam room workstation package', item_description = 'Small form factor PC, 24 inch monitor, label printer and mounting arm, installed and tested', item_quantity = 1,
        item_price = $price, item_subtotal = $price, item_tax = 0, item_total = $price, item_order = 1, item_tax_id = 0, item_quote_id = $qid");
    q("INSERT INTO history SET history_status = 'Draft', history_description = 'Quote created from Ticket!', history_quote_id = $qid");
    q("UPDATE tickets SET ticket_quote_id = $qid WHERE ticket_id = $t_quote");
    q("INSERT INTO ticket_replies SET ticket_reply = 'Created quote <a href=\"quote.php?quote_id=$qid\">" . e($prefix) . "$q_no</a> for this ticket.', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00',
        ticket_reply_by = " . uid('Sam Ortiz') . ", ticket_reply_ticket_id = $t_quote, ticket_reply_created_at = '" . dt(at(4, 11, 20)) . "'");
}

echo "40-field-work-billing: done (tickets $t_ups $t_outlook $t_teller $t_quote $t_printer $t_display $t_nonbill)\n";
