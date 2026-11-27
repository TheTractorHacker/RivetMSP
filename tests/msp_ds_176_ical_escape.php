<?php
// MSP-DS-176: iCal escaping/folding. Run: php tests/msp_ds_176_ical_escape.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$s = file_get_contents(__DIR__ . '/../agent/calendar_feed.php');
$a = strpos($s, 'function ical_escape'); $b = strpos($s, '$now_stamp');
eval(substr($s, $a, $b - $a));
$e = ical_escape("x\rEND:VEVENT\rBEGIN:VEVENT\rSUMMARY:evil");
$ok('bare CR cannot start a new content line', strpos($e, "\r") === false && strpos($e, "\n") === false);
$ok('CR becomes an escaped newline', str_contains($e, 'x\nEND:VEVENT'));
$ok('CRLF from a textarea is one escaped newline', ical_escape("a\r\nb") === 'a\nb');
$ok('commas, semicolons and backslashes still escaped', ical_escape('a,b;c\\d') === 'a\,b\;c\\\\d');
$line = 'SUMMARY:' . str_repeat('é', 120);
$folded = ical_fold($line);
$ok('every folded physical line is at most 75 octets (+CRLF)', max(array_map('strlen', explode("\r\n", rtrim($folded, "\r\n")))) <= 76);
$ok('no multi-byte character split by a fold', mb_check_encoding(str_replace("\r\n ", '', $folded), 'UTF-8') && str_replace("\r\n ", '', rtrim($folded, "\r\n")) === $line);
foreach (explode("\r\n", rtrim($folded, "\r\n")) as $p) { if (!mb_check_encoding($p, 'UTF-8')) { $ok('each physical line is valid UTF-8', false); } }
$ok('description separator is a real newline (escaped once to \\n), not a literal backslash-n', !str_contains($s, ". '\\n' : '') . \$url") && substr_count($s, '. "\n" : \'\') . $url') === 2);
exit($fail ? 1 : 0);
