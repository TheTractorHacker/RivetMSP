<?php
// MSP-DS-97 (complete): the passkey vault-key cookie is expired by the real clearPasskeyEncKeyCookie(), which post/logout.php and secSessionEnd() call.
// Run: php tests/msp_ds2_97.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$root = dirname(__DIR__);
$f = file_get_contents($root . '/functions.php');
preg_match('/function clearPasskeyEncKeyCookie\(\).*?\n\}\n/s', $f, $m);
$ok(!empty($m[0]), 'clearPasskeyEncKeyCookie() exists in functions.php');
// php-cli records no response headers, so the real function (extracted from functions.php, a trusted repo file) runs in a namespace
// whose setcookie()/headers_sent() capture the call instead.
eval('namespace Ds97Probe; function headers_sent() { return false; } function setcookie($n, $v = "", $o = []) { $GLOBALS["__ck"][] = [$n, $v, $o]; return true; } ' . $m[0]);
$_COOKIE['user_passkey_enc_key'] = 'abc';
foreach ([true, false] as $https) {
    $GLOBALS['__ck'] = [];
    $GLOBALS['config_https_only'] = $https;
    \Ds97Probe\clearPasskeyEncKeyCookie();
    [$n, $v, $o] = $GLOBALS['__ck'][0] ?? [null, null, []];
    $tag = 'https=' . var_export($https, true);
    $ok($n === 'user_passkey_enc_key' && $v === '' && ($o['expires'] ?? time()) < time(), "expires the cookie ($tag)");
    $ok(($o['httponly'] ?? false) === true && ($o['samesite'] ?? '') === 'Strict' && ($o['path'] ?? '') === '/', "same attributes as the setter ($tag)");
    $ok(($o['secure'] ?? null) === $https, "Secure follows config_https_only ($tag)");
}
$ok(!isset($_COOKIE['user_passkey_enc_key']), 'request cookie unset');
$ok(str_contains(file_get_contents($root . '/post/logout.php'), 'clearPasskeyEncKeyCookie();'), 'logout handler calls it (also covers sign-out-everywhere, which redirects to logout)');
$ok(str_contains(file_get_contents($root . '/includes/security_sessions.php'), 'clearPasskeyEncKeyCookie();'), 'idle/absolute/revoked session end calls it');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
