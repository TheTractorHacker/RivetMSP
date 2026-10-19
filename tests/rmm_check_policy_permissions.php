<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * MSP-9 regression: every check-policy action that changes or removes policies needs RMM modify rights (level 2).
 * The blanket check at the top of agent/post/rmm_check.php only requires read access (level 1), so a read-only RMM user could
 * save, delete or un-deploy Tactical check policies. DB-free source-shape test.
 *   php tests/rmm_check_policy_permissions.php        (exit 0 = all pass)
 */
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$s = file_get_contents(__DIR__ . '/../agent/post/rmm_check.php');
$ok(strpos($s, "enforceUserPermission('module_rmm');") !== false, 'the blanket module_rmm (read) check still exists');
foreach (['push_policy', 'remove_policy', 'save_policy', 'delete_policy'] as $action) {
    $start = strpos($s, "if (\$action === '$action') {");
    $ok($start !== false, "$action: handler found");
    if ($start === false) { continue; }
    $next = strpos($s, "\nif (\$action === ", $start + 10);
    $body = substr($s, $start, ($next === false ? strlen($s) : $next) - $start);
    $perm = strpos($body, "enforceUserPermission('module_rmm', 2);");
    $ok($perm !== false, "$action: requires RMM level 2");
    // the permission check must run before anything touches the database or an agent
    $first = PHP_INT_MAX;
    foreach (['mysqli_query', 'rmm_client', 'json_encode', 'curl'] as $needle) {
        $p = strpos($body, $needle);
        if ($p !== false && $p < $first) { $first = $p; }
    }
    $ok($perm !== false && $perm < $first, "$action: the check comes before any query, RMM call or output");
}
echo $fails === 0 ? "ALL PASSED\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
