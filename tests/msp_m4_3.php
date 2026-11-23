<?php
// MSP-DS-166 (re-review M4-3, M3-7 residual): a stale attempt with a stored PaymentIntent id used to be ABANDONED (and another method charged) when the Stripe
// lookup merely errored; a row with no id was abandoned without asking Stripe at all. Now: a stored id plus a null/errored lookup refuses (in_progress, needs_review
// after the 23h key window); a row without an id is abandoned only when a Stripe search by the attempt key (kept in PaymentIntent metadata) finds nothing.
// REAL charge_attempts.php functions, scratch DB, Stripe faked by lookup/search callables.
// Run: SCRATCH_TABLES="invoices payments invoice_charge_attempts history" bash tests/lib/with_scratch_db.sh php tests/msp_m4_3.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../includes/charge_attempts.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
for ($i = 1; $i <= 9; $i++) { $m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount) VALUES ($i,'Sent',100.00)"); }
$old = function (int $inv, string $age, string $pi = '', int $method = 3) use ($m) {
    $m->query("INSERT INTO invoice_charge_attempts SET attempt_invoice_id = $inv, attempt_key = 'oldkey-$inv', attempt_balance_cents = 10000, attempt_method_id = $method, attempt_source = 'portal', attempt_status = 'processing', attempt_pi_id = '$pi', attempt_created_at = NOW() - INTERVAL $age, attempt_updated_at = NOW() - INTERVAL $age");
};
$st = fn (int $inv) => $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_key = 'oldkey-$inv'");
$searchedKeys = [];
$mk = function (?string $piAnswer, $searchAnswer) use (&$searchedKeys): callable {
    return function (string $pi, bool $cancel = false, ?string $key = null, bool $bank = false) use ($piAnswer, $searchAnswer, &$searchedKeys) {
        if ($key !== null) { $searchedKeys[] = $key; return $searchAnswer; }
        return $piAnswer;
    };
};

// stored PaymentIntent id, Stripe lookup errored (null): REFUSE, never abandon
$old(1, '30 MINUTE', 'pi_unknown');
$r = chargeAttemptClaim($m, 1, 'agent', 5, $mk(null, null));
$ok('stored PI + errored lookup: another method is refused (in_progress), not charged', !$r['ok'] && $r['reason'] === 'in_progress' && $st(1) === 'processing');
$r = chargeAttemptClaim($m, 1, 'cron', 6, $mk(null, null));
$ok('... and the row keeps blocking every later claim', !$r['ok'] && $st(1) === 'processing');
$m->query("UPDATE invoice_charge_attempts SET attempt_created_at = NOW() - INTERVAL 25 HOUR, attempt_updated_at = NOW() - INTERVAL 25 HOUR WHERE attempt_key = 'oldkey-1'");
$r = chargeAttemptClaim($m, 1, 'agent', 5, $mk(null, null));
$ok('... past 23h it is needs_review and flagged on the invoice', !$r['ok'] && $r['reason'] === 'needs_review' && intval($one("SELECT COUNT(*) FROM history WHERE history_invoice_id = 1 AND history_status = 'Payment needs review'")) === 1);

// stored id, no lookup callable at all: refused as well
$old(2, '30 MINUTE', 'pi_nolookup');
$r = chargeAttemptClaim($m, 2, 'agent', 5);
$ok('stored PI + no lookup possible: refused', !$r['ok'] && $st(2) === 'processing');

// a definite answer still frees the row
$old(3, '30 MINUTE', 'pi_cancelled');
$r = chargeAttemptClaim($m, 3, 'agent', 5, $mk('canceled', null));
$ok('stored PI that Stripe says is canceled: abandoned, a new attempt is allowed', $r['ok'] && $st(3) === 'failed');

// NO stored id: a Stripe search by the attempt key decides
$old(4, '30 MINUTE');
$r = chargeAttemptClaim($m, 4, 'agent', 5, $mk(null, 'none'));
$ok('no PI id + search finds nothing: abandoned, new attempt allowed', $r['ok'] && $st(4) === 'failed' && $searchedKeys === ['oldkey-4']);
$old(5, '30 MINUTE');
$r = chargeAttemptClaim($m, 5, 'agent', 5, $mk(null, 'found'));
$ok('no PI id + search finds an intent: refused, row kept', !$r['ok'] && $st(5) === 'processing');
$old(6, '30 MINUTE');
$r = chargeAttemptClaim($m, 6, 'agent', 5, $mk(null, null));
$ok('no PI id + search errors: refused, row kept', !$r['ok'] && $st(6) === 'processing');
$old(7, '30 MINUTE');
$r = chargeAttemptClaim($m, 7, 'agent', 5);
$ok('no PI id + no way to search: refused, row kept', !$r['ok'] && $st(7) === 'processing');
$old(8, '25 HOUR');
$r = chargeAttemptClaim($m, 8, 'agent', 5, $mk(null, 'none'));
$ok('no PI id, 25h old, search finds nothing: abandoned (the search is not limited by the 24h key window)', $r['ok'] && $st(8) === 'failed');

// the same method still replays its key without asking Stripe
$old(9, '30 MINUTE', '', 5);
$asked = false;
$r = chargeAttemptClaim($m, 9, 'portal', 5, function () use (&$asked) { $asked = true; return null; });
$ok('same method: key replayed as before, no Stripe call', $r['ok'] && $r['reused'] && !$asked);

// the search capability of the real lookup builders
$called = [];
$lk = chargeAttemptPiOps(fn ($id) => (object) ['status' => 'succeeded'], function (string $key) use (&$called) { $called[] = $key; return []; });
$ok('chargeAttemptPiOps search: empty result is none', $lk('', false, 'k1') === 'none' && $called === ['k1']);
$lk = chargeAttemptPiOps(fn ($id) => (object) ['status' => 'succeeded'], fn ($key) => [(object) ['id' => 'pi_x']]);
$ok('chargeAttemptPiOps search: a hit is found', $lk('', false, 'k1') === 'found');
$lk = chargeAttemptPiOps(fn ($id) => (object) ['status' => 'succeeded'], function ($key) { throw new RuntimeException('timeout'); });
$ok('chargeAttemptPiOps search: an error is null (never none)', $lk('', false, 'k1') === null);
$lk = chargeAttemptPiOps(fn ($id) => (object) ['status' => 'succeeded']);
$ok('chargeAttemptPiOps without a search callable: null', $lk('', false, 'k1') === null);
$prov = new class { public $k; public function verifyCheckoutResult($id) { return (object) ['status' => 'canceled']; } public function searchCheckoutByAttemptKey(string $k): array { $this->k = $k; return []; } };
$ok('chargeAttemptProviderLookup uses the provider search', chargeAttemptProviderLookup($prov)('', false, 'abc') === 'none' && $prov->k === 'abc');

// every PaymentIntent create carries the attempt key in metadata so the search can find it
foreach (['guest/guest_ajax.php' => 1, 'agent/post/payment.php' => 2, 'client/post.php' => 1, 'cron/cron.php' => 1] as $f => $n) {
    $ok("$f: PaymentIntent metadata carries rivet_attempt_key ($n)", substr_count(file_get_contents(__DIR__ . '/../' . $f), "'rivet_attempt_key' =>") === $n);
}
$ok('the Stripe provider can search by attempt key', str_contains(file_get_contents(__DIR__ . '/../includes/class_stripe_payment_provider.php'), 'searchCheckoutByAttemptKey') && str_contains(file_get_contents(__DIR__ . '/../includes/class_stripe_payment_provider.php'), "metadata['rivet_attempt_key']"));
exit($fail ? 1 : 0);
