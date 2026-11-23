<?php
// MSP-DS-7: global/live search sections follow the module grants; the synced-scripts tab is client scoped. Run: php tests/msp_ds_7_search_module_scope.php
require dirname(__DIR__) . '/includes/search_scope.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$ok('no grant (false) denies', searchModuleDeny(false) === 'AND 1=0');
$ok('level 0 denies', searchModuleDeny(0) === 'AND 1=0');
$ok('read allows', searchModuleDeny(1) === '' && searchModuleDeny(3) === '');
$ok('restricted tech is scoped to own clients', searchClientScope('4,7', false, 'a.asset_client_id') === 'AND a.asset_client_id IN (4,7)');
$ok('admin unscoped', searchClientScope('4,7', true, 'a.asset_client_id') === '');
$ok('unrestricted (no rows) unscoped', searchClientScope('', false, 'a.asset_client_id') === '');
$ok('non-numeric ids are dropped', searchClientScope('4,x;DROP,7', false, 'c') === 'AND c IN (4,0,7)' || searchClientScope('4,x;DROP,7', false, 'c') === 'AND c IN (4,7)');
$src = fn($f) => file_get_contents(dirname(__DIR__) . '/' . $f);
$gs = $src('agent/global_search.php'); $aj = $src('agent/ajax.php'); $sy = $src('agent/includes/rmm_scripts_synced.php');
foreach (['sql_products' => 'sales', 'sql_documents' => 'support', 'sql_files' => 'support', 'sql_tickets' => 'support', 'sql_recurring_tickets' => 'support', 'sql_quotes' => 'sales', 'sql_invoices' => 'sales', 'sql_ticket_replies' => 'support'] as $v => $m) {
    $i = strpos($gs, "\$$v = mysqli_query"); $j = strpos($gs, ');', $i);
    $ok("global_search $v carries the $m gate", $i !== false && strpos(substr($gs, $i, $j - $i), "\$gs_deny_$m") !== false);
}
$i = strpos($aj, "global_search_live");
foreach (['FROM tickets' => 'support', 'FROM quotes' => 'sales', 'FROM invoices' => 'sales'] as $t => $m) {
    $k = strpos($aj, $t, $i); $e = strpos($aj, 'LIMIT 5', $k);
    $ok("live search $t carries the $m gate", strpos(substr($aj, $k, $e - $k), "\$gs_deny_$m") !== false);
}
$ok('synced tab scopes online assets and runs', substr_count($sy, '$rs_asset_scope') >= 3);
exit($fail ? 1 : 0);
