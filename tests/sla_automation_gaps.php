<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Roadmap #17 (SLA engine) and #18 (ticket automation rules) gap closure, against the REAL code on a
 * SCRATCH database (same harness as tests/security_*.php).
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/sla_automation_gaps.php
 *
 * #17: sla_policies.policy_client_id (DB 2.6.113) lets slaGetPolicyForTicket()'s already-forward-compatible
 * client-override logic actually resolve a per-client policy, falling back to the single company-wide default
 * for every other client. Also confirms the pre-existing breach-condition -> automation-rule pipeline
 * (sla_response_breached as a 'schedule' trigger condition field) still fires end-to-end on a ticket whose due
 * date came from a CLIENT-scoped policy, tying the two together.
 *
 * #18: the 'ticket_updated' trigger (dispatched from the real agent edit_ticket handler, mirroring the existing
 * ticket_created dispatch) and the 'set_category' / 'add_tag' actions (automationExecuteAction()), closing the
 * three vocabulary gaps against the "ticket created/updated/SLA breach/etc. ... notify/assign/tag/category-change"
 * spec.
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/database_version.php";

echo "\n--- DB 2.6.113 step ---\n";
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.113', '>='), 'LATEST_DATABASE_VERSION is 2.6.113 or later');
$sqlText = (string) file_get_contents("$root/db.sql");
$ok(str_contains($sqlText, '`policy_client_id` int(11) DEFAULT NULL'), 'db.sql carries the column');
$verBefore = (string) $one('SELECT config_current_database_version FROM settings LIMIT 1');
$q("UPDATE settings SET config_current_database_version = '2.6.112'");
[, $updOut] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut, '2.6.112') && (string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === LATEST_DATABASE_VERSION, 'the updater advances past 2.6.112 to the latest version');
[, $updOut2] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut2, 'already at the latest'), 'running it again is a no-op, not an error');
// Existence check runs AFTER the version-reset-and-replay above, not before: the shared scratch DB may
// have been left at a newer version string by a DIFFERENT worktree's test run without THIS branch's own
// migration ever having executed against it (documented test-staleness trap from earlier this session).
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sla_policies' AND COLUMN_NAME = 'policy_client_id'") === 1, 'sla_policies has policy_client_id');
$q("UPDATE settings SET config_current_database_version = '" . $esc($verBefore) . "'");

echo "\n--- fixtures ---\n";
$q("DELETE FROM tickets WHERE ticket_subject LIKE 'sag-test-%'");
$q("DELETE FROM ticket_automation_rules WHERE rule_name LIKE 'sag-test-%'");
$q("DELETE FROM sla_policies WHERE policy_name LIKE 'sag-test-%'");
$q("DELETE FROM tags WHERE tag_name LIKE 'sag-test-%'");
$q("DELETE FROM categories WHERE category_name LIKE 'sag-test-%'");
$q("DELETE FROM clients WHERE client_name LIKE 'sag-test-%'");

$q("INSERT INTO clients SET client_name='sag-test-ClientA'");
$clientA = (int) $db->insert_id;
$q("INSERT INTO clients SET client_name='sag-test-ClientB'");
$clientB = (int) $db->insert_id;
$q("INSERT INTO contacts SET contact_client_id = $clientA, contact_name = 'sag-test-ContactA', contact_email='sag-a@example.test', contact_primary=1");
$contactA = (int) $db->insert_id;
$q("INSERT INTO contacts SET contact_client_id = $clientB, contact_name = 'sag-test-ContactB', contact_email='sag-b@example.test', contact_primary=1");
$contactB = (int) $db->insert_id;

$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='sag-test-admin', user_email='sag-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

$q("INSERT INTO categories SET category_name='sag-test-CatA', category_type='Ticket'");
$catA = (int) $db->insert_id;
$q("INSERT INTO categories SET category_name='sag-test-CatB', category_type='Ticket'");
$catB = (int) $db->insert_id;
$q("INSERT INTO tags SET tag_name='sag-test-urgent', tag_type=6");
$tagUrgent = (int) $db->insert_id;

$sdir = sys_get_temp_dir() . '/sag_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sid = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
$agentPost  = fn (array $f) => sec_web($base, 'POST', '/agent/post.php', $sid, $f + ['csrf_token' => 'csrftok12345678'], []);
$adminPost  = fn (array $f, string $referer) => sec_web($base, 'POST', '/admin/post.php', $sid, $f + ['csrf_token' => 'csrftok12345678'], [], ["Referer: $base/admin/$referer"]);
$adminGet   = fn (string $qs, string $referer) => sec_web($base, 'GET', "/admin/post.php?$qs", $sid, [], [], ["Referer: $base/admin/$referer"]);
$tk = fn (int $id) => mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM tickets WHERE ticket_id = $id"));

