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
const CHARGE_ATTEMPT_GUEST_INTENT_SECONDS = 3600;   // a guest-page PaymentIntent the customer may still confirm keeps its attempt live this long
const CHARGE_ATTEMPT_BANK_VERIFY_DAYS = 10;         // M4-5: a bank debit waiting for microdeposit verification keeps its attempt (and its PaymentIntent) this long
const CHARGE_ATTEMPT_KEY_MAX_AGE = 82800;           // Stripe forgets an idempotency key after 24h: a key older than 23h is never replayed

/**
 * MSP-DS-166 (re-review MRR-5): the key carries this install's marker (a hash of the per-install random value stored in config.php, see
 * stripeInstallMarker()), so two installs sharing one Stripe account cannot reuse each other's key with different parameters. Deterministic
 * across retries. Without a marker (not configured) the key keeps its earlier shape.
 */
function stripeChargeAttemptKey($invoice_id, int $balance_cents, $method_id, int $seq, string $ns = ''): string
{
    $marker = preg_replace('/[^a-z0-9]/', '', strtolower(stripeInstallMarker()));
    return 'rivetmsp-' . ($marker !== '' ? substr($marker, 0, 12) . '-' : '') . 'inv' . intval($invoice_id) . '-' . $balance_cents . '-pm' . intval($method_id) . ($ns !== '' ? '-' . $ns : '') . '-a' . $seq;
}

/**
 * M4-4: true only for a DEFINITE "that PaymentIntent does not exist" answer from Stripe (HTTP 404 resource_missing, e.g. after a test-to-live key
 * switch or a deleted intent). A timeout, a 5xx, a rate limit or an auth error is NOT missing: the intent may well still exist.
 */
function chargeAttemptIsMissing(\Throwable $e): bool
{
    if (!is_a($e, '\Stripe\Exception\InvalidRequestException')) {
        return false;
    }
    return (int) $e->getHttpStatus() === 404 && ((string) $e->getStripeCode() === 'resource_missing' || stripos($e->getMessage(), 'No such payment_intent') !== false);
}

/**
 * M4-5: a PaymentIntent that is a bank debit waiting on the customer/bank (microdeposit verification, or a us_bank_account method already attached).
 * Such an intent sits in requires_action for days and must not be canceled by the guest-page expiry.
 */
function chargeAttemptPiIsBankDebit($pi): bool
{
    $next = $pi->next_action ?? null;
    if ($next && (string) ($next->type ?? '') === 'verify_with_microdeposits') {
        return true;
    }
    $pm = $pi->payment_method ?? null;
    return is_object($pm) && (string) ($pm->type ?? '') === 'us_bank_account';
}

/**
 * MSP-DS-166 (re-review MRR-6 / M3-1): a callable for chargeAttemptClaim() that asks Stripe for the status of an old attempt's PaymentIntent.
 * Called as $lookup($pi_id) it returns the status (null when Stripe could not be asked; 'missing' when Stripe definitely has no such intent, M4-4;
 * 'requires_action_bank' for a bank debit waiting for verification, M4-5). Called as $lookup($pi_id, true) it first cancels a PaymentIntent that is
 * still confirmable and returns 'canceled' only when the cancel took effect, otherwise the status Stripe reports now; a bank debit waiting for
 * verification is not canceled unless $include_bank. Called as $lookup('', false, $attempt_key) (M4-3) it searches PaymentIntents by the attempt
 * key kept in their metadata and returns 'none' (nothing found), 'found' or null (the search failed / is not available).
 * $retrieve($pi_id) returns the Stripe PaymentIntent object (it must have cancel()). $search($attempt_key) returns the list of matching intents.
 */
