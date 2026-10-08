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
    if ($p === false) $p = strpos($s, "isset(\$_GET['$post'])");
    if ($p === false) return '';
    $n = strpos($s, "\nif (isset(\$_", $p + 10);
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

// MSP-5: billing hardening on every payment/credit path
require_once __DIR__ . '/../includes/billing_guards.php';
$b = $block('agent/post/payment.php', 'add_bulk_payment');
$ok($b !== '', 'MSP-5: bulk handler found');
$ok(strpos($b, 'mysqli_begin_transaction') !== false && strpos($b, 'FOR UPDATE') !== false && strpos($b, 'mysqli_commit') !== false, 'MSP-5: bulk payment runs in a transaction with row locks');
$ok(strpos($b, "\$_POST['balance']") === false, 'MSP-5: bulk payment no longer trusts the posted balance');
$ok(strpos($b, 'parsePositiveMoney') !== false && strpos($b, "('Sent', 'Viewed', 'Partial', 'Overdue')") !== false, 'MSP-5: bulk payment validates the amount and skips Non-Billable/closed invoices');
$ok(strpos($b, 'floatval($_POST[\'amount\'])') === false, 'MSP-5: bulk payment amount is not a bare floatval');
$d = $block('agent/post/payment.php', 'delete_payment');
$ok($d !== '' && strpos($d, 'payment_client_id') === false && strpos($d, 'invoice_client_id') !== false, 'MSP-5: delete_payment resolves the client through the invoice');
$ok(strpos($d, '$invoice_balance == 0') === false, 'MSP-5: delete_payment compares in cents');
$c = $src('agent/post/credit.php');
$ok(strpos($c, 'parsePositiveMoney') !== false && strpos($c, "'promotion'") !== false, 'MSP-5: add_credit needs a positive amount and a known type');
$g = $src('guest/guest_pay_invoice_stripe.php');
$ok(strpos($g, 'INSERT INTO expenses') > strpos($g, 'insertStripePaymentOnce('), 'MSP-5: Stripe fee expense is inserted only after the payment was recorded once');
$ok(strpos($g, 'INSERT INTO expenses') > strpos($g, 'moneyToCents($balance_to_pay) !== moneyToCents($pi_amount_paid)'), 'MSP-5: Stripe fee expense is inserted only after the balance check');
$ok(strpos($g, 'invoiceStatusAfterPayment(') !== false && strpos($src('guest/payment_webhook.php'), 'invoiceStatusAfterPayment(') !== false, 'MSP-5: final Stripe status uses the cents helper (portal and webhook)');
$ok(substr_count($g . $src('guest/payment_webhook.php') . $src('guest/guest_ajax.php'), "'Cancelled','Non-Billable'") + substr_count($g, "'Cancelled', 'Non-Billable'") >= 4, 'MSP-5: online payment queries exclude Non-Billable invoices');

// MSP-6: balances under 1.00 are payable online
$ok(strpos($src('guest/guest_ajax.php'), 'intval($balance_to_pay) == 0') === false && strpos($src('guest/guest_ajax.php'), 'moneyToCents($balance_to_pay) <= 0') !== false, 'MSP-6: guest_ajax no longer rounds the balance down to whole dollars');
$ok(moneyToCents(0.50) > 0 && moneyToCents(0.01) > 0 && moneyToCents(0.004) <= 0 && moneyToCents(0.0) <= 0, 'MSP-6: cents check treats 0.50 and 0.01 as payable, 0 as nothing owed');
$ok(invoiceStatusAfterPayment(10.00, 9.50) === 'Partial' && invoiceStatusAfterPayment(10.00, 10.00) === 'Paid', 'MSP-5: status after payment in cents');

exit($fails ? 1 : 0);
