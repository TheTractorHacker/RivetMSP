<?php
// MSP-DS-92: recurring expense frequency is whitelisted on save and skipped in cron. Run: php tests/msp_ds_92_recurring_expense_frequency.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$root = dirname(__DIR__);
$h = file_get_contents("$root/agent/post/recurring_expense.php");
$ok(substr_count($h, "!in_array(\$frequency, [1, 2], true)") === 2, 'create and edit both refuse a frequency other than 1 or 2');
$ok(preg_match_all("/in_array\(\\\$frequency, \[1, 2\], true\)\) \{\s*flash_alert[^\n]*\n\s*redirect\(\);/", $h) === 2, 'refusal flashes and redirects before any write');
$c = file_get_contents("$root/cron/cron.php");
$start = strpos($c, '// Recurring Expenses');
$blk = substr($c, $start, 4000);
$skip = strpos($blk, '!in_array($recurring_expense_frequency, [1, 2], true)');
$ins = strpos($blk, 'INSERT INTO expenses');
$ok($skip !== false && $ins !== false && $skip < $ins, 'cron skips an unknown frequency before inserting an expense');
$ok(str_contains(substr($blk, $skip, 600), 'INTERVAL 1 DAY') && str_contains(substr($blk, $skip, 700), 'continue;'), 'skipped row is pushed out a day and the loop continues');
// UI offers only 1 and 2
$m = glob("$root/agent/modals/recurring_expense/*.php");
$vals = [];
foreach ($m as $f) { if (preg_match_all('/<option value="(\d+)"/', substr(file_get_contents($f), (int) strpos(file_get_contents($f), 'name="frequency"'), 600), $mm)) { $vals = array_merge($vals, $mm[1]); } }
$ok($vals === [] || count(array_diff($vals, ['1', '2'])) === 0, 'form still offers only valid values (' . implode(',', array_unique($vals)) . ')');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
