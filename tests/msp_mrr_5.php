<?php
// MSP-DS-166 (re-review MRR-5): the charge idempotency key carries the install marker, so two installs on one Stripe account cannot collide. REAL claim code, scratch DB.
// Run: SCRATCH_TABLES="invoices payments invoice_charge_attempts history" bash tests/lib/with_scratch_db.sh php tests/msp_mrr_5.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../includes/charge_attempts.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
$m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount) VALUES (5,'Sent',100.00)");

$GLOBALS['installation_id'] = 'installAaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$a = stripeChargeAttemptKey(5, 10000, 3, 0);
$GLOBALS['installation_id'] = 'installBbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
$b = stripeChargeAttemptKey(5, 10000, 3, 0);
$GLOBALS['installation_id'] = 'installAaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$ok("same invoice/amount/method on two installs gets different keys ($a vs $b)", $a !== $b);
$ok('the key is deterministic for one install (retry-safe)', $a === stripeChargeAttemptKey(5, 10000, 3, 0));
$ok('the key does not contain the raw installation id', !str_contains($a, 'installAaaa'));
$ok('the sequence still moves the key', $a !== stripeChargeAttemptKey(5, 10000, 3, 1));
$c = chargeAttemptClaim($m, 5, 'portal', 3);
$ok('the real claim stores the marked key', $c['ok'] && $c['key'] === $a && strlen($c['key']) <= 120);
exit($fail ? 1 : 0);
