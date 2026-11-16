<?php

/*
 * Pure (database-free) billing state and money guards shared by the invoice, quote and payment handlers.
 * Kept free of globals so tests/billing_guards.php can exercise it without a database.
 */

/**
 * Convert a money value to integer cents so comparisons never rely on float ==.
 */
function moneyToCents($value): int
{
    return (int) round(((float) $value) * 100);
}

/**
 * Parse a posted payment amount. Returns the amount rounded to cents, or null when it is not a
 * finite number greater than zero.
 */
function parsePositiveMoney($raw): ?float
{
    if (!is_numeric($raw)) {
        return null;
    }
    $value = (float) $raw;
    if (!is_finite($value)) {
        return null;
    }
    $cents = moneyToCents($value);
    if ($cents <= 0) {
        return null;
    }
    return $cents / 100;
}

/**
 * Invoice states that can still receive a payment.
 */
function invoiceStatusAcceptsPayment($status): bool
{
    return in_array((string) $status, ['Sent', 'Viewed', 'Partial', 'Overdue'], true);
}

/**
 * A Paid invoice is a closed financial record: no item edits, status changes, cancel or delete.
 */
function invoiceStatusIsLocked($status): bool
{
    return (string) $status === 'Paid';
}

/**
 * Status an invoice should have after a payment, comparing in cents (never float ==).
 * Returns 'Paid' when nothing is left, 'Partial' otherwise.
 */
function invoiceStatusAfterPayment($invoice_amount, $total_paid): string
{
    return moneyToCents($invoice_amount) - moneyToCents($total_paid) <= 0 ? 'Paid' : 'Partial';
}

/**
 * MSP-INJ-26(a): the amount to charge is what is STILL OWED (invoice total minus payments already recorded), never the full invoice total. A Partial invoice that was
 * set back to Sent used to be charged in full. Rounded to cents; zero or less means there is nothing to charge.
 */
function invoiceBalanceDue($mysqli, $invoice_id, $invoice_amount): float
{
    $invoice_id = intval($invoice_id);
    $paid = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(payment_amount), 0) FROM payments WHERE payment_invoice_id = $invoice_id"))[0] ?? 0;

    // whole cents, so rounding can never leave 0.01 behind or ask for 0.01 too much
    return (moneyToCents($invoice_amount) - moneyToCents($paid)) / 100;
}

/**
 * Whether a payment of $amount fits within the remaining balance (cents comparison).
 */
function paymentFitsBalance($amount, $balance): bool
{
    return moneyToCents($amount) <= moneyToCents($balance);
}

/**
 * A quote may only be accepted or declined from Sent/Viewed, and not after it expired.
 * $expire is the quote_expire date string (may be empty); $now is a unix timestamp.
 */
function quoteCanBeAnswered($status, $expire, ?int $now = null): bool
{
    if (!in_array((string) $status, ['Sent', 'Viewed'], true)) {
        return false;
    }
    $now = $now ?? time();
    $expire = trim((string) $expire);
    if ($expire !== '' && strpos($expire, '0000-00-00') !== 0) {
        $ts = strtotime($expire . ' 23:59:59');
        if ($ts !== false && $ts < $now) {
            return false;
        }
    }
    return true;
}

/**
 * A quote may only be turned into an invoice once the client (or an agent) accepted it.
 * Re-invoicing an already Invoiced quote is refused.
 */
/**
 * MSP-DS-57: a quote's items, discount and dates are editable only while the client has not answered it. Accepted, Declined and
 * Invoiced quotes are read-only (the invoice must match what the client accepted).
 */
function quoteContentEditable($status): bool
{
    return !in_array((string) $status, ['Accepted', 'Declined', 'Invoiced'], true);
}

function quoteCanBeInvoiced($status): bool
{
    return (string) $status === 'Accepted';
}

/**
 * Idempotency key for creating a Stripe PaymentIntent: the same invoice, amount and saved method
 * within the same minute map to one key, so a double-click or parallel POST cannot charge twice.
 * (The minute bucket keeps a genuine retry after a decline possible.)
 */
