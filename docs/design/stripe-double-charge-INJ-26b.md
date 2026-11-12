# Design: one charge per invoice balance (MSP-INJ-26b)

Status: DESIGN ONLY, technically approved by Code Review; implementation waits for Blake's OK (it adds a migration and changes the payment flow). Nothing here is implemented, and no Stripe call is needed to review or test it.

## Problem (confirmed in code)
- Three separate idempotency namespaces with a one-minute bucket: `stripeChargeIdempotencyKey()` (portal), `md5("agent_pay_stripe_...")` (agent, payment.php), `md5("cron_autopay_stripe_...")` (cron). A double submit across a minute boundary, or a portal click racing the agent button or cron autopay, creates two real PaymentIntents. The unique index on `payment_provider_ref` records both (two different intents).
- No lock: the invoice status check and the charge are separate steps, so two requests both see `Sent`.
- `guest/guest_ajax.php?stripe_create_pi` creates a NEW PaymentIntent on every page load; paying from two tabs charges twice.

## Design
1. **One claim table** `invoice_charge_attempts`: `attempt_id`, `invoice_id`, `balance_cents`, `generation` (int), `source` (agent|portal|autopay|guest), `status` (processing|succeeded|failed|abandoned), `processing_key` (= invoice_id while processing, else NULL; **UNIQUE**), `provider_ref` (PaymentIntent id, nullable), `created_by`, `created_at`, `finished_at`. MariaDB has no partial unique index, so the nullable unique `processing_key` is what makes two simultaneous claims impossible.
2. **Claim before calling the provider** (`claimInvoiceCharge($mysqli, $invoice_id, $source)` in `includes/billing_guards.php`):
   `START TRANSACTION; SELECT invoice_status, invoice_amount FROM invoices WHERE invoice_id = ? FOR UPDATE;` recompute the balance in integer cents (`invoiceBalanceDue`), refuse unless the status accepts payment and the balance is > 0; if a `processing` attempt exists and is younger than 10 minutes, refuse ("a payment is already in progress"); if older, mark it `abandoned` after asking the provider for its intent (never assume it failed); insert the new attempt with `generation = 1 + COUNT(failed attempts for this invoice and balance_cents)`; `COMMIT`.
3. **One deterministic key** for every path: `sha256("rivetmsp|inv|{invoice_id}|bal|{balance_cents}|gen|{generation}")`. Same invoice, same balance, same attempt generation => one provider charge, whatever path or minute. A genuine retry after a decline bumps `generation` (that is the only thing the old one-minute bucket was for). A part payment changes the balance and so the key.
4. **Finish the attempt** after the provider answers: `succeeded` (and record the payment through `insertStripePaymentOnce`), or `failed`. The same `finishInvoiceCharge()` runs in a `finally`, so an exception cannot leave an attempt stuck in `processing` for a human to find.
5. **Guest pay page reuses the open intent:** `stripe_create_pi` first looks for the invoice's attempt with a `provider_ref`; if the provider says that intent is still open (`requires_payment_method` / `requires_confirmation`) and its amount equals the current balance, return its `client_secret` instead of creating another; if the balance changed, cancel the stale intent first. Only one intent per invoice balance can exist, so two tabs cannot both pay.
6. **Webhook stays idempotent**, unchanged: `insertStripePaymentOnce` on the unique `payment_provider_ref`. Add: if a payment arrives for an invoice that is already `Paid`, record it and raise an alert ("overpayment: refund needed") instead of silently ignoring it, so money received is never unrecorded.
7. **Cron autopay** uses the same claim; if the claim is refused it logs "skipped: payment in progress" and moves on.

## Amendments from review
- **Stale claims.** A `finally` does not cover a killed process or a timeout. A `processing` attempt older than a few minutes is taken over on the next claim (marked `abandoned`) after the provider is asked about its intent. The retry keeps the SAME deterministic key (same invoice, balance and generation), so Stripe collapses it into the original charge (keys live about 24 h) instead of charging again.
- **Charged, but our DB write failed.** The balance is unchanged, so the key is identical and the retry returns the ORIGINAL PaymentIntent. That path records the payment from the returned intent through `insertStripePaymentOnce()`; it is never treated as a new success.
- **Missing table fails CLOSED.** If `invoice_charge_attempts` does not exist (code deployed before the migration), the charge is refused with a clear message and autopay skips the invoice with an alert; it never charges without the claim. (Replaces the earlier "no-op with a loud log", which failed open for money.)
- **`requires_action` / `authentication_required`** on an off-session charge counts as `failed`; `generation` is bumped only after that decline is recorded.
- **Extra tests:** stale-`processing` reclaim keeps the same key; the same invoice from portal and autopay yields one provider charge; a payment arriving for an already-Paid invoice raises the overpayment alert.
- **No keys, real or test,** are used until Blake decides.

## Migration
New table only (DB step + `db.sql` mirror). No change to existing rows; code deployed before the migration refuses to charge (see Amendments); the update runner applies migrations, so this only matters for a code-without-migration deploy.

## Tests (no live keys, ever)
- A `FakePaymentProvider` implementing `includes/interface_payment_provider.php` that records every call and returns canned intents; `getPaymentProvider()` gets a test hook.
- On a scratch DB: two child PHP processes call the claim at the same instant => exactly one gets it; the same invoice+balance+generation yields the same key on all three paths; a decline then retry yields generation 2; a part payment changes the key; a stuck `processing` older than 10 minutes is taken over only after the provider lookup; guest page twice => one intent.
- Real Stripe TEST-mode keys would add end-to-end confidence for the guest page; that is Blake's call and is not required for the logic above.

## Not in scope here
Bounding quote and recurring-invoice discounts (separate small change). (Pax8 cost sync locking, INJ-22d, is done separately.)
