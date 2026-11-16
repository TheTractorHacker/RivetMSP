<?php
// MSP-DS-23: the Comet polling fallback does not recreate/close a ticket on every run. Run: SCRATCH_TABLES="comet_backup_alerts comet_client_map settings tickets ticket_replies" tests/lib/with_scratch_db.sh php tests/msp_ds_23_comet_poll_idempotent.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$src = file_get_contents(__DIR__ . '/../includes/comet.php');
preg_match('/^function comet_process_job\(.*?^}\n/ms', $src, $m); eval($m[0]);
define('COMET_JOB_FAILED_WARNING', 7001);
function comet_resolve_device_name($u, $d) { return 'LAPTOP-1'; }
function comet_is_failed($s) { return $s >= 7000; }
function comet_is_success($s) { return $s === 5000; }
function comet_status_label($s) { return 'Error'; }
function sanitizeInput($s) { global $mysqli; return $mysqli->real_escape_string($s); }
function resolveTicketAssignee($x) { return 0; }
function resolveTicketStatusId($x) { return 4; }
function appNotify(...$a) {}
$mysqli->query("INSERT INTO settings SET company_id = 1, config_ticket_prefix = 'TST', config_ticket_next_number = 1");
$count = fn() => (int) $mysqli->query("SELECT COUNT(*) FROM tickets")->fetch_row()[0];
$fail_job = ['Status' => 7002, 'Username' => 'acme', 'DeviceID' => 'd1', 'Classification' => 4001, 'EndTime' => time() - 7200];
$succ_job = ['Status' => 5000, 'Username' => 'acme', 'DeviceID' => 'd1', 'Classification' => 4001, 'EndTime' => time() - 3600];
// the cron poll: same list, oldest first, three runs
$list = [$fail_job, $succ_job];
$results = [];
for ($run = 1; $run <= 3; $run++) { foreach ($list as $j) { $results[$run][] = comet_process_job($j)['action']; } }
$ok('run 1: failure opens a ticket then the success closes it', $results[1] === ['ticket_created', 'ticket_resolved']);
$ok('run 2 does nothing new', $results[2] === ['stale_job', 'stale_job'] || $results[2] === ['stale_job', 'no_open_alert']);
$ok('run 3 does nothing new', $results[3] === ['stale_job', 'stale_job'] || $results[3] === ['stale_job', 'no_open_alert']);
$ok('exactly one ticket after three polls (was 3)', $count() === 1);
// a genuinely new failure after the resolution still opens a ticket
$mysqli->query("UPDATE comet_backup_alerts SET alert_resolved_at = FROM_UNIXTIME(UNIX_TIMESTAMP() - 1800)");
$new_fail = $fail_job; $new_fail['EndTime'] = time() - 60;
$ok('a newer failure opens a new ticket', comet_process_job($new_fail)['action'] === 'ticket_created' && $count() === 2);
$ok('the same failure polled again is not duplicated', comet_process_job($new_fail)['action'] !== 'ticket_created' && $count() === 2);
$ok('an older success does not close the newer open alert', comet_process_job($succ_job)['action'] === 'stale_job');
$cron = file_get_contents(__DIR__ . '/../cron/cron.php');
$ok('cron sorts the polled jobs oldest first', str_contains($cron, 'usort($recent_jobs'));
exit($fail ? 1 : 0);
