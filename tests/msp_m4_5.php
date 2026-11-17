<?php
// MSP-DS-166 (re-review M4-5): cron guest expiry canceled ACH PaymentIntents waiting for microdeposit verification (requires_action), the return page treated
// requires_action as a failure and the webhook ignored it, so a hand-entered bank account could never pay through the guest page. Now a bank debit waiting for
// verification (next_action verify_with_microdeposits / us_bank_account method) is never canceled inside CHARGE_ATTEMPT_BANK_VERIFY_DAYS, is kept as 'pending' (visible
// to the other paths), the webhook settles payment_intent.requires_action / succeeded / payment_failed, and the return page says "pending verification".
// REAL charge_attempts.php functions (lookup builder, claim, expiry, settle), scratch DB, Stripe faked by retrieve/cancel objects.
// Run: SCRATCH_TABLES="invoices payments invoice_charge_attempts history" bash tests/lib/with_scratch_db.sh php tests/msp_m4_5.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../includes/charge_attempts.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$src = fn (string $f) => file_get_contents(__DIR__ . '/../' . $f);
for ($i = 1; $i <= 6; $i++) { $m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount) VALUES ($i,'Sent',100.00)"); }

// fake Stripe PaymentIntents
$cancels = [];
$pis = [];
$mkpi = function (string $id, string $status, ?string $next = null, ?string $pmType = null) use (&$pis, &$cancels) {
    $pis[$id] = new class($id, $status, $next, $pmType, $cancels) {
        public $status; public $next_action; public $payment_method = 'pm_x';
        public function __construct(public string $id, string $status, ?string $next, ?string $pmType, private array &$cancels) {
            $this->status = $status; $this->next_action = $next ? (object) ['type' => $next] : null;
            if ($pmType) { $this->payment_method = (object) ['type' => $pmType]; }
        }
        public function cancel() { $this->cancels[] = $this->id; $this->status = 'canceled'; }
    };
};
$lookup = chargeAttemptPiOps(function (string $id) use (&$pis) { return $pis[$id]; });

$mkpi('pi_micro', 'requires_action', 'verify_with_microdeposits');
$mkpi('pi_bankpm', 'requires_action', 'redirect_to_url', 'us_bank_account');
$mkpi('pi_3ds', 'requires_action', 'use_stripe_sdk', 'card');
$mkpi('pi_card', 'requires_payment_method');
$ok('lookup: microdeposit verification is reported as a bank debit waiting, not canceled', $lookup('pi_micro', true) === 'requires_action_bank' && $cancels === []);
$ok('lookup: a us_bank_account method in requires_action is the same', $lookup('pi_bankpm', true) === 'requires_action_bank' && $cancels === []);
$ok('lookup: a card 3-D Secure requires_action IS still canceled', $lookup('pi_3ds', true) === 'canceled' && $cancels === ['pi_3ds']);
$ok('lookup: a plain open card intent is still canceled', $lookup('pi_card', true) === 'canceled');
$ok('lookup: past the verification window the bank intent may be canceled (include_bank)', $lookup('pi_micro', true, null, true) === 'canceled' && in_array('pi_micro', $cancels, true));

// expiry (cron)
$cancels = []; $mkpi('pi_micro', 'requires_action', 'verify_with_microdeposits'); $mkpi('pi_3ds', 'requires_action', 'use_stripe_sdk', 'card'); $mkpi('pi_proc', 'processing', null, 'us_bank_account');
$lookup = chargeAttemptPiOps(function (string $id) use (&$pis) { return $pis[$id]; });
$guest = function (int $inv, string $pi, string $age) use ($m) {
    $g = chargeAttemptClaim($m, $inv, 'guest', 0); chargeAttemptStoreIntent($m, $g['attempt_id'], $pi);
    $m->query("UPDATE invoice_charge_attempts SET attempt_created_at = NOW() - INTERVAL $age, attempt_updated_at = NOW() - INTERVAL $age WHERE attempt_id = " . $g['attempt_id']);
    return $g['attempt_id'];
};
$st = fn (int $id) => $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_id = $id");
$a1 = $guest(1, 'pi_micro', '3 HOUR'); $a2 = $guest(2, 'pi_3ds', '3 HOUR'); $a3 = $guest(3, 'pi_proc', '3 HOUR');
$n = chargeAttemptExpireGuest($m, $lookup);
$ok('expiry does NOT cancel the microdeposit-verification intent; it stays at Stripe', !in_array('pi_micro', $cancels, true) && $pis['pi_micro']->status === 'requires_action');
$ok('... and its attempt is kept as pending (other paths wait, the 10-day window applies)', $st($a1) === 'pending' && intval($one("SELECT COUNT(*) FROM invoice_charge_attempts WHERE attempt_invoice_id = 1 AND attempt_status = 'failed'")) === 0);
$ok('expiry still cancels the abandoned card intent', in_array('pi_3ds', $cancels, true) && $st($a2) === 'failed');
$ok('a processing bank debit is never canceled or failed by expiry', !in_array('pi_proc', $cancels, true) && $st($a3) === 'processing');
$ok('only the card intent was counted as closed', $n === 1);
$r = chargeAttemptClaim($m, 1, 'cron', 7, $lookup);
$ok('a saved-card claim waits for the bank verification (pending)', !$r['ok'] && $r['reason'] === 'pending');

