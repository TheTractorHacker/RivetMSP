<?php
declare(strict_types=1);
// MSP-WEB-3: email change, enabling/disabling two-factor and adding/removing passkeys need the current password and a live session. Source-level.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__); $fail = 0;
$ok = function (string $n, bool $c) use (&$fail): void { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$f = static fn (string $p): string => (string) file_get_contents("$root/$p");
$ok('helper exists', str_contains($f('includes/security_policy.php'), 'function secReauthPassword'));
$p = $f('agent/user/post/profile.php');
$ok('disable_mfa is POST only and needs the password', !str_contains($p, "isset(\$_GET['disable_mfa'])") && preg_match("/isset\(\\\$_POST\['disable_mfa'\]\).*?secReauthPassword.*?UPDATE users SET user_token = ''/s", $p) === 1);
$ok('enable_mfa needs the password before the code is checked', preg_match("/isset\(\\\$_POST\['enable_mfa'\]\).*?secReauthPassword.*?TokenAuth6238::verify/s", $p) === 1);
$ok('an email change needs the password before the UPDATE', preg_match("/strcasecmp\(.*?secReauthPassword.*?UPDATE users SET user_name/s", $p) === 1);
foreach (['passkey_enroll_start', 'passkey_enroll_finish', 'passkey_delete'] as $e) {
    $s = $f("agent/user/$e.php");
    $ok("$e: session is enforced (idle/revocation)", str_contains($s, 'secSessionEnforce($mysqli)'));
}
$ok('passkey finish and delete need the password', str_contains($f('agent/user/passkey_enroll_finish.php'), 'secReauthPassword') && str_contains($f('agent/user/passkey_delete.php'), 'secReauthPassword'));
$ok('no one-click disable link remains', !str_contains($f('agent/user/user_security.php'), 'post.php?disable_mfa'));
exit($fail ? 1 : 0);
