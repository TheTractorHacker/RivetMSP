<?php
// MSP-DS-76: API alert ack/resolve and expense create need the web handlers' permission levels. Run: php tests/msp_ds_76_api_write_levels.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$root = dirname(__DIR__);
$a = file_get_contents("$root/api/v1/alerts.php");
$post = substr($a, strpos($a, "if (\$method === 'POST')"));
$ok(preg_match("/if \(\\\$method === 'POST'\) \{\s*\/\/[^\n]*\n\s*api_require_module_permission\(\\\$mysqli, \\\$api_user_id, 'module_rmm_alerts_ack'\);/", $a) === 1, 'alerts POST requires module_rmm_alerts_ack before any update');
$ok(strpos($post, 'module_rmm_alerts_ack') < strpos($post, 'UPDATE rmm_alerts'), 'permission check precedes the UPDATEs');
$e = file_get_contents("$root/api/v1/expenses.php");
$ok(str_contains($e, "'module_financial', \$method === 'GET' ? 1 : 2)"), 'expenses: POST needs module_financial level 2');
$ok(!str_contains($e, "pathinfo(\$_FILES['receipt']['name']"), 'receipt extension no longer from the client filename');
// extension map behaviour
$map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'application/pdf' => 'pdf'];
$ok(str_contains($e, "['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'application/pdf' => 'pdf'][\$mime]"), 'extension map covers every allowed MIME type');
// behaviour of the level check against a fake DB-free reading of the helper: level arg is honoured
$p = file_get_contents("$root/api/v1/includes/api_permissions.php");
$ok(str_contains($p, 'urp.user_role_permission_level >= $min_level'), 'helper compares against the requested level');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
