<?php
// MSP-DS-166/32 (re-review M5-7): a takeover replays the old idempotency key with the new rivet_attempt_key metadata; Stripe answers idempotency_error (400) when the
// original request had other parameters (rows created before the metadata existed). That was stored 'failed', so the next claim used seq+1 and charged again even if the
// original request did charge. Now ONE shared helper (chargeAttemptSend, used by agent saved-card, agent manual, portal and cron) replays the SAME key once with the
// original parameters (no metadata) on a takeover, and an idempotency_error that remains is UNKNOWN: the row stays 'processing' and the next claim looks the key up and
// then needs_review, never a fresh charge.
// REAL chargeAttemptClaim/Send/StoreException/StoreResult and the real Stripe exception classes, scratch DB.
// Run: SCRATCH_TABLES="invoices payments invoice_charge_attempts history" bash tests/lib/with_scratch_db.sh php tests/msp_m5_7.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../includes/charge_attempts.php';
require_once __DIR__ . '/../plugins/stripe-php/init.php';
require __DIR__ . '/lib/m5_stripe_fakes.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
for ($i = 1; $i <= 4; $i++) { $m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount) VALUES ($i,'Sent',100.00)"); }
$conflict = fn () => \Stripe\Exception\IdempotencyException::factory('Keys for idempotent requests can only be used with the same parameters they were first used with.', 400, null, null, null, 'idempotency_error');
$params = ['amount' => 10000, 'currency' => 'usd', 'metadata' => ['itflow_invoice_id' => 1, 'rivet_attempt_key' => 'K']];

// ---- the shared helper
$ok('the conflict is recognised from the real exception class', chargeAttemptIsIdempotencyConflict($conflict()) && !chargeAttemptIsIdempotencyConflict(\Stripe\Exception\CardException::factory('declined', 402)));
// the original request (a row from before the metadata existed) carried no rivet_attempt_key; Stripe only accepts exactly those parameters
$orig = (object) ['id' => 'pi_orig', 'status' => 'succeeded'];
$calls = [];
$stripe = function (array $p) use (&$calls, $conflict, $orig) { $calls[] = $p; if (isset($p['metadata']['rivet_attempt_key'])) { throw $conflict(); } return $orig; };
$pi = chargeAttemptSend($stripe, $params, true);
$ok('takeover: the conflict is retried once with the original parameters (no rivet_attempt_key) and returns the ORIGINAL PaymentIntent', $pi === $orig && count($calls) === 2 && !isset($calls[1]['metadata']['rivet_attempt_key']) && $calls[1]['metadata']['itflow_invoice_id'] === 1);
$calls = [];
try { chargeAttemptSend($stripe, $params, false); $thrown = false; } catch (\Throwable $e) { $thrown = chargeAttemptIsIdempotencyConflict($e); }
$ok('a fresh (non-takeover) charge is sent once and a conflict propagates', $thrown && count($calls) === 1);
$always = function (array $p) use (&$calls, $conflict) { $calls[] = $p; throw $conflict(); };
$calls = [];
try { chargeAttemptSend($always, $params, true); $thrown = false; } catch (\Throwable $e) { $thrown = chargeAttemptIsIdempotencyConflict($e); }
$ok('a conflict that remains after the original-parameter replay propagates (two sends at most)', $thrown && count($calls) === 2);
$calls = [];
$declined = function (array $p) use (&$calls) { $calls[] = $p; throw \Stripe\Exception\CardException::factory('Your card was declined.', 402); };
try { chargeAttemptSend($declined, $params, true); } catch (\Throwable $e) { }
$ok('other errors are not retried', count($calls) === 1);

// ---- the stored outcome
$m->query("INSERT INTO invoice_charge_attempts SET attempt_id = 1, attempt_invoice_id = 1, attempt_key = 'k1', attempt_balance_cents = 10000, attempt_method_id = 5, attempt_source = 'portal', attempt_status = 'processing'");
$r = chargeAttemptStoreException($m, 1, $conflict());
$ok('an idempotency_error is UNKNOWN: the row stays processing (a plain 400 still fails)', $r === 'processing' && $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_id = 1") === 'processing'
    && str_starts_with((string) $one("SELECT attempt_error FROM invoice_charge_attempts WHERE attempt_id = 1"), CHARGE_ATTEMPT_CONFLICT_MARK));
