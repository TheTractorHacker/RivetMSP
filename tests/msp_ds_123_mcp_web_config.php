<?php
// MSP-DS-123: the Apache rules the settings page promises exist; the page no longer claims more than is shipped.
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$h = file_get_contents(__DIR__ . '/../.htaccess');
$ok(strpos($h, 'RewriteRule ^mcp$ mcp_server/index.php') !== false, '.htaccess routes /mcp');
$ok(strpos($h, 'oauth-protected-resource$ mcp_server/index.php') !== false, '.htaccess routes the protected-resource metadata');
$ok(strpos($h, 'RedirectMatch 404') !== false && strpos($h, 'mcp_server') !== false, 'static-exposure rule still present');
$s = file_get_contents(__DIR__ . '/../admin/settings_mcp.php');
$ok(strpos($s, 'Apache users need nothing: the bundled') === false, 'false blanket Apache claim removed');
$ok(strpos($s, 'are not part of this edition yet') !== false, 'built-in sign-in warns when /oauth endpoints are absent');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
