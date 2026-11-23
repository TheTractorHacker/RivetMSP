<?php
// MSP-DS-167 (rest): assign_to / escalate automation actions and the default-technician fallback use the same eligibility as dispatch.
// Run: SCRATCH_TABLES="users user_roles user_role_permissions modules user_client_permissions tickets categories" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_167.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
// Minimal stand-ins for app helpers the engine calls; the code under test is the REAL automationExecuteAction / ticketAssigneeEligible
function logAction(...$a) {} function appNotify(...$a) {}
function getFieldById($t, $id, $f) { global $mysqli; $r = $mysqli->query("SELECT `$f` FROM `$t` WHERE " . ($t === 'tickets' ? 'ticket_id' : 'id') . " = " . intval($id)); $row = $r ? $r->fetch_row() : null; return $row ? $row[0] : null; }
require __DIR__ . '/../includes/dispatch_functions.php';
require __DIR__ . '/../includes/automation_functions.php';
foreach (['users', 'user_roles', 'user_role_permissions', 'modules', 'user_client_permissions', 'tickets'] as $t) { $mysqli->query("DELETE FROM $t"); }
$mysqli->query("INSERT INTO modules (module_id, module_name) VALUES (1,'module_support'),(2,'module_financial')");
$mysqli->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (1,'Admin',1),(2,'Tech',0),(3,'Accountant',0)");
$mysqli->query("INSERT INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) VALUES (2,1,2),(3,2,3)");
// 10 admin, 11 tech restricted to client 100, 12 accountant, 13 unrestricted tech, 14 archived tech
$mysqli->query("INSERT INTO users (user_id, user_name, user_email, user_type, user_status, user_role_id, user_archived_at) VALUES (10,'ad','a@x.t',1,1,1,NULL),(11,'tr','t@x.t',1,1,2,NULL),(12,'ac','c@x.t',1,1,3,NULL),(13,'tu','u@x.t',1,1,2,NULL),(14,'ar','r@x.t',1,1,2,NOW())");
$mysqli->query("INSERT INTO user_client_permissions (user_id, client_id) VALUES (11,100)");
$mysqli->query("INSERT INTO tickets (ticket_id, ticket_client_id, ticket_assigned_to, ticket_priority) VALUES (1,200,0,'low'),(2,100,0,'low')");

$ok('admin eligible for any client', ticketAssigneeEligible($mysqli, 10, 200));
$ok('unrestricted tech eligible', ticketAssigneeEligible($mysqli, 13, 200));
$ok('restricted tech eligible for own client', ticketAssigneeEligible($mysqli, 11, 100));
$ok('restricted tech NOT eligible for another client', !ticketAssigneeEligible($mysqli, 11, 200));
$ok('restricted tech NOT eligible for no-client ticket', !ticketAssigneeEligible($mysqli, 11, 0));
$ok('accountant (no module_support) NOT eligible', !ticketAssigneeEligible($mysqli, 12, 200));
$ok('archived tech NOT eligible', !ticketAssigneeEligible($mysqli, 14, 200));
$ok('unknown user NOT eligible', !ticketAssigneeEligible($mysqli, 999, 200));

$assigned = function ($tid) use ($mysqli) { return intval($mysqli->query("SELECT ticket_assigned_to FROM tickets WHERE ticket_id = $tid")->fetch_row()[0]); };
$rule = ['rule_name' => 'r', 'rule_id' => 1];
$ctx = ['tid' => 1, 'client_id' => 200];
automationExecuteAction($mysqli, ['action' => 'assign_to', 'value' => '11'], $ctx, $rule);
$ok('assign_to restricted-away tech: ticket stays unassigned', $assigned(1) === 0);
automationExecuteAction($mysqli, ['action' => 'assign_to', 'value' => '12'], $ctx, $rule);
$ok('assign_to accountant: ticket stays unassigned', $assigned(1) === 0);
automationExecuteAction($mysqli, ['action' => 'assign_to', 'value' => '13'], $ctx, $rule);
$ok('assign_to eligible tech works', $assigned(1) === 13);
$ctx = ['tid' => 2, 'client_id' => 100];
automationExecuteAction($mysqli, ['action' => 'assign_to', 'value' => '11'], $ctx, $rule);
$ok('assign_to restricted tech on their own client works', $assigned(2) === 11);
$mysqli->query("UPDATE tickets SET ticket_assigned_to = 0 WHERE ticket_id = 1");
$ctx = ['tid' => 1, 'client_id' => 200];
automationExecuteAction($mysqli, ['action' => 'escalate', 'value' => '11:high'], $ctx, $rule);
$pri = $mysqli->query("SELECT ticket_priority FROM tickets WHERE ticket_id = 1")->fetch_row()[0];
$ok('escalate to ineligible tech drops the assignee but still bumps priority', $assigned(1) === 0 && $pri === 'high');
automationExecuteAction($mysqli, ['action' => 'escalate', 'value' => '10:critical'], $ctx, $rule);
$ok('escalate to an admin works', $assigned(1) === 10);

// default-technician fallback: the real resolveTicketAssignee() from functions.php
$f = file_get_contents(__DIR__ . '/../functions.php'); $i = strpos($f, 'function resolveTicketAssignee');
$src = substr($f, $i, strpos($f, "\n}\n", $i) - $i + 2);
$src = str_replace("__DIR__ . '/includes/dispatch_functions.php'", "'" . realpath(__DIR__ . '/../includes') . "/dispatch_functions.php'", $src);
eval($src);
$config_ticket_default_technician_id = 11;
$ok('explicit assignee always wins', resolveTicketAssignee(13, 200) === 13);
$ok('default tech restricted away from the client: unassigned', resolveTicketAssignee(0, 200) === 0);
$ok('default tech allowed for their own client', resolveTicketAssignee(0, 100) === 11);
$ok('restricted default tech is not used when the client is unknown', resolveTicketAssignee(0) === 0);
$config_ticket_default_technician_id = 13;
$ok('unrestricted default tech used for any client / unknown client', resolveTicketAssignee(0, 200) === 13 && resolveTicketAssignee(0) === 13);
$config_ticket_default_technician_id = 0;
$ok('no default tech configured: 0', resolveTicketAssignee(0, 100) === 0);
exit($fail ? 1 : 0);
