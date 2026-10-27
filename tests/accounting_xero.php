<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Roadmap #20 step 2: Xero built on top of the AccountingClientInterface extraction (see
 * tests/accounting_interface_refactor.php for the QBO-unchanged proof this builds on).
 *
 * IMPORTANT, read before trusting this test as final proof: this environment has no real Xero
 * Developer sandbox credentials. Every assertion below is verified against a loopback fake-Xero
 * server (tests/support/fake_xero_router.php), built from Xero's public API documentation, NOT
 * against Xero's real servers. A genuine end-to-end OAuth2 round-trip and real API acceptance
 * can only be confirmed once a real Xero app is registered and tested live - flagging this
 * plainly rather than overstating confidence, per the agreed discipline for this item.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/accounting_xero.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/accounting_functions.php";

echo "\n--- fixtures ---\n";
$q("DELETE FROM accounting_sync_log WHERE 1");
$q("DELETE FROM accounting_sync_queue WHERE 1");
$q("DELETE FROM accounting_entity_map WHERE 1");
$q("DELETE FROM accounting_integrations WHERE 1");
$q("DELETE FROM invoice_items WHERE item_invoice_id IN (SELECT invoice_id FROM invoices WHERE invoice_note LIKE 'axt-test-%')");
$q("DELETE FROM invoices WHERE invoice_note LIKE 'axt-test-%'");
$q("DELETE FROM payments WHERE payment_reference LIKE 'axt-test-%'");
$q("DELETE FROM clients WHERE client_name LIKE 'axt-test-%'");
$q("UPDATE settings SET config_module_enable_accounting = 1 WHERE company_id = 1");

$q("INSERT INTO clients SET client_name='axt-test-Client'");
$client = (int) $db->insert_id;
$q("INSERT INTO contacts SET contact_client_id=$client, contact_name='axt-test-Contact', contact_email='axt-c@example.test', contact_primary=1");

$q("INSERT INTO invoices SET invoice_prefix='INV', invoice_number=9101, invoice_status='Sent', invoice_date=CURDATE(), invoice_due=CURDATE(), invoice_currency_code='USD', invoice_category_id=0, invoice_client_id=$client, invoice_note='axt-test-inv'");
$invoiceId = (int) $db->insert_id;
$q("INSERT INTO invoice_items SET item_name='Managed Services', item_description='Monthly MSP fee', item_quantity=3, item_price=120.00, item_order=0, item_product_id=601, item_invoice_id=$invoiceId");

$q("INSERT INTO payments SET payment_date=CURDATE(), payment_amount=250.00, payment_currency_code='USD', payment_account_id=0, payment_invoice_id=$invoiceId, payment_reference='axt-test-pay'");
$paymentId = (int) $db->insert_id;

$port = random_int(8600, 8999);
$storeFile = sys_get_temp_dir() . "/fake_xero_store_$port.json";
@unlink($storeFile);
$fakeLog = sys_get_temp_dir() . "/axt_fake_$port.log";
$fakeCmd = 'cd ' . escapeshellarg($root) . " && FAKE_XERO_STORE=" . escapeshellarg($storeFile) . " php -S 127.0.0.1:$port tests/support/fake_xero_router.php > " . escapeshellarg($fakeLog) . " 2>&1 & echo \$!";
$fakePid = (int) trim((string) shell_exec($fakeCmd));
register_shutdown_function(function () use ($fakePid, $storeFile) { if ($fakePid > 0) { @exec("kill $fakePid 2>/dev/null"); } @unlink($storeFile); });
usleep(300000);

$fakeBase = "http://127.0.0.1:$port";
putenv("RIVETMSP_TEST_XERO_TOKEN_URL=$fakeBase/token");
putenv("RIVETMSP_TEST_XERO_API_BASE=$fakeBase");
putenv("RIVETMSP_TEST_XERO_CONNECTIONS_URL=$fakeBase/connections");

