<?php
// MSP-DS-99: report_send_csv() writes RFC 4180 quoting, so a backslash-quote cannot break out of a cell into an unchecked formula cell. Run: php tests/msp_ds_99_csv_escape.php
$root = dirname(__DIR__);
if (($argv[1] ?? '') === 'child') {
    $f = file_get_contents("$root/functions.php");
    preg_match('/function report_send_csv\(.*?\n\}\n/s', $f, $m);
    eval($m[0]);
    report_send_csv('t.csv', ['Client', 'Note'], [["Acme\\\",=cmd|' /C calc'!A0,\\\"", 'x'], ['=2+5', '-5'], ["\t=1+1", '|cmd'], ['plain, "quoted"', "multi\nline"]]);
}
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$out = (string) shell_exec('php ' . escapeshellarg(__FILE__) . ' child 2>/dev/null');
$out = ltrim($out, "\xEF\xBB\xBF");
$fh = fopen('php://memory', 'w+'); fwrite($fh, $out); rewind($fh);
$rows = []; while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) { $rows[] = $r; }
$ok(count($rows) === 5, 'header + 4 rows round trip as exactly 5 records (' . count($rows) . ')');
$ok(count($rows[1] ?? []) === 2, 'the hostile cell stays ONE cell, no break-out into extra cells');
$ok(str_starts_with($rows[1][0] ?? '', 'Acme\\"'), 'its text is intact');
$ok(($rows[2][0] ?? '') === "'=2+5", 'leading = is neutralised');
$ok(($rows[2][1] ?? '') === '-5', 'a genuine negative number is untouched');
$ok(($rows[3][0] ?? '') === "'\t=1+1" && ($rows[3][1] ?? '') === "'|cmd", 'leading tab and pipe are neutralised');
$ok(($rows[4][0] ?? '') === 'plain, "quoted"' && ($rows[4][1] ?? '') === "multi\nline", 'ordinary quotes and newlines round trip');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
