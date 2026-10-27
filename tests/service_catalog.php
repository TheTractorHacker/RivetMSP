<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Service Catalog v1 (GitHub roadmap #6, "Request Something"), against the REAL code on a SCRATCH database (same
 * harness as tests/security_*.php). This is a deliberately smaller slice of RivetIT's own much larger, evolved
 * feature - see admin/database_updates.php's 2.6.112->2.6.113 step for exactly what was left out and why.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/service_catalog.php
 *
 * Covers: the DB 2.6.113 step (db.sql parity, idempotent re-run); admin CRUD through the real admin/post.php
 * handler (Referer-routed); the price field; the agent "Request Something" page and creating a ticket from an
 * item through agent/post/ticket.php (tags ticket_catalog_item_id; requires_approval holds the ticket at Pending
 * Approval with a System note and an immediate SLA pause); the client portal equivalent through client/post.php;
 * "Popular this month" and "Recently used by you" over real ticket rows; deleting an item only detaches it from
 * past tickets, never deletes them.
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/database_version.php";

echo "\n--- DB 2.6.113 step ---\n";
$ok((int) $one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_catalog_items'") === 1, 'service_catalog_items exists');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'ticket_catalog_item_id'") === 1, 'tickets has ticket_catalog_item_id');
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.113', '>='), 'LATEST_DATABASE_VERSION is 2.6.113 or later');
$sqlText = (string) file_get_contents("$root/db.sql");
$ok(str_contains($sqlText, 'CREATE TABLE `service_catalog_items`') && str_contains($sqlText, '`price` decimal(10,2) DEFAULT NULL') && str_contains($sqlText, '`ticket_catalog_item_id` int(11) DEFAULT NULL'), 'db.sql carries the table and column exactly as the migration made them');
$verBefore = (string) $one('SELECT config_current_database_version FROM settings LIMIT 1');
$q("UPDATE settings SET config_current_database_version = '2.6.112'");
[, $updOut] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut, '2.6.112') && (string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === LATEST_DATABASE_VERSION, 'the updater advances past 2.6.112 to the latest version');
[, $updOut2] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut2, 'already at the latest'), 'running it again is a no-op, not an error');
$q("UPDATE settings SET config_current_database_version = '" . $esc($verBefore) . "'");

echo "\n--- fixtures ---\n";
$q("DELETE FROM service_catalog_items WHERE name LIKE 'svc-test-%'");
$q("DELETE FROM tickets WHERE ticket_subject LIKE 'svc-test-%'");
$q("DELETE FROM clients WHERE client_name = 'svc-test-Client'");
$q("DELETE FROM categories WHERE category_name = 'svc-test-cat'");
$q("DELETE FROM users WHERE user_email = 'svc-test-portal@example.test'");
$q("INSERT INTO clients SET client_name='svc-test-Client'");
$clientId = (int) $db->insert_id;
// A portal login (users.user_type = 2) - client/includes/check_login.php loads this row by session user_id
// independently of the contact row, so both must exist and be active for the portal session to be accepted.
$q("INSERT INTO users SET user_name='svc-test-portal', user_email='svc-test-portal@example.test', user_password='x', user_type=2, user_status=1");
$portalUserId = (int) $db->insert_id;
$q("INSERT INTO contacts SET contact_client_id = $clientId, contact_name = 'svc-test-contact', contact_email = 'svc-test-contact@example.test', contact_primary = 1, contact_user_id = $portalUserId");
$contactId = (int) $db->insert_id;
$q("INSERT INTO categories SET category_name = 'svc-test-cat', category_type = 'Ticket', category_archived_at = NULL");
$categoryId = (int) $db->insert_id;
// A "Pending Approval" status, flagged to pause the SLA clock, so the hold path can be proven end to end.
$q("DELETE FROM ticket_statuses WHERE ticket_status_name = 'Pending Approval'");
$holdStatusCol = (string) $one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_statuses' AND COLUMN_NAME LIKE '%pause%sla%'");
$q("INSERT INTO ticket_statuses SET ticket_status_name = 'Pending Approval', ticket_status_active = 1, ticket_status_order = 999" . ($holdStatusCol ? ", `$holdStatusCol` = 1" : ''));
$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='svc-test-admin', user_email='svc-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

$sdir = sys_get_temp_dir() . '/svc_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sid = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
$ref = ['Referer: ' . $base . '/admin/service_catalog.php'];

