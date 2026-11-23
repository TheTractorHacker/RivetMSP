<?php
// MSP-DS-128: editing a contract needs Sales write, deleting needs Sales full (both can pause recurring invoices).
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$s = file_get_contents(__DIR__ . '/../agent/post/contract.php');
$e = strpos($s, "isset(\$_POST['edit_contract'])"); $ok($e !== false && strpos(substr($s, $e, 300), "enforceUserPermission('module_sales', 2)") !== false, 'edit_contract needs module_sales 2');
$d = strpos($s, "isset(\$_GET['delete_contract'])"); $ok($d !== false && strpos(substr($s, $d, 300), "enforceUserPermission('module_sales', 3)") !== false, 'delete_contract needs module_sales 3');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