function stripeChargeIdempotencyKey($invoice_id, $amount, $saved_method_id, ?int $now = null): string
{
    $now = $now ?? time();
    return 'rivetmsp-inv' . intval($invoice_id) . '-' . moneyToCents($amount) . '-pm' . intval($saved_method_id) . '-' . intval(floor($now / 60));
}

/**
 * Record a Stripe payment exactly once per PaymentIntent. Returns the new payment_id, or 0 when
 * a payment for this PaymentIntent already exists (recorded by the webhook, the portal, the agent
 * or autopay path). Works before and after the payment_provider_ref UNIQUE index migration:
 * the existence check runs on payment_reference, and the index (when present) closes the race.
 * $pi_id and $currency must already be sanitised by the caller.
 */
function insertStripePaymentOnce($mysqli, $date, $amount, $currency, $account_id, $method, $pi_id, $invoice_id): int
{
    static $has_provider_ref = null;

    $reference = "Stripe - $pi_id";

    $exists = mysqli_query($mysqli, "SELECT payment_id FROM payments WHERE payment_reference = '$reference' LIMIT 1");
    if ($exists && mysqli_num_rows($exists) > 0) {
        return 0;
    }

    if ($has_provider_ref === null) {
        $col = mysqli_query($mysqli, "SHOW COLUMNS FROM payments LIKE 'payment_provider_ref'");
        $has_provider_ref = ($col && mysqli_num_rows($col) > 0);
    }

    $account_id = intval($account_id);
    $invoice_id = intval($invoice_id);
    $amount = floatval($amount);
    $ref_sql = $has_provider_ref ? ", payment_provider_ref = '$reference'" : '';

    $sql = "INSERT INTO payments SET payment_date = '$date', payment_amount = $amount, payment_currency_code = '$currency', payment_account_id = $account_id, payment_method = '$method', payment_reference = '$reference'$ref_sql, payment_invoice_id = $invoice_id";

    try {
        $ok = mysqli_query($mysqli, $sql);
    } catch (\mysqli_sql_exception $e) {
        if ((int) $e->getCode() === 1062) {
            return 0;
        }
        throw $e;
    }
    if (!$ok) {
        return 0; // duplicate (errno 1062) or any failed insert: nothing was recorded
    }

    return (int) mysqli_insert_id($mysqli);
}

/**
 * Why an invoice may not be edited/cancelled/deleted right now, or null when the change is allowed.
 * A Paid invoice is closed. With $block_if_payments, any invoice that already has payments recorded
 * is also refused (cancel / non-billable / delete would orphan or destroy the financial record).
 */
function invoiceChangeBlockReason($mysqli, $invoice_id, bool $block_if_payments = false): ?string
{
    $invoice_id = intval($invoice_id);
    $res = mysqli_query($mysqli, "SELECT invoice_status FROM invoices WHERE invoice_id = $invoice_id LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row) {
        return 'Invoice not found';
    }
    if (invoiceStatusIsLocked($row['invoice_status'])) {
        return 'A paid invoice is a closed record and cannot be changed';
    }
    if ($block_if_payments) {
        $pay = mysqli_query($mysqli, "SELECT COUNT(*) AS c FROM payments WHERE payment_invoice_id = $invoice_id");
        $prow = $pay ? mysqli_fetch_assoc($pay) : null;
        if ($prow && intval($prow['c']) > 0) {
            return 'This invoice has payments recorded; remove or reconcile the payments first';
        }
    }
    return null;
}

/**
 * MSP-DS-68 / MSP-DS-129 (review R15): the invoice amount after adding a line of $line_total - every existing line plus the new one, minus the
 * discount, never below zero (same formula the line-item handlers use after saving).
 */
function invoiceAmountAfterLine($mysqli, $invoice_id, $line_total): float
{
    $invoice_id = intval($invoice_id);
    $items = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(item_total), 0) FROM invoice_items WHERE item_invoice_id = $invoice_id"))[0] ?? 0;
    $disc = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(invoice_discount_amount, 0) FROM invoices WHERE invoice_id = $invoice_id"))[0] ?? 0;
    return max(0, moneyToCents(floatval($items) + floatval($line_total) - floatval($disc)) / 100);
}