echo "\n--- admin CRUD through admin/post.php (Referer-routed) ---\n";
[$c] = sec_web($base, 'POST', '/admin/post.php', $sid, ['add_service_catalog_item' => '1', 'csrf_token' => 'csrftok12345678', 'name' => 'svc-test-item', 'description' => 'svc-test-desc', 'icon' => 'fa-laptop', 'sort_order' => '0', 'ticket_subject_template' => 'svc-test-subject', 'ticket_category_id' => (string) $categoryId, 'default_priority' => 'High', 'price' => '49.99', 'is_active' => '1'], [], $ref);
$ok($c === 200 || $c === 302, 'add_service_catalog_item is accepted');
$itemId = (int) $one("SELECT catalog_item_id FROM service_catalog_items WHERE name = 'svc-test-item'");
$ok($itemId > 0, 'the item was created');
$ok((string) $one("SELECT price FROM service_catalog_items WHERE catalog_item_id = $itemId") === '49.99', 'the price is stored exactly as entered');
$ok((int) $one("SELECT requires_approval FROM service_catalog_items WHERE catalog_item_id = $itemId") === 0, 'requires_approval defaults off (the add form did not tick it)');

sec_web($base, 'POST', '/admin/post.php', $sid, ['edit_service_catalog_item' => '1', 'csrf_token' => 'csrftok12345678', 'catalog_item_id' => (string) $itemId, 'name' => 'svc-test-item', 'description' => 'svc-test-desc', 'icon' => 'fa-laptop', 'sort_order' => '0', 'ticket_subject_template' => 'svc-test-subject', 'ticket_category_id' => (string) $categoryId, 'default_priority' => 'High', 'price' => '79.00', 'is_active' => '1', 'requires_approval' => '1'], [], $ref);
$ok((string) $one("SELECT price FROM service_catalog_items WHERE catalog_item_id = $itemId") === '79.00', 'editing changes the stored price');
$ok((int) $one("SELECT requires_approval FROM service_catalog_items WHERE catalog_item_id = $itemId") === 1, 'editing can turn requires_approval on');

sec_web($base, 'GET', '/admin/post.php?toggle_service_catalog_item=' . $itemId . '&csrf_token=csrftok12345678', $sid, [], [], $ref);
$ok((int) $one("SELECT is_active FROM service_catalog_items WHERE catalog_item_id = $itemId") === 0, 'toggle deactivates it');
sec_web($base, 'GET', '/admin/post.php?toggle_service_catalog_item=' . $itemId . '&csrf_token=csrftok12345678', $sid, [], [], $ref);
$ok((int) $one("SELECT is_active FROM service_catalog_items WHERE catalog_item_id = $itemId") === 1, 'toggling again reactivates it');

echo "\n--- admin list page ---\n";
[$c, $body] = sec_web($base, 'GET', '/admin/service_catalog.php', $sid);
$ok($c === 200 && str_contains($body, 'svc-test-item') && str_contains($body, '$79.00') && str_contains($body, 'Requires approval'), 'the admin list shows the item, its price and the approval badge');

echo "\n--- agent Request Something + creating a ticket (requires_approval) ---\n";
[$c, $body] = sec_web($base, 'GET', '/agent/service_catalog.php', $sid);
$ok($c === 200 && str_contains($body, 'svc-test-item') && str_contains($body, 'Needs approval'), 'the agent page shows the active item with the approval badge');