echo "\n--- #17: per-client SLA policy override ---\n";
[$c] = $adminPost([
    'add_sla_policy' => '1', 'policy_name' => 'sag-test-Default', 'policy_calendar_id' => '0', 'policy_client_id' => '0',
    'policy_low_response' => '500', 'policy_low_resolution' => '1000', 'policy_medium_response' => '', 'policy_medium_resolution' => '',
    'policy_high_response' => '', 'policy_high_resolution' => '', 'policy_is_default' => '1',
], 'sla_policies.php');
$ok($c === 200 || $c === 302, 'add_sla_policy (company-wide default) accepted');
$policyDefault = (int) $one("SELECT policy_id FROM sla_policies WHERE policy_name = 'sag-test-Default'");
$ok($policyDefault > 0 && (int) $one("SELECT policy_client_id FROM sla_policies WHERE policy_id = $policyDefault") === 0, 'the default policy has no client_id (NULL/0, i.e. company-wide)');

// (no policy_is_default key at all here: like a real unchecked HTML checkbox, the field
// must be ABSENT from the POST to mean "not default" - the handler reads isset(), not the value.)
[$c] = $adminPost([
    'add_sla_policy' => '1', 'policy_name' => 'sag-test-ClientA', 'policy_calendar_id' => '0', 'policy_client_id' => (string) $clientA,
    'policy_low_response' => '15', 'policy_low_resolution' => '30', 'policy_medium_response' => '', 'policy_medium_resolution' => '',
    'policy_high_response' => '', 'policy_high_resolution' => '',
], 'sla_policies.php');
$ok($c === 200 || $c === 302, 'add_sla_policy (ClientA override) accepted');
$policyClientA = (int) $one("SELECT policy_id FROM sla_policies WHERE policy_name = 'sag-test-ClientA'");
$ok($policyClientA > 0 && (int) $one("SELECT policy_client_id FROM sla_policies WHERE policy_id = $policyClientA") === $clientA, 'the ClientA override is stored with the right policy_client_id');

[$c, $body] = sec_web($base, 'GET', '/admin/sla_policies.php', $sid);
$ok($c === 200 && str_contains($body, 'sag-test-ClientA') && preg_match('/sag-test-ClientA<\/a>.*?sag-test-ClientA/s', $body), 'the listing shows the policy name and the client scope column names ClientA');
$ok(str_contains($body, 'Company-wide'), 'the default policy shows as Company-wide in the client column');

// ClientA ticket -> should resolve to the ClientA-scoped policy, not the company-wide default.
[$c] = $agentPost(['add_ticket' => '1', 'client_id' => (string) $clientA, 'contact_id' => (string) $contactA, 'subject' => 'sag-test-TicketA', 'details' => 'x', 'priority' => 'Low', 'assigned_to' => '0', 'billable' => '0', 'category_id' => '0']);
$ok($c === 200 || $c === 302, 'add_ticket for ClientA accepted');
$ticketA = (int) $one("SELECT ticket_id FROM tickets WHERE ticket_subject = 'sag-test-TicketA'");
$ok($ticketA > 0 && (int) $tk($ticketA)['ticket_sla_policy_id'] === $policyClientA, 'ClientA ticket resolves to the ClientA-scoped policy, not the company default');

// ClientB ticket (no override of its own) -> should still fall back to the company-wide default.
[$c] = $agentPost(['add_ticket' => '1', 'client_id' => (string) $clientB, 'contact_id' => (string) $contactB, 'subject' => 'sag-test-TicketB', 'details' => 'x', 'priority' => 'Low', 'assigned_to' => '0', 'billable' => '0', 'category_id' => '0']);
$ok($c === 200 || $c === 302, 'add_ticket for ClientB accepted');
$ticketB = (int) $one("SELECT ticket_id FROM tickets WHERE ticket_subject = 'sag-test-TicketB'");
$ok($ticketB > 0 && (int) $tk($ticketB)['ticket_sla_policy_id'] === $policyDefault, "ClientB ticket falls back to the company-wide default - ClientA's override does not leak to other clients");