/**
 * Reason a line of $line_total (negative = credit) cannot be added to an existing invoice, or null. A line that would take the invoice total
 * below what has already been paid leaves a Partial invoice no payment path accepts (MSP-DS-129), so it is refused.
 */
function invoiceLineBlockReason($mysqli, $invoice_id, $line_total): ?string
{
    if (invoiceBalanceDue($mysqli, $invoice_id, invoiceAmountAfterLine($mysqli, $invoice_id, $line_total)) < 0) {
        return 'This line would take the invoice total below the amount already paid; reconcile the payments first';
    }
    return null;
}

/**
 * After an invoice total change: a Partial invoice whose payments now exactly cover the total is Paid (invoiceStatusAfterPayment).
 */
function invoiceSettleStatus($mysqli, $invoice_id): void
{
    $invoice_id = intval($invoice_id);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT invoice_status, invoice_amount FROM invoices WHERE invoice_id = $invoice_id LIMIT 1"));
    if ($row && $row['invoice_status'] === 'Partial' && invoiceBalanceDue($mysqli, $invoice_id, $row['invoice_amount']) <= 0) {
        mysqli_query($mysqli, "UPDATE invoices SET invoice_status = 'Paid' WHERE invoice_id = $invoice_id LIMIT 1");
    }
}

/**
 * MSP-DS-35: write one invoice-history row per (invoice, PaymentIntent, kind) and say whether this call wrote it. $pi_id must already be
 * SQL-escaped by the caller (sanitizeInput). Used for the two Stripe cases that must never be silent: a bank debit that is still 'processing'
 * when the customer returns to the pay page, and a succeeded PaymentIntent the webhook could not record against a payable invoice.
 * Returns true only for the call that inserted the row, so the caller notifies once however often the page or the event is replayed.
 */
