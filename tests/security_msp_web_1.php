<?php
declare(strict_types=1);
// MSP-WEB-1: no file decrypts credential values without going through vaultGate() first (except the reveal endpoint itself and the model/crypto code). Source-level.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__); $fail = 0;
$ok = function (string $n, bool $c) use (&$fail): void { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$files = ['agent/ajax.php', 'agent/post/credential.php', 'agent/post/client.php', 'agent/global_search.php', 'agent/client_overview.php', 'agent/asset_details.php',
    'agent/contact_details.php', 'agent/modals/asset/asset_details.php', 'agent/modals/contact/contact_details.php', 'agent/modals/credential/credential_edit.php'];
foreach ($files as $f) {
    $s = (string) file_get_contents("$root/$f");
    $first = strpos($s, 'vaultGate(');
    $dec = preg_match('/decryptCredentialEntry\(\$row\[|decryptOtpSecret\(\$(row|totp_secret_raw)/', $s, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
    $ok("$f: gate precedes the first decrypt of a stored value", $first !== false && $dec !== false && $first < $dec);
}
$ok('bulk paths ask for the step-up', substr_count((string) file_get_contents("$root/agent/post/credential.php") . (string) file_get_contents("$root/agent/post/client.php"), ', true);') >= 2);
exit($fail ? 1 : 0);
