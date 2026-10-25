<?php

/*
 * ITFlow - Xero API client (roadmap #20: one-way push, mirroring QboClient).
 *
 * IMPORTANT, read before touching: this environment has no real Xero Developer sandbox
 * credentials, so nothing in this file has ever been exercised against Xero's actual servers.
 * It is built from Xero's public Accounting API documentation (OAuth2 Authorization Code flow,
 * the /connections tenant-selection step, the Contacts/Items/Invoices/Quotes/Payments/Accounts
 * endpoint shapes) and verified only against a loopback fake-Xero server
 * (tests/support/fake_xero_router.php, used by tests/accounting_xero.php). The real "does Xero
 * actually accept this" proof can only happen once a real Xero app is registered and tested
 * live - flagging this plainly rather than overstating confidence.
 *
 * FACADE DESIGN (see includes/interface_accounting_client.php's header for why): every method
 * here takes/returns the SAME QuickBooks-Online-shaped array that QboClient uses
 * (CustomerRef-free, but 'DisplayName'/'Id'/'SyncToken'/'DocNumber'/'ItemRef' etc. on the way
 * out) so includes/accounting_functions.php's existing sync orchestration and
 * admin/settings_accounting.php's existing parsing code work completely unchanged for Xero too.
 * Internally, every method translates that shape into Xero's real wire format, makes the real
 * Xero API call, and translates the response back. This class is the ONLY place that knows
 * Xero's actual JSON shape.
 *
 * Xero's "Item" entities require a unique Code (<=30 chars); one is derived here from the local
 * product id (or a slug of the name) since ITFlow's own data model has no separate SKU field.
 * Xero's "Payment" entities require a bank Account (QBO's Payment needs no such thing - it is
 * tied to the Invoice via LinkedTxn only) - the SAME accounting_default_income_account_id field
 * QBO uses for its income account is reused here to hold Xero's default bank Account Code
 * instead (the column is generic enough; see the dynamic label in admin/settings_accounting.php).
 *
 * Tokens are stored encrypted via encryptSetting()/decryptSetting() (functions.php) - this class
 * only calls them, same convention as QboClient.
 */

require_once __DIR__ . '/interface_accounting_client.php';

class XeroException extends AccountingClientException {}

class XeroClient implements AccountingClientInterface {

    // Xero OAuth2 token endpoint (same for every tenant - Xero has no sandbox API host; its
    // "Demo Company" is just a normal tenant selected via /connections like any other org).
    const TOKEN_URL = 'https://identity.xero.com/connect/token';

    // Xero requires calling /connections (outside the api.xro namespace) with the access token
    // to discover which tenant(s) it's authorized for - QBO's realmId needs no such extra call.
    const CONNECTIONS_URL = 'https://api.xero.com/connections';

    const API_BASE = 'https://api.xero.com/api.xro/2.0';

    /** @var mysqli */
    private $mysqli;
    private $integration;
    private $accounting_id;
    private $tenant_id;      // accounting_realm_id, reused for Xero's tenantId
    private $client_id;
    private $client_secret;
    private $bank_account_code_hint;
    private $access_token;

    // Test-injectability only (see class header) - null means "use the real constants".
    private $token_url_override;
    private $connections_url_override;
    private $api_base_override;

    public function __construct(mysqli $mysqli, array $integration, array $url_overrides = []) {
        $this->mysqli          = $mysqli;
        $this->integration     = $integration;
        $this->accounting_id   = intval($integration['accounting_id']);
        $this->tenant_id       = (string) ($integration['accounting_realm_id'] ?? '');
        $this->client_id       = (string) ($integration['accounting_client_id'] ?? '');
        $this->client_secret   = decryptSetting((string) ($integration['accounting_client_secret'] ?? ''));
        $this->bank_account_code_hint = (string) ($integration['accounting_default_income_account_id'] ?? '');
        $this->access_token    = '';
        $this->token_url_override       = $url_overrides['token_url'] ?? null;
        $this->connections_url_override = $url_overrides['connections_url'] ?? null;
        $this->api_base_override        = $url_overrides['api_base'] ?? null;
    }

