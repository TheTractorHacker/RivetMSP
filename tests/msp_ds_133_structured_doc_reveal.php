<?php
// MSP-DS-133: structured-doc password reveal needs module_credential and goes through the vault reveal limit.
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$s = file_get_contents(__DIR__ . '/../agent/post/structured_doc.php');
$a = strpos($s, "isset(\$_POST['reveal_structured_doc_field'])"); $b = substr($s, $a);
$ok(strpos($b, "lookupUserPermission('module_credential') < 1") !== false, 'needs module_credential');
$ok(strpos($b, 'vaultDisclosureCheck($mysqli') !== false, 'goes through the vault reveal limit / log');
$ok(strpos($b, 'vaultDisclosureCheck(') < strpos($b, 'decryptSetting('), 'the limit is applied before decrypting');
$ok(strpos($b, 'module_credential') < strpos($b, 'SELECT doc_client_id'), 'permission checked before any lookup');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
