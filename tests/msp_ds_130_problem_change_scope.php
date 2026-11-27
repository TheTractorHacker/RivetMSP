<?php
// MSP-DS-130: problem change picker and link_problem_change respect client access.
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$d = file_get_contents(__DIR__ . '/../agent/problem_details.php');
$ok(strpos($d, 'client_id = 0 OR client_id IS NULL OR client_id IN ($client_access_string)') !== false && strpos($d, '!$session_is_admin') !== false, 'picker lists only visible clients\' changes (and internal ones) for restricted users');
$p = file_get_contents(__DIR__ . '/../agent/post/problem.php');
$a = strpos($p, "isset(\$_POST['link_problem_change'])"); $blk = substr($p, $a, 1400);
$ok(strpos($blk, "getFieldById('changes', \$change_id, 'client_id')") !== false && strpos($blk, 'enforceClientAccess($change_client_id)') !== false, 'link_problem_change enforces access to the change\'s client');
$ok(strpos($blk, 'enforceClientAccess(') < strpos($blk, 'linkChange('), 'check runs before linking');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