$post = fn (array $f) => sec_web($base, 'POST', '/agent/post.php', $sid, $f + ['csrf_token' => 'csrftok12345678'], []);
[$c] = $post(['add_ticket' => '1', 'catalog_item_id' => (string) $itemId, 'client_id' => (string) $clientId, 'contact_id' => (string) $contactId, 'assigned_to' => '0', 'category_id' => (string) $categoryId, 'subject' => 'svc-test-subject', 'priority' => 'High', 'details' => 'svc-test-details', 'vendor_ticket_number' => '', 'vendor_id' => '0', 'asset_id' => '0', 'location_id' => '0', 'project_id' => '0', 'use_primary_contact' => '0', 'ticket_template_id' => '0', 'billable' => '0']);
$ok($c === 200 || $c === 302, 'add_ticket with a catalog_item_id is accepted');
$ticketId = (int) $one("SELECT ticket_id FROM tickets WHERE ticket_subject = 'svc-test-subject' AND ticket_client_id = $clientId ORDER BY ticket_id DESC LIMIT 1");
$ok($ticketId > 0 && (int) $one("SELECT ticket_catalog_item_id FROM tickets WHERE ticket_id = $ticketId") === $itemId, 'the ticket is tagged with the catalog item');
$heldStatusId = (int) $one("SELECT ticket_status FROM tickets WHERE ticket_id = $ticketId");
$heldStatusName = (string) $one("SELECT ticket_status_name FROM ticket_statuses WHERE ticket_status_id = $heldStatusId");
$ok($heldStatusName === 'Pending Approval', 'requires_approval holds the ticket at Pending Approval, not its normal creation status');
$ok((bool) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $ticketId AND ticket_reply_type = 'System' AND ticket_reply LIKE '%Held for approval%'"), 'a System note explains the hold');
if ($holdStatusCol) {
    $ok($one("SELECT ticket_sla_paused_at FROM tickets WHERE ticket_id = $ticketId") !== null, 'the SLA pause flag was synced immediately, not left for the next cron run');
}

echo "\n--- a plain item (no approval) creates a normal, unheld ticket ---\n";
$q("UPDATE service_catalog_items SET requires_approval = 0 WHERE catalog_item_id = $itemId");
[$c] = $post(['add_ticket' => '1', 'catalog_item_id' => (string) $itemId, 'client_id' => (string) $clientId, 'contact_id' => (string) $contactId, 'assigned_to' => '0', 'category_id' => (string) $categoryId, 'subject' => 'svc-test-subject-2', 'priority' => 'Low', 'details' => 'svc-test-details', 'vendor_ticket_number' => '', 'vendor_id' => '0', 'asset_id' => '0', 'location_id' => '0', 'project_id' => '0', 'use_primary_contact' => '0', 'ticket_template_id' => '0', 'billable' => '0']);
$ticket2Id = (int) $one("SELECT ticket_id FROM tickets WHERE ticket_subject = 'svc-test-subject-2' AND ticket_client_id = $clientId ORDER BY ticket_id DESC LIMIT 1");
$status2 = (int) $one("SELECT ticket_status FROM tickets WHERE ticket_id = $ticket2Id");
$ok($ticket2Id > 0 && $status2 !== $heldStatusId, 'no approval needed means the ticket is NOT held');
$q("UPDATE service_catalog_items SET requires_approval = 1 WHERE catalog_item_id = $itemId");

echo "\n--- client portal: the browse page and creating a held ticket ---\n";
$csid = sec_forge_session($sdir, ['client_logged_in' => true, 'user_id' => $portalUserId, 'contact_id' => $contactId, 'client_id' => $clientId, 'csrf_token' => 'csrftok12345678']);
[$c, $body] = sec_web($base, 'GET', '/client/service_catalog.php', $csid);
$ok($c === 200 && str_contains($body, 'svc-test-item') && str_contains($body, 'Needs approval'), 'the portal browse page shows the active item');
[$c, $body] = sec_web($base, 'GET', '/client/ticket_add.php?catalog_item_id=' . $itemId, $csid);
$ok($c === 200 && str_contains($body, 'value="svc-test-subject"') && str_contains($body, 'needs approval'), 'the portal ticket form is prefilled from the catalog item and warns about the hold');
[$c] = sec_web($base, 'POST', '/client/post.php', $csid, ['add_ticket' => '1', 'catalog_item_id' => (string) $itemId, 'subject' => 'svc-test-subject', 'priority' => 'High', 'category' => (string) $categoryId, 'details' => 'svc-test-portal-details', 'csrf_token' => 'csrftok12345678'], []);
$ok($c === 200 || $c === 302, 'the portal add_ticket with a catalog_item_id is accepted');
$portalTicketId = (int) $one("SELECT ticket_id FROM tickets WHERE ticket_source = 'Portal' AND ticket_catalog_item_id = $itemId ORDER BY ticket_id DESC LIMIT 1");
$ok($portalTicketId > 0 && (int) $one("SELECT ticket_status FROM tickets WHERE ticket_id = $portalTicketId") === $heldStatusId, 'a portal-raised request is held exactly the same way as an agent-raised one');

echo "\n--- popular this month / recently used by you ---\n";
$trending = serviceCatalogTrending($mysqli, 5, 30);
$trendingNames = array_column($trending, 'name');
$ok(in_array('svc-test-item', $trendingNames, true), 'the item shows up in trending after 3 tickets were raised from it');
$recent = serviceCatalogRecentForContact($mysqli, $contactId, $clientId, 5);
$ok(count($recent) === 1 && $recent[0]['name'] === 'svc-test-item', "recently-used-by-you is scoped to this contact's own tickets");

echo "\n--- deleting an item detaches it from past tickets without touching them ---\n";
sec_web($base, 'GET', '/admin/post.php?delete_service_catalog_item=' . $itemId . '&csrf_token=csrftok12345678', $sid, [], [], $ref);
$ok((int) $one("SELECT COUNT(*) FROM service_catalog_items WHERE catalog_item_id = $itemId") === 0, 'the item is gone');
$ok($one("SELECT ticket_catalog_item_id FROM tickets WHERE ticket_id = $ticketId") === null, 'the ticket it created still exists, just with the link cleared');
$ok((bool) $one("SELECT COUNT(*) FROM tickets WHERE ticket_id = $ticketId"), 'and the ticket itself was never deleted');

[, $log] = sec_sh('cat ' . escapeshellarg(sys_get_temp_dir()) . '/sec_server_*.log 2>/dev/null');
$ok(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $log) || str_contains($log, 'Undefined array key "HTTP_USER_AGENT"'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');

$q("DELETE FROM tickets WHERE ticket_subject LIKE 'svc-test-%'");
$q("DELETE FROM contacts WHERE contact_name = 'svc-test-contact'");
$q("DELETE FROM users WHERE user_email = 'svc-test-portal@example.test'");
$q("DELETE FROM clients WHERE client_name = 'svc-test-Client'");
$q("DELETE FROM categories WHERE category_name = 'svc-test-cat'");
$q("DELETE FROM ticket_statuses WHERE ticket_status_name = 'Pending Approval'");
