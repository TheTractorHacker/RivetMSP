<?php
// Mobile contract guards: (1) PUT /me never demands a password/TOTP when the email is unchanged (both apps send name+email on every save);
// (2) GET /search always answers every section key as an array, even for a role without the module. Source-level. Run: php tests/msp_ds_mobile_contract_me_search.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$root = dirname(__DIR__);
$me = file_get_contents("$root/api/v1/me.php");
$ok(str_contains($me, '$me_email_changed = strcasecmp(trim((string) ($body[\'email\'] ?? \'\')), (string) ($me_row[\'user_email\'] ?? \'\')) !== 0;'), 'PUT /me compares the submitted email with the stored one, trimmed and case-insensitively');
$put = substr($me, strpos($me, "if (\$method === 'PUT'"));
$validation = substr($put, strpos($put, 'if ($me_email_changed) {'), 700);
$ok(!str_contains($validation, 'current_password') && !str_contains($validation, 'totp'), 'email validation block adds no password/TOTP demand for an unchanged email');
$ok(!preg_match('/if \(!\$me_email_changed\).*api_error/s', substr($put, 0, 900)), 'an unchanged email path has no error branch');
$s = file_get_contents("$root/api/v1/search.php");
$ok(preg_match('/api_response\(200, \[\'tickets\' => \$tickets, \'clients\' => \$clients, \'assets\' => \$assets, \'software\' => \$software, \'networks\' => \$networks, \'services\' => \$services, \'linked\' => \$linked\]\)/', $s) === 1, 'search answers the same section keys as before (shape unchanged)');
foreach (['tickets', 'clients', 'assets'] as $k) { $ok(str_contains($s, "\$$k = [];"), "\$$k starts as an empty array, so a role without the module gets []"); }
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