$m->query("INSERT INTO invoice_charge_attempts SET attempt_id = 2, attempt_invoice_id = 2, attempt_key = 'k2', attempt_balance_cents = 10000, attempt_method_id = 5, attempt_source = 'portal', attempt_status = 'processing'");
$ok('a plain 400 is still a failed attempt', chargeAttemptStoreException($m, 2, \Stripe\Exception\InvalidRequestException::factory('bad', 400, null, null, null, 'parameter_invalid_empty')) === 'failed');

// ---- the next claim: never a fresh charge
$m->query("UPDATE invoice_charge_attempts SET attempt_created_at = NOW() - INTERVAL 30 MINUTE, attempt_updated_at = NOW() - INTERVAL 30 MINUTE WHERE attempt_id = 1");
$s = new M5Stripe;
$r = chargeAttemptClaim($m, 1, 'portal', 5, $s->lookup());
$ok('the conflict row is neither replayed again nor abandoned on an empty search (the search cannot see pre-metadata requests): needs_review', !$r['ok'] && $r['reason'] === 'needs_review'
    && $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_id = 1") === 'processing' && intval($one("SELECT COUNT(*) FROM invoice_charge_attempts WHERE attempt_invoice_id = 1")) === 1
    && intval($one("SELECT COUNT(*) FROM history WHERE history_invoice_id = 1 AND history_status = 'Payment needs review'")) === 1);
$r = chargeAttemptClaim($m, 1, 'portal', 5, null);
$ok('... also with no lookup at all', !$r['ok'] && $r['reason'] === 'needs_review');
$s = new M5Stripe; $s->add('pi_found', 'succeeded', 'k1');
$r = chargeAttemptClaim($m, 1, 'portal', 5, $s->lookup());
$ok('a key lookup that finds the intent adopts it: id stored on the row, still no new charge', !$r['ok'] && $one("SELECT attempt_pi_id FROM invoice_charge_attempts WHERE attempt_id = 1") === 'pi_found');
$s = new M5Stripe; $s->add('pi_found2', 'requires_payment_method', 'k1');
$m->query("UPDATE invoice_charge_attempts SET attempt_pi_id = '', attempt_updated_at = NOW() - INTERVAL 30 MINUTE WHERE attempt_id = 1");
$r = chargeAttemptClaim($m, 1, 'portal', 5, $s->lookup());

$ok('... a found intent that Stripe then cancels frees the row (a definite answer)', $r['ok'] && $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_id = 1") === 'failed');

// ---- end to end: a timeout, a takeover whose key Stripe knows with other parameters, and the original PaymentIntent comes back
$c = chargeAttemptClaim($m, 3, 'agent', 7, null);
chargeAttemptStoreException($m, $c['attempt_id'], \Stripe\Exception\ApiConnectionException::factory('timeout'));
$m->query("UPDATE invoice_charge_attempts SET attempt_created_at = NOW() - INTERVAL 20 MINUTE, attempt_updated_at = NOW() - INTERVAL 20 MINUTE WHERE attempt_id = " . $c['attempt_id']);
$t = chargeAttemptClaim($m, 3, 'cron', 7, (new M5Stripe)->lookup());
$ok('the takeover reuses the old key', $t['ok'] && $t['reused'] && $t['key'] === $c['key']);
$calls = [];
$pi = chargeAttemptSend($stripe, ['metadata' => ['rivet_attempt_key' => $t['key']]], !empty($t['reused']));
chargeAttemptStoreResult($m, $t['attempt_id'], $pi->status, $pi->id);
$ok('... the original PaymentIntent is returned and stored (no seq+1 charge)', $pi->id === 'pi_orig' && $one("SELECT attempt_pi_id FROM invoice_charge_attempts WHERE attempt_id = " . $t['attempt_id']) === 'pi_orig' && intval($one("SELECT COUNT(*) FROM invoice_charge_attempts WHERE attempt_invoice_id = 3")) === 1);

// ---- every call site goes through the one helper
foreach (['agent/post/payment.php' => 2, 'client/post.php' => 1, 'cron/cron.php' => 1] as $f => $n) {
    $src = file_get_contents(__DIR__ . '/../' . $f);
    $ok("$f: $n charge call(s) through chargeAttemptSend with the claim's reused flag, no direct create left", substr_count($src, 'chargeAttemptSend(') === $n && substr_count($src, "!empty(\$charge_claim['reused'])") === $n
        && !preg_match('/\$payment_intent = \$(stripe->paymentIntents->create|provider->chargeSavedMethod)\(/', $src));
}
exit($fail ? 1 : 0);
