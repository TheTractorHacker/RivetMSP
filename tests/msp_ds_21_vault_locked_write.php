<?php
// MSP-DS-21: encrypting a credential while the vault is locked must refuse, never fall back to an all-zero key. Run: php tests/msp_ds_21_vault_locked_write.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$src = file_get_contents(__DIR__ . '/../functions.php');
foreach (['vaultSessionMasterKeyOrStop', 'encryptCredentialEntry', 'encryptOtpSecret'] as $fn) {
    preg_match('/^function ' . $fn . '\(.*?^}\n/ms', $src, $m); eval($m[0]);
}
function randomString() { return bin2hex(random_bytes(8)); }
$_SESSION = []; $_COOKIE = [];
$threw = function (callable $f) { try { $f(); return false; } catch (RuntimeException $e) { return true; } };
$ok($threw(fn () => encryptCredentialEntry('secret')), 'locked vault (no session key) refuses to encrypt a credential');
$ok($threw(fn () => encryptOtpSecret('JBSWY3DPEHPK3PXP')), 'locked vault refuses to encrypt an OTP seed');
// unlocked: build a session exactly like login does
$master = random_bytes(8) ; $master = bin2hex($master);   // 16 chars
$key = bin2hex(random_bytes(8)); $iv = bin2hex(random_bytes(8));
$_SESSION['user_encryption_session_ciphertext'] = openssl_encrypt($master, 'aes-128-cbc', $key, 0, $iv);
$_SESSION['user_encryption_session_iv'] = $iv; $_COOKIE['user_encryption_session_key'] = $key;
$enc = encryptCredentialEntry('secret');
$ok(openssl_decrypt(substr($enc, 16), 'aes-128-cbc', $master, 0, substr($enc, 0, 16)) === 'secret', 'unlocked vault encrypts under the real master key');
$ok(openssl_decrypt(substr($enc, 16), 'aes-128-cbc', str_repeat("\0", 16), 0, substr($enc, 0, 16)) !== 'secret', 'the value is NOT readable with a zero key');
unset($_COOKIE['user_encryption_session_key']);
$ok($threw(fn () => encryptCredentialEntry('secret')), 'cookie missing again -> refuses');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
