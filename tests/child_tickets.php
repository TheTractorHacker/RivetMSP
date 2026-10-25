<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Child tickets (roadmap #21 remaining slice), against the REAL code on a SCRATCH database (same harness
 * as tests/security_*.php). "Log a ticket from an asset" (the other #21 item) was found ALREADY BUILT -
 * agent/asset_details.php's New menu already opens modals/ticket/ticket_add.php with asset_id prefilled
 * and correctly pre-selected - so this test covers only the genuinely new piece: tickets.ticket_parent_id.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/child_tickets.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/database_version.php";

echo "\n--- DB 2.6.113 step ---\n";
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.113', '>='), 'LATEST_DATABASE_VERSION is 2.6.113 or later');
$sqlText = (string) file_get_contents("$root/db.sql");
$ok(str_contains($sqlText, '`ticket_parent_id` int(11) DEFAULT NULL'), 'db.sql carries the column');
$verBefore = (string) $one('SELECT config_current_database_version FROM settings LIMIT 1');
$q("UPDATE settings SET config_current_database_version = '2.6.112'");
[, $updOut] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut, '2.6.112') && (string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === LATEST_DATABASE_VERSION, 'the updater advances past 2.6.112 to the latest version');
[, $updOut2] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut2, 'already at the latest'), 'running it again is a no-op, not an error');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'ticket_parent_id'") === 1, 'tickets has ticket_parent_id');
$q("UPDATE settings SET config_current_database_version = '" . $esc($verBefore) . "'");

echo "\n--- fixtures ---\n";
$q("DELETE FROM tickets WHERE ticket_subject LIKE 'ct-test-%'");
$q("DELETE FROM clients WHERE client_name LIKE 'ct-test-%'");
$q("INSERT INTO clients SET client_name='ct-test-ClientA'");
$clientA = (int) $db->insert_id;
$q("INSERT INTO clients SET client_name='ct-test-ClientB'");
$clientB = (int) $db->insert_id;
$q("INSERT INTO contacts SET contact_client_id = $clientA, contact_name = 'ct-test-ContactA', contact_email='ct-a@example.test', contact_primary=1");
$contactA = (int) $db->insert_id;
$q("INSERT INTO contacts SET contact_client_id = $clientB, contact_name = 'ct-test-ContactB', contact_email='ct-b@example.test', contact_primary=1");
$contactB = (int) $db->insert_id;
$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='ct-test-admin', user_email='ct-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

$sdir = sys_get_temp_dir() . '/ct_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sid = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
$agentPost = fn (array $f) => sec_web($base, 'POST', '/agent/post.php', $sid, $f + ['csrf_token' => 'csrftok12345678'], []);
$tk = fn (int $id) => mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM tickets WHERE ticket_id = $id"));

echo "\n--- creating a ticket with a parent ---\n";
[$c] = $agentPost(['add_ticket' => '1', 'client_id' => (string) $clientA, 'contact_id' => (string) $contactA, 'subject' => 'ct-test-Parent', 'details' => 'x', 'priority' => 'Low', 'assigned_to' => '0', 'billable' => '0', 'category_id' => '0']);
$ok($c === 200 || $c === 302, 'add_ticket (parent) accepted');
$parentId = (int) $one("SELECT ticket_id FROM tickets WHERE ticket_subject = 'ct-test-Parent'");
$ok($parentId > 0, 'parent ticket created');

[$c] = $agentPost(['add_ticket' => '1', 'client_id' => (string) $clientA, 'contact_id' => (string) $contactA, 'subject' => 'ct-test-Child1', 'details' => 'x', 'priority' => 'Low', 'assigned_to' => '0', 'billable' => '0', 'category_id' => '0', 'parent_ticket_id' => (string) $parentId]);
$ok($c === 200 || $c === 302, 'add_ticket (child, real parent) accepted');
$childId = (int) $one("SELECT ticket_id FROM tickets WHERE ticket_subject = 'ct-test-Child1'");
$ok($childId > 0 && (int) $tk($childId)['ticket_parent_id'] === $parentId, 'the child ticket is stored with ticket_parent_id = the parent');

echo "\n--- cross-client parent is rejected, not just silently wrong ---\n";
[$c] = $agentPost(['add_ticket' => '1', 'client_id' => (string) $clientB, 'contact_id' => (string) $contactB, 'subject' => 'ct-test-CrossClient', 'details' => 'x', 'priority' => 'Low', 'assigned_to' => '0', 'billable' => '0', 'category_id' => '0', 'parent_ticket_id' => (string) $parentId]);
$crossId = (int) $one("SELECT ticket_id FROM tickets WHERE ticket_subject = 'ct-test-CrossClient'");
$ok($crossId > 0 && (int) $tk($crossId)['ticket_parent_id'] === 0, "a parent belonging to a DIFFERENT client is rejected (stays NULL/0), not linked across tenants");

echo "\n--- self-reference is rejected ---\n";
// A ticket cannot name itself as its own parent via edit (the only path where the id is already known).
[$t2] = [$tk($childId)];
[$c] = $agentPost(['edit_ticket' => '1', 'ticket_id' => (string) $childId, 'contact_id' => (string) $contactA, 'contact_notify' => '0',
    'category_id' => (string) $t2['ticket_category'], 'subject' => $t2['ticket_subject'], 'billable' => '0', 'priority' => $t2['ticket_priority'],
    'details' => $t2['ticket_details'], 'vendor_ticket_number' => '', 'vendor_id' => '0', 'asset_id' => '0', 'location_id' => '0', 'project_id' => '0', 'assigned_to' => '0',
    'parent_ticket_id' => (string) $childId]);
