<?php
// DB 2.6.142 (re-review M6-2, MSP-DS-67; also keeps M5-1): the backfill takes requested_at only from REQUEST events and caps requested_at/rejected_at at client_decided_at, so the
// approved/rejected audit/log rows written a second AFTER the decision cannot make a client-approved change look stale to the real changeClientApprovalSatisfied().
// (shape from M4-1): changes.client_approval_requested_at / client_rejected_at. A fresh db.sql has them; the REAL 2.6.141 -> 2.6.142 step
// in admin/database_updates.php (run from the top of the file up to the Core catch-up) adds them to a `changes` table built from the PREVIOUS db.sql
// (50b1cad78), backfills from audit_events and from the surviving logs rows, never invents history for untouched changes, and is idempotent.
// Run: SCRATCH_TABLES="changes audit_events logs settings" bash tests/lib/with_scratch_db.sh php tests/msp_m6_2.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
$root = dirname(__DIR__);
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$col = fn (string $c) => ($r = $m->query("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'changes' AND column_name = '$c'")) ? $r->fetch_row() : null;
$ok('fresh db.sql has both columns (datetime NULL)', $col('client_approval_requested_at') === ['datetime', 'YES'] && $col('client_rejected_at') === ['datetime', 'YES']);

// the previous release's `changes` table
$old = (string) shell_exec('git -C ' . escapeshellarg($root) . ' show 50b1cad78:db.sql');
$ok('previous db.sql obtained', strlen($old) > 100000);
preg_match('/CREATE TABLE `changes` \(.*?\n\) ENGINE[^\n]*;/s', $old, $mm);
$m->query('DROP TABLE changes');
$m->multi_query($mm[0]); while ($m->more_results() && $m->next_result()) {}
$ok('old changes table loaded without the columns', $m->errno === 0 && $col('client_approval_requested_at') === null && $col('client_rejected_at') === null);

$m->query("DELETE FROM settings");
$m->query("INSERT INTO settings SET company_id = 1, config_current_database_version = '2.6.141'");
$ins = "INSERT INTO changes (change_id, title, client_id, requires_client_approval, client_decision, client_decided_at, status, created_at) VALUES ";
$m->query($ins . "
  (1, 'M5-1: approved, audit approved row +1s', 5, 1, 'approved', '2026-03-06 12:00:00', 'awaiting_approval', '2026-01-01 00:00:00'),
  (7, 'copy-link approved, then reset to pending (audit row)', 5, 1, 'pending', NULL, 'draft', '2026-01-01 00:00:00'),
  (8, 'copy-link approved, then reset to pending (log row only)', 5, 1, 'pending', NULL, 'draft', '2026-01-01 00:00:00'),
  (9, 'rejected, then reset to pending (audit row)', 5, 1, 'pending', NULL, 'draft', '2026-01-01 00:00:00'),
  (10, 'never started', 5, 0, 'pending', NULL, 'draft', '2026-01-01 00:00:00')");
$ev = fn (string $type, int $id, string $at) => $m->query("INSERT INTO audit_events (event_type, entity_type, entity_id, action, created_at) VALUES ('$type', 'change', '$id', 'x', '$at')");
$lg = fn (string $desc, int $id, string $at) => $m->query("INSERT INTO logs (log_type, log_action, log_description, log_entity_id, log_created_at) VALUES ('Support', 'Edit', '" . $m->real_escape_string($desc) . "', $id, '$at')");
$ev('change.client_approved', 1, '2026-03-06 12:00:01');
$ev('change.client_approved', 7, '2026-03-12 09:00:00');
$lg('Acme approved change "x" via guest link', 8, '2026-03-13 09:00:00');
$ev('change.client_rejected', 9, '2026-03-14 09:00:00');
$run = function () use ($root): string {
    $code = '$root = ' . var_export($root, true) . ';'
        . 'mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);'
        . '$mysqli = new mysqli("localhost", getenv("RIVETMSP_TEST_DB_USER"), getenv("RIVETMSP_TEST_DB_PASS"), getenv("RIVETMSP_TEST_DB_NAME"));'
        . '$mysqli->query("SET SESSION sql_mode=\'\'");'
        . 'define("LATEST_DATABASE_VERSION", "2.6.142"); define("CURRENT_DATABASE_VERSION", "2.6.141");'
        . '$src = file_get_contents($root . "/admin/database_updates.php"); $cut = strpos($src, "// Core migrations catch-up");'
        . 'if ($cut === false) { echo "CUT_MARKER_MISSING\n"; exit(3); }'
        . '$tmp = $root . "/admin/.m4_migration_tmp.php"; file_put_contents($tmp, substr($src, 0, $cut)); ob_start(); try { require $tmp; } finally { @unlink($tmp); ob_end_clean(); }'
        . 'echo "MIGRATION_DONE\n";';
    $p = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($p);

    return trim($out);
};
$ok('step runs without error (' . ($out = $run()) . ')', $out === 'MIGRATION_DONE');
$q = fn (int $id) => $m->query("SELECT client_approval_requested_at q, client_rejected_at j FROM changes WHERE change_id = $id")->fetch_assoc();
$ok('step runs without error (' . ($out = $run()) . ')', $out === 'MIGRATION_DONE');
$q = fn (int $id) => $m->query("SELECT client_approval_requested_at q, client_rejected_at j, client_decision d FROM changes WHERE change_id = $id")->fetch_assoc();
$ok('M5-1 still holds: decided at :00 with an approved row at :01 is not stale', $q(1)['q'] !== null && $q(1)['q'] <= '2026-03-06 12:00:00');
$ok('M6-2: copy-link approved then reset to pending (audit row): requested_at is set', $q(7)['q'] === '2026-03-12 09:00:00');
$ok('M6-2: same, evidenced by a log row only', $q(8)['q'] === '2026-03-13 09:00:00');
$ok('M6-2: rejected then reset to pending: requested_at and rejected_at are set', $q(9)['q'] === '2026-03-14 09:00:00' && $q(9)['j'] === '2026-03-14 09:00:00');
$ok('a change nobody ever touched gets nothing invented', $q(10)['q'] === null && $q(10)['j'] === null);
$src = file_get_contents($root . '/functions.php');
preg_match('/\nfunction changeClientApprovalSatisfied\(.*?\n}\n/s', $src, $fm);
preg_match('/\nfunction changeClientApprovalEverStarted\(.*?\n}\n/s', $src, $em);
$ok('real functions found', !empty($fm[0]) && !empty($em[0]));
eval($fm[0]); eval($em[0]);
$mysqli = $m;
$ok('M5-1 change is satisfied', changeClientApprovalSatisfied(1) === true);
foreach ([7, 8, 9] as $id) {
    $ok("M6-2: change $id counts as approval-started, so a non-admin untick is refused by the real guard input", changeClientApprovalEverStarted($id) === true);
}
$ok('never-started change stays freely editable', changeClientApprovalEverStarted(10) === false);
exit($fail);
