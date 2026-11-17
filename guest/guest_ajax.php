<?php

/*
 * guest_ajax.php
 * Similar to post.php/ajax.php, but for unauthenticated requests using Asynchronous JavaScript
 * Always returns data in JSON format, unless otherwise specified
 */

require_once "../config.php";

// Set Timezone
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
require_once "../plugins/totp/totp.php";


/*
 * Creates & Returns a Stripe Payment Intent for a particular invoice ID
 */

if (isset($_GET['stripe_create_pi'])) {

    header('Content-Type: application/json');

    // MSP-DS-166 (re-review M3-2): every refusal is JSON the page shows (the page's r.json() used to fail on plain text, leaving an empty form)
    function guestPiError(string $message): void
    {
        echo json_encode(['error' => $message]);
        exit;
    }

    // Params from POST (guest_pay_invoice_stripe.js)
    $jsonStr = file_get_contents('php://input');
    $jsonObj = json_decode($jsonStr, true);
    $invoice_id = intval($jsonObj['invoice_id']);
    $url_key = sanitizeInput($jsonObj['url_key']);

    // Query invoice details
    $invoice_sql = mysqli_query(
        $mysqli,
        "SELECT * FROM invoices
        LEFT JOIN clients ON invoice_client_id = client_id
        WHERE invoice_id = $invoice_id
        AND invoice_url_key = '$url_key'
        AND invoice_status NOT IN ('Draft','Paid','Cancelled','Non-Billable')
        LIMIT 1"
    );
    if (!$invoice_sql || mysqli_num_rows($invoice_sql) !== 1) {
        guestPiError("Invalid Invoice ID/SQL query");
    }

    $row = mysqli_fetch_assoc($invoice_sql);
    $invoice_prefix = nullable_htmlentities($row['invoice_prefix']);
    $invoice_number = intval($row['invoice_number']);
    $invoice_amount = floatval($row['invoice_amount']);
    $invoice_currency_code = nullable_htmlentities($row['invoice_currency_code']);
    $client_id = intval($row['client_id']);
    $client_name = nullable_htmlentities($row['client_name']);

    // Add up all the payments for the invoice and get the total amount paid to the invoice
    $sql_amount_paid = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS amount_paid FROM payments WHERE payment_invoice_id = $invoice_id");
    $row_amt = mysqli_fetch_assoc($sql_amount_paid);
    $amount_paid = floatval($row_amt['amount_paid']);
    $balance_to_pay = $invoice_amount - $amount_paid;

    $balance_to_pay = round($balance_to_pay, 2);

    if (moneyToCents($balance_to_pay) <= 0) {
        guestPiError("No balance outstanding");
    }

    // MSP-DS-166 / MSP-DS-32: a saved-card/autopay charge in flight, or a bank debit still processing, means this invoice is being paid already
    // MSP-DS-166 (re-review MRR-2): ... except the guest page's own open PaymentIntent, which is reused below (one open intent per invoice)
    // MSP-DS-166 (re-review M3-2): the page's own earlier intent is found whatever its age (an abandoned page load 2 hours or 2 days ago), not only
    // inside the live window, and reused when Stripe says it is still open.
    $live_attempt = chargeAttemptLive($mysqli, $invoice_id);
    $reuse_attempt = null;
    if ($live_attempt && !($live_attempt['attempt_source'] === 'guest' && $live_attempt['attempt_status'] === 'processing' && $live_attempt['attempt_pi_id'] !== '')) {
        guestPiError($live_attempt['attempt_status'] === 'pending' ? "A bank payment for this invoice is still processing" : "A payment for this invoice is in progress");
    }
    $reuse_attempt = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM invoice_charge_attempts WHERE attempt_invoice_id = $invoice_id AND attempt_status = 'processing'
        AND attempt_source = 'guest' AND attempt_pi_id <> '' ORDER BY attempt_id DESC LIMIT 1")) ?: null;

    // MSP-DS-102: invoices at or above the provider's card threshold are not payable by card, whatever page the request came from
    if (!cardPaymentAllowedForInvoice($mysqli, $invoice_amount)) {
        guestPiError("This invoice cannot be paid by card");
    }

    // Setup payment provider (Stripe) from payment_providers via the factory
    $stripe_provider = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT payment_provider_id FROM payment_providers WHERE payment_provider_name = 'Stripe' LIMIT 1"));
    if (!$stripe_provider) {
        guestPiError("Stripe not enabled / configured");
    }
    $provider_id = intval($stripe_provider['payment_provider_id']);

    require_once '../includes/payment_provider_factory.php';
    $provider = getPaymentProvider($provider_id);

    if ($reuse_attempt) {
        try {
            $open_pi = $provider->verifyCheckoutResult($reuse_attempt['attempt_pi_id']);
            $open_states = ['requires_payment_method', 'requires_confirmation', 'requires_action'];
            $open_ok = in_array($open_pi->status, $open_states, true)
                && intval($open_pi->amount) === stripeAmountToMinor($balance_to_pay, $invoice_currency_code)
                && strtoupper((string) $open_pi->currency) === strtoupper($invoice_currency_code);
            if ($open_ok) {
                chargeAttemptStoreIntent($mysqli, $reuse_attempt['attempt_id'], $open_pi->id);   // keeps the attempt live while the customer is still on the page
                echo json_encode(['clientSecret' => $open_pi->client_secret]);
                exit;
            }
            if (in_array($open_pi->status, ['processing', 'succeeded'], true)) {
                guestPiError("A payment for this invoice is in progress");
            }
            if (in_array($open_pi->status, $open_states, true)) {
                try { $open_pi->cancel(); } catch (Exception $eCancel) { }   // the balance moved since: that intent must not be confirmable any more
            }
            chargeAttemptFinish($mysqli, $reuse_attempt['attempt_id'], 'failed', 'guest intent replaced (' . $open_pi->status . ')');
        } catch (Exception $e) {
            http_response_code(500);
            exit(json_encode(['error' => $e->getMessage()]));
        }
    }

    // Claim the charge (invoice row lock, balance read under it, ONE deterministic idempotency key) BEFORE the PaymentIntent is created, so cron autopay,
    // an agent's saved-card charge and the portal see this guest payment and wait.
    $claim = chargeAttemptClaim($mysqli, $invoice_id, 'guest', 0, chargeAttemptProviderLookup($provider));
    if (!$claim['ok']) {
        guestPiError($claim['message']);
    }
    $balance_to_pay = $claim['balance'];
    $attempt_id = $claim['attempt_id'];

    $pi_description = "RivetMSP: $client_name payment of $invoice_currency_code $balance_to_pay for $invoice_prefix$invoice_number";

    // Same intent arguments as before (card-only preserves exact prior behaviour).
    $pi_args = [
        'amount' => stripeAmountToMinor($balance_to_pay, $invoice_currency_code), // Stripe expects cents - round before truncating so float representation error (e.g. 19.99*100 = 1998.9999...) can't undercharge by a cent
        'currency' => $invoice_currency_code,
        'description' => $pi_description,
        'metadata' => [
            'itflow_client_id' => $client_id,
            'itflow_client_name' => $client_name,
            'itflow_invoice_number' => $invoice_prefix . $invoice_number,
            'itflow_invoice_id' => $invoice_id,
            'itflow_install' => stripeInstallMarker(),
        ],
        'payment_method_types' => ['card'],
    ];

    try {
        // A3 (Stripe ACH): offer bank/ACH alongside cards when the account
        // supports it. Only an intent is created here (no charge yet), so if the
        // Stripe account has not enabled us_bank_account we safely retry card-only
        // (under its own key: the two requests have different parameters).
        try {
            $ach_args = $pi_args;
            $ach_args['payment_method_types'] = ['card', 'us_bank_account'];
            $paymentIntent = $provider->createCheckout($ach_args, ['idempotency_key' => $claim['key']]);
        } catch (Exception $eAch) {
            $paymentIntent = $provider->createCheckout($pi_args, ['idempotency_key' => $claim['key'] . '-card']);
        }

        chargeAttemptStoreIntent($mysqli, $attempt_id, $paymentIntent->id);

        $output = [
            'clientSecret' => $paymentIntent->client_secret,
        ];

        echo json_encode($output);

    } catch (Exception $e) {
        chargeAttemptStoreException($mysqli, $attempt_id, $e);
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }

    exit;
}
