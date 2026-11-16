<?php

/**
 * MSP-DS-166 / MSP-DS-32 (INJ-26b): one charge-attempt scheme for every card/bank charge of an invoice (portal, agent, cron autopay, guest page).
 *
 * invoice_charge_attempts holds one row per attempt. Before ANY Stripe call a path calls chargeAttemptClaim(), which, inside one transaction
 * that row-locks the invoice (SELECT ... FOR UPDATE), reads the balance, refuses when another attempt is live and inserts a 'processing' row:
 *   processing - claimed; the Stripe call is in flight or its result was not stored yet (live for CHARGE_ATTEMPT_STALE_SECONDS)
 *   pending    - Stripe accepted it but the money has not moved (ACH 'processing'); live for CHARGE_ATTEMPT_PENDING_DAYS, settled by the webhook
 *   succeeded  - the payment was recorded           failed - declined / error / cancelled (a later attempt is allowed)
 * The idempotency key is deterministic (invoice, balance in cents, saved method, sequence of earlier attempts for that tuple) and identical for every
 * path: no clock bucket, so two paths racing for the same balance cannot both reach Stripe, and a retry after a real failure gets the next sequence.
 * Needs billing_guards.php (moneyToCents, invoiceBalanceDue).
 */
require_once __DIR__ . '/billing_guards.php';

const CHARGE_ATTEMPT_STALE_SECONDS = 900;
const CHARGE_ATTEMPT_PENDING_DAYS = 10;

/**
 * MSP-DS-166 (re-review MRR-5): the key carries this install's marker (a hash of the per-install random value stored in config.php, see
 * stripeInstallMarker()), so two installs sharing one Stripe account cannot reuse each other's key with different parameters. Deterministic
 * across retries. Without a marker (not configured) the key keeps its earlier shape.
 */
function stripeChargeAttemptKey($invoice_id, int $balance_cents, $method_id, int $seq): string
{
    $marker = preg_replace('/[^a-z0-9]/', '', strtolower(stripeInstallMarker()));
    return 'rivetmsp-' . ($marker !== '' ? substr($marker, 0, 12) . '-' : '') . 'inv' . intval($invoice_id) . '-' . $balance_cents . '-pm' . intval($method_id) . '-a' . $seq;
}

function chargeAttemptRefusal(string $reason): array
{
    $messages = [
        'in_progress' => 'Another payment for this invoice is in progress, please try again in a moment',
        'pending' => 'A bank payment for this invoice is still processing; it will be recorded when it clears, so it has not been charged again',
        'nothing_due' => 'Nothing left to charge on this invoice',
        'not_payable' => 'Invalid invoice state (draft/paid/cancelled/not billable)',
        'not_found' => 'Invoice not found',
        'error' => 'The payment could not be started, please try again',
    ];
    return ['ok' => false, 'reason' => $reason, 'message' => $messages[$reason] ?? $messages['error']];
}

/**
 * The live attempt that blocks a new charge for this invoice, or null. Read-only unless $lock (the guest page uses it before creating an intent).
 */
function chargeAttemptLive($mysqli, $invoice_id, bool $lock = false): ?array
{
    $invoice_id = intval($invoice_id);
    $r = mysqli_query($mysqli, "SELECT * FROM invoice_charge_attempts WHERE attempt_invoice_id = $invoice_id AND (
            (attempt_status = 'pending' AND attempt_updated_at >= NOW() - INTERVAL " . CHARGE_ATTEMPT_PENDING_DAYS . " DAY)
         OR (attempt_status = 'processing' AND attempt_updated_at >= NOW() - INTERVAL " . CHARGE_ATTEMPT_STALE_SECONDS . " SECOND))
        ORDER BY attempt_id LIMIT 1" . ($lock ? ' FOR UPDATE' : ''));
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row ?: null;
}

/**
 * Claim the right to charge this invoice. Returns ['ok'=>true, 'attempt_id', 'key', 'balance' (float), 'balance_cents', 'reused'] or chargeAttemptRefusal().
 * The balance returned is read under the invoice row lock; charge exactly that, with exactly that key. $source: portal|agent|cron|guest.
 */
