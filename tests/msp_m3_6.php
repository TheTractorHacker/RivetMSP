<?php
// MSP-DS-166 (re-review M3-6): guest and agent 'charge card on file' both used method 0 and shared one key namespace, so the agent took over an abandoned guest row's key
// with different parameters (Stripe 400 idempotency_error). Guest attempts now have their own key namespace and are never taken over by another path. REAL claim code, scratch DB.
// Run: SCRATCH_TABLES="invoices payments invoice_charge_attempts history" bash tests/lib/with_scratch_db.sh php tests/msp_m3_6.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../includes/charge_attempts.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount) VALUES (1,'Sent',100.00),(2,'Sent',100.00),(3,'Sent',100.00)");
$age = fn (int $inv, string $by = '20 MINUTE') => $m->query("UPDATE invoice_charge_attempts SET attempt_created_at = NOW() - INTERVAL $by, attempt_updated_at = NOW() - INTERVAL $by WHERE attempt_invoice_id = $inv");

// a guest page load whose request died before any PaymentIntent id was stored, then an agent charges a saved card with method 0
$g = chargeAttemptClaim($m, 1, 'guest', 0); $age(1);
$a = chargeAttemptClaim($m, 1, 'agent', 0);
$ok('the agent claim does NOT take over the guest row (different parameters)', $a['ok'] && !$a['reused'] && $a['key'] !== $g['key'] && $a['attempt_id'] !== $g['attempt_id']);
$ok('the guest row was closed as abandoned', $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_id = " . $g['attempt_id']) === 'failed');
$ok('guest and agent keys live in different namespaces', $g['key'] !== $a['key'] && str_contains($g['key'], '-g-') && !str_contains($a['key'], '-g-'));

// reverse: an abandoned agent row is not taken over by the guest page
$a2 = chargeAttemptClaim($m, 2, 'agent', 0); $age(2);
$g2 = chargeAttemptClaim($m, 2, 'guest', 0);
$ok('the guest claim does NOT take over an agent row', $g2['ok'] && !$g2['reused'] && $g2['key'] !== $a2['key']);

// still taken over by its own path (the MRR-1 replay protection is unchanged)
$p = chargeAttemptClaim($m, 3, 'portal', 4); $age(3);
$q = chargeAttemptClaim($m, 3, 'agent', 4);
$ok('portal/agent/cron with the same saved method still replay the same key', $q['ok'] && $q['reused'] && $q['key'] === $p['key']);
$gg = chargeAttemptClaim($m, 3, 'guest', 0);
$ok('a second guest attempt gets its own sequence, never an agent key', !$gg['ok'] || $gg['key'] !== $q['key']);
exit($fail ? 1 : 0);
