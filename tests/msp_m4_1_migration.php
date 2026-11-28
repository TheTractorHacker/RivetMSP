<?php
// DB 2.6.142 (re-review M4-1, MSP-DS-67): changes.client_approval_requested_at / client_rejected_at. A fresh db.sql has them; the REAL 2.6.141 -> 2.6.142 step
// in admin/database_updates.php (run from the top of the file up to the Core catch-up) adds them to a `changes` table built from the PREVIOUS db.sql
// (50b1cad78), backfills from audit_events and from the surviving logs rows, never invents history for untouched changes, and is idempotent.
// Run: SCRATCH_TABLES="changes audit_events logs settings" bash tests/lib/with_scratch_db.sh php tests/msp_m4_1_migration.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
$root = dirname(__DIR__);
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$col = fn (string $c) => ($r = $m->query("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'changes' AND column_name = '$c'")) ? $r->fetch_row() : null;
$ok('fresh db.sql has both columns (datetime NULL)', $col('client_approval_requested_at') === ['datetime', 'YES'] && $col('client_rejected_at') === ['datetime', 'YES']);
$ok('LATEST_DATABASE_VERSION is at least 2.6.142', preg_match('/"LATEST_DATABASE_VERSION", "([0-9.]+)"/', file_get_contents($root . '/includes/database_version.php'), $lv) === 1 && version_compare($lv[1], '2.6.142', '>='));

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
  (1, 'untouched', 5, 1, 'pending', NULL, 'draft', '2026-01-01 00:00:00'),
  (2, 'audit request', 5, 1, 'pending', NULL, 'draft', '2026-01-01 00:00:00'),
  (3, 'audit reject, reset to pending', 5, 1, 'pending', NULL, 'draft', '2026-01-01 00:00:00'),
  (4, 'log request only', 5, 1, 'pending', NULL, 'awaiting_approval', '2026-01-01 00:00:00'),
  (5, 'log reject (reset to pending)', 5, 1, 'pending', NULL, 'draft', '2026-01-01 00:00:00'),
  (6, 'currently rejected, no history', 5, 1, 'rejected', '2026-03-05 12:00:00', 'awaiting_approval', '2026-01-01 00:00:00'),
  (7, 'currently approved, no history', 5, 1, 'approved', '2026-03-06 12:00:00', 'awaiting_approval', '2026-01-01 00:00:00'),
  (8, 'not required, nothing', 0, 0, 'pending', NULL, 'draft', '2026-01-01 00:00:00')");
$ok('old rows inserted', $m->errno === 0);
$ev = fn (string $type, int $id, string $at) => $m->query("INSERT INTO audit_events (event_type, entity_type, entity_id, action, created_at) VALUES ('$type', 'change', '$id', 'x', '$at')");
$ev('change.client_approval_requested', 2, '2026-02-01 08:00:00');
$ev('change.client_approval_requested', 3, '2026-02-01 08:00:00');
$ev('change.client_rejected', 3, '2026-02-02 08:00:00');
$ev('change.client_rejected', 3, '2026-02-04 08:00:00');
$ev('change.client_approval_requested', 8, '2026-02-01 08:00:00'); // another entity type with the same id must not count
$m->query("UPDATE audit_events SET entity_type = 'ticket' WHERE entity_id = '8'");
$lg = fn (string $desc, int $id, string $at) => $m->query("INSERT INTO logs (log_type, log_action, log_description, log_entity_id, log_created_at) VALUES ('Support', 'Edit', '" . $m->real_escape_string($desc) . "', $id, '$at')");
$lg('Tech sent a client approval request for change "x"', 4, '2026-02-10 09:00:00');
$lg('Acme rejected change "x" via guest link', 5, '2026-02-11 09:00:00');
$lg('Acme rejected change "x" via guest link', 5, '2026-02-12 09:00:00');
$lg('Tech sent a client approval request for change "x"', 5, '2026-02-09 09:00:00');
$lg('Tech moved ticket 9 to somewhere', 1, '2026-02-09 09:00:00'); // unrelated Support log for change id 1: must not count

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
$ok('columns added with the same definition as db.sql', $col('client_approval_requested_at') === ['datetime', 'YES'] && $col('client_rejected_at') === ['datetime', 'YES']);
$ok('version is 2.6.142 or later', version_compare($m->query("SELECT config_current_database_version FROM settings WHERE company_id = 1")->fetch_row()[0], '2.6.142', '>='));
$q = fn (int $id) => $m->query("SELECT client_approval_requested_at q, client_rejected_at j FROM changes WHERE change_id = $id")->fetch_assoc();
$ok('untouched draft: nothing invented (an unrelated Support log row does not count)', $q(1) === ['q' => null, 'j' => null]);
$ok('audit request: requested_at = event time', $q(2) === ['q' => '2026-02-01 08:00:00', 'j' => null]);
$ok('audit reject, reset to pending: requested = first event, rejected = LATEST rejection', $q(3) === ['q' => '2026-02-01 08:00:00', 'j' => '2026-02-04 08:00:00']);
$ok('log request only: requested_at from the surviving log row', $q(4) === ['q' => '2026-02-10 09:00:00', 'j' => null]);
$ok('log reject: requested = earliest log, rejected = latest rejection log', $q(5) === ['q' => '2026-02-09 09:00:00', 'j' => '2026-02-12 09:00:00']);
$ok('currently rejected with no history: both from client_decided_at', $q(6) === ['q' => '2026-03-05 12:00:00', 'j' => '2026-03-05 12:00:00']);
$ok('currently approved with no history: requested from client_decided_at, not rejected', $q(7) === ['q' => '2026-03-06 12:00:00', 'j' => null]);
$ok('audit event of another entity type is ignored', $q(8) === ['q' => null, 'j' => null]);

// idempotent: a re-run (version put back) neither errors nor moves anything
$before = [$q(3), $q(5), $q(6)];
$m->query("UPDATE settings SET config_current_database_version = '2.6.141'");
$ok('second run is clean (' . ($out = $run()) . ')', $out === 'MIGRATION_DONE');
$ok('second run changes no value', [$q(3), $q(5), $q(6)] === $before);
$ok('and ends on 2.6.142 or later', version_compare($m->query("SELECT config_current_database_version FROM settings WHERE company_id = 1")->fetch_row()[0], '2.6.142', '>='));
exit($fail);
