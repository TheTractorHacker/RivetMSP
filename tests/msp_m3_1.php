<?php
// MSP-DS-166 (re-review M3-1): a card declined on the guest page leaves its PaymentIntent confirmable, so its attempt must stay held until Stripe says the intent is canceled.
// Any other path first cancels an open guest intent and charges only when the cancel worked. REAL charge_attempts.php functions on a scratch DB, Stripe faked by a lookup/cancel callable.
// Run: SCRATCH_TABLES="invoices payments invoice_charge_attempts history" bash tests/lib/with_scratch_db.sh php tests/msp_m3_1.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../includes/charge_attempts.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount) VALUES (1,'Sent',100.00),(2,'Sent',100.00),(3,'Sent',100.00),(4,'Sent',100.00),(5,'Sent',100.00),(6,'Sent',100.00),(7,'Sent',100.00),(8,'Sent',100.00)");
$pis = []; $cancelled = [];
$lookup = function (string $pi, bool $cancel = false) use (&$pis, &$cancelled): ?string {
    if (!isset($pis[$pi])) { return null; }
    if ($cancel && str_starts_with($pis[$pi], 'requires_')) { $pis[$pi] = 'canceled'; $cancelled[] = $pi; return 'canceled'; }
    return $pis[$pi];
};
$guest = function (int $inv, string $pi, string $age = '1 MINUTE') use ($m, &$pis) {
    $g = chargeAttemptClaim($m, $inv, 'guest', 0); chargeAttemptStoreIntent($m, $g['attempt_id'], $pi); $pis[$pi] = 'requires_payment_method';
    $m->query("UPDATE invoice_charge_attempts SET attempt_updated_at = NOW() - INTERVAL $age WHERE attempt_id = " . $g['attempt_id']);
    return $g['attempt_id'];
};
$st = fn (int $id) => $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_id = $id");

// the webhook for payment_intent.payment_failed does NOT free the guest attempt
$g1 = $guest(1, 'pi_g1');
chargeAttemptSettleIntent($m, 'pi_g1', 0, 0, 'failed', 'webhook', true);
$ok('payment_failed leaves the guest attempt processing and live', $st($g1) === 'processing' && (chargeAttemptLive($m, 1)['attempt_id'] ?? 0) == $g1);
$hook = file_get_contents(__DIR__ . '/../guest/payment_webhook.php');
$ok("the webhook passes keep-open for payment_failed only", str_contains($hook, "'webhook', \$event_type === 'payment_intent.payment_failed'"));
chargeAttemptSettleIntent($m, 'pi_g1', 0, 0, 'failed');
$ok('payment_intent.canceled (no keep flag) still settles it failed', $st($g1) === 'failed');

// cron / agent / portal claim while the guest intent is open: canceled at Stripe first, then charged
foreach (['cron', 'agent', 'portal'] as $i => $src) {
    $inv = 2 + $i; $g = $guest($inv, "pi_open$inv"); $cancelled = [];
    $r = chargeAttemptClaim($m, $inv, $src, 7, $lookup);
    $ok("$src: the open guest PaymentIntent is canceled, then the claim succeeds", $r['ok'] && $cancelled === ["pi_open$inv"] && $st($g) === 'failed' && $pis["pi_open$inv"] === 'canceled');
}
// the same after the guest window (the page may still be open)
$g = $guest(5, 'pi_old5', '3 HOUR'); $cancelled = [];
$r = chargeAttemptClaim($m, 5, 'cron', 7, $lookup);
$ok('a guest intent older than the guest window is canceled before anything else charges', $r['ok'] && $cancelled === ['pi_old5']);

// the customer confirmed with another card: cancel fails, the claim is refused and the guest attempt is untouched
$g = $guest(6, 'pi_paid6'); $pis['pi_paid6'] = 'succeeded';
$r = chargeAttemptClaim($m, 6, 'agent', 7, $lookup);
$ok('PaymentIntent already succeeded: refused, guest attempt kept', !$r['ok'] && $st($g) === 'processing');
$g = $guest(7, 'pi_proc7'); $pis['pi_proc7'] = 'processing';
$r = chargeAttemptClaim($m, 7, 'agent', 7, $lookup);
$ok('PaymentIntent processing: refused', !$r['ok'] && $st($g) === 'processing');
$g = $guest(8, 'pi_unk8', '3 HOUR'); unset($pis['pi_unk8']);
$r = chargeAttemptClaim($m, 8, 'cron', 7, $lookup);
$ok('Stripe cannot be asked: refused, not freed (even past the guest window)', !$r['ok'] && $st($g) === 'processing');
$r = chargeAttemptClaim($m, 8, 'cron', 7);
$ok('no lookup supplied: refused too', !$r['ok'] && $st($g) === 'processing');

// wiring: every non-guest claim passes the Stripe callable
foreach (['agent/post/payment.php' => 2, 'client/post.php' => 1, 'cron/cron.php' => 1] as $f => $n) {
    $src = file_get_contents(__DIR__ . '/../' . $f);
    $ok("$f: all claims pass a PaymentIntent lookup/cancel callable", preg_match_all('/chargeAttemptClaim\([^;]*chargeAttempt(?:Stripe|Provider)Lookup\(/', $src) === $n);
}
exit($fail ? 1 : 0);