// a bank intent still 'processing' (never settled) older than the claim's stale window: a claim must not cancel it either
$mkpi('pi_micro4', 'requires_action', 'verify_with_microdeposits');
$a4 = $guest(4, 'pi_micro4', '3 HOUR');
$lookup = chargeAttemptPiOps(function (string $id) use (&$pis) { return $pis[$id]; });
$r = chargeAttemptClaim($m, 4, 'agent', 7, $lookup);
$ok('a claim over an unsettled bank-verification guest intent refuses and does not cancel it', !$r['ok'] && !in_array('pi_micro4', $cancels, true) && $st($a4) === 'processing');
// after the 10 day window it may be canceled
$m->query("UPDATE invoice_charge_attempts SET attempt_created_at = NOW() - INTERVAL 11 DAY, attempt_updated_at = NOW() - INTERVAL 11 DAY WHERE attempt_id = $a4");
$r = chargeAttemptClaim($m, 4, 'agent', 7, $lookup);
$ok('after the 10 day window the stuck bank intent is canceled and the claim goes through', $r['ok'] && in_array('pi_micro4', $cancels, true) && $st($a4) === 'failed');
$ok('the window is a constant of 10 days', CHARGE_ATTEMPT_BANK_VERIFY_DAYS === 10);

// webhook settle path: requires_action (bank) -> pending, then succeeded / payment_failed
$mkpi('pi_micro5', 'requires_action', 'verify_with_microdeposits');
$a5 = $guest(5, 'pi_micro5', '10 MINUTE');
chargeAttemptSettleIntent($m, 'pi_micro5', 5, 10000, 'pending', 'webhook');
$ok('webhook requires_action settles the guest attempt as pending', $st($a5) === 'pending');
chargeAttemptSettleIntent($m, 'pi_micro5', 0, 0, 'failed', 'webhook', true);
$ok('payment_failed afterwards frees it (the verification failed)', $st($a5) === 'failed');
$a6 = $guest(6, 'pi_micro6', '10 MINUTE');
chargeAttemptSettleIntent($m, 'pi_micro6', 6, 10000, 'pending', 'webhook'); chargeAttemptSettleIntent($m, 'pi_micro6', 0, 0, 'succeeded');
$ok('succeeded afterwards settles it', $st($a6) === 'succeeded');

// the real webhook / return page / helper
$wh = $src('guest/payment_webhook.php');
$ok('webhook: payment_intent.requires_action is handled for bank debits only', str_contains($wh, "'payment_intent.requires_action'") && str_contains($wh, 'chargeAttemptPiIsBankDebit($event->data->object)'));
$ok('webhook: requires_action settles as pending with the invoice id', preg_match("/in_array\(\\\$event_type, \['payment_intent\.processing', 'payment_intent\.requires_action'\], true\) \? \\\$att_invoice : 0/", $wh) === 1);
$rp = $src('guest/guest_pay_invoice_stripe.php');
$ok('return page: requires_action for a bank debit is "pending verification", before the failure branch', ($a = strpos($rp, 'requires_action" && chargeAttemptPiIsBankDebit($pi_obj)')) !== false && $a < strpos($rp, '$pi_obj->status !== "succeeded"') && str_contains($rp, 'Your bank account needs to be verified'));
$ok('helper: the event shape (next_action only) is recognised', chargeAttemptPiIsBankDebit((object) ['next_action' => (object) ['type' => 'verify_with_microdeposits']]) && !chargeAttemptPiIsBankDebit((object) ['next_action' => (object) ['type' => 'use_stripe_sdk'], 'payment_method' => 'pm_1']));
exit($fail ? 1 : 0);
