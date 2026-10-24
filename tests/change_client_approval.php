<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Client-facing change approval (GitHub roadmap #5), against the REAL code on a SCRATCH database (same harness as
 * tests/security_*.php). A RivetMSP-only layer on top of RivetCore's ChangeService (which has no client/approver
 * concept at all - see the DB 2.6.110 migration comment).
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/change_client_approval.php
 *
 * Covers: the DB 2.6.110 step (db.sql parity, idempotent replay); creating/editing a change through the real
 * handlers sets client_id/requires_client_approval and generates a url_key exactly once (never rotated on a later
 * re-save); a technician restricted to another client cannot touch it, but an internal (no-client) change stays
 * visible/editable regardless of client restrictions; the "Send Approval Request" handler emails the client's
 * primary contact and is itself client-access-checked; the real guest page and guest post handlers (token-only,
 * no login, no CSRF - the url_key IS the authorization) approve/reject exactly once, record who/when, notify the
 * agent side, and refuse a wrong token, a second decision, or a change that has moved past the point where the
 * client's decision would still matter; none of this touches RivetCore's own status column.
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/database_version.php";

echo "\n--- DB 2.6.110 step ---\n";
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'changes' AND COLUMN_NAME = 'client_id'") === 1, 'the changes table has client_id');
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.110', '>='), 'LATEST_DATABASE_VERSION is 2.6.110 or later');
$sqlText = (string) file_get_contents("$root/db.sql");
$ok(str_contains($sqlText, "`client_decision` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending'"), 'db.sql carries client_decision exactly as the migration made it');
$verBefore = (string) $one('SELECT config_current_database_version FROM settings LIMIT 1');
$q("UPDATE settings SET config_current_database_version = '2.6.109'");
[, $updOut] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
// Not pinned to the literal "2.6.110" text: a later migration chains straight through in the same run (and may
// not print every intermediate version in its summary), same staleness class fixed in tests/credential_rotation.php.
$ok(str_contains($updOut, '2.6.109') && (string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === LATEST_DATABASE_VERSION, 'the updater advances past 2.6.109 to the latest version');
[, $updOut2] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut2, 'already at the latest'), 'running it again is a no-op, not an error');
$q("UPDATE settings SET config_current_database_version = '" . $esc($verBefore) . "'");
$q("UPDATE settings SET config_core_itsm_enabled = 1 WHERE company_id = 1");
\RivetMSP\Core\CoreBridge::reset();

echo "\n--- fixtures ---\n";
$q("DELETE FROM changes WHERE title LIKE 'chg-test-%'");
$q("DELETE FROM contacts WHERE contact_name LIKE 'chg-test-%'");
$q("DELETE FROM clients WHERE client_name LIKE 'chg-test-%'");
$q("DELETE FROM notifications WHERE notification_type LIKE 'Change %'");
$q("DELETE FROM email_queue WHERE email_subject LIKE '%chg-test%'");
$q("INSERT INTO clients SET client_name='chg-test-Client A'"); $clientA = (int) $db->insert_id;
$q("INSERT INTO clients SET client_name='chg-test-Client B'"); $clientB = (int) $db->insert_id;
$q("INSERT INTO contacts SET contact_name='chg-test-Primary A', contact_email='chg-test-a@example.test', contact_primary=1, contact_client_id=$clientA");
$q("DELETE FROM user_client_permissions WHERE user_id IN (SELECT user_id FROM users WHERE user_email LIKE 'chg-test-%')");
$q("DELETE FROM users WHERE user_email LIKE 'chg-test-%'");
$q("DELETE FROM user_roles WHERE role_name = 'chg-test-TechB'");
$q("INSERT INTO user_roles SET role_name='chg-test-TechB', role_is_admin=0, role_type=1");
$roleTechB = (int) $db->insert_id;
$modSupportId = (int) $one("SELECT module_id FROM modules WHERE module_name='module_support'");
if ($modSupportId === 0) { $q("INSERT INTO modules SET module_name='module_support'"); $modSupportId = (int) $db->insert_id; }
$q("DELETE FROM user_role_permissions WHERE user_role_id = $roleTechB");
$q("INSERT INTO user_role_permissions SET user_role_id=$roleTechB, module_id=$modSupportId, user_role_permission_level=2");
$q("INSERT INTO users SET user_name='chg-test-techb', user_email='chg-test-techb@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$roleTechB");
$techB = (int) $db->insert_id;
$q("INSERT INTO user_settings SET user_id=$techB ON DUPLICATE KEY UPDATE user_id=user_id");
$q("INSERT INTO user_client_permissions SET user_id=$techB, client_id=$clientB");   // restricted to Client B only
$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='chg-test-admin', user_email='chg-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

