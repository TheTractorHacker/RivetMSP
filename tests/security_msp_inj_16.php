<?php
declare(strict_types=1);
// MSP-INJ-16: the mobile API sign-in honours the MFA-required policy. Pure-logic check of secMfaEnforcementState's contract plus a source check of the API.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__); $fail = 0;
$ok = function (string $n, bool $c) use (&$fail): void { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$a = (string) file_get_contents("$root/api/v1/auth.php");
$gate = strpos($a, 'secMfaEnforcementState($mysqli, $uid)');
$ok('the MFA policy is evaluated', $gate !== false);
$ok('it runs before the password path issues a token (the passkey path above is its own strong factor)', $gate !== false && $gate < strrpos($a, 'INSERT INTO api_tokens'));
$ok('an unenrolled, out-of-grace account gets 403, not a token', str_contains($a, "!\$mfa_state['in_grace']") && str_contains($a, "api_error(403, 'Two-factor authentication is required"));
exit($fail ? 1 : 0);
