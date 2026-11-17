<?php
// MSP-DS-166 (re-review MRR-2): the guest pay page claims a charge attempt before it creates a PaymentIntent, so other paths see it. REAL charge_attempts.php functions on a scratch DB;
// guest/guest_ajax.php (needs live Stripe) is checked for the call order and the idempotency key in its source.
// Run: SCRATCH_TABLES="invoices payments invoice_charge_attempts history" bash tests/lib/with_scratch_db.sh php tests/msp_mrr_2.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../includes/charge_attempts.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount) VALUES (1,'Sent',100.00),(2,'Sent',100.00)");

// the guest page's claim, then its PaymentIntent id is stored
$g = chargeAttemptClaim($m, 1, 'guest', 0);
$ok('the guest page gets a claim with a deterministic key', $g['ok'] && str_contains($g['key'], 'inv1-10000-pm0-g-a0'));
chargeAttemptStoreIntent($m, $g['attempt_id'], 'pi_guest');
$ok('the open intent is recorded on the attempt', $one("SELECT attempt_pi_id FROM invoice_charge_attempts WHERE attempt_id = " . $g['attempt_id']) === 'pi_guest');

// the customer is still typing their card: cron autopay and an agent saved-card charge, 20 minutes later (past the normal 15 minute window)
$m->query("UPDATE invoice_charge_attempts SET attempt_updated_at = NOW() - INTERVAL 20 MINUTE WHERE attempt_id = " . $g['attempt_id']);
$ok('a 20 minute old guest intent is still a live attempt', (chargeAttemptLive($m, 1)['attempt_source'] ?? '') === 'guest');
foreach (['cron', 'agent', 'portal'] as $src) { $r = chargeAttemptClaim($m, 1, $src, 7); $ok("$src claim is refused while the guest intent is open", !$r['ok'] && $r['reason'] === 'in_progress'); }
$ok('no other attempt row was created', intval($one("SELECT COUNT(*) FROM invoice_charge_attempts WHERE attempt_invoice_id = 1")) === 1);

// reverse order: cron claimed first, the guest page must be refused
$c = chargeAttemptClaim($m, 2, 'cron', 7); $r = chargeAttemptClaim($m, 2, 'guest', 0);
$ok('a guest claim is refused while a saved-card charge is in flight', $c['ok'] && !$r['ok'] && $r['reason'] === 'in_progress');

// the guest intent does not block forever: after the guest window it is stale like any abandoned attempt
$m->query("UPDATE invoice_charge_attempts SET attempt_updated_at = NOW() - INTERVAL 2 HOUR WHERE attempt_id = " . $g['attempt_id']);
$ok('a guest intent older than the guest window is no longer live', chargeAttemptLive($m, 1) === null);
// a settled guest intent (return page / webhook, by PaymentIntent id) closes the attempt
$m->query("UPDATE invoice_charge_attempts SET attempt_updated_at = NOW() WHERE attempt_id = " . $g['attempt_id']);
chargeAttemptSettleIntent($m, 'pi_guest', 1, 10000, 'succeeded', 'guest');
$ok('the return page settles the guest attempt by intent id', $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_id = " . $g['attempt_id']) === 'succeeded');

// guest_ajax.php source: claim before create, key passed, card-only fallback under its own key
$src = file_get_contents(__DIR__ . '/../guest/guest_ajax.php');
$claimAt = strpos($src, "chargeAttemptClaim(\$mysqli, \$invoice_id, 'guest'"); $createAt = strpos($src, '->createCheckout(');
$ok('guest_ajax claims an attempt before creating the PaymentIntent', $claimAt !== false && $createAt !== false && $claimAt < $createAt);
$ok('both createCheckout calls carry an idempotency key, the fallback under its own', substr_count($src, "['idempotency_key' => ") === 2 && str_contains($src, "\$claim['key'] . '-card'"));
$ok('a reusable open guest intent is looked up instead of creating a second one', str_contains($src, 'verifyCheckoutResult($reuse_attempt'));
exit($fail ? 1 : 0);
