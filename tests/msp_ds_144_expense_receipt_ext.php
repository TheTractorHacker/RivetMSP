<?php
// MSP-DS-144: API expense receipts get an extension derived from the sniffed type; uploads/.htaccess forces download.
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$s = file_get_contents(__DIR__ . '/../api/v1/expenses.php');
$ok(strpos($s, "pathinfo(\$_FILES['receipt']['name']") === false, 'client file name no longer decides the extension');
$ok(strpos($s, "'application/pdf' => 'pdf'][\$mime]") !== false, 'extension map keyed by sniffed MIME');
// the map covers exactly the allowed types
preg_match("/\\\$allowed = \\[(.*?)\\];/", $s, $m); preg_match_all("/'([a-z]+\/[a-z]+)'/", $m[1], $mm);
foreach ($mm[1] as $t) { $ok(strpos($s, "'$t' =>") !== false, "map has an entry for $t"); }
$h = file_get_contents(__DIR__ . '/../uploads/.htaccess');
$ok(strpos($h, 'Content-Disposition "attachment"') !== false && strpos($h, 'nosniff') !== false, 'Apache uploads serve as attachments with nosniff');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
