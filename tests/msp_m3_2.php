<?php
// MSP-DS-166 (re-review M3-2): abandoned guest page intents are expired (PaymentIntent canceled, attempt closed), a guest claim over an old open intent works, and guest_ajax answers JSON errors.
// REAL charge_attempts.php functions on a scratch DB, Stripe faked by a lookup/cancel callable.
// Run: SCRATCH_TABLES="invoices payments invoice_charge_attempts history" bash tests/lib/with_scratch_db.sh php tests/msp_m3_2.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../includes/charge_attempts.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount) VALUES (1,'Sent',100.00),(2,'Sent',100.00),(4,'Sent',10.00),(5,'Sent',10.00)");
$pis = []; $cancelled = [];
$lookup = function (string $pi, bool $cancel = false) use (&$pis, &$cancelled): ?string {
    if (!isset($pis[$pi])) { return null; }
    if ($cancel && str_starts_with($pis[$pi], 'requires_')) { $pis[$pi] = 'canceled'; $cancelled[] = $pi; return 'canceled'; }
    return $pis[$pi];
};
$guest = function (int $inv, string $pi, string $age) use ($m, &$pis) {
    $g = chargeAttemptClaim($m, $inv, 'guest', 0); chargeAttemptStoreIntent($m, $g['attempt_id'], $pi); $pis[$pi] = 'requires_payment_method';
    $m->query("UPDATE invoice_charge_attempts SET attempt_created_at = NOW() - INTERVAL $age, attempt_updated_at = NOW() - INTERVAL $age WHERE attempt_id = " . $g['attempt_id']);
    return $g['attempt_id'];
};
$st = fn (int $id) => $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_id = $id");

// 23h+ later a guest claim over the abandoned intent: with a lookup it does not dead-end in needs_review
$g1 = $guest(1, 'pi_a1', '25 HOUR');
$r = chargeAttemptClaim($m, 1, 'guest', 0, $lookup);
$ok('a guest claim over a 25h-old open intent cancels it and succeeds (no needs_review dead end)', $r['ok'] && $cancelled === ['pi_a1'] && $st($g1) === 'failed');

// expiry (cron): old guest intents canceled and closed, fresh ones and paid ones left alone
$cancelled = [];
$old = $guest(2, 'pi_old', '3 HOUR');
$fresh = $guest(4, 'pi_fresh', '5 MINUTE');
$paid = $guest(5, 'pi_paid', '3 HOUR'); $pis['pi_paid'] = 'succeeded';
$n = chargeAttemptExpireGuest($m, $lookup);
$ok('expiry closes only the abandoned, cancelable guest intent', $n === 1 && $cancelled === ['pi_old'] && $st($old) === 'failed' && $st($fresh) === 'processing' && $st($paid) === 'processing');
$ok('the closed attempt is marked abandoned', $one("SELECT attempt_error FROM invoice_charge_attempts WHERE attempt_id = $old") === 'abandoned');
$r = chargeAttemptClaim($m, 2, 'cron', 7, $lookup);
$ok('after expiry autopay is no longer blocked', $r['ok']);
$ok('expiry is idempotent', chargeAttemptExpireGuest($m, $lookup) === 0);

// wiring: cron runs the expiry; guest_ajax answers JSON for every refusal, passes a lookup, reuses the stored intent whatever its age
$cron = file_get_contents(__DIR__ . '/../cron/cron.php');
$ok('cron expires abandoned guest intents', str_contains($cron, 'chargeAttemptExpireGuest('));
$ajax = file_get_contents(__DIR__ . '/../guest/guest_ajax.php');
$ok('guest_ajax has no plain-text exit left', !preg_match('/\bexit\("/', $ajax));
$ok('guest_ajax claims with a Stripe lookup', str_contains($ajax, "'guest', 0, chargeAttemptProviderLookup("));
$ok('guest_ajax finds its open intent regardless of age', str_contains($ajax, "attempt_source = 'guest' AND attempt_pi_id <> '' ORDER BY attempt_id DESC"));
$js = file_get_contents(__DIR__ . '/../js/guest_pay_invoice_stripe.js');
$ok('the page shows the error from the endpoint', str_contains($js, 'intent.error') && $js === file_get_contents(__DIR__ . '/../agent/js/guest_pay_invoice_stripe.js'));
exit($fail ? 1 : 0);
