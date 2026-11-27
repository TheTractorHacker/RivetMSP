<?php
// MSP-DS-75: no GET delete_passkey handler without password re-auth. Run: php tests/msp_ds_75_no_get_delete_passkey.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$root = dirname(__DIR__);
$p = file_get_contents("$root/agent/user/post/profile.php");
$ok(!preg_match('/\$_GET\[\'delete_passkey\'\]/', $p), 'agent profile.php has no GET delete_passkey');
$ok(!preg_match('/DELETE FROM user_passkeys/', $p), 'agent profile.php never deletes passkeys');
$d = file_get_contents("$root/agent/user/passkey_delete.php");
$ok(str_contains($d, 'secReauthPassword'), 'passkey_delete.php still requires the password');
foreach (glob("$root/agent/user/post/*.php") as $f) {
    $ok(!str_contains(file_get_contents($f), 'DELETE FROM user_passkeys'), basename($f) . ' has no passkey delete');
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
