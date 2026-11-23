<?php
// MSP-DS-89: portal password reset link round-trips plus-addressed and ampersand emails. Run: php tests/msp_ds_89_portal_reset_link.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$s = file_get_contents(dirname(__DIR__) . '/client/login_reset.php');
$ok(str_contains($s, "http_build_query(['email' => (string) \$row['user_email'], 'token' => \$token, 'client' => \$client])"), 'link built with http_build_query from the stored address');
$ok(!str_contains($s, 'login_reset.php?email=$email&'), 'no raw interpolated email in the link');
foreach (['alice+billing@x.com', 'a&b@x.com', 'plain@x.com'] as $e) {
    $url = '/client/login_reset.php?' . http_build_query(['email' => $e, 'token' => 'T', 'client' => 3]);
    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    $ok($q['email'] === $e && $q['token'] === 'T' && $q['client'] === '3', "round trip $e");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
