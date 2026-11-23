<?php
// MSP-DS-77: the mobile passkey login can be switched to require user verification ($config_passkey_mobile_require_uv in config.php).
// Default stays off (shipped apps keep working). Runs the real wa_verify_assertion with a presence-only assertion.
// Run: php tests/msp_ds2_77.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
require $root . '/includes/webauthn.php';
$config_base_url = 'x.test'; $config_https_only = true;
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$pem = openssl_pkey_get_details($key)['key'];
$challenge = wa_b64u_encode(random_bytes(32));
$cd = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => 'https://x.test']);
$assert = function (int $flags) use ($key, $cd) {
    $ad = hash('sha256', 'x.test', true) . chr($flags) . pack('N', 5);
    openssl_sign($ad . hash('sha256', $cd, true), $sig, $key, OPENSSL_ALGO_SHA256);
    return [wa_b64u_encode($cd), wa_b64u_encode($ad), wa_b64u_encode($sig)];
};
$verify = function (int $flags, bool $uv) use ($assert, $pem, $challenge) {
    [$c, $a, $s] = $assert($flags);
    try { return wa_verify_assertion('cred', $c, $a, $s, $pem, 0, $challenge, [], $uv); } catch (RuntimeException $e) { return $e->getMessage(); }
};
$ok('presence-only assertion is accepted when UV is not required', $verify(0x01, false) === 5);
$ok('presence-only assertion is refused when UV is required', $verify(0x01, true) === 'User Verified flag not set');
$ok('user-verified assertion passes when UV is required', $verify(0x05, true) === 5);

$auth = file_get_contents($root . '/api/v1/auth.php');
$ok('auth.php passes the config switch as requireUV', str_contains($auth, '!empty($config_passkey_mobile_require_uv)   // MSP-DS-77'));
$ok('auth.php advertises required/preferred from the same switch', str_contains($auth, "'userVerification' => !empty(\$config_passkey_mobile_require_uv) ? 'required' : 'preferred'"));
$ok('the old hard-coded false is gone', !preg_match('/\$androidOrigins,\s*false\b/', $auth));
exit($fail ? 1 : 0);
