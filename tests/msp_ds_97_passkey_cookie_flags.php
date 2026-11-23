<?php
// MSP-DS-97 (partial): the passkey vault-key cookie is HttpOnly + SameSite=Strict in both HTTPS and HTTP mode. Run: php tests/msp_ds_97_passkey_cookie_flags.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$f = file_get_contents(dirname(__DIR__) . '/functions.php');
preg_match('/function storePasskeyEncKey\(.*?\n\}\n/s', $f, $m);
$fn = $m[0];
$ok(substr_count($fn, 'setcookie(') === 1, 'a single setcookie call (no flag-less HTTP branch)');
$ok(str_contains($fn, "'httponly' => true") && str_contains($fn, "'samesite' => 'Strict'"), 'HttpOnly and SameSite=Strict');
$ok(!str_contains($fn, "'None'"), 'no SameSite=None');
$ok(str_contains($fn, "'secure' => (bool) \$https_only"), 'Secure follows config_https_only');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