$sdir = sys_get_temp_dir() . '/chg_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sidAdmin = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
$sidTechB = sec_forge_session($sdir, ['logged' => true, 'user_id' => $techB, 'csrf_token' => 'csrftok12345678']);
$post = fn (string $sid, array $f) => sec_web($base, 'POST', '/agent/post.php', $sid, $f + ['csrf_token' => 'csrftok12345678']);

echo "\n--- create/edit through the real handlers ---\n";
$post($sidAdmin, ['add_change' => '1', 'title' => 'chg-test-internal', 'risk' => 'low', 'client_id' => '0']);
$internalId = (int) $one("SELECT change_id FROM changes WHERE title = 'chg-test-internal'");
$ok($internalId > 0 && (int) $one("SELECT client_id FROM changes WHERE change_id = $internalId") === 0, 'an internal (no-client) change saves with client_id 0');

$post($sidAdmin, ['add_change' => '1', 'title' => 'chg-test-cliA', 'risk' => 'medium', 'client_id' => (string) $clientA, 'requires_client_approval' => '1']);
$changeId = (int) $one("SELECT change_id FROM changes WHERE title = 'chg-test-cliA'");
$row = $tk = fn () => mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM changes WHERE change_id = $changeId"));
$first = $tk();
$ok($changeId > 0 && (int) $first['client_id'] === $clientA && (int) $first['requires_client_approval'] === 1 && strlen((string) $first['url_key']) === 32, 'a client-tied change with approval requested gets client_id, the flag and a 32-char url_key');
$urlKey1 = $first['url_key'];

$post($sidAdmin, ['edit_change' => '1', 'change_id' => $changeId, 'title' => 'chg-test-cliA', 'risk' => 'high', 'client_id' => (string) $clientA, 'requires_client_approval' => '1']);
$second = $tk();
$ok($second['url_key'] === $urlKey1 && $second['risk'] === 'high', 're-saving with the box still checked keeps the SAME url_key (a link already shared with the client must keep working) and still saves other fields');

$post($sidAdmin, ['add_change' => '1', 'title' => 'chg-test-cliA-noapproval', 'risk' => 'low', 'client_id' => (string) $clientA]);
$noApprovalId = (int) $one("SELECT change_id FROM changes WHERE title = 'chg-test-cliA-noapproval'");
$ok((int) $one("SELECT requires_client_approval FROM changes WHERE change_id = $noApprovalId") === 0 && $one("SELECT url_key FROM changes WHERE change_id = $noApprovalId") === null, 'a client-tied change WITHOUT approval requested gets no url_key at all');

echo "\n--- tenant isolation ---\n";
[$c, $body] = $post($sidTechB, ['add_change' => '1', 'title' => 'chg-test-blocked', 'risk' => 'low', 'client_id' => (string) $clientA]);
$ok((int) $one("SELECT COUNT(*) FROM changes WHERE title = 'chg-test-blocked'") === 0, 'a technician restricted to Client B cannot create a change for Client A');
[$c, $body] = sec_web($base, 'GET', '/agent/change_details.php?id=' . $changeId, $sidTechB);
$ok(!str_contains($body, 'chg-test-cliA<') && !str_contains($body, '>chg-test-cliA</'), 'and cannot view its detail page');
[$c, $body] = sec_web($base, 'GET', '/agent/change_details.php?id=' . $internalId, $sidTechB);
$ok($c === 200 && str_contains($body, 'chg-test-internal'), 'but the internal (no-client) change is visible to every technician regardless of client restrictions');
[$c, $body] = sec_web($base, 'GET', '/agent/changes.php', $sidTechB);
$ok(str_contains($body, 'chg-test-internal') && !str_contains($body, 'chg-test-cliA<'), 'the list page shows the internal change but hides the Client A one from a Client-B-only technician');

