<?php
// MSP-DS-79 (crash report metadata is one line) and MSP-DS-80 (API credential detail decrypts the OTP seed, uses index.php's bearer token).
// Run: php tests/msp_ds_79_80_api_crash_otp.php
namespace RivetMSP\Crypto { class VaultV3 { public static function isV3Field($v) { return false; } } }
namespace {
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$root = dirname(__DIR__);
$c = file_get_contents("$root/api/v1/crash_reports.php");
preg_match('/\$crash_oneline = (static function.*?\n\};)/s', $c, $m);
$f = eval('return ' . rtrim($m[1], ';') . ';');
$out = $f("Pixel\nCaller: user #1\r\nFatal: yes");
$ok(!preg_match('/[\r\n]/', $out) && str_contains($out, 'Caller: user #1'), 'newlines replaced by spaces: ' . $out);
$ok($f("a\x00b\x1Fc\x7Fd") === 'a b c d', 'control characters replaced');
foreach (['platform', 'app_version_name', 'device_model', 'os_version', 'thread_name', 'occurred_at'] as $k) {
    $ok(str_contains($c, "\$$k") && preg_match("/\\\$$k\s*= substr\(\\\$crash_oneline\(/", $c) === 1, "$k goes through the one-line filter");
}
$cr = file_get_contents("$root/api/v1/credentials.php");
preg_match('/function _api_decrypt\(.*?\n\}\n/s', $cr, $fm);
eval($fm[0]);
// legacy encoding: aes-128-cbc, master key, 16-char iv, as encryptOtpSecret() does
$master = random_bytes(8); $master = bin2hex($master);   // 16 chars
$raw = bin2hex(random_bytes(32));
$encKey = substr(hash('sha256', $raw . 'itflow_enc', true), 0, 16);
$ivM = random_bytes(16);
$tok = ['token_enc_master_key' => openssl_encrypt($master, 'aes-128-cbc', $encKey, 0, $ivM), 'token_enc_master_iv' => bin2hex($ivM)];
$iv = bin2hex(random_bytes(8));
$stored = 'enc:' . $iv . openssl_encrypt('JBSWY3DPEHPK3PXP', 'aes-128-cbc', $master, 0, $iv);
$ok(_api_decrypt($stored, $raw, $tok, 5, 'otp') === 'JBSWY3DPEHPK3PXP', 'legacy enc: OTP seed is decrypted');
$ok(_api_decrypt('PLAINSEED', $raw, $tok, 5, 'otp') === 'PLAINSEED', 'legacy plaintext OTP seed returned as-is');
$ok(_api_decrypt($stored, 'wrong', $tok, 5, 'otp') === '', 'wrong token yields empty, not ciphertext');
$pw = $iv . openssl_encrypt('hunter2', 'aes-128-cbc', $master, 0, $iv);
$ok(_api_decrypt($pw, $raw, $tok, 5, 'password') === 'hunter2', 'password decrypt unchanged');
$ok(!str_contains($cr, "\$_SERVER['HTTP_AUTHORIZATION'] ?? '', \$bm"), 'credentials.php no longer re-reads only HTTP_AUTHORIZATION');
$ok(str_contains($cr, "'otp_secret' => _api_decrypt("), 'otp_secret field is decrypted');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
}
