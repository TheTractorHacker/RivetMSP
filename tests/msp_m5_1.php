<?php
// DB 2.6.142 (re-review M5-1, MSP-DS-67): the backfill takes requested_at only from REQUEST events and caps requested_at/rejected_at at client_decided_at, so the
// approved/rejected audit/log rows written a second AFTER the decision cannot make a client-approved change look stale to the real changeClientApprovalSatisfied().
// (shape from M4-1): changes.client_approval_requested_at / client_rejected_at. A fresh db.sql has them; the REAL 2.6.141 -> 2.6.142 step
// in admin/database_updates.php (run from the top of the file up to the Core catch-up) adds them to a `changes` table built from the PREVIOUS db.sql
// (50b1cad78), backfills from audit_events and from the surviving logs rows, never invents history for untouched changes, and is idempotent.
// Run: SCRATCH_TABLES="changes audit_events logs settings" bash tests/lib/with_scratch_db.sh php tests/msp_m5_1.php
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
  (1, 'approved, no request row, audit +1s', 5, 1, 'approved', '2026-03-06 12:00:00', 'awaiting_approval', '2026-01-01 00:00:00'),
  (2, 'approved, no request row, log +1s', 5, 1, 'approved', '2026-03-07 12:00:00', 'awaiting_approval', '2026-01-01 00:00:00'),
  (3, 'rejected then approved, reject audit lag', 5, 1, 'approved', '2026-03-09 12:00:00', 'awaiting_approval', '2026-01-01 00:00:00'),
  (4, 'rejected, audit +1s (variant)', 5, 1, 'rejected', '2026-03-08 12:00:00', 'awaiting_approval', '2026-01-01 00:00:00'),
  (5, 'approved with an earlier request', 5, 1, 'approved', '2026-03-10 12:00:00', 'awaiting_approval', '2026-01-01 00:00:00'),
  (6, 'approved, rejection +1s after the approving second', 5, 1, 'approved', '2026-03-11 12:00:00', 'awaiting_approval', '2026-01-01 00:00:00')");
$ev = fn (string $type, int $id, string $at) => $m->query("INSERT INTO audit_events (event_type, entity_type, entity_id, action, created_at) VALUES ('$type', 'change', '$id', 'x', '$at')");
$lg = fn (string $desc, int $id, string $at) => $m->query("INSERT INTO logs (log_type, log_action, log_description, log_entity_id, log_created_at) VALUES ('Support', 'Edit', '" . $m->real_escape_string($desc) . "', $id, '$at')");
$ev('change.client_approved', 1, '2026-03-06 12:00:01');
$lg('Acme approved change "x" via guest link', 2, '2026-03-07 12:00:01');
$ev('change.client_approval_requested', 3, '2026-03-01 08:00:00');
$ev('change.client_rejected', 3, '2026-03-02 08:00:00');
$ev('change.client_approved', 3, '2026-03-09 12:00:01');
$ev('change.client_rejected', 4, '2026-03-08 12:00:01');
$ev('change.client_approval_requested', 5, '2026-03-02 08:00:00');
$ev('change.client_approved', 5, '2026-03-10 12:00:01');
$ev('change.client_rejected', 6, '2026-03-11 12:00:01'); // pathological: lag row at the same second window as the approval
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
$ok('1 audit: requested_at is not later than the decision (' . json_encode($q(1)) . ')', $q(1)['q'] !== null && $q(1)['q'] <= '2026-03-06 12:00:00');
$ok('2 log: requested_at is not later than the decision', $q(2)['q'] !== null && $q(2)['q'] <= '2026-03-07 12:00:00');
$ok('3 requested_at = the request event, rejected_at = earlier rejection', $q(3) === ['q' => '2026-03-01 08:00:00', 'j' => '2026-03-02 08:00:00']);
$ok('4 rejected variant: requested_at and rejected_at <= decision', $q(4)['q'] <= '2026-03-08 12:00:00' && $q(4)['j'] <= '2026-03-08 12:00:00' && $q(4)['q'] !== null && $q(4)['j'] !== null);
$ok('5 earlier request row is kept', $q(5)['q'] === '2026-03-02 08:00:00');
$ok('6 rejected_at capped at the decision', $q(6)['j'] <= '2026-03-11 12:00:00');

// the REAL changeClientApprovalSatisfied(), extracted verbatim from functions.php
$src = file_get_contents($root . '/functions.php');
preg_match('/\nfunction changeClientApprovalSatisfied\(.*?\n}\n/s', $src, $fm);
$ok('real function found', !empty($fm[0]));
eval($fm[0]);
$mysqli = $m;
$ok('1 approved change is satisfied', changeClientApprovalSatisfied(1) === true);
$ok('2 approved change is satisfied', changeClientApprovalSatisfied(2) === true);
$ok('3 approved after an earlier rejection is satisfied', changeClientApprovalSatisfied(3) === true);
$ok('4 rejected change is not satisfied', changeClientApprovalSatisfied(4) === false);
$ok('5 approved change is satisfied', changeClientApprovalSatisfied(5) === true);
$ok('6 approved change is satisfied', changeClientApprovalSatisfied(6) === true);
exit($fail);
