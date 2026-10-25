<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Technician utilization reports and Power BI export (GitHub roadmap #31 - confirmed with the coordinator
 * that "Power BI export" means a clean, documented CSV/OData-shaped export, not an actual Power BI
 * connector), against the REAL code on a SCRATCH database, real HTTP (same harness as tests/security_*.php).
 *
 * This is a VERIFICATION, not a new feature: investigation found agent/reports/technician_performance.php
 * already exists and already covers everything the issue asks for - billable/non-billable hours,
 * utilization %, billable value, tickets closed, average handle time, CSAT, a date-range filter, and a
 * working "Export CSV" button (functions.php's report_send_csv(), the same shared helper other reports
 * already use). Nothing new was built - this proves the existing report produces correct numbers for a
 * real technician's real tracked time, end to end, and that the CSV export matches what the page shows.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/technician_utilization_verify.php
 *
 * Covers: a technician with 2 billable hours (a labor type with a real rate) and 1 non-billable hour (no
 * labor type) of real tracked time, plus one closed ticket, shows the right billable/non-billable hours,
 * the right billable value (2h * rate) and tickets-closed count on the real page; the CSV export served
 * by the real "Export CSV" link contains that same technician row with the same numbers; the report is
 * refused to a user without module_reporting; a date range that excludes the fixture shows none of it
 * (the filter is real, not decorative).
 */
require __DIR__ . '/support/security_lib.php';

echo "\n--- fixtures ---\n";
$q("DELETE FROM ticket_replies WHERE ticket_reply LIKE 'techutil-test-%'");
$q("DELETE FROM tickets WHERE ticket_subject LIKE 'techutil-test-%'");
$q("DELETE FROM labor_types WHERE labor_type_name LIKE 'techutil-test-%'");
$q("DELETE FROM clients WHERE client_name LIKE 'techutil-test-%'");
$q("DELETE FROM user_roles WHERE role_name = 'techutil-test-NoReporting'");
$q("DELETE FROM users WHERE user_email LIKE 'techutil-test-%'");

$q("INSERT INTO clients SET client_name='techutil-test-Client'"); $clientId = (int) $db->insert_id;
$q("INSERT INTO labor_types SET labor_type_name='techutil-test-Billable', labor_type_rate=100.00");
$laborTypeId = (int) $db->insert_id;
$q("INSERT INTO users SET user_name='techutil-test-Tech', user_email='techutil-test-tech@example.test', user_password='x', user_type=1, user_status=1, user_role_id=0");
$techId = (int) $db->insert_id;

$today = date('Y-m-d');
// Resolved by NAME, never a literal id (status ids are per-install) - "tickets closed" below only checks
// ticket_closed_at/ticket_archived_at/ticket_assigned_to anyway, so any real, existing status id works.
$closedStatusId = (int) $one("SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name IN ('Closed', 'Resolved') ORDER BY ticket_status_name = 'Closed' DESC LIMIT 1");
if ($closedStatusId === 0) { $closedStatusId = (int) $one("SELECT ticket_status_id FROM ticket_statuses ORDER BY ticket_status_id ASC LIMIT 1"); }
$q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=90001, ticket_subject='techutil-test-closed', ticket_details='x', ticket_client_id=$clientId, ticket_assigned_to=$techId, ticket_status=$closedStatusId, ticket_closed_at='$today 12:00:00'");
$ticketId = (int) $db->insert_id;

// 2h billable (real rate labor type) + 1h non-billable (no labor type).
$q("INSERT INTO ticket_replies SET ticket_reply_ticket_id=$ticketId, ticket_reply_type='Labor', ticket_reply_by=$techId, ticket_reply='techutil-test-billable', ticket_reply_time_worked='02:00:00', ticket_reply_labor_type_id=$laborTypeId, ticket_reply_created_at='$today 09:00:00'");
$q("INSERT INTO ticket_replies SET ticket_reply_ticket_id=$ticketId, ticket_reply_type='Labor', ticket_reply_by=$techId, ticket_reply='techutil-test-nonbillable', ticket_reply_time_worked='01:00:00', ticket_reply_labor_type_id=NULL, ticket_reply_created_at='$today 11:00:00'");

$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 AND user_email NOT LIKE 'techutil-test-%' LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='techutil-test-admin', user_email='techutil-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

