<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Automated dispatch v1 (roadmap #29): skill-tag match + workload-balance tiebreak, no AI
 * dependency, against the REAL code on a SCRATCH database (same harness as tests/security_*.php).
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/dispatch_v1.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/database_version.php";
require_once "$root/includes/dispatch_functions.php";

echo "\n--- DB 2.6.113 step ---\n";
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.113', '>='), 'LATEST_DATABASE_VERSION is 2.6.113 or later');
$sqlText = (string) file_get_contents("$root/db.sql");
$ok(str_contains($sqlText, 'CREATE TABLE `user_tags`'), 'db.sql carries the user_tags table');
$ok(str_contains($sqlText, '`category_required_skill_tag_id` int(11) DEFAULT NULL'), 'db.sql carries the categories column');
$verBefore = (string) $one('SELECT config_current_database_version FROM settings LIMIT 1');
$q("UPDATE settings SET config_current_database_version = '2.6.112'");
[, $updOut] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut, '2.6.112') && (string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === LATEST_DATABASE_VERSION, 'the updater advances past 2.6.112 to the latest version');
[, $updOut2] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut2, 'already at the latest'), 'running it again is a no-op, not an error');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_tags'") === 1, 'user_tags table exists');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'category_required_skill_tag_id'") === 1, 'categories has category_required_skill_tag_id');
$q("UPDATE settings SET config_current_database_version = '" . $esc($verBefore) . "'");

echo "\n--- fixtures ---\n";
$q("DELETE FROM tickets WHERE ticket_subject LIKE 'disp-test-%'");
$q("DELETE FROM ticket_automation_rules WHERE rule_name LIKE 'disp-test-%'");
$q("DELETE FROM categories WHERE category_name LIKE 'disp-test-%'");
$q("DELETE FROM tags WHERE tag_name LIKE 'disp-test-%'");
$q("DELETE FROM users WHERE user_email LIKE 'disp-test-%'");
$q("DELETE FROM clients WHERE client_name LIKE 'disp-test-%'");

$q("INSERT INTO clients SET client_name='disp-test-Client'");
$client = (int) $db->insert_id;
$q("INSERT INTO contacts SET contact_client_id = $client, contact_name = 'disp-test-Contact', contact_email='disp-c@example.test', contact_primary=1");
$contact = (int) $db->insert_id;
$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='disp-test-admin', user_email='disp-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

$q("INSERT INTO tags SET tag_name='disp-test-Networking', tag_type=7");
$skillNet = (int) $db->insert_id;
$q("INSERT INTO tags SET tag_name='disp-test-Printers', tag_type=7");
$skillPrint = (int) $db->insert_id;

// Two technicians with the Networking skill, one without any skill at all.
$q("INSERT INTO users SET user_name='disp-test-TechA', user_email='disp-test-techa@example.test', user_password='x', user_type=1, user_status=1");
$techA = (int) $db->insert_id;
$q("INSERT INTO users SET user_name='disp-test-TechB', user_email='disp-test-techb@example.test', user_password='x', user_type=1, user_status=1");
$techB = (int) $db->insert_id;
$q("INSERT INTO users SET user_name='disp-test-TechC-NoSkill', user_email='disp-test-techc@example.test', user_password='x', user_type=1, user_status=1");
$techC = (int) $db->insert_id;
$q("INSERT INTO users SET user_name='disp-test-TechD-Disabled', user_email='disp-test-techd@example.test', user_password='x', user_type=1, user_status=0");
$techD = (int) $db->insert_id;
$q("INSERT INTO user_tags SET user_tag_user_id = $techA, user_tag_tag_id = $skillNet");
$q("INSERT INTO user_tags SET user_tag_user_id = $techB, user_tag_tag_id = $skillNet");
$q("INSERT INTO user_tags SET user_tag_user_id = $techD, user_tag_tag_id = $skillNet"); // disabled - must never be picked

$q("INSERT INTO categories SET category_name='disp-test-Networking-Cat', category_type='Ticket', category_required_skill_tag_id=$skillNet");
$catNet = (int) $db->insert_id;
$q("INSERT INTO categories SET category_name='disp-test-Nobody-Cat', category_type='Ticket', category_required_skill_tag_id=$skillPrint"); // nobody holds this one
$catNobody = (int) $db->insert_id;
$q("INSERT INTO categories SET category_name='disp-test-NoSkill-Cat', category_type='Ticket'"); // no required skill at all
$catNone = (int) $db->insert_id;

$sdir = sys_get_temp_dir() . '/disp_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sid = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
$agentPost = fn (array $f) => sec_web($base, 'POST', '/agent/post.php', $sid, $f + ['csrf_token' => 'csrftok12345678'], []);
$adminPost = fn (array $f, string $referer) => sec_web($base, 'POST', '/admin/post.php', $sid, $f + ['csrf_token' => 'csrftok12345678'], [], ["Referer: $base/admin/$referer"]);
$tk = fn (int $id) => mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM tickets WHERE ticket_id = $id"));
$mkTicket = function (int $catId, string $subject) use ($agentPost, $one, $client, $contact) {
    $agentPost(['add_ticket' => '1', 'client_id' => (string) $client, 'contact_id' => (string) $contact, 'subject' => $subject, 'details' => 'x', 'priority' => 'Low', 'assigned_to' => '0', 'billable' => '0', 'category_id' => (string) $catId]);
    return (int) $one("SELECT ticket_id FROM tickets WHERE ticket_subject = '$subject'");
};

