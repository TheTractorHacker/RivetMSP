<?php
// MSP-DS-98 (partial): validateAdminRole() accepts any role flagged role_is_admin, refuses everyone else. Run: php tests/msp_ds_98_validate_admin_role.php
define('WORDING_ROLECHECK_FAILED', 'denied');
$f = file_get_contents(dirname(__DIR__) . '/functions.php');
preg_match('/function validateAdminRole\(\) \{.*?\n\}\n/s', $f, $m);
eval($m[0]);
if (($argv[1] ?? '') === 'child') {   // header()+exit() cannot be observed in-process, so each case runs in a child
    $session_is_admin = $argv[2] === '1' ? true : false;
    if ($argv[2] === 'unset') { unset($session_is_admin); }
    $_SESSION = []; $_SERVER['HTTP_REFERER'] = 'x';
    validateAdminRole();
    echo 'PASSED';
    exit;
}
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$ok(!str_contains($m[0], '!= 3') && str_contains($m[0], 'session_is_admin'), 'no hard-coded role id');
$child = fn (string $arg) => trim((string) shell_exec('php ' . escapeshellarg(__FILE__) . ' child ' . escapeshellarg($arg) . ' 2>/dev/null'));
$ok($child('1') === 'PASSED', 'role_is_admin=1 on any role id passes');
$ok($child('0') === '', 'non-admin role is refused');
$ok($child('unset') === '', 'missing session flag is refused');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