// Editing the ClientA policy must not drop its client scope (the edit form must round-trip policy_client_id).
[$c] = $adminPost([
    'edit_sla_policy' => '1', 'policy_id' => (string) $policyClientA, 'policy_name' => 'sag-test-ClientA', 'policy_calendar_id' => '0', 'policy_client_id' => (string) $clientA,
    'policy_low_response' => '10', 'policy_low_resolution' => '20', 'policy_medium_response' => '', 'policy_medium_resolution' => '',
    'policy_high_response' => '', 'policy_high_resolution' => '',
], 'sla_policies.php');
$ok($c === 200 || $c === 302, 'edit_sla_policy accepted');
$ok((int) $one("SELECT policy_client_id FROM sla_policies WHERE policy_id = $policyClientA") === $clientA && (int) $one("SELECT policy_low_response FROM sla_policies WHERE policy_id = $policyClientA") === 10, 'editing the policy keeps its client scope and updates its targets');
[$c, $body] = sec_web($base, 'GET', "/admin/modals/sla/policy_edit.php?id=$policyClientA", $sid);
// The ajax-modal response is JSON-escaped HTML (quotes are backslash-escaped in the raw body);
// stripslashes() is enough to match attributes without needing a full JSON decode here.
$ok($c === 200 && preg_match('/<option value="' . $clientA . '"[^>]*selected/', stripslashes($body)), 'the edit modal pre-selects ClientA in the Client picker');

