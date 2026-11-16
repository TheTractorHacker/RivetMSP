<?php
// MSP-DS-100: notifyUser()/appNotify() cut type/details/action by characters, so multibyte text never ends in a half character that fails the INSERT. Run:
// SCRATCH_TABLES="notifications" tests/lib/with_scratch_db.sh php tests/msp_ds_100_notification_cut.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode='STRICT_ALL_TABLES'");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$f = file_get_contents(__DIR__ . '/../functions.php');
foreach (['appNotify', 'notifyUser'] as $fn) {
    preg_match('/function ' . $fn . '\(.*?\n\}\n/s', $f, $mm);
    $ok("$fn has no byte-based substr cut", !preg_match('/[^_]substr\(/', $mm[0]));
}
$details = str_repeat('B', 999) . str_repeat('€', 50);   // the old byte cut at 1000 lands inside the first euro sign
$old = substr($details, 0, 1000); $new = mb_substr($details, 0, 1000, 'UTF-8');
$insert = fn (string $d) => $m->query("INSERT INTO notifications (notification_type, notification, notification_action, notification_user_id) VALUES ('Ticket', '" . $m->real_escape_string($d) . "', '/x', 1)");
$threw = false; try { $insert($old); } catch (mysqli_sql_exception $e) { $threw = true; }
$ok('the old byte cut makes the strict INSERT throw (premise)', $threw);
$threw = false; try { $insert($new); } catch (mysqli_sql_exception $e) { $threw = true; }
$ok('the character cut inserts', !$threw && mb_strlen($new, 'UTF-8') === 1000);
$cyr = str_repeat('Ж', 1200);
$threw = false; try { $insert(mb_substr($cyr, 0, 1000, 'UTF-8')); } catch (mysqli_sql_exception $e) { $threw = true; }
$ok('1000 Cyrillic characters fit the column', !$threw);
exit($fail ? 1 : 0);
