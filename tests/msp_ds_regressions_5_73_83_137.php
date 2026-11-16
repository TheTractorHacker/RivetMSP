<?php
// MSP-DS-5 (scratch DB: users' client rows for archived clients survive an edit), MSP-DS-73, MSP-DS-83, MSP-DS-137.
// Run: SCRATCH_TABLES="clients user_client_permissions" tests/lib/with_scratch_db.sh php tests/msp_ds_regressions_5_73_83_137.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
$u = file_get_contents("$root/admin/post/users.php");
$edit = substr($u, strpos($u, 'if (isset($_POST[\'edit_user\']))'));
preg_match('/mysqli_query\(\$mysqli,"(DELETE FROM user_client_permissions WHERE user_id = \$user_id AND client_id IN \(SELECT client_id FROM clients WHERE client_archived_at IS NULL\))"\);/', $edit, $mm);
$ok('MSP-DS-5: edit_user deletes only the rows the form can show', !empty($mm[1]));
$m->query("DELETE FROM clients"); $m->query("DELETE FROM user_client_permissions");
$m->query("INSERT INTO clients (client_id, client_name, client_archived_at) VALUES (5,'Live',NULL),(6,'Gone',NOW())");
$m->query("INSERT INTO user_client_permissions (user_id, client_id) VALUES (7,5),(7,6),(7,99)");   // 99: hard-deleted client
$sql = str_replace('$user_id', '7', $mm[1]); $m->query($sql);
$left = []; $r = $m->query("SELECT client_id FROM user_client_permissions WHERE user_id = 7 ORDER BY client_id"); while ($x = $r->fetch_row()) { $left[] = (int) $x[0]; }
$ok('MSP-DS-5: rows for an archived and a deleted client survive, the visible one is replaced (' . implode(',', $left) . ')', $left === [6, 99]);
$ok('MSP-DS-73: forced-MFA form asks for the current password', str_contains(file_get_contents("$root/agent/user/mfa_enforcement.php"), 'name="current_password"'));
$ok('MSP-DS-83: notifications.php loads api_permissions.php', str_contains(file_get_contents("$root/api/v1/notifications.php"), "includes/api_permissions.php"));
$ok('MSP-DS-83: that include defines api_client_scope_sql', str_contains(file_get_contents("$root/api/v1/includes/api_permissions.php"), 'function api_client_scope_sql'));
$w = file_get_contents("$root/agent/post/worksheet.php");
$ok('MSP-DS-137: worksheetOwnedOrStop skips the client check for client 0', preg_match('/\(int\) \$ws\[\'ticket_client_id\'\] > 0\) \{\s+enforceClientAccess/', $w) === 1);
$ok('MSP-DS-137: add_ticket_worksheet only checks a real client and refuses a missing ticket', str_contains($w, "if (\$client_id > 0) {   // MSP-DS-137") && str_contains($w, "Ticket not found"));
exit($fail ? 1 : 0);