echo "\n--- send approval request (agent side) ---\n";
$q("DELETE FROM email_queue");
$post($sidAdmin, ['send_change_approval_request' => '1', 'change_id' => $changeId]);
$mail = $rows("SELECT * FROM email_queue WHERE email_recipient = 'chg-test-a@example.test'");
$ok(count($mail) === 1 && str_contains($mail[0]['email_subject'], 'chg-test-cliA') && str_contains($mail[0]['email_content'], $urlKey1), 'sending the request queues one email to the primary contact, carrying the real url_key');
[$c] = $post($sidTechB, ['send_change_approval_request' => '1', 'change_id' => $changeId]);
$ok((int) $one("SELECT COUNT(*) FROM email_queue WHERE email_recipient = 'chg-test-a@example.test'") === 1, 'a technician without access to Client A cannot trigger the request for it (still exactly one email, not two)');
$post($sidAdmin, ['send_change_approval_request' => '1', 'change_id' => $noApprovalId]);
$noApprovalRow = mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM changes WHERE change_id = $noApprovalId"));
$ok((int) $noApprovalRow['requires_client_approval'] === 1 && strlen((string) $noApprovalRow['url_key']) === 32, 'sending a request for a change that never had the box ticked turns it on and makes a key on the spot');

echo "\n--- the real guest page and guest_post handlers (token only, no login) ---\n";
[$c, $body] = sec_web($base, 'GET', '/guest/guest_view_change.php?change_id=' . $changeId . '&url_key=' . $urlKey1, 'nosuchsession');
$ok($c === 200 && str_contains($body, 'chg-test-cliA') && str_contains($body, 'approve_change') && str_contains($body, 'reject_change') && str_contains($body, 'confirm-link'), 'the guest page renders with no session at all, and the Approve/Reject buttons use the RivetDialog confirm-link pattern, not a native confirm()');
[$c, $body] = sec_web($base, 'GET', '/guest/guest_view_change.php?change_id=' . $changeId . '&url_key=WRONGKEYWRONGKEYWRONGKEYWRONGKEY', 'nosuchsession');
$ok($c === 200 && !str_contains($body, 'chg-test-cliA'), 'a wrong url_key shows nothing about the change');
[$c, $body] = sec_web($base, 'GET', '/guest/guest_view_change.php?change_id=' . $noApprovalId . '&url_key=', 'nosuchsession');
$ok($c === 200 && !str_contains($body, 'chg-test-cliA-noapproval'), 'an empty url_key is refused too (never matches a real one)');

[$c, $body] = sec_web($base, 'POST', '/guest/guest_post.php', 'nosuchsession', ['approve_change' => $changeId, 'url_key' => 'WRONGKEYWRONGKEYWRONGKEYWRONGKEY']);
$ok((string) $one("SELECT client_decision FROM changes WHERE change_id = $changeId") === 'pending', 'approving with the wrong key changes nothing');

[$c, $body] = sec_web($base, 'POST', '/guest/guest_post.php', 'nosuchsession', ['approve_change' => $changeId, 'url_key' => $urlKey1]);
$decided = mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM changes WHERE change_id = $changeId"));
$ok($decided['client_decision'] === 'approved' && $decided['client_decided_at'] !== null, 'the right key approves it and stamps the decision time');
$ok((string) $one("SELECT status FROM changes WHERE change_id = $changeId") === 'draft', 'the client decision never touches RivetCore\'s own status column (still draft - this is a layer on top, not a Core change)');
$ok((int) $one("SELECT COUNT(*) FROM notifications WHERE notification_type = 'Change Approved' AND notification LIKE '%chg-test-cliA%'") >= 1, 'the agent side is notified of the approval');