function chargeAttemptClaim($mysqli, $invoice_id, string $source, $method_id = 0): array
{
    $invoice_id = intval($invoice_id);
    $method_id = intval($method_id);
    $source = substr(preg_replace('/[^a-z_]/', '', strtolower($source)), 0, 20);
    try {
        mysqli_begin_transaction($mysqli);
        $inv = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT invoice_amount, invoice_status FROM invoices WHERE invoice_id = $invoice_id FOR UPDATE"));
        if (!$inv) {
            mysqli_rollback($mysqli);
            return chargeAttemptRefusal('not_found');
        }
        if (in_array((string) $inv['invoice_status'], ['Draft', 'Paid', 'Cancelled', 'Non-Billable'], true)) {
            mysqli_rollback($mysqli);
            return chargeAttemptRefusal('not_payable');
        }
        $balance = invoiceBalanceDue($mysqli, $invoice_id, $inv['invoice_amount']);
        $cents = moneyToCents($balance);
        if ($cents <= 0) {
            mysqli_rollback($mysqli);
            return chargeAttemptRefusal('nothing_due');
        }
        $live = chargeAttemptLive($mysqli, $invoice_id, true);
        if ($live) {
            mysqli_rollback($mysqli);
            return chargeAttemptRefusal($live['attempt_status'] === 'pending' ? 'pending' : 'in_progress');
        }
        // Whatever is left is not live any more: an abandoned 'processing' row (the request died after claiming) or an expired 'pending' one.
        // An abandoned row for the SAME balance and method is taken over with its own key, so if Stripe did get that charge, replaying the key
        // returns the original PaymentIntent instead of creating a second one.
        $stale = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM invoice_charge_attempts WHERE attempt_invoice_id = $invoice_id AND attempt_status = 'processing'
            AND attempt_balance_cents = $cents AND attempt_method_id = $method_id ORDER BY attempt_id LIMIT 1 FOR UPDATE"));
        mysqli_query($mysqli, "UPDATE invoice_charge_attempts SET attempt_status = 'failed', attempt_error = 'abandoned' WHERE attempt_invoice_id = $invoice_id
            AND attempt_status IN ('processing','pending')" . ($stale ? ' AND attempt_id <> ' . intval($stale['attempt_id']) : ''));
        if ($stale) {
            mysqli_query($mysqli, "UPDATE invoice_charge_attempts SET attempt_updated_at = NOW(), attempt_source = '$source' WHERE attempt_id = " . intval($stale['attempt_id']));
            mysqli_commit($mysqli);
            return ['ok' => true, 'attempt_id' => intval($stale['attempt_id']), 'key' => $stale['attempt_key'], 'balance' => $balance, 'balance_cents' => $cents, 'reused' => true];
        }
        $seq = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM invoice_charge_attempts WHERE attempt_invoice_id = $invoice_id
            AND attempt_balance_cents = $cents AND attempt_method_id = $method_id"))[0] ?? 0);
        $key = stripeChargeAttemptKey($invoice_id, $cents, $method_id, $seq);
        mysqli_query($mysqli, "INSERT INTO invoice_charge_attempts SET attempt_invoice_id = $invoice_id, attempt_key = '$key', attempt_balance_cents = $cents,
            attempt_method_id = $method_id, attempt_source = '$source', attempt_status = 'processing'");
        $attempt_id = intval(mysqli_insert_id($mysqli));
        mysqli_commit($mysqli);
        if ($attempt_id <= 0) {
            return chargeAttemptRefusal('in_progress');
        }
        return ['ok' => true, 'attempt_id' => $attempt_id, 'key' => $key, 'balance' => $balance, 'balance_cents' => $cents, 'reused' => false];
    } catch (\Throwable $e) {
        try { mysqli_rollback($mysqli); } catch (\Throwable $e2) { }
        error_log('chargeAttemptClaim: ' . $e->getMessage());
        return chargeAttemptRefusal(((int) $e->getCode() === 1062) ? 'in_progress' : 'error');
    }
}

/**
 * Store what Stripe answered, right after the call returns: succeeded stays 'processing' (with the PaymentIntent id) until the payment row is
 * written, then chargeAttemptFinish(..., 'succeeded'); 'processing' at Stripe (ACH) becomes 'pending'; anything else is a failed attempt.
 * Returns the stored status.
 */
function chargeAttemptStoreResult($mysqli, $attempt_id, $pi_status, $pi_id): string
{
    $attempt_id = intval($attempt_id);
    $pi_id = mysqli_real_escape_string($mysqli, (string) $pi_id);
    $status = $pi_status === 'succeeded' ? 'processing' : ($pi_status === 'processing' ? 'pending' : 'failed');
    $err = $status === 'failed' ? ", attempt_error = '" . mysqli_real_escape_string($mysqli, 'Stripe status ' . $pi_status) . "'" : '';
    mysqli_query($mysqli, "UPDATE invoice_charge_attempts SET attempt_status = '$status', attempt_pi_id = '$pi_id'$err WHERE attempt_id = $attempt_id");
    return $status;
}

/**
 * A Stripe exception: a definite refusal (card declined, bad request, 4xx other than 409) is a failed attempt. A timeout / connection error /
 * 5xx leaves the attempt 'processing' with its key, because Stripe may have charged: the next claim after the stale window takes the same key over.
 */
function chargeAttemptStoreException($mysqli, $attempt_id, \Throwable $e): string
{
    $attempt_id = intval($attempt_id);
    $definite = false;
    if (is_a($e, '\Stripe\Exception\ApiErrorException') && !is_a($e, '\Stripe\Exception\ApiConnectionException')) {
        $http = (int) $e->getHttpStatus();
        $definite = $http >= 400 && $http < 500 && $http !== 409;
    }
    $err = mysqli_real_escape_string($mysqli, substr($e->getMessage(), 0, 250));
    $status = $definite ? 'failed' : 'processing';
    mysqli_query($mysqli, "UPDATE invoice_charge_attempts SET attempt_status = '$status', attempt_error = '$err' WHERE attempt_id = $attempt_id");
    return $status;
}

function chargeAttemptFinish($mysqli, $attempt_id, string $status, string $error = ''): void
{
    $status = in_array($status, ['succeeded', 'failed', 'pending', 'processing'], true) ? $status : 'failed';
    $err = $error !== '' ? ", attempt_error = '" . mysqli_real_escape_string($mysqli, substr($error, 0, 250)) . "'" : '';
    mysqli_query($mysqli, "UPDATE invoice_charge_attempts SET attempt_status = '$status'$err WHERE attempt_id = " . intval($attempt_id));
}

/**
 * The webhook (or the guest return page) settles an attempt it did not start: by PaymentIntent id, or - for a charge whose response never
 * came back - the one unsettled 'processing' attempt of that invoice with the same amount. $status: succeeded|failed|pending.
 * For 'pending' with no row for that intent (a guest-page bank debit) a row is recorded so the other paths see the debit and wait.
 */
function chargeAttemptSettleIntent($mysqli, string $pi_id, int $invoice_id, int $cents, string $status, string $source = 'webhook'): void
{
    $pi = mysqli_real_escape_string($mysqli, $pi_id);
    $status = in_array($status, ['succeeded', 'failed', 'pending'], true) ? $status : 'failed';
    mysqli_query($mysqli, "UPDATE invoice_charge_attempts SET attempt_status = '$status', attempt_pi_id = '$pi'
        WHERE attempt_status IN ('processing','pending') AND (attempt_pi_id = '$pi'
           OR (attempt_pi_id = '' AND attempt_invoice_id = $invoice_id AND attempt_balance_cents = $cents AND attempt_status = 'processing'))");
    if (mysqli_affected_rows($mysqli) > 0 || $status !== 'pending' || $invoice_id <= 0) {
        return;
    }
    $has = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM invoice_charge_attempts WHERE attempt_pi_id = '$pi'"))[0] ?? 0;
    if (intval($has) === 0) {
        $source = substr(preg_replace('/[^a-z_]/', '', strtolower($source)), 0, 20);
        mysqli_query($mysqli, "INSERT IGNORE INTO invoice_charge_attempts SET attempt_invoice_id = $invoice_id, attempt_key = 'pi-$pi', attempt_balance_cents = $cents,
            attempt_method_id = 0, attempt_source = '$source', attempt_status = 'pending', attempt_pi_id = '$pi'");
    }
}

/**
 * MSP-DS-32: cron autopay runs every few minutes and the due-date query matches all day, so autopay claims (invoice, day) once, whatever the
 * outcome (a decline is not retried every run). Atomic conditional insert into history under a named lock. True for the run that got the claim.
 */
function autopayClaimToday($mysqli, int $invoice_id, ?string $day = null): bool
{
    $marker = 'Cron autopay attempt on ' . ($day ?? date('Y-m-d'));
    $got = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('rivet_cron_autopay_claim', 10)"))[0] ?? 0;
    if ((int) $got !== 1) {
        return false;
    }
    mysqli_query($mysqli, "INSERT INTO history (history_status, history_description, history_invoice_id)
        SELECT 'Sent', '$marker', $invoice_id FROM DUAL
        WHERE NOT EXISTS (SELECT 1 FROM history WHERE history_invoice_id = $invoice_id AND history_description = '$marker')");
    $claimed = mysqli_affected_rows($mysqli) >= 1;
    mysqli_query($mysqli, "DO RELEASE_LOCK('rivet_cron_autopay_claim')");
    return $claimed;
}

/**
 * MSP-DS-164: record a Stripe refund or dispute (chargeback) against the payment it reverses. $obj is the event's Charge (charge.refunded) or Dispute
 * (charge.dispute.*) object. One payment_reversals row per refund total / dispute (UPSERT, so a replayed or later event updates it), an invoice history row
 * per (reversal, status), and the amount/status come back for the caller's notification. Nothing is booked automatically (a refund/chargeback is a
 * financial decision), the invoice and payment stay as they are and are flagged. Returns null when the payment is not one this install recorded.
 * @return null|array{payment_id:int, invoice_id:int, reversal_id:int, changed:bool, amount:float, status:string, kind:string}
 */
function stripeRecordReversal($mysqli, string $event_type, $obj): ?array
{
    $pi = mysqli_real_escape_string($mysqli, (string) ($obj->payment_intent ?? ''));
    if ($pi === '') {
        return null;
    }
    $pay = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT payment_id, payment_invoice_id FROM payments WHERE payment_reference = 'Stripe - $pi' LIMIT 1"));
    if (!$pay) {
        return null;   // not recorded here: another install's payment (or never recorded), nothing of ours to flag
    }
    $kind = strpos($event_type, 'dispute') !== false ? 'dispute' : 'refund';
    $currency = strtoupper(mysqli_real_escape_string($mysqli, substr((string) ($obj->currency ?? ''), 0, 10)));
    if ($kind === 'dispute') {
        $ref = 'dispute:' . (string) ($obj->id ?? '');
        $minor = intval($obj->amount ?? 0);
        $status = (string) ($obj->status ?? 'needs_response');
        if ($event_type === 'charge.dispute.funds_withdrawn') {
            $status = 'funds_withdrawn';
        } elseif ($event_type === 'charge.dispute.funds_reinstated') {
            $status = 'funds_reinstated';
        }
    } else {
        $minor = intval($obj->amount_refunded ?? 0);
        $ref = 'refund:' . (string) ($obj->id ?? '') . ':' . $minor;   // a further partial refund has a new total, so a new row
        $status = !empty($obj->refunded) ? 'refunded' : 'partially_refunded';
    }
    $ref = mysqli_real_escape_string($mysqli, substr($ref, 0, 100));
    $status = mysqli_real_escape_string($mysqli, substr($status, 0, 40));
    $amount = stripeAmountFromMinor($minor, $currency ?: 'USD');
    $payment_id = intval($pay['payment_id']);
    $invoice_id = intval($pay['payment_invoice_id']);

    $before = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT reversal_id, reversal_status FROM payment_reversals WHERE reversal_ref = '$ref' LIMIT 1"));
    mysqli_query($mysqli, "INSERT INTO payment_reversals SET reversal_ref = '$ref', reversal_kind = '$kind', reversal_payment_id = $payment_id, reversal_invoice_id = $invoice_id,
        reversal_pi_id = '$pi', reversal_amount = $amount, reversal_currency = '$currency', reversal_status = '$status', reversal_event_type = '" . mysqli_real_escape_string($mysqli, substr($event_type, 0, 60)) . "'
        ON DUPLICATE KEY UPDATE reversal_status = VALUES(reversal_status), reversal_amount = VALUES(reversal_amount), reversal_event_type = VALUES(reversal_event_type)");
    $reversal_id = $before ? intval($before['reversal_id']) : intval(mysqli_insert_id($mysqli));
    $changed = !$before || $before['reversal_status'] !== $status;
    if ($changed && $invoice_id > 0) {
        $label = $kind === 'dispute' ? 'Payment disputed' : 'Payment refunded';
        invoiceHistoryOnce($mysqli, $invoice_id, $label, "Stripe $kind $status, $currency $amount. PaymentIntent", $pi);
    }

    return ['payment_id' => $payment_id, 'invoice_id' => $invoice_id, 'reversal_id' => $reversal_id, 'changed' => $changed, 'amount' => $amount, 'status' => $status, 'kind' => $kind];
}
