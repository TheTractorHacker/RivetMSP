<?php
// MSP-DS-166/32 (re-review M5-3): the admin "Clear this stuck charge attempt" freed a row whose PaymentIntent could still complete (bank debit awaiting microdeposits,
// an open guest page at requires_payment_method, 3DS requires_action) and never canceled it, so the customer could pay after billing charged another method.
// Now Clear does a lookup WITH cancel and include_bank and accepts only canceled/missing; the invoice page marks live attempts as not clearable.
// REAL chargeAttemptClear() / chargeAttemptPiOps() / chargeAttemptLiveCondition() on a scratch DB, Stripe faked behind the real lookup.
// Run: SCRATCH_TABLES="invoices payments invoice_charge_attempts history" bash tests/lib/with_scratch_db.sh php tests/msp_m5_3.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../includes/charge_attempts.php';
require_once __DIR__ . '/../plugins/stripe-php/init.php';
require __DIR__ . '/lib/m5_stripe_fakes.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount) VALUES (1,'Sent',100.00)");
$mk = function (int $id, string $src, string $status, string $pi) use ($m) {
    $m->query("INSERT INTO invoice_charge_attempts SET attempt_id = $id, attempt_invoice_id = 1, attempt_key = 'k$id', attempt_balance_cents = 10000, attempt_method_id = 5, attempt_source = '$src', attempt_status = '$status', attempt_pi_id = '$pi'");
};
$st = fn (int $id) => $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_id = $id");

$s = new M5Stripe;
$s->add('pi_bank', 'requires_action', '', true); $mk(1, 'portal', 'pending', 'pi_bank');
$r = chargeAttemptClear($m, 1, 'customer called', $s->lookup(), 'Alice');
$ok('a bank debit awaiting microdeposits: Clear CANCELS the PaymentIntent, then frees the attempt', $r['ok'] && $s->pis['pi_bank']->status === 'canceled' && $st(1) === 'failed');

$s->add('pi_guest', 'requires_payment_method'); $mk(2, 'guest', 'processing', 'pi_guest');
$r = chargeAttemptClear($m, 2, 'stuck', $s->lookup(), 'Alice');
$ok('an open guest page intent (requires_payment_method): canceled so the page cannot pay later', $r['ok'] && $s->pis['pi_guest']->status === 'canceled' && $st(2) === 'failed');

$s->add('pi_3ds', 'requires_action'); $mk(3, 'agent', 'processing', 'pi_3ds');
$r = chargeAttemptClear($m, 3, 'stuck', $s->lookup(), 'Alice');
$ok('a 3DS requires_action intent: canceled', $r['ok'] && $s->pis['pi_3ds']->status === 'canceled' && $st(3) === 'failed');

$s->add('pi_paid', 'succeeded'); $mk(4, 'cron', 'processing', 'pi_paid');
$ok('succeeded: refused, row kept', !chargeAttemptClear($m, 4, 'stuck', $s->lookup())['ok'] && $st(4) === 'processing');

// the customer confirmed between the retrieve and the cancel: cancel throws and the fresh status says processing
$race = new class('pi_race', 'requires_payment_method') extends M5Pi { public function cancel() { $this->status = 'processing'; throw new RuntimeException('already confirmed'); } };
$s->pis['pi_race'] = $race; $mk(5, 'guest', 'processing', 'pi_race');
$ok('confirmed while clearing: refused, row kept', !chargeAttemptClear($m, 5, 'stuck', $s->lookup())['ok'] && $st(5) === 'processing');

$mk(6, 'portal', 'processing', 'pi_nowhere');
$ok('Stripe has no such intent (missing): cleared', chargeAttemptClear($m, 6, 'key switch', $s->lookup())['ok'] && $st(6) === 'failed');

// a lookup that merely reports a still-live status is never enough
$mk(7, 'guest', 'processing', 'pi_x');
$seen = [];
$fake = function (string $pi, bool $cancel = false, ?string $k = null, bool $bank = false) use (&$seen) { $seen[] = [$cancel, $bank]; return 'requires_action_bank'; };
$ok('only canceled/missing is accepted (a live-looking status refuses)', !chargeAttemptClear($m, 7, 'stuck', $fake)['ok'] && $st(7) === 'processing');
$ok('the lookup is asked with cancel=true and include_bank=true', $seen === [[true, true]]);
$mk(8, 'guest', 'processing', 'pi_y');
$ok('a requires_payment_method answer (not canceled) is refused too', !chargeAttemptClear($m, 8, 'stuck', fn ($p, $c = false, $k = null, $b = false) => 'requires_payment_method')['ok'] && $st(8) === 'processing');

// ---- the invoice page: a live attempt is not clearable
$m->query("UPDATE invoice_charge_attempts SET attempt_status = 'failed'");
$mk(20, 'portal', 'processing', '');
$mk(21, 'guest', 'processing', 'pi_g');
$m->query("UPDATE invoice_charge_attempts SET attempt_updated_at = NOW() - INTERVAL 2 HOUR WHERE attempt_id = 21");
$mk(22, 'portal', 'pending', 'pi_p');
$mk(23, 'portal', 'processing', '');
$m->query("UPDATE invoice_charge_attempts SET attempt_updated_at = NOW() - INTERVAL 2 HOUR WHERE attempt_id = 23");
$live = [];
$q = $m->query("SELECT attempt_id, " . chargeAttemptLiveCondition() . " AS attempt_live FROM invoice_charge_attempts WHERE attempt_status IN ('processing','pending')");
while ($row = $q->fetch_assoc()) { $live[intval($row['attempt_id'])] = (int) $row['attempt_live']; }
$ok('the live condition: fresh processing and pending are live, a 2h-old row is not', ($live[20] ?? 0) === 1 && ($live[22] ?? 0) === 1 && ($live[23] ?? 1) === 0 && ($live[21] ?? 1) === 0);
$page = file_get_contents(__DIR__ . '/../agent/invoice.php');
$ok('agent/invoice.php selects the live flag and renders no clear form for a live attempt', str_contains($page, 'chargeAttemptLiveCondition()') && preg_match('/if \(!empty\(\$stuck\[\'attempt_live\'\]\)\) \{ \?>.*?cannot be cleared yet.*?continue; \} \?>.*?name="clear_charge_attempt"/s', $page) === 1);
exit($fail ? 1 : 0);
