<?php
declare(strict_types=1);
// MSP-WEB-1 (API) and MSP-INJ-6: the API credential reveal shares the web reveal limit and audit line; enrolling the step-up device key needs the password (+MFA).
// Source-level (the web-side limit logic has the scratch-DB proof in tests/security_behaviour_scratch.php).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__); $fail = 0;
$ok = function (string $n, bool $c) use (&$fail): void { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$c = (string) file_get_contents("$root/api/v1/credentials.php");
$gate = strpos($c, 'vaultRevealCount($mysqli, (int) $api_user_id)');
$ok('API reveal checks the shared limit before decrypting', $gate !== false && $gate < strpos($c, "'username'   => _api_decrypt("));
$ok('API reveal writes a Credential Reveal line', str_contains($c, "logAction('Credential', 'Reveal'"));
$ok('API reveal answers 429 with Retry-After at the limit', str_contains($c, "api_error(429, 'Credential reveal limit reached"));
$m = (string) file_get_contents("$root/api/v1/me.php");
$put = strpos($m, 'INSERT INTO api_biometric_keys');
$ok('key enrolment verifies the password before the INSERT', ($p = strpos($m, 'password_verify($bio_pass')) !== false && $p < $put);
$ok('key enrolment needs MFA when enabled, before the INSERT', ($t = strpos($m, 'MFA code required to register')) !== false && $t < $put);
$ok('denials are counted from the always-on activity log and rate limited', str_contains($m, "log_action = 'Step-up Key Enrol Denied'") && str_contains($m, 'api_rate_limit('));
exit($fail ? 1 : 0);