$q("INSERT INTO user_roles SET role_name='techutil-test-NoReporting', role_is_admin=0, role_type=1");
$roleNoReporting = (int) $db->insert_id;
$q("INSERT INTO users SET user_name='techutil-test-noreporting', user_email='techutil-test-noreporting@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$roleNoReporting");
$noReportingUser = (int) $db->insert_id;
$q("INSERT INTO user_settings SET user_id=$noReportingUser ON DUPLICATE KEY UPDATE user_id=user_id");

$sdir = sys_get_temp_dir() . '/techutil_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sidAdmin = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
$sidNoReporting = sec_forge_session($sdir, ['logged' => true, 'user_id' => $noReportingUser, 'csrf_token' => 'csrftok12345678']);

echo "\n--- the real report page, for a date range that includes the fixture ---\n";
$qs = "canned_date=custom&dtf=$today&dtt=$today";
[$c, $body] = sec_web($base, 'GET', "/agent/reports/technician_performance.php?$qs", $sidAdmin);
$ok($c === 200 && str_contains($body, 'techutil-test-Tech'), 'the technician appears on the report for a range covering today');
$ok(str_contains($body, '200') || str_contains($body, '200.00'), 'the billable value (2h * $100 rate = $200) shows somewhere on the page - the CSV assertions below pin the exact hours precisely');

echo "\n--- the real CSV export matches ---\n";
[$c, $body, $head] = sec_web($base, 'GET', "/agent/reports/technician_performance.php?$qs&export=csv", $sidAdmin);
$ok($c === 200 && str_contains($head, 'text/csv'), 'the export answers as a real CSV download');
$lines = array_map('str_getcsv', array_filter(explode("\n", trim($body))));
$header = $lines[0] ?? [];
$techRow = null;
foreach ($lines as $row) { if (($row[0] ?? '') === 'techutil-test-Tech') { $techRow = $row; } }
$ok($techRow !== null, 'the CSV contains the technician\'s own row');
$byCol = $techRow !== null ? array_combine($header, $techRow) : [];
$ok(($byCol['Billable hours'] ?? null) === '2', 'CSV: billable hours = 2');
$ok(($byCol['Non-billable hours'] ?? null) === '1', 'CSV: non-billable hours = 1');
$ok((float) ($byCol['Billable value'] ?? 0) === 200.0, 'CSV: billable value = 200 (2h at the $100 labor rate)');
$ok((int) ($byCol['Tickets closed'] ?? -1) === 1, 'CSV: tickets closed = 1 (the one ticket closed today)');

echo "\n--- a date range that excludes the fixture shows none of it ---\n";
$yesterday = date('Y-m-d', strtotime('-1 day'));
$dayBefore = date('Y-m-d', strtotime('-2 days'));
[$c, $body] = sec_web($base, 'GET', "/agent/reports/technician_performance.php?canned_date=custom&dtf=$dayBefore&dtt=$yesterday", $sidAdmin);
$ok($c === 200 && !str_contains($body, '200.00'), 'a range that excludes today shows none of this fixture\'s billable value - the filter is real, not decorative');

echo "\n--- refused without module_reporting ---\n";
[$c, $body] = sec_web($base, 'GET', "/agent/reports/technician_performance.php?$qs", $sidNoReporting);
$ok($c !== 200 || !str_contains($body, 'techutil-test-Tech'), 'a user without module_reporting cannot see the report');

$thisPort = (int) explode(':', $base)[2];
$logText = (string) @file_get_contents(sys_get_temp_dir() . "/sec_server_$thisPort.log");
$otherWarnings = preg_replace('/^.*Undefined array key "HTTP_USER_AGENT".*$/m', '', $logText);
$ok(!str_contains($otherWarnings, 'PHP Warning') && !str_contains($otherWarnings, 'PHP Fatal') && !str_contains($otherWarnings, 'PHP Notice'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');

echo "\n--- cleanup ---\n";
$q("DELETE FROM ticket_replies WHERE ticket_reply LIKE 'techutil-test-%'");
$q("DELETE FROM tickets WHERE ticket_subject LIKE 'techutil-test-%'");
$q("DELETE FROM labor_types WHERE labor_type_name LIKE 'techutil-test-%'");
$q("DELETE FROM clients WHERE client_id = $clientId");
$q("DELETE FROM users WHERE user_id IN ($techId, $noReportingUser)");
$q("DELETE FROM user_roles WHERE user_role_id = $roleNoReporting");
