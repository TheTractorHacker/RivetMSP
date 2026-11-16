<?php
// MSP-DS-112: setup wizard passes DB credentials through unchanged (trim only). Run: php tests/msp_ds_112_setup_db_credentials.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$src = file_get_contents(__DIR__ . '/../setup/index.php');
$ok(strpos($src, 'FILTER_SANITIZE_STRING') === false, 'setup/index.php no longer uses FILTER_SANITIZE_STRING');
$ok(preg_match('/\$password = trim\(\(string\) \(\$_POST\[\'password\'\]/', $src) === 1, 'password is only trimmed');
// the config line written is var_export, which round-trips any string
$pw = 'p@ss"w<rd>x\'1\\';
$cfg = '<?php $p = ' . var_export($pw, true) . ';';
$f = tempnam(sys_get_temp_dir(), 'c');
file_put_contents($f, $cfg);
include $f;
unlink($f);
$ok($p === $pw, 'var_export round-trips a password with quotes, angle brackets and backslash');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