    private function tokenUrl(): string {
        return $this->token_url_override ?? self::TOKEN_URL;
    }

    private function connectionsUrl(): string {
        return $this->connections_url_override ?? self::CONNECTIONS_URL;
    }

    private function apiBase(): string {
        return $this->api_base_override ?? self::API_BASE;
    }

    /**
     * Return a valid access token, refreshing (and persisting the rotated refresh token) if
     * the stored one is missing or within 2 minutes of expiry. Mirrors QboClient::getAccessToken()
     * exactly - Xero's refresh grant is the same shape as QBO's.
     */
    public function getAccessToken(): string {
        $expires_at = $this->integration['accounting_token_expires_at'] ?? null;
        $access     = decryptSetting((string) ($this->integration['accounting_access_token'] ?? ''));

        $needs_refresh = empty($access)
            || empty($expires_at)
            || strtotime($expires_at) <= (time() + 120);

        if (!$needs_refresh) {
            $this->access_token = $access;
            return $access;
        }

        return $this->refreshAccessToken();
    }

    private function refreshAccessToken(): string {
        $refresh_token = decryptSetting((string) ($this->integration['accounting_refresh_token'] ?? ''));
        if (empty($refresh_token) || empty($this->client_id) || empty($this->client_secret)) {
            throw new XeroException('Xero not connected: missing client credentials or refresh token.');
        }

        $ch = curl_init($this->tokenUrl());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->client_id . ':' . $this->client_secret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ], '', '&'));
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $raw       = curl_exec($ch);
        $curl_err  = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $http_code < 200 || $http_code >= 300) {
            $reason = !empty($curl_err) ? $curl_err : "HTTP $http_code";
            throw new XeroException("Xero token refresh failed: $reason");
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['access_token'])) {
            throw new XeroException('Xero token refresh failed: access token missing from response.');
        }

        $new_access  = (string) $json['access_token'];
        // Xero rotates refresh tokens on every use, exactly like Intuit - always store it.
        $new_refresh = !empty($json['refresh_token']) ? (string) $json['refresh_token'] : $refresh_token;
        $expires_in  = intval($json['expires_in'] ?? 1800);
        $expires_at  = date('Y-m-d H:i:s', time() + $expires_in);

        $access_esc  = mysqli_real_escape_string($this->mysqli, encryptSetting($new_access));
        $refresh_esc = mysqli_real_escape_string($this->mysqli, encryptSetting($new_refresh));
        $expires_esc = mysqli_real_escape_string($this->mysqli, $expires_at);

        mysqli_query(
            $this->mysqli,
            "UPDATE accounting_integrations SET
                accounting_access_token = '$access_esc',
                accounting_refresh_token = '$refresh_esc',
                accounting_token_expires_at = '$expires_esc'
             WHERE accounting_id = $this->accounting_id"
        );

        $this->integration['accounting_access_token']     = encryptSetting($new_access);
        $this->integration['accounting_refresh_token']    = encryptSetting($new_refresh);
        $this->integration['accounting_token_expires_at'] = $expires_at;
        $this->access_token = $new_access;

        return $new_access;
    }

    /** Authenticated Xero Accounting API request. Returns the decoded JSON array. */
    private function request(string $method, string $path, ?array $body = null): array {
        if (empty($this->tenant_id)) {
            throw new XeroException('Xero not connected: missing tenant id (did the /connections step run?).');
        }

        $token = $this->getAccessToken();
        $url   = $this->apiBase() . '/' . ltrim($path, '/');

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Xero-tenant-id: ' . $this->tenant_id,
            'Accept: application/json',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $raw       = curl_exec($ch);
        $curl_err  = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new XeroException("Xero request transport error: $curl_err");
        }
        if ($http_code < 200 || $http_code >= 300) {
            $snippet = substr((string) $raw, 0, 400);
            throw new XeroException("Xero API error HTTP $http_code on $method $path: $snippet");
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    /** Escape a value for Xero's "where" clause string literal. */
    private function w(string $value): string {
        return str_replace('"', '\\"', $value);
    }

    // ── Customer (Xero Contact) ──────────────────────────────────────────────

    public function findCustomer(string $display_name, string $email = ''): ?array {
        if ($display_name !== '') {
            $res = $this->request('GET', 'Contacts?where=' . rawurlencode('Name=="' . $this->w($display_name) . '"'));
            if (!empty($res['Contacts'][0]['ContactID'])) {
                return $this->contactToQboShape($res['Contacts'][0]);
            }
        }
        if ($email !== '') {
            $res = $this->request('GET', 'Contacts?where=' . rawurlencode('EmailAddress=="' . $this->w($email) . '"'));
            if (!empty($res['Contacts'][0]['ContactID'])) {
                return $this->contactToQboShape($res['Contacts'][0]);
            }
        }
        return null;
    }

    public function searchCustomers(string $q = '', int $max = 50): array {
        $q   = trim($q);
        $max = max(1, min(1000, $max));
        $path = 'Contacts?order=Name&page=1';
        if ($q !== '') {
            $path .= '&where=' . rawurlencode('Name.StartsWith("' . $this->w($q) . '")');
        }
        $res = $this->request('GET', $path);
        $out = [];
        foreach (array_slice($res['Contacts'] ?? [], 0, $max) as $c) {
            $out[] = [
                'Id'          => (string) ($c['ContactID'] ?? ''),
                'DisplayName' => (string) ($c['Name'] ?? ''),
                'Email'       => (string) ($c['EmailAddress'] ?? ''),
                'SyncToken'   => '', // Xero has no QBO-style SyncToken; updates use ContactID alone.
            ];
        }
        return $out;
    }

    public function createCustomer(array $data): array {
        $contact = ['Name' => $data['display_name']];
        if (!empty($data['email'])) {
            $contact['EmailAddress'] = $data['email'];
        }
        if (!empty($data['phone'])) {
            $contact['Phones'] = [['PhoneType' => 'DEFAULT', 'PhoneNumber' => $data['phone']]];
        }
        if (!empty($data['website'])) {
            $contact['Website'] = $data['website'];
        }
        $res = $this->request('PUT', 'Contacts', ['Contacts' => [$contact]]);
        if (empty($res['Contacts'][0]['ContactID'])) {
            throw new XeroException('Xero createCustomer: unexpected response.');
        }
        return $this->contactToQboShape($res['Contacts'][0]);
    }

    private function contactToQboShape(array $c): array {
        return [
            'Id'               => (string) ($c['ContactID'] ?? ''),
            'SyncToken'        => '',
            'DisplayName'      => (string) ($c['Name'] ?? ''),
            'PrimaryEmailAddr' => ['Address' => (string) ($c['EmailAddress'] ?? '')],
        ];
    }

    // ── Item ─────────────────────────────────────────────────────────────────

    public function findItem(string $name): ?array {
        if ($name === '') {
            return null;
        }
        $res = $this->request('GET', 'Items?where=' . rawurlencode('Name=="' . $this->w($name) . '"'));
        if (!empty($res['Items'][0]['ItemID'])) {
            return $this->itemToQboShape($res['Items'][0]);
        }
        return null;
    }

    public function searchItems(string $q = '', int $max = 50): array {
        $q   = trim($q);
        $max = max(1, min(1000, $max));
        $path = 'Items?order=Name';
        if ($q !== '') {
            $path .= '&where=' . rawurlencode('Name.StartsWith("' . $this->w($q) . '")');
        }
        $res = $this->request('GET', $path);
        $out = [];
        foreach (array_slice($res['Items'] ?? [], 0, $max) as $it) {
            $out[] = [
                'Id'        => (string) ($it['ItemID'] ?? ''),
                'Name'      => (string) ($it['Name'] ?? ''),
                'UnitPrice' => isset($it['SalesDetails']['UnitPrice']) ? (string) $it['SalesDetails']['UnitPrice'] : '',
                'Type'      => 'Service',
                'SyncToken' => '',
            ];
        }
        return $out;
    }

    /**
     * Create a Xero Item. Xero requires a unique Code (<=30 chars) that QBO's model has no
     * equivalent of - derived here from the income-account-ref convention QboClient uses
     * ($data['income_account_id'] is actually the SALES account Code for Xero, see class header).
     */
    public function createItem(array $data): array {
        $name = (string) $data['name'];
        $code = substr('RMSP-' . preg_replace('/[^A-Za-z0-9]/', '', $name) . '-' . substr(md5($name), 0, 6), 0, 30);
        $item = [
            'Code' => $code,
            'Name' => $name,
        ];
        $sales = [];
        if (isset($data['price'])) {
            $sales['UnitPrice'] = round(floatval($data['price']), 2);
        }
        if (!empty($data['income_account_id'])) {
            $sales['AccountCode'] = (string) $data['income_account_id'];
        }
        if (!empty($sales)) {
            $item['SalesDetails'] = $sales;
        }
        if (!empty($data['description'])) {
            $item['Description'] = $data['description'];
        }
        $res = $this->request('PUT', 'Items', ['Items' => [$item]]);
        if (empty($res['Items'][0]['ItemID'])) {
            throw new XeroException('Xero createItem: unexpected response.');
        }
        return $this->itemToQboShape($res['Items'][0]);
    }

    private function itemToQboShape(array $it): array {
        return [
            'Id'        => (string) ($it['ItemID'] ?? ''),
            'SyncToken' => '',
            'Name'      => (string) ($it['Name'] ?? ''),
        ];
    }

    /** First active SALES-type account - Xero's equivalent of QBO's first active Income account. */
    public function getDefaultIncomeAccountId(): ?string {
        $res = $this->request('GET', 'Accounts?where=' . rawurlencode('Type=="SALES" AND Status=="ACTIVE"'));
        if (!empty($res['Accounts'][0]['Code'])) {
            return (string) $res['Accounts'][0]['Code'];
        }
        return null;
    }

    /** First active BANK-type account - used by createPayment() when no account code is configured. */
    private function getDefaultBankAccountCode(): ?string {
        if ($this->bank_account_code_hint !== '') {
            return $this->bank_account_code_hint;
        }
        $res = $this->request('GET', 'Accounts?where=' . rawurlencode('Type=="BANK" AND Status=="ACTIVE"'));
        if (!empty($res['Accounts'][0]['Code'])) {
            return (string) $res['Accounts'][0]['Code'];
        }
        return null;
    }

    // ── Invoice ──────────────────────────────────────────────────────────────

    /** $payload is QBO-shaped (CustomerRef/Line[]/TxnDate/DueDate/DocNumber) - translated here. */
    public function createInvoice(array $payload): array {
        $xero = [
            'Type'    => 'ACCREC',
            'Contact' => ['ContactID' => (string) ($payload['CustomerRef']['value'] ?? '')],
            'Date'    => (string) ($payload['TxnDate'] ?? ''),
            'DueDate' => (string) ($payload['DueDate'] ?? ''),
            'Status'  => 'AUTHORISED',
        ];
        if (!empty($payload['DocNumber'])) {
            $xero['InvoiceNumber'] = (string) $payload['DocNumber'];
        }
        $xero['LineItems'] = $this->linesToXeroShape($payload['Line'] ?? []);

        $res = $this->request('PUT', 'Invoices', ['Invoices' => [$xero]]);
        if (empty($res['Invoices'][0]['InvoiceID'])) {
            throw new XeroException('Xero createInvoice: unexpected response.');
        }
        return $this->invoiceToQboShape($res['Invoices'][0]);
    }

    public function searchInvoices(int $max = 1000): array {
        throw new XeroException('Bulk "pull from Xero" is not supported in this version - push individual records from RivetMSP instead.');
    }

    private function invoiceToQboShape(array $inv): array {
        return [
            'Id'         => (string) ($inv['InvoiceID'] ?? ''),
            'SyncToken'  => '',
            'DocNumber'  => (string) ($inv['InvoiceNumber'] ?? ''),
        ];
    }

    // ── Estimate (ITFlow "Quote") -> Xero Quote ──────────────────────────────

    public function createEstimate(array $payload): array {
        $xero = [
            'Contact' => ['ContactID' => (string) ($payload['CustomerRef']['value'] ?? '')],
            'Date'    => (string) ($payload['TxnDate'] ?? ''),
            'Status'  => 'SENT',
        ];
        if (!empty($payload['DocNumber'])) {
            $xero['QuoteNumber'] = (string) $payload['DocNumber'];
        }
        if (!empty($payload['ExpirationDate'])) {
            $xero['ExpiryDate'] = (string) $payload['ExpirationDate'];
        }
        $xero['LineItems'] = $this->linesToXeroShape($payload['Line'] ?? []);

        $res = $this->request('PUT', 'Quotes', ['Quotes' => [$xero]]);
        if (empty($res['Quotes'][0]['QuoteID'])) {
            throw new XeroException('Xero createEstimate: unexpected response.');
        }
        return [
            'Id'        => (string) ($res['Quotes'][0]['QuoteID'] ?? ''),
            'SyncToken' => '',
            'DocNumber' => (string) ($res['Quotes'][0]['QuoteNumber'] ?? ''),
        ];
    }

    public function searchEstimates(int $max = 1000): array {
        throw new XeroException('Bulk "pull from Xero" is not supported in this version - push individual records from RivetMSP instead.');
    }

    // ── Payment ──────────────────────────────────────────────────────────────

    /** $payload is QBO-shaped (TotalAmt/CustomerRef/TxnDate/Line[0].LinkedTxn.TxnId). */
    public function createPayment(array $payload): array {
        $invoice_id = (string) ($payload['Line'][0]['LinkedTxn'][0]['TxnId'] ?? '');
        if ($invoice_id === '') {
            throw new XeroException('Xero createPayment: no linked invoice id in payload.');
        }
        $account_code = $this->getDefaultBankAccountCode();
        if ($account_code === null) {
            throw new XeroException('No Xero bank account is available to record the payment against. Set a Default Bank Account Code in Accounting Settings.');
        }
        $xero = [
            'Invoice' => ['InvoiceID' => $invoice_id],
            'Account' => ['Code' => $account_code],
            'Date'    => (string) ($payload['TxnDate'] ?? ''),
            'Amount'  => round(floatval($payload['TotalAmt'] ?? 0), 2),
        ];
        $res = $this->request('PUT', 'Payments', ['Payments' => [$xero]]);
        if (empty($res['Payments'][0]['PaymentID'])) {
            throw new XeroException('Xero createPayment: unexpected response.');
        }
        return [
            'Id'        => (string) ($res['Payments'][0]['PaymentID'] ?? ''),
            'SyncToken' => '',
        ];
    }

    public function searchPayments(int $max = 1000): array {
        throw new XeroException('Bulk "pull from Xero" is not supported in this version - push individual records from RivetMSP instead.');
    }

    /** Translate QBO-shaped Line[] (SalesItemLineDetail) into Xero LineItems[]. */
    private function linesToXeroShape(array $lines): array {
        $out = [];
        foreach ($lines as $line) {
            $detail = $line['SalesItemLineDetail'] ?? [];
            $out[] = [
                'Description' => (string) ($line['Description'] ?? ''),
                'Quantity'    => (float) ($detail['Qty'] ?? 1),
                'UnitAmount'  => (float) ($detail['UnitPrice'] ?? $line['Amount'] ?? 0),
                // ItemCode is intentionally omitted: accounting_functions.php passes QBO Item Ids
                // (ItemRef.value) which are not Xero Item Codes - the line still posts correctly
                // to Xero without one (a free-text line), just not linked to a Xero Item record.
            ];
        }
        return $out;
    }
}
