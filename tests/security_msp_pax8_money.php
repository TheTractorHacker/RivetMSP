<?php
declare(strict_types=1);
// MSP-INJ-22: Pax8 re-map/un-map stops billing the old client; billing picks a deterministic map row and only subscriptions of companies with billing on. Source-level
// (no Pax8 call is ever made; the logic is SQL text). The cron invoice prefix/currency (Low) is NOT part of this work.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__); $fail = 0;
$ok = function (string $n, bool $c) use (&$fail): void { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$s = (string) file_get_contents("$root/admin/settings_pax8.php");
$arch = strpos($s, 'UPDATE recurring_invoice_items SET item_archived_at = NOW()');
$ok('re-map/un-map archives the company lines on the old recurring invoice before the map row changes', $arch !== false && $arch < strpos($s, 'UPDATE pax8_client_map SET recurring_invoice_id = IF('));
$ok('and recalculates the old invoice and repoints the subscriptions', str_contains($s, 'pax8RecalcRecurringInvoiceAmount($mysqli, $old_ri)') && str_contains($s, 'UPDATE pax8_subscriptions SET client_id = $client_sql'));
$f = (string) file_get_contents("$root/includes/pax8_functions.php");
$ok('the map row is deterministic (billing on, ORDER BY map_id)', str_contains($f, 'billing_sync_enabled = 1 ORDER BY map_id ASC LIMIT 1'));
$ok('only subscriptions of companies mapped to this client with billing on are billed', str_contains($f, 'JOIN pax8_client_map m ON m.pax8_company_id = s.pax8_company_id'));
$g = strpos($f, "GET_LOCK('rivet_pax8_billing_sync', 30)");
$ok('INJ-22(d): the subscription replace takes the billing lock and runs in one transaction', $g !== false && $g < strpos($f, "DELETE FROM pax8_subscriptions WHERE pax8_company_id") && str_contains($f, 'mysqli_begin_transaction($mysqli)') && str_contains($f, 'mysqli_commit($mysqli)') && str_contains($f, 'mysqli_rollback($mysqli)'));
exit($fail ? 1 : 0);