echo "\n--- #17: SLA breach feeds the automation engine end-to-end (client-scoped policy included) ---\n";
$q("INSERT INTO ticket_automation_rules SET rule_name='sag-test-BreachAlert', rule_enabled=1, rule_trigger='schedule',
    rule_cond_field='sla_response_breached', rule_cond_op='equals', rule_cond_value='1',
    rule_action='add_note', rule_action_value='SLA response breached', rule_order=0");
// Force TicketA's response due date into the past with no first response yet, so cron's SLA-breach computation marks it breached.
$q("UPDATE tickets SET ticket_sla_response_due = DATE_SUB(NOW(), INTERVAL 1 HOUR), ticket_first_response_at = NULL WHERE ticket_id = $ticketA");
$q("UPDATE settings SET config_enable_cron = 1 WHERE company_id = 1");
sec_sh('cd ' . escapeshellarg("$root/cron") . ' && RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=' . escapeshellarg((string) getenv('RIVETMSP_REDIS_PORT')) . ' RIVETMSP_REDIS_ENV_FILE=/dev/null php cron.php </dev/null 2>&1');
$q("UPDATE settings SET config_enable_cron = 0 WHERE company_id = 1");
$noteCount = (int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $ticketA AND ticket_reply_type = 'Automation' AND ticket_reply LIKE '%SLA response breached%'");
$ok($noteCount > 0, 'the schedule-trigger rule fired on the breached ClientA ticket (sla_response_breached condition -> add_note action) via a real cron run');
$ok((int) $one("SELECT COUNT(*) FROM ticket_automation_runs WHERE rule_id = (SELECT rule_id FROM ticket_automation_rules WHERE rule_name = 'sag-test-BreachAlert')") > 0, 'the run is recorded in the automation run log');

echo "\n--- #18: 'ticket_updated' trigger + 'add_tag' action ---\n";
// Admin validation must accept ticket_updated with EMPTY conditions (same "match every edit" convention as ticket_created).
[$c] = $adminPost(['add_rule' => '1', 'rule_name' => 'sag-test-TagOnUpdate', 'rule_trigger' => 'ticket_updated', 'rule_order' => '0',
    'action_name' => ['add_tag'], 'action_value' => [(string) $tagUrgent]], 'ticket_automation.php');
$ok($c === 200 || $c === 302, 'add_rule (ticket_updated, no conditions) is accepted, not rejected for missing conditions');
$ruleTag = (int) $one("SELECT rule_id FROM ticket_automation_rules WHERE rule_name = 'sag-test-TagOnUpdate'");
$ok($ruleTag > 0 && (string) $one("SELECT rule_trigger FROM ticket_automation_rules WHERE rule_id = $ruleTag") === 'ticket_updated', 'the rule is stored with rule_trigger = ticket_updated');

// Edit TicketB (plain agent edit_ticket save) and confirm the tag gets added via the new dispatch.
$tb = $tk($ticketB);
[$c] = $agentPost(['edit_ticket' => '1', 'ticket_id' => (string) $ticketB, 'contact_id' => (string) $contactB, 'contact_notify' => '0',
    'category_id' => (string) $tb['ticket_category'], 'subject' => $tb['ticket_subject'], 'billable' => '0', 'priority' => $tb['ticket_priority'],
    'details' => $tb['ticket_details'], 'vendor_ticket_number' => '', 'vendor_id' => '0', 'asset_id' => '0', 'location_id' => '0', 'project_id' => '0', 'assigned_to' => '0']);
$ok($c === 200 || $c === 302, 'edit_ticket accepted');
$ok((int) $one("SELECT COUNT(*) FROM ticket_tags WHERE ticket_tag_ticket_id = $ticketB AND ticket_tag_tag_id = $tagUrgent") === 1, 'editing the ticket ran the ticket_updated automation and added the tag');
$ok((int) $one("SELECT COUNT(*) FROM ticket_automation_runs WHERE rule_id = $ruleTag") === 1, 'exactly one ticket_updated run is logged for this edit');

// Re-editing again must not error on the duplicate tag (ticket_tags PK is ticket_id+tag_id; add_tag uses INSERT IGNORE).
[$c] = $agentPost(['edit_ticket' => '1', 'ticket_id' => (string) $ticketB, 'contact_id' => (string) $contactB, 'contact_notify' => '0',
    'category_id' => (string) $tb['ticket_category'], 'subject' => $tb['ticket_subject'], 'billable' => '0', 'priority' => $tb['ticket_priority'],
    'details' => $tb['ticket_details'], 'vendor_ticket_number' => '', 'vendor_id' => '0', 'asset_id' => '0', 'location_id' => '0', 'project_id' => '0', 'assigned_to' => '0']);
$ok($c === 200 || $c === 302, 'editing again (tag already present) does not error');
$ok((int) $one("SELECT COUNT(*) FROM ticket_tags WHERE ticket_tag_ticket_id = $ticketB AND ticket_tag_tag_id = $tagUrgent") === 1, 'still exactly one tag row - add_tag is idempotent');

echo "\n--- #18: 'set_category' action, gated by a real condition ---\n";
[$c] = $adminPost(['add_rule' => '1', 'rule_name' => 'sag-test-CategoryOnHigh', 'rule_trigger' => 'ticket_updated', 'rule_order' => '0',
    'cond_field' => ['priority'], 'cond_op' => ['equals'], 'cond_value' => ['high'],
    'action_name' => ['set_category'], 'action_value' => [(string) $catB]], 'ticket_automation.php');
$ok($c === 200 || $c === 302, 'add_rule (ticket_updated, priority=high -> set_category) accepted');

// Create a fresh ticket in CatA/low priority, then edit it to high priority: the rule should now fire and move it to CatB.
[$c] = $agentPost(['add_ticket' => '1', 'client_id' => (string) $clientB, 'contact_id' => (string) $contactB, 'subject' => 'sag-test-TicketC', 'details' => 'x', 'priority' => 'Low', 'assigned_to' => '0', 'billable' => '0', 'category_id' => (string) $catA]);
$ticketC = (int) $one("SELECT ticket_id FROM tickets WHERE ticket_subject = 'sag-test-TicketC'");
$ok($ticketC > 0 && (int) $tk($ticketC)['ticket_category'] === $catA, 'TicketC starts in CatA');

// Edit #1: still Low priority -> condition does not match -> category must NOT change.
[$c] = $agentPost(['edit_ticket' => '1', 'ticket_id' => (string) $ticketC, 'contact_id' => (string) $contactB, 'contact_notify' => '0',
    'category_id' => (string) $catA, 'subject' => 'sag-test-TicketC', 'billable' => '0', 'priority' => 'Low', 'details' => 'x',
    'vendor_ticket_number' => '', 'vendor_id' => '0', 'asset_id' => '0', 'location_id' => '0', 'project_id' => '0', 'assigned_to' => '0']);
$ok((int) $tk($ticketC)['ticket_category'] === $catA, 'editing with priority still Low leaves the category alone (condition correctly gates the action)');

// Edit #2: bump to High priority -> condition matches -> category moves to CatB.
[$c] = $agentPost(['edit_ticket' => '1', 'ticket_id' => (string) $ticketC, 'contact_id' => (string) $contactB, 'contact_notify' => '0',
    'category_id' => (string) $catA, 'subject' => 'sag-test-TicketC', 'billable' => '0', 'priority' => 'High', 'details' => 'x',
    'vendor_ticket_number' => '', 'vendor_id' => '0', 'asset_id' => '0', 'location_id' => '0', 'project_id' => '0', 'assigned_to' => '0']);
$ok((int) $tk($ticketC)['ticket_category'] === $catB, 'editing with priority=High fires the rule and moves the ticket to CatB');

echo "\n--- #18: rule list rendering ---\n";
[$c, $body] = sec_web($base, 'GET', '/admin/ticket_automation.php', $sid);
$ok($c === 200 && str_contains($body, 'Ticket updated'), 'the rule list shows the "Ticket updated" trigger label');
$ok(str_contains($body, 'sag-test-urgent'), 'the add_tag action value is resolved to the tag name, not a raw ID');
$ok(str_contains($body, 'sag-test-CatB'), 'the set_category action value is resolved to the category name, not a raw ID');

[, $log] = sec_sh('cat ' . escapeshellarg(sys_get_temp_dir()) . '/sec_server_*.log 2>/dev/null');
$ok(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $log) || str_contains($log, 'Undefined array key "HTTP_USER_AGENT"'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');
