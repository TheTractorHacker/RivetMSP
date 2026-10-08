<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Regression checks for the 2026-10-07/08 nightly review fixes. DB-free: source-shape assertions plus pure-function tests.
 *   php tests/nightly_2026_10_08.php        (exit 0 = all pass)
 */
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$src = function (string $rel): string { return file_get_contents(__DIR__ . '/../' . $rel); };
/** Body of the `if (isset($_POST['x'])) {` block, up to the next top-level handler. */
$block = function (string $file, string $post) use ($src): string {
    $s = $src($file);
    $p = strpos($s, "isset(\$_POST['$post'])");
    if ($p === false) return '';
    $n = strpos($s, "\nif (isset(\$_POST[", $p + 10);
    return substr($s, $p, ($n === false ? strlen($s) : $n) - $p);
};

// MSP-1: add_invoice_from_ticket
$b = $block('agent/post/ticket.php', 'add_invoice_from_ticket');
$ok($b !== '', 'MSP-1: handler found');
$ok(strpos($b, 'WHERE invoice_id = $invoice_id AND invoice_client_id = $client_id') !== false, 'MSP-1: existing invoice must belong to the ticket client');
$ok(strpos($b, 'invoiceChangeBlockReason($mysqli, $invoice_id)') !== false, 'MSP-1: Paid/locked invoices are refused');
$ok(strpos($b, "Ticket not found") !== false, 'MSP-1: missing ticket is refused');

exit($fails ? 1 : 0);
