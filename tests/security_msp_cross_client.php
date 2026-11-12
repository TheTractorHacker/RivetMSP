<?php
declare(strict_types=1);
// MSP-INJ-4, 5, 6, 7, 8, 9: cross-client writes and reads. Source-level (no database): each fix must sit before the change/read it protects.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__); $fail = 0;
$ok = function (string $n, bool $c) use (&$fail): void { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$f = static fn (string $p): string => (string) file_get_contents("$root/$p");
$before = static fn (string $s, string $a, string $b, int $from = 0): bool => ($x = strpos($s, $a, $from)) !== false && ($y = strpos($s, $b, $from)) !== false && $x < $y;

$a = $f('agent/ajax.php');
foreach (['client_set_notes' => 'UPDATE clients SET client_notes', 'contact_set_notes' => 'UPDATE contacts SET contact_notes', 'asset_set_notes' => 'UPDATE assets SET asset_notes'] as $k => $upd) {
    $s = strpos($a, "isset(\$_POST['$k'])");
    $ok("INJ-4 $k: client access is enforced before the UPDATE", $s !== false && $before($a, 'enforceClientAccess($client_id)', $upd, $s));
}
$t = $f('agent/post/transfer.php');
$ok('INJ-5 edit_transfer: expense/revenue ids come from the transfers row', str_contains($t, 'SELECT transfer_expense_id, transfer_revenue_id FROM transfers') && !str_contains($t, "\$expense_id = intval(\$_POST['expense_id'])") && !str_contains($t, "\$revenue_id = intval(\$_POST['revenue_id'])"));
$p = $f('agent/post/project.php');
$e = strpos($p, "isset(\$_POST['edit_project'])");
$ok('INJ-6 edit_project: the stored project client is checked before the UPDATE', $before($p, 'SELECT project_client_id FROM projects', 'UPDATE projects SET project_name', $e) && str_contains($p, 'enforceClientAccess(intval($stored_project'));
$ok('INJ-6 link_ticket_to_project: only tickets of the project\'s own client', str_contains($p, 'intval($row[\'ticket_client_id\']) !== $client_id'));
$ok('INJ-6 project pages: link modal uses the stored client; ticket list is scoped', str_contains($f('agent/modals/project/project_link_ticket.php'), 'SELECT project_client_id FROM projects') && str_contains($f('agent/project_details.php'), '$project_ticket_scope'));
$w = $f('agent/post/worksheet.php');
$ok('INJ-7 worksheets: client derived from the worksheet row; signed worksheets cannot be edited or deleted', substr_count($w, 'worksheetOwnedOrStop(') >= 4 && substr_count($w, 'worksheet_signed_at') >= 2 && !str_contains($w, "getFieldById('tickets', \$ticket_id, 'ticket_client_id')"));
$ok('INJ-8 client export is scoped to accessible clients', str_contains($f('agent/post/client.php'), 'WHERE 1 = 1 $access_permission_query'));
$ok('INJ-8 expense export is scoped to accessible clients', str_contains($f('agent/post/expense.php'), 'expense_client_id IN ($client_access_string)'));
$v = $f('agent/post/vendor_contact.php');
$ok('INJ-8 vendor-contact export/import check the POSTed client', substr_count($v, 'enforceClientAccess($client_id)') >= 2);
$m = $f('includes/modal_header.php');
$ok('INJ-9 modals: money modals need module_financial/module_sales, ticket modals module_support', str_contains($m, "'payment' => 'module_financial'") && str_contains($m, "'invoice' => 'module_sales'") && str_contains($m, "'ticket' => 'module_support'"));
$ok('INJ-9 ticket modals: the ticket\'s client is checked', str_contains($m, 'You do not have access to this client.'));
$ok('INJ-10 dashboard: the old GET flag page only redirects (widgets carry module and client gates)', !str_contains($f('agent/dashboard.php'), 'enable_financial') && str_contains($f('src/Reports/Widgets/WidgetRegistry.php'), "'modules' => ['module_financial']"));
// the modal map must list every agent/modals folder and file (fail closed otherwise, but a new one should be put in the map deliberately)
$mapSrc = $f('includes/modal_header.php');
$missing = [];
foreach (scandir("$root/agent/modals") as $e) {
    if ($e === '.' || $e === '..') { continue; }
    $key = is_dir("$root/agent/modals/$e") ? $e : basename($e, '.php');
    if (!str_contains($mapSrc, "'$key' =>")) { $missing[] = $key; }
}
$ok('INJ-9: every agent/modals folder/file is in the module map' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''), $missing === []);
$ok('INJ-9: an unmapped folder fails closed to module_support', str_contains($mapSrc, "?? 'module_support'"));
exit($fail ? 1 : 0);
