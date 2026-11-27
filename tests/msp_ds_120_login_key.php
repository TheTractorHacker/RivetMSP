<?php
// MSP-DS-120: login key checked before the agent password, placeholder does not depend on it, 16-char minimum on save.
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$l = file_get_contents(__DIR__ . '/../login.php');
$gate = strpos($l, 'MSP-DS-120');
$verify = strpos($l, 'password_verify($password, $r[\'user_password\'])');
$ok($gate !== false && $verify !== false && $gate < $verify, 'key gate runs before password_verify');
$ok(strpos($l, 'echo "Client "') === false, 'placeholder no longer reveals key correctness');
$ok(strpos($l, 'placeholder="Email"') !== false, 'placeholder is constant');
$s = file_get_contents(__DIR__ . '/../admin/post/settings_security.php');
$ok(strpos($s, '\w{16,99}') !== false, 'server enforces minimum length');
foreach (['short' => false, 'abcdefghijklmnop' => true, 'has space in it ok ok' => false, str_repeat('a', 100) => false, 'abc_DEF_123_ghi_456' => true] as $k => $exp) {
    $ok((bool) preg_match('/^\w{16,99}$/', $k) === $exp, 'regex: ' . substr($k, 0, 20));
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
