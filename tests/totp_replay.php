<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* TokenAuth6238::verifyOnce (RivetMSP port of RivetIT's single-use TOTP): rejects replays and out-of-window codes. No DB, no network. */
require_once __DIR__ . '/../plugins/totp/totp.php';
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$secret = 'JBSWY3DPEHPK3PXP';
@unlink(sys_get_temp_dir() . '/rivetmsp_totp_replay/' . hash('sha256', $secret));
$code = TokenAuth6238::getTokenCode($secret);
$ok(TokenAuth6238::verifyOnce($secret, $code) === true, 'fresh code accepted');
$ok(TokenAuth6238::verifyOnce($secret, $code) === false, 'same code rejected on replay');
$ok(TokenAuth6238::verifyOnce($secret, '000000') === false || TokenAuth6238::matchStep($secret, '000000') !== null, 'wrong code rejected');
$ok(TokenAuth6238::matchStep($secret, $code, 0) !== null, 'matchStep finds current step');
@unlink(sys_get_temp_dir() . '/rivetmsp_totp_replay/' . hash('sha256', $secret));
// out-of-window codes: a code from 3 steps ago/ahead is refused with window 1, a code one step old is accepted once
$mk = function (string $sec, int $step) { $r = new ReflectionMethod('TokenAuth6238', 'oath_hotp'); $r->setAccessible(true); $t = new ReflectionMethod('TokenAuth6238', 'oath_truncate'); $t->setAccessible(true);
    return str_pad((string) $t->invoke(null, $r->invoke(null, Base32Static::decode($sec), $step)), 6, '0', STR_PAD_LEFT); };
$secret2 = 'JBSWY3DPEHPK3PXR'; $now = (int) (time() / 30);
@unlink(sys_get_temp_dir() . '/rivetmsp_totp_replay/' . hash('sha256', $secret2));
$ok(TokenAuth6238::verifyOnce($secret2, $mk($secret2, $now - 3), 1) === false, 'a code three steps old is refused with window 1');
$ok(TokenAuth6238::verifyOnce($secret2, $mk($secret2, $now + 3), 1) === false, 'a code three steps ahead is refused with window 1');
$ok(TokenAuth6238::verifyOnce($secret2, $mk($secret2, $now), 1) === true, 'the current code is accepted');
$ok(TokenAuth6238::verifyOnce($secret2, $mk($secret2, $now - 1), 1) === false, 'an older step than the last accepted one is refused (no going back)');
$ok(TokenAuth6238::verifyOnce($secret2, $mk($secret2, $now + 1), 1) === true, 'a newer step is accepted once');
$ok(TokenAuth6238::verifyOnce($secret2, $mk($secret2, $now + 1), 1) === false, '... and not again');
$secret3 = 'JBSWY3DPEHPK3PXS'; @unlink(sys_get_temp_dir() . '/rivetmsp_totp_replay/' . hash('sha256', $secret3));
$ok(TokenAuth6238::verifyOnce($secret3, $mk($secret3, $now), 1) === true, 'the state is per secret: another secret is unaffected');
foreach ([$secret2, $secret3] as $sx) { @unlink(sys_get_temp_dir() . '/rivetmsp_totp_replay/' . hash('sha256', $sx)); }
echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit($fails ? 1 : 0);
