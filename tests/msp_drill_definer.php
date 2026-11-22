<?php
// Drill import: a dump with DEFINER=`app`@`host` views/triggers fails for the scoped drill account; the REAL RestoreDrill::stripDefiners() makes it import.
// Real CREATE VIEW on a scratch DB: with the DEFINER clause it is refused for a non-privileged account, after stripDefiners() it succeeds.
// Run: bash tests/lib/with_scratch_db.sh php tests/msp_drill_definer.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../src/Recovery/RestoreDrill.php';
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m->query('CREATE TABLE tt (a int)');
$dump = "CREATE ALGORITHM=UNDEFINED DEFINER=`itflow`@`localhost` SQL SECURITY DEFINER VIEW `vv` AS select `a` from `tt`;\n"
      . "/*!50017 DEFINER=`itflow`@`localhost`*/ /*!50003 CREATE TRIGGER x BEFORE INSERT ON tt FOR EACH ROW SET NEW.a = 1 */;\n"
      . "INSERT INTO tt VALUES ('has DEFINER=`x`@`y` in data');\n";
$ok('the DEFINER view is refused for the scoped account', $m->query(explode("\n", $dump)[0]) === false);
$d = sys_get_temp_dir() . '/dfn_' . bin2hex(random_bytes(3)); mkdir($d);
file_put_contents("$d/in.sql", $dump);
\RivetMSP\Recovery\RestoreDrill::stripDefiners("$d/in.sql", "$d/out.sql");
$out = file("$d/out.sql");
$ok('the view statement has no DEFINER and now creates', stripos($out[0], 'DEFINER=`') === false && $m->query($out[0]) !== false);
$ok('versioned-comment trigger DEFINER removed', stripos($out[1], 'DEFINER=`itflow`') === false && str_contains($out[1], 'CREATE TRIGGER'));
$ok('data rows are never rewritten', $out[2] === explode("\n", $dump)[2] . "\n");
array_map('unlink', glob("$d/*")); rmdir($d);
exit($fail ? 1 : 0);
