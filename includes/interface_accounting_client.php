<?php
/*
 * ITFlow - Accounting client interface (roadmap #20: Xero, built on top of this extraction).
 *
 * Extracted verbatim from QboClient's existing public method surface - a pure refactor, zero
 * behavior change. QboClient now implements this interface with no changes to its own method
 * bodies; includes/accounting_functions.php's three QBO-typed sync functions (
 * accountingMakeEnsureCustomer, accountingMakeEnsureItem, accountingSyncProcessJob) are
 * re-typed against this interface instead of the concrete QboClient class, and nothing else
 * about their logic changes.
 *
 * IMPORTANT - the payload/response SHAPE every method takes/returns is QuickBooks Online's own
 * wire shape (CustomerRef, SalesItemLineDetail, SyncToken, etc.) because that is the exact shape
 * accounting_functions.php already builds and reads today, and that layer is NOT changing. Any
 * other implementation of this interface (XeroClient) is responsible for translating QBO-shaped
 * input into its own provider's real wire format internally, and translating its own responses
 * back into this same QBO-shaped output - a facade, not a second protocol. This keeps the shared
 * sync orchestration and the admin settings UI (which also reads these QBO-shaped fields in its
 * "pull from QuickBooks" import screens) working unchanged for every provider, with all of the
 * provider-specific translation work contained inside that provider's own client class.
 */

interface AccountingClientInterface {

    /** Return a valid access token, refreshing (and persisting) if needed. */
    public function getAccessToken(): string;

    // ── Customer ─────────────────────────────────────────────────────────────

    /** Find an existing remote Customer by display name or (failing that) email. */
    public function findCustomer(string $display_name, string $email = ''): ?array;

    /** List/search remote Customers for the mapping picker. */
    public function searchCustomers(string $q = '', int $max = 50): array;

    /** Create a remote Customer. Returns the created Customer array (QBO shape). */
    public function createCustomer(array $data): array;

    // ── Item ─────────────────────────────────────────────────────────────────

    /** Find an existing remote Item by name. */
    public function findItem(string $name): ?array;

    /** List/search remote Items for the product/service mapping picker. */
    public function searchItems(string $q = '', int $max = 50): array;

    /** Create a remote (Service) Item. */
    public function createItem(array $data): array;

    /** Return the Id of a usable default income account, or null. */
    public function getDefaultIncomeAccountId(): ?string;

    // ── Invoice ──────────────────────────────────────────────────────────────

    public function createInvoice(array $payload): array;

    /** List remote Invoices as full entities, for the "pull" import screen. */
    public function searchInvoices(int $max = 1000): array;

    // ── Estimate (ITFlow "Quote") ────────────────────────────────────────────

    public function createEstimate(array $payload): array;

    /** List remote Estimates as full entities, for the "pull" import screen. */
    public function searchEstimates(int $max = 1000): array;

    // ── Payment ──────────────────────────────────────────────────────────────

    public function createPayment(array $payload): array;

    /** List remote Payments as full entities, for the "pull" import screen. */
    public function searchPayments(int $max = 1000): array;
}

/** Shared base for every accounting-client exception, so a caller can catch either the
 *  generic base or a provider-specific one; existing QboException catches are unaffected. */
class AccountingClientException extends Exception {}
