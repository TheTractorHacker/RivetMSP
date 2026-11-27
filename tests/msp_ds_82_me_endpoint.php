<?php
// MSP-DS-82 (partial): PUT /api/v1/me counts and audits wrong current passwords, rate limits them, validates and de-duplicates a changed email,
// audits the email change. Run: php tests/msp_ds_82_me_endpoint.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$m = file_get_contents(dirname(__DIR__) . '/api/v1/me.php');
$g = substr($m, strpos($m, "if (\$method === 'PUT' || \$method === 'POST') {"));
$ok(str_contains($g, "api_rate_limit('me_password:'"), 'password change attempts are rate limited');
$ok(str_contains($g, "'Password Change Denied'"), 'wrong current password is audited and counted');
$ok(strpos($g, "api_rate_limit('me_password:'") < strpos($g, 'password_verify('), 'rate limit runs before password_verify');
$ok(str_contains($g, 'FILTER_VALIDATE_EMAIL'), 'changed email must be a valid address');
$ok(str_contains($g, 'api_error(409'), 'changed email must not be used by another account');
$ok(str_contains($g, "'Email Change'"), 'email change is audited');
$ok(str_contains($g, 'addToMailQueue'), 'old address is notified');
// the 'unchanged email' path (mobile sends the same address back with every save) must not hit the new checks
$ok(str_contains($g, '$me_email_changed = strcasecmp('), 'checks only apply when the email actually changes');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
