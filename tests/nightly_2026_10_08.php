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

// MSP-3: client portal contact edit
$b = $block('client/post.php', 'edit_contact');
$ok(preg_match('/session_contact_primary != 1 && \(\$row\[\'contact_technical\'\] == 1 \|\| \$row\[\'contact_billing\'\] == 1\) && \$contact_id != intval\(\$session_contact_id\)/', $b) === 1, 'MSP-3: non-primary editor cannot touch a technical/billing contact');
$ok(strpos($b, 'WHERE user_id = $contact_user_id AND user_type = 2') !== false, 'MSP-3: users UPDATE is limited to portal users (user_type = 2)');

// MSP-4: API tokens are revoked on disable / 2FA reset / role change
$u = $src('admin/post/users.php');
foreach (['disable_user' => 'GET', 'disable_2fa' => 'GET'] as $k => $_) {
    $p = strpos($u, "isset(\$_GET['$k'])"); $n = strpos($u, "\nif (isset(", $p + 10);
    $ok($p !== false && strpos(substr($u, $p, $n - $p), 'DELETE FROM api_tokens WHERE token_user_id') !== false, "MSP-4: $k revokes API tokens");
}
$e = $block('admin/post/users.php', 'edit_user');
$ok(strpos($e, '$previous_role_id !== intval($role)') !== false && strpos($e, 'DELETE FROM api_tokens', strpos($e, '$previous_role_id !== intval')) !== false, 'MSP-4: role change revokes API tokens');
$ok(preg_match("/\\\$two_fa == 'disable'\) \{.*?DELETE FROM api_tokens/s", $e) === 1, 'MSP-4: edit_user 2FA reset revokes API tokens');
$pf = $src('agent/user/post/profile.php');
$p = strpos($pf, "isset(\$_GET['disable_mfa'])");
$ok($p !== false && strpos(substr($pf, $p, 900), 'DELETE FROM api_tokens') !== false, 'MSP-4: self disable_mfa revokes API tokens');

exit($fails ? 1 : 0);
