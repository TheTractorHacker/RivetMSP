<?php
// MSP-DS-184: WebAuthn sign-count: stored>0 requires a strictly larger count (a reset to 0 is refused). Run: php tests/msp_ds_184_webauthn_signcount.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
require __DIR__ . '/../includes/webauthn.php';
$config_base_url = 'rp.example.test'; $config_https_only = true;
$key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$pem = openssl_pkey_get_details($key)['key'];
$challenge = random_bytes(32);
$assert = function (int $count) use ($key, $pem, $challenge) {
    $ad = hash('sha256', 'rp.example.test', true) . chr(0x05) . pack('N', $count);
    $cdj = json_encode(['type' => 'webauthn.get', 'challenge' => wa_b64u_encode($challenge), 'origin' => 'https://rp.example.test']);
    openssl_sign($ad . hash('sha256', $cdj, true), $sig, $key, OPENSSL_ALGO_SHA256);
    return [wa_b64u_encode('cred'), wa_b64u_encode($cdj), wa_b64u_encode($ad), wa_b64u_encode($sig), $pem];
};
$run = function (int $stored, int $new) use ($assert, $challenge) {
    [$c, $cdj, $ad, $sig, $pem] = $assert($new);
    try { return wa_verify_assertion($c, $cdj, $ad, $sig, $pem, $stored, wa_b64u_encode($challenge)); } catch (RuntimeException $e) { return $e->getMessage(); }
};
$ok('stored 5, new 6 accepted -> 6', $run(5, 6) === 6);
$ok('stored 5, new 5 refused', is_string($run(5, 5)) && str_contains($run(5, 5), 'Sign count'));
$ok('stored 5, new 0 (reset) refused', is_string($run(5, 0)) && str_contains($run(5, 0), 'Sign count'));
$ok('stored 0, new 0 (authenticator without counter) accepted', $run(0, 0) === 0);
$ok('stored 0, new 3 accepted', $run(0, 3) === 3);
exit($fail ? 1 : 0);