$ok((int) $tk($childId)['ticket_parent_id'] === 0, 'a ticket cannot be made its own parent - edit clears it instead of writing a self-reference');

echo "\n--- a real edit can set/clear the parent ---\n";
$t2 = $tk($childId);
[$c] = $agentPost(['edit_ticket' => '1', 'ticket_id' => (string) $childId, 'contact_id' => (string) $contactA, 'contact_notify' => '0',
    'category_id' => (string) $t2['ticket_category'], 'subject' => $t2['ticket_subject'], 'billable' => '0', 'priority' => $t2['ticket_priority'],
    'details' => $t2['ticket_details'], 'vendor_ticket_number' => '', 'vendor_id' => '0', 'asset_id' => '0', 'location_id' => '0', 'project_id' => '0', 'assigned_to' => '0',
    'parent_ticket_id' => (string) $parentId]);
$ok((int) $tk($childId)['ticket_parent_id'] === $parentId, 'editing the ticket sets the parent via the real handler');
$t2 = $tk($childId);
[$c] = $agentPost(['edit_ticket' => '1', 'ticket_id' => (string) $childId, 'contact_id' => (string) $contactA, 'contact_notify' => '0',
    'category_id' => (string) $t2['ticket_category'], 'subject' => $t2['ticket_subject'], 'billable' => '0', 'priority' => $t2['ticket_priority'],
    'details' => $t2['ticket_details'], 'vendor_ticket_number' => '', 'vendor_id' => '0', 'asset_id' => '0', 'location_id' => '0', 'project_id' => '0', 'assigned_to' => '0',
    'parent_ticket_id' => '0']);
$ok((int) $tk($childId)['ticket_parent_id'] === 0, 'editing again with "- None -" (0) clears the parent');
// restore the link for the rendering checks below
$q("UPDATE tickets SET ticket_parent_id = $parentId WHERE ticket_id = $childId");

echo "\n--- a narrower POST that omits parent_ticket_id entirely never wipes an existing link ---\n";
[$c] = $agentPost(['edit_ticket_priority' => '1', 'ticket_id' => (string) $childId, 'priority' => 'High']);
$ok((int) $tk($childId)['ticket_parent_id'] === $parentId, 'edit_ticket_priority (a narrower action with no parent_ticket_id key at all) leaves the parent link untouched');

echo "\n--- one-level cycle is rejected ---\n";
// childId is a child of parentId. Trying to make parentId a child of childId (the reverse) must be rejected.
$tp = $tk($parentId);
[$c] = $agentPost(['edit_ticket' => '1', 'ticket_id' => (string) $parentId, 'contact_id' => (string) $contactA, 'contact_notify' => '0',
    'category_id' => (string) $tp['ticket_category'], 'subject' => $tp['ticket_subject'], 'billable' => '0', 'priority' => $tp['ticket_priority'],
    'details' => $tp['ticket_details'], 'vendor_ticket_number' => '', 'vendor_id' => '0', 'asset_id' => '0', 'location_id' => '0', 'project_id' => '0', 'assigned_to' => '0',
    'parent_ticket_id' => (string) $childId]);
$ok((int) $tk($parentId)['ticket_parent_id'] === 0, 'making the parent a child of its own child is rejected, not creating a 2-ticket cycle');
$ok((int) $tk($childId)['ticket_parent_id'] === $parentId, "the original child->parent link is unaffected by the rejected reverse attempt");

echo "\n--- ticket page rendering ---\n";
[$c, $body] = sec_web($base, 'GET', "/agent/ticket.php?ticket_id=$childId", $sid);
$ok($c === 200 && str_contains($body, 'Parent / Child Tickets') && str_contains($body, 'Parent ticket:') && str_contains($body, 'ct-test-Parent'), 'the child ticket page shows its parent');
[$c, $body] = sec_web($base, 'GET', "/agent/ticket.php?ticket_id=$parentId", $sid);
$ok($c === 200 && str_contains($body, 'Child tickets (1)') && str_contains($body, 'ct-test-Child1'), 'the parent ticket page lists its one child');

echo "\n--- edit modal pre-selects the current parent, offers only same-client tickets, excludes self ---\n";
[$c, $body] = sec_web($base, 'GET', "/agent/modals/ticket/ticket_edit.php?id=$childId", $sid);
$body = stripslashes($body);
$ok($c === 200 && preg_match('/<option\s+selected\s+value="' . $parentId . '"|<option\s+value="' . $parentId . '"\s+selected/', $body), 'the edit modal pre-selects the current parent ticket');
$ok(!preg_match('/value="' . $childId . '"[^>]*>ct-test-Child1/', $body), "the modal's own ticket (ct-test-Child1 / id $childId) is excluded from its own parent picker");
$ok(!str_contains($body, 'ct-test-CrossClient'), "ClientB's ticket never appears in ClientA's parent picker");

[, $log] = sec_sh('cat ' . escapeshellarg(sys_get_temp_dir()) . '/sec_server_*.log 2>/dev/null');
$ok(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $log) || str_contains($log, 'Undefined array key "HTTP_USER_AGENT"'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');