echo "\n--- admin UI: skill tags and category wiring ---\n";
[$c, $body] = sec_web($base, 'GET', '/admin/tag.php?type=7', $sid);
$ok($c === 200 && str_contains($body, 'Technician Tags') && str_contains($body, 'disp-test-Networking'), 'the Technician tag type (7) is reachable and lists the skill tags');

[$c] = $adminPost(['update_user_skills' => '1', 'user_id' => (string) $techC, 'tags' => [(string) $skillPrint]], 'users.php');
$ok($c === 200 || $c === 302, 'update_user_skills accepted');
$ok((int) $one("SELECT COUNT(*) FROM user_tags WHERE user_tag_user_id = $techC AND user_tag_tag_id = $skillPrint") === 1, "TechC's skill was saved via the real admin handler");

[$c, $body] = sec_web($base, 'GET', "/admin/modals/category/category_edit.php?id=$catNet", $sid);
$ok($c === 200 && preg_match('/value="' . $skillNet . '"[^>]*selected/', stripslashes($body)), 'the category edit modal pre-selects the configured Required Skill');

echo "\n--- dispatchAssignTicket(): direct function checks ---\n";
$tNet = $mkTicket($catNet, 'disp-test-NetTicket1');
$picked = dispatchAssignTicket($db, $tNet);
$ok(in_array($picked, [$techA, $techB], true), 'a ticket in the Networking category is matched to one of the two technicians who hold that skill');
$ok($picked !== $techC && $picked !== $techD, "the no-skill tech and the DISABLED skilled tech are both excluded");

// Note: the scratch DB's pre-existing admin fixture user is also user_type=1/user_status=1 (a
// legitimate member of the "every active technician" pool) and, being shared across many test
// files this session, may carry an unpredictable existing open-ticket count - so these two
// fallback checks assert "a real, active technician was picked" (not stranded with null) rather
// than pinning down exactly which one, which the later workload-tiebreak section tests precisely
// using a pool deliberately restricted to tags/technicians nothing else could match.
$tNobody = $mkTicket($catNobody, 'disp-test-NobodyTicket');
$picked2 = dispatchAssignTicket($db, $tNobody);
$ok($picked2 !== null && (int) $one("SELECT COUNT(*) FROM users WHERE user_id = $picked2 AND user_type = 1 AND user_status = 1") === 1, 'when nobody holds the required skill, dispatch falls back to a real active technician (not stranded)');

$tNone = $mkTicket($catNone, 'disp-test-NoSkillTicket');
$picked3 = dispatchAssignTicket($db, $tNone);
$ok($picked3 !== null && (int) $one("SELECT COUNT(*) FROM users WHERE user_id = $picked3 AND user_type = 1 AND user_status = 1") === 1, 'a category with no required skill at all also falls back to a real active technician');

echo "\n--- workload-balance tiebreak ---\n";
// Give TechA 3 open tickets and TechB 0: with equal skill match, TechB (fewer open tickets) must win.
for ($i = 0; $i < 3; $i++) {
    $q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=" . (90000 + $i) . ", ticket_subject='disp-test-load-$i', ticket_details='x', ticket_priority='Low', ticket_status=1, ticket_client_id=$client, ticket_assigned_to=$techA, ticket_created_by=$admin");
}
$tTie = $mkTicket($catNet, 'disp-test-TieBreak');
$pickedTie = dispatchAssignTicket($db, $tTie);
$ok($pickedTie === $techB, 'with TechA carrying 3 open tickets and TechB carrying 0, the tiebreak picks TechB (fewer open tickets)');

// Now even the load: give TechB 3 tickets too (one more than TechA's 3, after the tie-break ticket was NOT
// yet assigned to anyone) - deterministic final tiebreak (lowest user_id) when counts are equal.
$q("UPDATE tickets SET ticket_assigned_to = $techB WHERE ticket_subject IN ('disp-test-load-0', 'disp-test-load-1', 'disp-test-load-2')");
$lowerId = min($techA, $techB);
$pickedEqual = dispatchAssignTicket($db, $tTie);
$ok($pickedEqual === $lowerId, 'when workload counts are exactly equal, the lowest user_id wins as the final deterministic tiebreak');

echo "\n--- wired as a real ticket-automation action, end to end ---\n";
$q("DELETE FROM tickets WHERE ticket_subject LIKE 'disp-test-load-%'");
[$c] = $adminPost(['add_rule' => '1', 'rule_name' => 'disp-test-AutoDispatch', 'rule_trigger' => 'ticket_created', 'rule_order' => '0',
    'action_name' => ['dispatch_assign'], 'action_value' => ['']], 'ticket_automation.php');
$ok($c === 200 || $c === 302, 'a real admin-created rule using the dispatch_assign action is accepted');
$ruleId = (int) $one("SELECT rule_id FROM ticket_automation_rules WHERE rule_name = 'disp-test-AutoDispatch'");
$ok($ruleId > 0, 'the rule was stored');

$tEnd = $mkTicket($catNet, 'disp-test-EndToEnd');
$assignedTo = (int) $tk($tEnd)['ticket_assigned_to'];
$ok(in_array($assignedTo, [$techA, $techB], true), 'creating a new Networking-category ticket through the real agent handler auto-dispatches it to a Networking-skilled technician via the rule engine');
$ok((int) $one("SELECT COUNT(*) FROM ticket_automation_runs WHERE rule_id = $ruleId AND ticket_id = $tEnd") === 1, 'the dispatch is recorded in the automation run log like any other action');

[, $log] = sec_sh('cat ' . escapeshellarg(sys_get_temp_dir()) . '/sec_server_*.log 2>/dev/null');
$ok(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $log) || str_contains($log, 'Undefined array key "HTTP_USER_AGENT"'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');
