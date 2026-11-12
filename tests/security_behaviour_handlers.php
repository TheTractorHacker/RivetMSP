<?php
declare(strict_types=1);
// Behavioural proof (scratch DB, real handler files run in a child process as a client-restricted technician) for MSP-INJ-5, 6, 7, 8.
//   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=rivetmsp_scratch_x RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... php tests/security_behaviour_handlers.php   (DB loaded from db.sql)
// Deletes roles/users/clients/projects/tickets/expenses/transfers/worksheets in that scratch database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', (string) getenv('RIVETMSP_TEST_DB_USER'), (string) getenv('RIVETMSP_TEST_DB_PASS'), (string) getenv('RIVETMSP_TEST_DB_NAME'));
$q = function (string $s) use ($m) { $r = $m->query($s); if ($r === false) { fwrite(STDERR, $m->error . "\n" . $s . "\n"); exit(3); } return $r; };
$one = fn (string $s) => ($r = $q($s)->fetch_row()) ? $r[0] : null;
$q("SET SESSION sql_mode=''");
foreach (['users', 'user_roles', 'user_client_permissions', 'modules', 'user_role_permissions', 'clients', 'projects', 'tickets', 'expenses', 'revenues', 'transfers', 'ticket_worksheets', 'ticket_worksheet_responses', 'contacts', 'locations', 'logs'] as $t) { $q("DELETE FROM `$t`"); }
$q("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (91,'Adm',1),(92,'Tech',0)");
$mods = ['module_support', 'module_client', 'module_financial', 'module_sales'];
foreach ($mods as $i => $mn) { $q("INSERT INTO modules (module_id, module_name) VALUES (" . (950 + $i) . ", '$mn')"); $q("INSERT INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) VALUES (92," . (950 + $i) . ",3)"); }
foreach ([[1, 91], [2, 92], [3, 92]] as [$id, $r]) { $q("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status,user_token,user_auth_method,user_created_at) VALUES ($id,'u$id','u$id@x.test','x',$r,1,1,'','local','2020-01-01')"); }
$q("INSERT INTO user_client_permissions (user_id, client_id) VALUES (3, 1)");   // user 3 = technician restricted to client 1; user 2 = unrestricted technician; user 1 = admin
$q("INSERT INTO clients (client_id, client_name, client_type) VALUES (1,'Client One',''),(2,'Client Two Victim','')");
$q("INSERT INTO projects (project_id, project_name, project_client_id, project_prefix, project_number) VALUES (11,'P1',1,'P',1),(22,'P2',2,'P',2)");
$q("INSERT INTO tickets (ticket_id, ticket_client_id, ticket_subject, ticket_prefix, ticket_number, ticket_project_id) VALUES (101,1,'T1','T',1,0),(202,2,'T2','T',2,0)");
$q("INSERT INTO expenses (expense_id, expense_client_id, expense_amount, expense_date, expense_account_id) VALUES (301,1,10,'2026-01-01',1),(302,2,500,'2026-01-02',1)");
$q("INSERT INTO revenues (revenue_id, revenue_amount, revenue_date, revenue_account_id) VALUES (401,5,'2026-01-01',1)");
$q("INSERT INTO transfers (transfer_id, transfer_expense_id, transfer_revenue_id, transfer_method) VALUES (501, 301, 401, 'orig')");
$q("INSERT INTO ticket_worksheets (worksheet_id, worksheet_ticket_id, worksheet_template_id, worksheet_signed_at) VALUES (601,101,1,NULL),(602,202,1,NULL),(603,202,1,NOW())");

$run = function (string $handler, int $user, array $post = [], array $get = []): string {
    $cmd = [PHP_BINARY, __DIR__ . '/lib/run_post_handler.php', json_encode(['handler' => $handler, 'user_id' => $user, 'post' => $post, 'get' => $get])];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p);
    return $out;
};
$fail = 0;
$ok = function (string $n, bool $c) use (&$fail): void { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };

// INJ-6
$run('project.php', 3, ['edit_project' => 1, 'project_id' => 22, 'name' => 'HACKED', 'description' => '', 'due_date' => '', 'project_manager' => 0, 'client_id' => 1]);
$ok('INJ-6: restricted tech cannot rename or pull another client\'s project into their client', $one("SELECT project_name FROM projects WHERE project_id=22") === 'P2' && (int) $one("SELECT project_client_id FROM projects WHERE project_id=22") === 2);
$run('project.php', 3, ['edit_project' => 1, 'project_id' => 11, 'name' => 'P1x', 'description' => '', 'due_date' => '', 'project_manager' => 0, 'client_id' => 2]);
$ok('INJ-6: restricted tech cannot move their own project into a client they cannot access', (int) $one("SELECT project_client_id FROM projects WHERE project_id=11") === 1);
$run('project.php', 3, ['edit_project' => 1, 'project_id' => 11, 'name' => 'P1renamed', 'description' => '', 'due_date' => '', 'project_manager' => 0, 'client_id' => 1]);
$ok('INJ-6: the legitimate edit of their own project still works', $one("SELECT project_name FROM projects WHERE project_id=11") === 'P1renamed');
$run('project.php', 3, ['link_ticket_to_project' => 1, 'project_id' => 11, 'tickets' => [101, 202]]);
$ok('INJ-6: link_ticket_to_project links their own ticket but refuses another client\'s ticket', (int) $one("SELECT ticket_project_id FROM tickets WHERE ticket_id=101") === 11 && (int) $one("SELECT ticket_project_id FROM tickets WHERE ticket_id=202") === 0);

// INJ-5
$run('transfer.php', 3, ['edit_transfer' => 1, 'transfer_id' => 501, 'expense_id' => 302, 'revenue_id' => 401, 'date' => '2000-01-01', 'amount' => '0.01', 'account_from' => 1, 'account_to' => 1, 'transfer_method' => 'm', 'notes' => 'n']);
$ok('INJ-5: a POSTed expense_id no longer rewrites another client\'s expense', (float) $one("SELECT expense_amount FROM expenses WHERE expense_id=302") === 500.0);
$ok('INJ-5: the transfer\'s own expense is the one updated', (float) $one("SELECT expense_amount FROM expenses WHERE expense_id=301") === 0.01);
$run('transfer.php', 3, ['edit_transfer' => 1, 'transfer_id' => 99999, 'expense_id' => 302, 'revenue_id' => 401, 'date' => '2000-01-01', 'amount' => '0.02', 'account_from' => 1, 'account_to' => 1, 'transfer_method' => 'm', 'notes' => 'n']);
$ok('INJ-5: a nonexistent transfer id changes nothing', (float) $one("SELECT expense_amount FROM expenses WHERE expense_id=302") === 500.0);

// INJ-7
$run('worksheet.php', 3, ['save_worksheet' => 1, 'worksheet_id' => 602, 'ticket_id' => 101]);
$ok('INJ-7: authorising on the technician\'s own ticket no longer lets them complete another client\'s worksheet', $one("SELECT worksheet_completed_at FROM ticket_worksheets WHERE worksheet_id=602") === null);
$run('worksheet.php', 3, [], ['delete_ticket_worksheet' => 602, 'ticket_id' => 101]);
$ok('INJ-7: nor delete it', (int) $one("SELECT COUNT(*) FROM ticket_worksheets WHERE worksheet_id=602") === 1);
$run('worksheet.php', 1, [], ['delete_ticket_worksheet' => 603, 'ticket_id' => 202]);
$ok('INJ-7: even an administrator cannot delete a customer-signed worksheet', (int) $one("SELECT COUNT(*) FROM ticket_worksheets WHERE worksheet_id=603") === 1);
$run('worksheet.php', 2, [], ['delete_ticket_worksheet' => 602, 'ticket_id' => 202]);
$ok('INJ-7: an authorised technician can still delete an unsigned worksheet of an accessible client', (int) $one("SELECT COUNT(*) FROM ticket_worksheets WHERE worksheet_id=602") === 0);

// INJ-8
$csvA = $run('client.php', 3, ['export_clients_csv' => 1]);
$ok('INJ-8: the client export of a restricted tech excludes other clients', str_contains($csvA, 'Client One') && !str_contains($csvA, 'Victim'));
$csvB = $run('client.php', 2, ['export_clients_csv' => 1]);
$ok('INJ-8: an unrestricted tech still gets every client', str_contains($csvB, 'Client One') && str_contains($csvB, 'Victim'));
exit($fail ? 1 : 0);