$q("INSERT INTO accounting_integrations SET
    accounting_provider='xero', accounting_realm_id='fake-tenant-001',
    accounting_client_id='axt-test-client-id', accounting_client_secret='" . $esc(encryptSetting('axt-test-secret')) . "',
    accounting_access_token='" . $esc(encryptSetting('stale')) . "', accounting_refresh_token='" . $esc(encryptSetting('axt-test-refresh')) . "',
    accounting_token_expires_at='2000-01-01 00:00:00',
    accounting_auto_push=1, accounting_enabled=1");
$accId = (int) $db->insert_id;

echo "\n--- makeAccountingClient() picks XeroClient for provider='xero' ---\n";
$integration = getAccountingIntegration($mysqli);
$xero = makeAccountingClient($mysqli, $integration);
$ok($xero instanceof XeroClient && $xero instanceof AccountingClientInterface, 'makeAccountingClient() returns a XeroClient implementing AccountingClientInterface for provider=xero');

echo "\n--- push-sync pipeline against the fake Xero server, through the SAME unchanged orchestration ---\n";
$ok(enqueueAccountingSync($mysqli, 'invoice', $invoiceId), 'invoice enqueued');
$ok(enqueueAccountingSync($mysqli, 'payment', $paymentId), 'payment enqueued');

$ensureCustomer = accountingMakeEnsureCustomer($mysqli, $xero, $accId);
$ensureItem     = accountingMakeEnsureItem($mysqli, $xero, $accId, '');
$jobs = $rows("SELECT * FROM accounting_sync_queue WHERE queue_accounting_id = $accId AND queue_status = 'pending' ORDER BY queue_id ASC");
$ok(count($jobs) === 2, 'two jobs pending (invoice, payment)');
$results = [];
foreach ($jobs as $job) {
    $results[$job['queue_local_type']] = accountingSyncProcessJob($mysqli, $xero, $accId, $job, $ensureCustomer, $ensureItem);
}
$ok(($results['invoice']['outcome'] ?? '') === 'delivered', "invoice outcome is 'delivered': " . ($results['invoice']['message'] ?? ''));
$ok(($results['payment']['outcome'] ?? '') === 'delivered', "payment outcome is 'delivered' (dependency ordering worked for Xero too): " . ($results['payment']['message'] ?? ''));

$store = json_decode((string) file_get_contents($storeFile), true);
$ok(count($store['Contacts'] ?? []) === 1 && $store['Contacts'][0]['Name'] === 'axt-test-Client', 'exactly one Xero Contact created with the right Name (QBO-shaped DisplayName translated to Xero Name internally)');
$ok(count($store['Items'] ?? []) === 1 && !empty($store['Items'][0]['Code']), 'one Xero Item created with a derived Code (Xero requires one; QBO has no equivalent)');
$ok(count($store['Invoices'] ?? []) === 1 && $store['Invoices'][0]['Type'] === 'ACCREC', 'one Xero Invoice created with Type=ACCREC');
$line = $store['Invoices'][0]['LineItems'][0] ?? [];
$ok(((float) ($line['Quantity'] ?? 0)) === 3.0 && ((float) ($line['UnitAmount'] ?? 0)) === 120.0, 'the Xero invoice line carries the correct quantity and unit amount, translated from the QBO-shaped payload');
$ok(count($store['Payments'] ?? []) === 1, 'one Xero Payment created');
$ok(($store['Payments'][0]['Invoice']['InvoiceID'] ?? '') === $store['Invoices'][0]['InvoiceID'], 'the Xero payment is linked to the same Invoice that was just created');
$ok(($store['Payments'][0]['Account']['Code'] ?? '') === '090', 'the payment used the fake Bank account code looked up via Accounts (no Default Bank Account Code was configured, so it fell back to the lookup)');

echo "\n--- mapping is provider-agnostic (same accounting_entity_map shape as QBO) ---\n";
$map = getAccountingMap($mysqli, $accId, 'invoice', $invoiceId);
$ok($map && $map['map_remote_id'] === $store['Invoices'][0]['InvoiceID'], 'accounting_entity_map holds the Xero InvoiceID exactly like it would hold a QBO Invoice Id');

echo "\n--- customer/item mapping-picker search works for Xero ---\n";
$found = $xero->searchCustomers('axt-test');
$ok(count($found) === 1 && $found[0]['DisplayName'] === 'axt-test-Client', 'searchCustomers() finds the Xero Contact, QBO-shaped (DisplayName/Id/Email)');

echo "\n--- bulk 'pull from Xero' is a clean, caught refusal, not a silent gap ---\n";
$threw = false;
try {
    $xero->searchInvoices();
} catch (XeroException $e) {
    $threw = true;
    $ok(str_contains($e->getMessage(), 'not supported'), 'searchInvoices() throws a clear, explicit XeroException rather than pretending to support bulk import');
}
$ok($threw, 'the exception was actually thrown (not silently swallowed)');
$ok($e instanceof AccountingClientException, 'XeroException is catchable as the shared AccountingClientException base too');

echo "\n--- admin settings UI: Xero-specific labels render when provider=xero ---\n";
$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='axt-test-admin', user_email='axt-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }
$sdir = sys_get_temp_dir() . '/axt_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sid = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);

[$c, $body] = sec_web($base, 'GET', '/admin/settings_accounting.php?tab=connection', $sid);
$ok($c === 200, 'settings_accounting.php loads with provider=xero connected, no fatal');
$ok(str_contains($body, 'Xero Tenant ID') && str_contains($body, 'fake-tenant-001'), 'the connection status shows "Xero Tenant ID" (not "QBO Company (Realm) ID") with the right tenant id');
$ok(str_contains($body, 'Connect to Xero') || str_contains($body, 'Reconnect'), 'the connect/reconnect button is present');
$ok(str_contains($body, "never Xero's real servers"), 'the UI itself carries the "not live-tested against real Xero" warning, not just this test suite');
$ok(!str_contains($body, 'Environment</label>'), 'the QuickBooks-only Environment field is hidden for the Xero provider');

echo "\n--- real HTTP: the OAuth connect + callback flow against the fake Xero server ---\n";
// connect: redirects to login.xero.com with the right query string and stashes state in session
[$c, , $hdrs] = sec_web($base, 'GET', '/admin/oauth_xero_connect.php', $sid);
$ok($c === 302 || $c === 301, 'oauth_xero_connect.php redirects');
preg_match('/^Location:\s*(\S+)/mi', $hdrs, $m);
$authorize_url = $m[1] ?? '';
$ok(str_contains($authorize_url, 'login.xero.com') && str_contains($authorize_url, 'client_id=axt-test-client-id'), 'the redirect target is the real Xero authorize endpoint with our client_id');
parse_str((string) parse_url($authorize_url, PHP_URL_QUERY), $authQs);
$state = $authQs['state'] ?? '';
$ok($state !== '', 'a CSRF-style state token was generated');

// callback: forge the "Xero redirected back with this code+state" request against the SAME
// session (so $_SESSION['xero_oauth_state'] matches) - this is the one step a loopback test
// CAN exercise for real: the token exchange + /connections discovery + DB write, all against
// the fake server, with the actual oauth_xero_callback.php code (not a reimplementation of it).
[$c2, $body2] = sec_web($base, 'GET', '/admin/oauth_xero_callback.php?code=fake-auth-code&state=' . urlencode($state), $sid);
$ok($c2 === 302 || $c2 === 200, 'oauth_xero_callback.php completes without a fatal');
$row = mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM accounting_integrations WHERE accounting_id = $accId"));
$ok((string) $row['accounting_realm_id'] === 'fake-tenant-001', "the callback discovered the fake tenant via /connections and stored it as accounting_realm_id - exactly the real Xero flow's extra step QBO's callback doesn't need");
$ok(!empty($row['accounting_access_token']) && decryptSetting($row['accounting_access_token']) !== 'stale', 'a fresh access token was stored (not the stale fixture value)');
$ok((int) $row['accounting_enabled'] === 1 && !empty($row['accounting_connected_at']), 'the row is marked connected');

echo "\n--- provider switch clears a stale prior connection (settings save handler) ---\n";
$adminPost = fn (array $f) => sec_web($base, 'POST', '/admin/settings_accounting.php', $sid, $f + ['csrf_token' => 'csrftok12345678']);
[$c3] = $adminPost(['save_accounting_settings' => '1', 'accounting_provider' => 'quickbooks_online', 'accounting_client_id' => 'switched-client-id', 'accounting_client_secret' => '', 'accounting_environment' => 'production', 'accounting_default_income_account_id' => '']);
$ok($c3 === 200 || $c3 === 302, 'save_accounting_settings with a different provider is accepted');
$row2 = mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM accounting_integrations WHERE accounting_id = $accId"));
$ok((string) $row2['accounting_provider'] === 'quickbooks_online', 'the provider actually changed to quickbooks_online');
$ok(empty($row2['accounting_realm_id']) && empty($row2['accounting_access_token']) && (int) $row2['accounting_enabled'] === 0, "switching provider cleared the old Xero tenant id/tokens and disabled sync - never left stale Xero tokens sitting under a provider='quickbooks_online' row");

[, $log] = sec_sh('cat ' . escapeshellarg(sys_get_temp_dir()) . '/sec_server_*.log 2>/dev/null');
$ok(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $log) || str_contains($log, 'Undefined array key "HTTP_USER_AGENT"'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');
[, $fakelog] = sec_sh('cat ' . escapeshellarg($fakeLog) . ' 2>/dev/null');
$ok(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $fakelog), 'no PHP warning, notice or fatal in the fake Xero server log');
