<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * API login hardening, no database needed:  php tests/api_auth_hardening.php
 *  - api_rate_limit() fails open by default but CLOSED when asked and Redis is unavailable
 *  - api/v1/auth.php counts a wrong TOTP code against the user lockout counter and only resets
 *    the counter after the second factor passed (source-order contract), with a +/-1 step window
 */
define('FROM_API', true);
function getRedisClient() { return null; } // Redis unavailable
require __DIR__ . '/../api/v1/includes/api_ratelimit.php';

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$ok(api_rate_limit('x', 1, 60) === true, 'default: fails open when Redis is down (non-credential endpoints keep serving)');
$ok(api_rate_limit('x', 1, 60, true) === false, 'fail_closed: denied when Redis is down');

$src = file_get_contents(__DIR__ . '/../api/v1/auth.php');
$pwdPos = strpos($src, '// ── 2FA (TOTP)');
$verifyPos = strpos($src, 'TokenAuth6238::verifyOnce($totp_secret, $totp, 1)');
$incPos = strpos($src, 'user_failed_login_count = user_failed_login_count + 1', $verifyPos ?: 0);
$resetPos = strpos($src, 'SET user_failed_login_count = 0 WHERE user_id = $uid"', $verifyPos ?: 0);
$ok($verifyPos !== false, 'TOTP verified single-use with a +/-1 step window');
$ok(strpos($src, 'TokenAuth6238::verify(') === false, 'the replayable verify() is no longer used by the mobile login');
$ok($incPos !== false && $incPos < $resetPos, 'a wrong TOTP increments the failure counter before any reset');
$ok($resetPos !== false && $resetPos > $verifyPos, 'failure counter is reset only AFTER the second factor passed');
$earlyReset = strpos($src, 'SET user_failed_login_count = 0 WHERE user_id = $uid"');
$ok($earlyReset === $resetPos, 'no earlier reset of the counter between password check and TOTP check');
$ok(strpos($src, 'MFA Failed') !== false, 'TOTP failure is logged');

// the verifier itself: a code is accepted once, then rejected
require_once __DIR__ . '/../plugins/totp/totp.php';
$secret = 'JBSWY3DPEHPK3PXQ';
@unlink(sys_get_temp_dir() . '/rivetmsp_totp_replay/' . hash('sha256', $secret));
$code = TokenAuth6238::getTokenCode($secret);
$ok(TokenAuth6238::verifyOnce($secret, $code, 1) === true && TokenAuth6238::verifyOnce($secret, $code, 1) === false, 'a TOTP code works once, the replay is refused');
@unlink(sys_get_temp_dir() . '/rivetmsp_totp_replay/' . hash('sha256', $secret));

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
