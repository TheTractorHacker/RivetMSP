<?php
// MSP-DS-166 / MSP-DS-32 (INJ-26b): the shared charge-attempt scheme in includes/charge_attempts.php, run against a scratch DB with the REAL functions.
// Run: SCRATCH_TABLES="invoices payments invoice_charge_attempts history" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_166.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mk = function () { $m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''"); return $m; };
require __DIR__ . '/../includes/charge_attempts.php';

if (($argv[1] ?? '') === 'worker') {   // one concurrent "request": claim and report
    $m = $mk(); $r = chargeAttemptClaim($m, intval($argv[2]), 'portal', 5);
    echo json_encode(['ok' => $r['ok'], 'reason' => $r['reason'] ?? '', 'key' => $r['key'] ?? '']);
    exit(0);
}
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = $mk();
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount) VALUES (10,'Sent',100.00),(11,'Paid',50.00),(12,'Sent',0.00),(13,'Sent',80.00),(14,'Sent',60.00),(15,'Sent',40.00)");

// 1. six concurrent requests (portal click, agent click, cron ... all the same claim) for one invoice: exactly one may reach Stripe
$procs = [];
for ($i = 0; $i < 6; $i++) { $procs[] = proc_open([PHP_BINARY, __FILE__, 'worker', '10'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]); }
$res = [];
foreach ($procs as $i => $p) { $res[] = json_decode(stream_get_contents($pipes[$i][1]), true); proc_close($p); }
$wins = array_filter($res, fn ($r) => $r && $r['ok']);
$ok('6 concurrent claims: exactly one wins (' . count($wins) . ')', count($wins) === 1);
$ok('the others are told another payment is in progress', count(array_filter($res, fn ($r) => $r && !$r['ok'] && $r['reason'] === 'in_progress')) === 5);
$ok('one attempt row exists for the invoice', intval($one("SELECT COUNT(*) FROM invoice_charge_attempts WHERE attempt_invoice_id = 10")) === 1);
$ok('the key is one deterministic value (invoice, cents, method, sequence) with no clock bucket', array_values($wins)[0]['key'] === 'rivetmsp-inv10-10000-pm5-a0');

// 2. refusals that need no attempt
$r = chargeAttemptClaim($m, 11, 'agent'); $ok('a Paid invoice is not payable', !$r['ok'] && $r['reason'] === 'not_payable');
$r = chargeAttemptClaim($m, 12, 'agent'); $ok('a zero balance has nothing to charge', !$r['ok'] && $r['reason'] === 'nothing_due');
$r = chargeAttemptClaim($m, 999, 'agent'); $ok('an unknown invoice is refused', !$r['ok'] && $r['reason'] === 'not_found');