function chargeAttemptPiOps(callable $retrieve, ?callable $search = null): callable
{
    return function (string $pi_id, bool $cancel = false, ?string $search_key = null, bool $include_bank = false) use ($retrieve, $search): ?string {
        if ($search_key !== null) {
            if ($search === null) {
                return null;
            }
            try {
                $found = $search($search_key);
                return is_countable($found) ? (count($found) === 0 ? 'none' : 'found') : null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        try {
            $pi = $retrieve($pi_id);
            $status = (string) $pi->status;
            if ($status === 'requires_action' && chargeAttemptPiIsBankDebit($pi) && !$include_bank) {
                return 'requires_action_bank';
            }
            if ($cancel && in_array($status, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'requires_capture'], true)) {
                try {
                    $pi->cancel();
                    return 'canceled';
                } catch (\Throwable $e) {
                    return (string) $retrieve($pi_id)->status;   // the customer confirmed in the meantime: report what Stripe says now
                }
            }
            return $status;
        } catch (\Throwable $e) {
            return chargeAttemptIsMissing($e) ? 'missing' : null;
        }
    };
}

function chargeAttemptStripeLookup($stripe): callable
{
    return chargeAttemptPiOps(
        fn (string $pi_id) => $stripe->paymentIntents->retrieve($pi_id, []),
        fn (string $key) => $stripe->paymentIntents->search(['query' => "metadata['rivet_attempt_key']:'" . preg_replace('/[^A-Za-z0-9_-]/', '', $key) . "'", 'limit' => 5])->data
    );
}

function chargeAttemptProviderLookup($provider): callable
{
    return chargeAttemptPiOps(
        fn (string $pi_id) => $provider->verifyCheckoutResult($pi_id),
        method_exists($provider, 'searchCheckoutByAttemptKey') ? fn (string $key) => $provider->searchCheckoutByAttemptKey($key) : null
    );
}

function chargeAttemptRefusal(string $reason): array
{
    $messages = [
        'in_progress' => 'Another payment for this invoice is in progress, please try again in a moment',
        'pending' => 'A bank payment for this invoice is still processing; it will be recorded when it clears, so it has not been charged again',
        'nothing_due' => 'Nothing left to charge on this invoice',
        'not_payable' => 'Invalid invoice state (draft/paid/cancelled/not billable)',
        'not_found' => 'Invoice not found',
        'needs_review' => 'An earlier payment attempt for this invoice timed out and may have been charged; billing must check Stripe before it is charged again',
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
         OR (attempt_status = 'processing' AND attempt_updated_at >= NOW() - INTERVAL " . CHARGE_ATTEMPT_STALE_SECONDS . " SECOND)
         OR (attempt_status = 'processing' AND attempt_source = 'guest' AND attempt_pi_id <> '' AND attempt_updated_at >= NOW() - INTERVAL " . CHARGE_ATTEMPT_GUEST_INTENT_SECONDS . " SECOND))
        ORDER BY attempt_id LIMIT 1" . ($lock ? ' FOR UPDATE' : ''));
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row ?: null;
}

/**
 * Claim the right to charge this invoice. Returns ['ok'=>true, 'attempt_id', 'key', 'balance' (float), 'balance_cents', 'reused'] or chargeAttemptRefusal().
 * The balance returned is read under the invoice row lock; charge exactly that, with exactly that key. $source: portal|agent|cron|guest.
 */
function chargeAttemptClaim($mysqli, $invoice_id, string $source, $method_id = 0, ?callable $pi_lookup = null): array
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
        $is_guest_claim = $source === 'guest';
        // MSP-DS-166 (re-review M3-1): a guest page PaymentIntent is confirmable on the open page until it is canceled, whatever age its attempt
        // has. Nobody else (and no later guest claim) may free its attempt or charge, unless Stripe confirms the intent is canceled first.
        $guest_open = mysqli_query($mysqli, "SELECT *, TIMESTAMPDIFF(SECOND, attempt_created_at, NOW()) AS attempt_age FROM invoice_charge_attempts WHERE attempt_invoice_id = $invoice_id
            AND attempt_status = 'processing' AND attempt_source = 'guest' AND attempt_pi_id <> '' ORDER BY attempt_id FOR UPDATE");
        $guest_open = $guest_open ? mysqli_fetch_all($guest_open, MYSQLI_ASSOC) : [];
        foreach ($guest_open as $g) {
            $g_status = $pi_lookup !== null ? $pi_lookup((string) $g['attempt_pi_id'], true, null, intval($g['attempt_age']) >= CHARGE_ATTEMPT_BANK_VERIFY_DAYS * 86400) : null;
            if ($g_status === 'canceled') {
                mysqli_query($mysqli, "UPDATE invoice_charge_attempts SET attempt_status = 'failed', attempt_error = 'guest intent canceled by " . $source . " claim' WHERE attempt_id = " . intval($g['attempt_id']));
                continue;
            }
            if ($g_status === 'missing') {   // M4-4: Stripe definitely has no such intent any more (key switch / deleted): nothing can be confirmed or charged
                mysqli_query($mysqli, "UPDATE invoice_charge_attempts SET attempt_status = 'failed', attempt_error = 'abandoned; Stripe intent missing' WHERE attempt_id = " . intval($g['attempt_id']));
                continue;
            }
            if ($g_status === null && intval($g['attempt_age']) >= CHARGE_ATTEMPT_KEY_MAX_AGE) {
                invoiceHistoryOnce($mysqli, $invoice_id, 'Payment needs review', 'A guest payment page attempt is over 23 hours old and Stripe could not be asked about it; check Stripe before charging again. Attempt', (string) intval($g['attempt_id']));
                mysqli_commit($mysqli);
                return chargeAttemptRefusal('needs_review');
            }
            mysqli_commit($mysqli);
            return chargeAttemptRefusal('in_progress');   // paid / processing at Stripe (the webhook settles it) or Stripe unreachable: do not charge
        }
        $live = chargeAttemptLive($mysqli, $invoice_id, true);
        if ($live) {
            mysqli_commit($mysqli);
            return chargeAttemptRefusal($live['attempt_status'] === 'pending' ? 'pending' : 'in_progress');
        }
        // Whatever is left is not live any more: an abandoned 'processing' row (the request died after claiming) or an expired 'pending' one.
        // An abandoned row for the SAME balance and method (and the same path namespace: a guest row is only ever taken over by the guest page,
        // M3-6) is taken over with its own key, so if Stripe did get that charge, replaying the key returns the original PaymentIntent.
        // MSP-DS-166 (re-review MRR-6 / M3-7): any other stale row cannot be replayed, so Stripe is asked about its PaymentIntent first (a charge
        // that never happened is closed; one that happened, or one past Stripe's 24h key window that cannot be checked, blocks and is flagged for billing).
        $stale = null;
        $rows = mysqli_query($mysqli, "SELECT *, TIMESTAMPDIFF(SECOND, attempt_created_at, NOW()) AS attempt_age FROM invoice_charge_attempts
            WHERE attempt_invoice_id = $invoice_id AND attempt_status = 'processing' ORDER BY attempt_id FOR UPDATE");
        $rows = $rows ? mysqli_fetch_all($rows, MYSQLI_ASSOC) : [];
        foreach ($rows as $row) {
            $row_guest = $row['attempt_source'] === 'guest';
            $age = intval($row['attempt_age']);
            $same = intval($row['attempt_balance_cents']) === $cents && intval($row['attempt_method_id']) === $method_id && $row_guest === $is_guest_claim;
            if ($same && $age < CHARGE_ATTEMPT_KEY_MAX_AGE && $stale === null) {
                $stale = $row;
                continue;
            }
            // M4-3: a row is abandoned only on a DEFINITE answer. With a stored PaymentIntent id: Stripe says canceled / requires_payment_method /
            // missing (M4-4). With no id: a search of PaymentIntents by the attempt key finds nothing. A lookup that merely errored (null), a search that
            // errored or found an intent, or no way to ask at all, refuses (in_progress; needs_review once the 23h key window has passed).
            $old_status = null;
            if ($row['attempt_pi_id'] !== '') {
                $old_status = $pi_lookup !== null ? $pi_lookup((string) $row['attempt_pi_id']) : null;
                if (in_array($old_status, ['canceled', 'requires_payment_method', 'missing'], true)) {
                    mysqli_query($mysqli, "UPDATE invoice_charge_attempts SET attempt_status = 'failed', attempt_error = 'stale; Stripe intent " . $old_status . "' WHERE attempt_id = " . intval($row['attempt_id']));
                    continue;
                }
            } elseif ($pi_lookup !== null && $pi_lookup('', false, (string) $row['attempt_key']) === 'none') {
                continue;   // nothing at Stripe carries this attempt's key: it never reached Stripe, closed by the abandon below
            }
            if ($old_status !== null || $age >= CHARGE_ATTEMPT_KEY_MAX_AGE) {
                invoiceHistoryOnce($mysqli, $invoice_id, 'Payment needs review', 'A charge attempt timed out and may have been charged at Stripe; check Stripe before charging again. Attempt', (string) intval($row['attempt_id']));
                mysqli_commit($mysqli);
                return chargeAttemptRefusal('needs_review');
            }
            mysqli_commit($mysqli);
            return chargeAttemptRefusal('in_progress');   // Stripe could not be asked (or the search found an intent): do not charge, retry later
        }
        mysqli_query($mysqli, "UPDATE invoice_charge_attempts SET attempt_status = 'failed', attempt_error = 'abandoned' WHERE attempt_invoice_id = $invoice_id
            AND attempt_status IN ('processing','pending')" . ($stale ? ' AND attempt_id <> ' . intval($stale['attempt_id']) : ''));
        if ($stale) {
            mysqli_query($mysqli, "UPDATE invoice_charge_attempts SET attempt_updated_at = NOW(), attempt_source = '$source' WHERE attempt_id = " . intval($stale['attempt_id']));
            mysqli_commit($mysqli);
            return ['ok' => true, 'attempt_id' => intval($stale['attempt_id']), 'key' => $stale['attempt_key'], 'balance' => $balance, 'balance_cents' => $cents, 'reused' => true];
        }
        $seq = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM invoice_charge_attempts WHERE attempt_invoice_id = $invoice_id
            AND attempt_balance_cents = $cents AND attempt_method_id = $method_id AND (attempt_source = 'guest') = " . ($is_guest_claim ? '1' : '0')))[0] ?? 0);
        $key = stripeChargeAttemptKey($invoice_id, $cents, $method_id, $seq, $is_guest_claim ? 'g' : '');
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

/**
 * MSP-DS-166 (re-review MRR-2): the guest page created a PaymentIntent the customer will confirm later. Record its id on the attempt and keep the
 * attempt 'processing' (live for CHARGE_ATTEMPT_GUEST_INTENT_SECONDS, see chargeAttemptLive) so cron autopay / agent / portal charges see it and wait.
 * The return page and the webhook settle it by PaymentIntent id.
 */
function chargeAttemptStoreIntent($mysqli, $attempt_id, $pi_id): void
{
    $pi_id = mysqli_real_escape_string($mysqli, (string) $pi_id);
    mysqli_query($mysqli, "UPDATE invoice_charge_attempts SET attempt_pi_id = '$pi_id', attempt_updated_at = NOW() WHERE attempt_id = " . intval($attempt_id));
}

/**
 * MSP-DS-166 (re-review M3-2): guest page loads create an intent nobody may ever confirm. Past the guest window such an attempt is closed: its
 * PaymentIntent is canceled at Stripe through $pi_lookup($pi_id, true) and only then is the attempt marked failed/'abandoned'. An intent Stripe says
 * is paid/processing (or that could not be asked) is left for the webhook / the next claim. Returns the number of attempts closed. Run from cron.
 */
function chargeAttemptExpireGuest($mysqli, callable $pi_lookup, int $limit = 50): int
{
    $limit = max(1, $limit);
    $r = mysqli_query($mysqli, "SELECT attempt_id, attempt_pi_id, attempt_invoice_id, attempt_balance_cents, TIMESTAMPDIFF(SECOND, attempt_created_at, NOW()) AS attempt_age FROM invoice_charge_attempts
        WHERE attempt_status = 'processing' AND attempt_source = 'guest' AND attempt_pi_id <> ''
        AND attempt_updated_at < NOW() - INTERVAL " . CHARGE_ATTEMPT_GUEST_INTENT_SECONDS . " SECOND ORDER BY attempt_id LIMIT $limit");
    $rows = $r ? mysqli_fetch_all($r, MYSQLI_ASSOC) : [];
    $closed = 0;
    foreach ($rows as $row) {
        $bank_window_over = intval($row['attempt_age']) >= CHARGE_ATTEMPT_BANK_VERIFY_DAYS * 86400;
        $status = $pi_lookup((string) $row['attempt_pi_id'], true, null, $bank_window_over);   // M4-5: a bank debit waiting for verification is not canceled inside its window
        if ($status === 'requires_action_bank') {
            // the customer submitted a bank account and the debit waits for microdeposit verification (days): keep it visible to the other paths as 'pending'
            chargeAttemptSettleIntent($mysqli, (string) $row['attempt_pi_id'], intval($row['attempt_invoice_id']), intval($row['attempt_balance_cents']), 'pending', 'guest');
        } elseif ($status === 'canceled' || $status === 'missing') {   // M4-4: an intent Stripe no longer has is as closed as a canceled one
            mysqli_query($mysqli, "UPDATE invoice_charge_attempts SET attempt_status = 'failed', attempt_error = '" . ($status === 'missing' ? 'abandoned; Stripe intent missing' : 'abandoned') . "' WHERE attempt_id = " . intval($row['attempt_id']) . " AND attempt_status = 'processing'");
            $closed += max(0, mysqli_affected_rows($mysqli));
        }
    }
    return $closed;
}

/**
 * M4-4: an admin clears one stuck attempt (needs_review forever, or an intent Stripe can no longer return). Refused when the attempt is not open, when
 * a Stripe lookup is possible and the intent is succeeded/processing there, or when Stripe could not be asked (then nothing is known). The attempt is
 * closed as failed with the admin's reason, and an invoice history row records who/why. $pi_lookup may be null only when no Stripe provider exists.
 * @return array{ok:bool, message:string}
 */
function chargeAttemptClear($mysqli, int $attempt_id, string $reason, ?callable $pi_lookup, string $by = 'admin'): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'message' => 'A reason is required to clear a charge attempt'];
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM invoice_charge_attempts WHERE attempt_id = " . intval($attempt_id)));
    if (!$row || !in_array($row['attempt_status'], ['processing', 'pending'], true)) {
        return ['ok' => false, 'message' => 'That charge attempt is not open'];
    }
    if ($pi_lookup !== null) {
        if ($row['attempt_pi_id'] !== '') {
            $st = $pi_lookup((string) $row['attempt_pi_id']);
            if ($st === null) {
                return ['ok' => false, 'message' => 'Stripe could not be asked about this payment right now; try again when it is reachable'];
            }
            if (in_array($st, ['succeeded', 'processing', 'requires_capture'], true)) {
                return ['ok' => false, 'message' => 'Stripe reports this payment as ' . $st . '; it is not cleared (the webhook records it)'];
            }
        } else {
            $st = $pi_lookup('', false, (string) $row['attempt_key']);
            if ($st !== 'none') {
                return ['ok' => false, 'message' => $st === 'found' ? 'Stripe has a PaymentIntent for this attempt; it is not cleared' : 'Stripe could not be searched for this attempt right now; try again when it is reachable'];
            }
        }
    }
    $err = mysqli_real_escape_string($mysqli, substr('cleared by ' . $by . ': ' . $reason, 0, 250));
    mysqli_query($mysqli, "UPDATE invoice_charge_attempts SET attempt_status = 'failed', attempt_error = '$err' WHERE attempt_id = " . intval($attempt_id) . " AND attempt_status IN ('processing','pending')");
    if (mysqli_affected_rows($mysqli) < 1) {
        return ['ok' => false, 'message' => 'That charge attempt was settled in the meantime'];
    }
    $desc = mysqli_real_escape_string($mysqli, substr('Charge attempt ' . intval($attempt_id) . ' cleared by ' . $by . ': ' . $reason, 0, 250));
    mysqli_query($mysqli, "INSERT INTO history SET history_status = 'Charge attempt cleared', history_description = '$desc', history_invoice_id = " . intval($row['attempt_invoice_id']));
    return ['ok' => true, 'message' => 'Charge attempt cleared'];
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
function chargeAttemptSettleIntent($mysqli, string $pi_id, int $invoice_id, int $cents, string $status, string $source = 'webhook', bool $keep_guest_open = false): void
{
    $pi = mysqli_real_escape_string($mysqli, $pi_id);
    $status = in_array($status, ['succeeded', 'failed', 'pending'], true) ? $status : 'failed';
    // MSP-DS-166 (re-review M3-1): payment_intent.payment_failed leaves a guest-page intent at requires_payment_method, still confirmable on the
    // open page. $keep_guest_open leaves such an attempt live (it is canceled at Stripe by the next claim or by chargeAttemptExpireGuest()).
    $keep = $keep_guest_open ? " AND NOT (attempt_source = 'guest' AND attempt_status = 'processing')" : '';
    mysqli_query($mysqli, "UPDATE invoice_charge_attempts SET attempt_status = '$status', attempt_pi_id = '$pi'
        WHERE attempt_status IN ('processing','pending')$keep AND (attempt_pi_id = '$pi'
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