function invoiceHistoryOnce($mysqli, int $invoice_id, string $status, string $description_prefix, string $pi_id): bool
{
    $description = $description_prefix . ' ' . $pi_id;
    mysqli_query($mysqli, "INSERT INTO history (history_status, history_description, history_invoice_id)
        SELECT '$status', '$description', $invoice_id FROM DUAL
        WHERE NOT EXISTS (SELECT 1 FROM history WHERE history_invoice_id = $invoice_id AND history_description = '$description')");

    return mysqli_affected_rows($mysqli) >= 1;
}

/**
 * MSP-DS-161: Stripe amounts are in the currency's smallest unit. Zero-decimal currencies (JPY, KRW, VND ...) are whole units,
 * three-decimal currencies (KWD, BHD ...) use 1000. ISK/UGX stay at 100: Stripe's API still wants two decimals for them.
 */
function stripeMinorFactor($currency): int
{
    $c = strtoupper((string) $currency);
    if (in_array($c, ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true)) {
        return 1;
    }
    if (in_array($c, ['BHD', 'JOD', 'KWD', 'OMR', 'TND'], true)) {
        return 1000;
    }
    return 100;
}

/** Major-unit amount -> the integer Stripe expects. Three-decimal currencies must end in 0 (Stripe rule), so round to 10 minor units. */
function stripeAmountToMinor($amount, $currency): int
{
    $f = stripeMinorFactor($currency);
    $minor = intval(round(floatval($amount) * $f));   // round before truncating so float error (19.99*100) cannot undercharge
    return $f === 1000 ? intval(round($minor / 10) * 10) : $minor;
}

/** Integer from Stripe -> major-unit amount recorded in the books. */
function stripeAmountFromMinor($minor, $currency): float
{
    return floatval($minor) / stripeMinorFactor($currency);
}

/**
 * MSP-DS-164: marker put into every PaymentIntent's metadata so a Stripe account shared by two installs (production + beta,
 * white-label ...) does not record one install's payments on the other's invoice with the same numeric id.
 * Not the raw installation id (it also appears in lock file names); a one-way digest of it.
 */
function stripeInstallMarker(): string
{
    $id = (string) ($GLOBALS['installation_id'] ?? '');
    return $id === '' ? '' : substr(hash('sha256', 'stripe-install|' . $id), 0, 16);
}

/**
 * MSP-DS-164: does this PaymentIntent belong to this install's invoice? A marker from another install, another client, or a
 * different currency means "not ours". Intents created before the marker existed carry none and are matched on client and currency only.
 */
function stripeIntentMatchesInvoice($metadata, $pi_currency, $invoice_client_id, $invoice_currency_code, string $our_marker): bool
{
    $theirs = (string) ($metadata->itflow_install ?? '');
    if ($theirs !== '' && $our_marker !== '' && !hash_equals($our_marker, $theirs)) {
        return false;
    }
    $pi_client = (string) ($metadata->itflow_client_id ?? '');
    if ($pi_client !== '' && intval($pi_client) !== intval($invoice_client_id)) {
        return false;
    }
    return strtoupper((string) $pi_currency) === strtoupper((string) $invoice_currency_code);
}

/**
 * MSP-DS-164 (review R29): whether a succeeded PaymentIntent that found no PAYABLE invoice is still this install's money, i.e. worth a
 * "payment received but not recorded, refund needed" alert. $invoice is the unfiltered invoice row (needs invoice_client_id and
 * invoice_currency_code) or null when the id is unknown here. Another install's intent (marker, client or currency mismatch) is not ours to report.
 */
function stripeUnrecordedIntentIsOurs($metadata, $pi_currency, ?array $invoice, string $our_marker): bool
{
    if ($invoice === null) {
        // no invoice to compare client/currency against: only the install marker can say it is foreign
        $theirs = (string) ($metadata->itflow_install ?? '');
        return !($theirs !== '' && $our_marker !== '' && !hash_equals($our_marker, $theirs));
    }
    return stripeIntentMatchesInvoice($metadata, $pi_currency, intval($invoice['invoice_client_id']), (string) $invoice['invoice_currency_code'], $our_marker);
}

/**
 * MSP-DS-102: the active payment provider's card threshold (Admin > Payment Providers: invoices at or above it are not paid by card), enforced where the card
 * is charged, not only where the Pay button is shown. 0 means no limit. Same comparison as the button in guest_view_invoice.php / client/unpaid_invoices.php.
 */
function cardPaymentAllowedForInvoice($mysqli, $invoice_amount): bool
{
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT payment_provider_threshold FROM payment_providers WHERE payment_provider_active = 1 LIMIT 1")) ?: [];
    $threshold = floatval($row['payment_provider_threshold'] ?? 0);
    return $threshold == 0 || $threshold > floatval($invoice_amount);
}

/**
 * MSP-DS-56: move stock for a stocked product ATOMICALLY. Call inside a transaction (mysqli_begin_transaction): the product row is locked
 * (SELECT ... FOR UPDATE) so two concurrent invoice edits serialise on it, the available quantity is summed under that lock, and a decrement is
 * refused (false, nothing written) when it would take the stock below zero. $delta > 0 puts stock back, < 0 takes it. The lock is released by the
 * caller's commit/rollback, so the stock row and the invoice line it belongs to are written (or undone) together.
 */
function productStockMove($mysqli, $product_id, float $delta, string $note): bool
{
    $product_id = intval($product_id);
    if ($delta == 0.0) {
        return true;
    }
    mysqli_query($mysqli, "SELECT product_id FROM products WHERE product_id = $product_id FOR UPDATE");
    if ($delta < 0) {
        $available = floatval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(stock_qty), 0) FROM product_stock WHERE stock_product_id = $product_id"))[0] ?? 0);
        if ($available + $delta < 0) {
            return false;
        }
    }
    $note = mysqli_real_escape_string($mysqli, $note);
    mysqli_query($mysqli, "INSERT INTO product_stock SET stock_qty = $delta, stock_note = '$note', stock_product_id = $product_id");
    return mysqli_affected_rows($mysqli) === 1;
}

require_once __DIR__ . '/charge_attempts.php';   // MSP-DS-166 / MSP-DS-32: the shared charge-attempt scheme
