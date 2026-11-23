<?php
// MSP-DS-167: auto-dispatch only picks technicians who can work the ticket (module_support write, client scope).
// Run: SCRATCH_TABLES="users user_roles user_role_permissions modules user_client_permissions tickets categories" tests/lib/with_scratch_db.sh php tests/msp_ds_167_dispatch_eligibility.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
require __DIR__ . '/../includes/dispatch_functions.php';
foreach (['users', 'user_roles', 'user_role_permissions', 'modules', 'user_client_permissions', 'tickets', 'categories'] as $t) { $m->query("DELETE FROM $t"); }
$m->query("INSERT INTO modules (module_id, module_name) VALUES (1,'module_support'),(2,'module_financial')");
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (1,'Admin',1),(2,'Tech',0),(3,'Accountant',0)");
$m->query("INSERT INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) VALUES (2,1,2),(3,2,3)");
// 10 = Admin (busy), 11 = tech restricted to client A(100) with no tickets, 12 = accountant (no support), 13 = unrestricted tech (busy)
$m->query("INSERT INTO users (user_id, user_name, user_email, user_type, user_status, user_role_id) VALUES (10,'ad','a@x.t',1,1,1),(11,'tr','t@x.t',1,1,2),(12,'ac','c@x.t',1,1,3),(13,'tu','u@x.t',1,1,2)");
$m->query("INSERT INTO user_client_permissions (user_id, client_id) VALUES (11,100)");
$m->query("INSERT INTO tickets (ticket_id, ticket_client_id, ticket_category, ticket_assigned_to) VALUES (1,200,0,0),(2,100,0,0),(3,200,0,10),(4,200,0,10),(5,200,0,13)");
$pick = dispatchAssignTicket($m, 1);
$ok("client-B ticket does not go to the idle tech restricted to client A or to the accountant (got $pick)", $pick === 13 || $pick === 10);
$pick2 = dispatchAssignTicket($m, 2);
$ok("client-A ticket may go to the restricted tech with the lightest load (got $pick2)", $pick2 === 11);
$ok('module_support-less role is never picked', !in_array(dispatchAssignTicket($m, 1), [12], true) && !in_array($pick2, [12], true));
exit($fail ? 1 : 0);