// 3. ACH: Stripe says 'processing' -> attempt is pending, nobody charges again, for days
$c = chargeAttemptClaim($m, 13, 'cron', 7);
$ok('first claim for invoice 13 succeeds, balance is read under the lock', $c['ok'] && $c['balance'] === 80.0 && $c['key'] === 'rivetmsp-inv13-8000-pm7-a0');
$ok("Stripe 'processing' is stored as pending", chargeAttemptStoreResult($m, $c['attempt_id'], 'processing', 'pi_ach') === 'pending');
foreach (['portal', 'agent', 'cron'] as $src) { $r = chargeAttemptClaim($m, 13, $src, 7); $ok("ACH pending: $src is refused with 'pending', not re-charged", !$r['ok'] && $r['reason'] === 'pending'); }
$ok('the guest page sees the live attempt', chargeAttemptLive($m, 13)['attempt_pi_id'] === 'pi_ach');
$m->query("UPDATE invoice_charge_attempts SET attempt_updated_at = NOW() - INTERVAL 11 DAY WHERE attempt_invoice_id = 13");
$r = chargeAttemptClaim($m, 13, 'agent', 7); $ok('a pending debit older than 10 days no longer blocks (and is closed as abandoned)', $r['ok'] && $one("SELECT attempt_error FROM invoice_charge_attempts WHERE attempt_invoice_id = 13 AND attempt_pi_id = 'pi_ach'") === 'abandoned');
chargeAttemptFinish($m, $r['attempt_id'], 'failed');
$m->query("UPDATE invoice_charge_attempts SET attempt_status='pending', attempt_error='', attempt_updated_at = NOW() WHERE attempt_pi_id = 'pi_ach'");
chargeAttemptSettleIntent($m, 'pi_ach', 13, 8000, 'failed');
$ok('webhook payment_failed settles the pending attempt as failed', $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_pi_id = 'pi_ach'") === 'failed');
$r = chargeAttemptClaim($m, 13, 'agent', 7); $ok('after the bank debit failed a new attempt is allowed with the next key', $r['ok']);
chargeAttemptFinish($m, $r['attempt_id'], 'failed');

// 4. a decline frees the invoice and the retry gets a NEW key (Stripe would replay the cached decline for the old one)
$a = chargeAttemptClaim($m, 14, 'portal', 3); chargeAttemptStoreResult($m, $a['attempt_id'], 'requires_payment_method', 'pi_x');
$b = chargeAttemptClaim($m, 14, 'agent', 3);
$ok('after a decline the next attempt is allowed', $b['ok']);
$ok('... with the next sequence key', $a['key'] === 'rivetmsp-inv14-6000-pm3-a0' && $b['key'] === 'rivetmsp-inv14-6000-pm3-a1');

// 5. a lost response (timeout) keeps the attempt claimed; after the stale window the SAME key is reused so Stripe returns the original PaymentIntent
$e = new RuntimeException('cURL error 28: timed out');
$ok('a timeout leaves the attempt processing (Stripe may have charged)', chargeAttemptStoreException($m, $b['attempt_id'], $e) === 'processing');
$r = chargeAttemptClaim($m, 14, 'cron', 3); $ok('while it is live nobody else charges', !$r['ok'] && $r['reason'] === 'in_progress');
$m->query("UPDATE invoice_charge_attempts SET attempt_updated_at = NOW() - INTERVAL 20 MINUTE WHERE attempt_id = " . intval($b['attempt_id']));
$r = chargeAttemptClaim($m, 14, 'cron', 3);
$ok('after the stale window the abandoned claim is taken over with its OWN key and id', $r['ok'] && $r['reused'] && $r['key'] === $b['key'] && $r['attempt_id'] === $b['attempt_id']);
require __DIR__ . '/../plugins/stripe-php/init.php';
$card = \Stripe\Exception\CardException::factory('Your card was declined', 402, '{}', [], [], 'card_declined');
$ok('a 4xx Stripe refusal is a definite failure', chargeAttemptStoreException($m, $r['attempt_id'], $card) === 'failed');

// 6. success: the attempt stays claimed until the payment row exists; the balance then drops
$s = chargeAttemptClaim($m, 15, 'agent', 0);
$ok("Stripe 'succeeded' keeps the attempt claimed until it is recorded", chargeAttemptStoreResult($m, $s['attempt_id'], 'succeeded', 'pi_ok') === 'processing');
$r = chargeAttemptClaim($m, 15, 'portal', 0); $ok('... so a racing path is still refused', !$r['ok']);
$m->query("INSERT INTO payments (payment_invoice_id, payment_amount, payment_date, payment_method, payment_reference) VALUES (15, 40.00, CURDATE(), 'Stripe', 'Stripe - pi_ok')");
chargeAttemptFinish($m, $s['attempt_id'], 'succeeded');
$r = chargeAttemptClaim($m, 15, 'portal', 0); $ok('after the payment is recorded there is nothing left to charge', !$r['ok'] && $r['reason'] === 'nothing_due');

// 7. webhook closes an attempt whose response was lost, by invoice + amount, and a guest-page debit is recorded as pending for the others
$m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount) VALUES (17,'Sent',25.00)");
$g = chargeAttemptClaim($m, 17, 'agent', 0); chargeAttemptStoreException($m, $g['attempt_id'], $e);
chargeAttemptSettleIntent($m, 'pi_lost', 17, 2500, 'succeeded');
$ok('webhook succeeded settles the lost-response attempt (no pi on the row) by invoice + amount', $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_id = " . intval($g['attempt_id'])) === 'succeeded');
$m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount) VALUES (18,'Sent',30.00)");
chargeAttemptSettleIntent($m, 'pi_guest', 18, 3000, 'pending', 'guest');
$r = chargeAttemptClaim($m, 18, 'cron', 0); $ok('a guest-page bank debit blocks the other paths', !$r['ok'] && $r['reason'] === 'pending');

// 9. wiring: every charge path claims before Stripe and uses the claim's key; no clock-bucketed keys remain
foreach (['agent/post/payment.php' => 'chargeSavedMethod', 'client/post.php' => 'paymentIntents->create'] as $f => $call) {
    $src = file_get_contents(__DIR__ . "/../$f");
    $c = strpos($src, 'chargeAttemptClaim('); $s = strpos($src, $call . '(');
    $ok("$f claims before it calls Stripe", $c !== false && $s !== false && $c < $s);
    $ok("$f has no clock-bucketed idempotency key", !preg_match('/intdiv\(time\(\), ?60\)|stripeChargeIdempotencyKey\(/', $src));
}
exit($fail ? 1 : 0);