[$c, $body] = sec_web($base, 'POST', '/guest/guest_post.php', 'nosuchsession', ['approve_change' => $changeId, 'url_key' => $urlKey1]);
$ok((string) $one("SELECT client_decision FROM changes WHERE change_id = $changeId") === 'approved', 'a decision cannot be made twice (still approved, not reverted or duplicated)');
[$c, $body] = sec_web($base, 'GET', '/guest/guest_view_change.php?change_id=' . $changeId . '&url_key=' . $urlKey1, 'nosuchsession');
$ok(!str_contains($body, 'name="approve_change"') && str_contains($body, 'already approved'), 'the guest page itself no longer offers a decision once one is made');

$q("UPDATE changes SET client_decision = 'pending' WHERE change_id = $noApprovalId");
$urlKey2 = (string) $one("SELECT url_key FROM changes WHERE change_id = $noApprovalId");
[$c] = sec_web($base, 'POST', '/guest/guest_post.php', 'nosuchsession', ['reject_change' => $noApprovalId, 'url_key' => $urlKey2]);
$rejected = mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM changes WHERE change_id = $noApprovalId"));
$ok($rejected['client_decision'] === 'rejected' && (int) $one("SELECT COUNT(*) FROM notifications WHERE notification_type = 'Change Rejected'") >= 1, 'reject works the same way and notifies the agent side');

echo "\n--- a change that has moved past the point of deciding ---\n";
$q("DELETE FROM changes WHERE title = 'chg-test-cancelled'");
$q("INSERT INTO changes SET title='chg-test-cancelled', risk='low', status='cancelled', client_id=$clientA, requires_client_approval=1, client_decision='pending', url_key='" . randomString(32) . "'");
$cancelledId = (int) $db->insert_id;
$cancelledKey = (string) $one("SELECT url_key FROM changes WHERE change_id = $cancelledId");
[$c, $body] = sec_web($base, 'GET', '/guest/guest_view_change.php?change_id=' . $cancelledId . '&url_key=' . $cancelledKey, 'nosuchsession');
$ok(!str_contains($body, 'name="approve_change"'), 'a cancelled change offers no decision even though client_decision is still pending');
sec_web($base, 'POST', '/guest/guest_post.php', 'nosuchsession', ['approve_change' => $cancelledId, 'url_key' => $cancelledKey]);
$ok((string) $one("SELECT client_decision FROM changes WHERE change_id = $cancelledId") === 'pending', 'and the handler refuses it server-side too, not just hiding the button');

$thisPort = (int) explode(':', $base)[2];
$logText = (string) @file_get_contents(sys_get_temp_dir() . "/sec_server_$thisPort.log");
$otherWarnings = preg_replace('/^.*Undefined array key "HTTP_USER_AGENT".*$/m', '', $logText);
$ok(!str_contains($otherWarnings, 'PHP Warning') && !str_contains($otherWarnings, 'PHP Fatal') && !str_contains($otherWarnings, 'PHP Notice'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');

// cleanup
$q("DELETE FROM changes WHERE title LIKE 'chg-test-%'");
$q("DELETE FROM contacts WHERE contact_name LIKE 'chg-test-%'");
$q("DELETE FROM user_client_permissions WHERE user_id = $techB");
$q("DELETE FROM users WHERE user_id = $techB");
$q("DELETE FROM user_role_permissions WHERE user_role_id = $roleTechB");
$q("DELETE FROM user_roles WHERE role_id = $roleTechB");
$q("DELETE FROM clients WHERE client_id IN ($clientA, $clientB)");
$q("DELETE FROM notifications WHERE notification_type LIKE 'Change %'");
$q("DELETE FROM email_queue WHERE email_subject LIKE '%chg-test%'");
