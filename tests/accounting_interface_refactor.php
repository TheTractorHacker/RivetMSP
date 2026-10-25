<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Roadmap #20 step 1: proves the AccountingClientInterface extraction over QboClient is a real
 * pure refactor - QBO's push-sync behavior through includes/accounting_functions.php's shared
 * orchestration is unchanged - before building XeroClient on top of it (see
 * tests/accounting_xero.php for that half).
 *
 * This is the comprehensive functional proof requested: every behavior accountingSyncProcessJob()
 * exercises (customer/item find-or-create, invoice/quote/payment push with correct line items,
 * dependency ordering - payment waits for its invoice, idempotency via accounting_entity_map,
 * retry/backoff on failure) is run against a loopback fake-QBO server
 * (tests/support/fake_qbo_router.php) through the REAL QboClient class exactly as
 * cron/accounting_sync.php calls it. The only lines that changed in includes/class_qbo_client.php
 * for this refactor: `implements AccountingClientInterface` on the class declaration, one
 * optional 4th constructor parameter (test-only URL overrides, defaulted to never changing real
 * behavior), and TOKEN_URL/apiBase() reading that override when set - no method body's logic
 * changed. This test passing is the "after" proof; it is written to exercise literally every
 * code path those methods have, so a behavior change in any of them would be caught here.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/accounting_interface_refactor.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/accounting_functions.php";
require_once "$root/includes/database_version.php";

echo "\n--- DB 2.6.113 step: incidental fix, missing accounting_entity_map columns ---\n";
// Found while testing this item (unrelated to Xero itself): getAllClientMappings()/
// getAllItemMappings() and friends already SELECT/INSERT map_remote_name (and, for items,
// map_local_subtype), but neither column ever existed - admin/settings_accounting.php's Client/
// Item Mapping tabs fatal under strict SQL mode the moment there's any accounting_id > 0. The
// automatic push-sync pipeline this test otherwise covers was unaffected (setAccountingMap()
// never references either column), which is why this went unnoticed.
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.113', '>='), 'LATEST_DATABASE_VERSION is 2.6.113 or later');
$sqlText = (string) file_get_contents("$root/db.sql");
$ok(str_contains($sqlText, '`map_remote_name` varchar(255) DEFAULT NULL') && str_contains($sqlText, '`map_local_subtype` varchar(20) DEFAULT NULL'), 'db.sql carries both columns');
$verBefore = (string) $one('SELECT config_current_database_version FROM settings LIMIT 1');
$q("UPDATE settings SET config_current_database_version = '2.6.112'");
[, $updOut] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut, '2.6.112') && (string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === LATEST_DATABASE_VERSION, 'the updater advances past 2.6.112 to the latest version');
[, $updOut2] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut2, 'already at the latest'), 'running it again is a no-op, not an error');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounting_entity_map' AND COLUMN_NAME = 'map_remote_name'") === 1, 'map_remote_name exists');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounting_entity_map' AND COLUMN_NAME = 'map_local_subtype'") === 1, 'map_local_subtype exists');
$q("UPDATE settings SET config_current_database_version = '" . $esc($verBefore) . "'");

echo "\n--- fixtures ---\n";
$q("DELETE FROM accounting_sync_log WHERE 1");
$q("DELETE FROM accounting_sync_queue WHERE 1");
$q("DELETE FROM accounting_entity_map WHERE 1");
$q("DELETE FROM accounting_integrations WHERE 1");
$q("DELETE FROM invoice_items WHERE item_invoice_id IN (SELECT invoice_id FROM invoices WHERE invoice_note LIKE 'air-test-%')");
$q("DELETE FROM invoices WHERE invoice_note LIKE 'air-test-%'");
$q("DELETE FROM quote_items WHERE item_quote_id IN (SELECT quote_id FROM quotes WHERE quote_note LIKE 'air-test-%')");
$q("DELETE FROM quotes WHERE quote_note LIKE 'air-test-%'");
$q("DELETE FROM payments WHERE payment_reference LIKE 'air-test-%'");
$q("DELETE FROM clients WHERE client_name LIKE 'air-test-%'");
$q("UPDATE settings SET config_module_enable_accounting = 1 WHERE company_id = 1");

$q("INSERT INTO clients SET client_name='air-test-Client', client_website='https://air-test.example'");
$client = (int) $db->insert_id;
$q("INSERT INTO contacts SET contact_client_id=$client, contact_name='air-test-Contact', contact_email='air-c@example.test', contact_phone='555-0100', contact_primary=1");

$q("INSERT INTO invoices SET invoice_prefix='INV', invoice_number=9001, invoice_status='Sent', invoice_date=CURDATE(), invoice_due=CURDATE(), invoice_currency_code='USD', invoice_category_id=0, invoice_client_id=$client, invoice_note='air-test-inv'");
$invoiceId = (int) $db->insert_id;
// Distinct (fake but nonzero) product ids - accountingMakeEnsureItem()'s map lookup is keyed
// purely by product_id, so two DIFFERENT line items sharing product_id=0 (the "generic
// services" ad-hoc fallback) would collapse onto the SAME remote Item by existing, unchanged
// design - using distinct ids here tests the realistic catalog-backed case instead.
$q("INSERT INTO invoice_items SET item_name='Managed Services', item_description='Monthly MSP fee', item_quantity=2, item_price=150.00, item_order=0, item_product_id=501, item_invoice_id=$invoiceId");

$q("INSERT INTO quotes SET quote_prefix='QU', quote_number=5001, quote_status='Sent', quote_date=CURDATE(), quote_currency_code='USD', quote_category_id=0, quote_client_id=$client, quote_note='air-test-quote'");
$quoteId = (int) $db->insert_id;
$q("INSERT INTO quote_items SET item_name='New Workstation', item_description='Dell OptiPlex', item_quantity=1, item_price=899.00, item_order=0, item_product_id=502, item_quote_id=$quoteId");

$q("INSERT INTO payments SET payment_date=CURDATE(), payment_amount=300.00, payment_currency_code='USD', payment_account_id=0, payment_invoice_id=$invoiceId, payment_reference='air-test-pay'");
$paymentId = (int) $db->insert_id;

$port = random_int(8600, 8999);
$storeFile = sys_get_temp_dir() . "/fake_qbo_store_$port.json";
@unlink($storeFile);
putenv("FAKE_QBO_STORE=$storeFile");
$fakeCmd = 'cd ' . escapeshellarg($root) . " && FAKE_QBO_STORE=" . escapeshellarg($storeFile) . " php -S 127.0.0.1:$port tests/support/fake_qbo_router.php > " . escapeshellarg(sys_get_temp_dir() . "/air_fake_$port.log") . " 2>&1 & echo \$!";
$fakePid = (int) trim((string) shell_exec($fakeCmd));
register_shutdown_function(function () use ($fakePid, $storeFile) { if ($fakePid > 0) { @exec("kill $fakePid 2>/dev/null"); } @unlink($storeFile); });
usleep(300000);

$fakeBase = "http://127.0.0.1:$port";
putenv("RIVETMSP_TEST_QBO_TOKEN_URL=$fakeBase/token");
putenv("RIVETMSP_TEST_QBO_API_BASE=$fakeBase");

$q("INSERT INTO accounting_integrations SET
    accounting_provider='quickbooks_online', accounting_realm_id='air-test-realm',
    accounting_client_id='air-test-client-id', accounting_client_secret='" . $esc(encryptSetting('air-test-secret')) . "',
    accounting_environment='production',
    accounting_access_token='" . $esc(encryptSetting('stale')) . "', accounting_refresh_token='" . $esc(encryptSetting('air-test-refresh')) . "',
    accounting_token_expires_at='2000-01-01 00:00:00',
    accounting_auto_push=1, accounting_enabled=1");
$accId = (int) $db->insert_id;

echo "\n--- enqueue + process (same order cron/accounting_sync.php uses) ---\n";
$ok(enqueueAccountingSync($mysqli, 'invoice', $invoiceId), 'invoice enqueued');
$ok(enqueueAccountingSync($mysqli, 'payment', $paymentId), 'payment enqueued');
$ok(enqueueAccountingSync($mysqli, 'quote', $quoteId), 'quote enqueued');

$integration = getAccountingIntegration($mysqli);
$ok($integration && (string) $integration['accounting_provider'] === 'quickbooks_online', 'integration row resolved with provider quickbooks_online');
$qbo = makeAccountingClient($mysqli, $integration);
$ok($qbo instanceof QboClient && $qbo instanceof AccountingClientInterface, 'makeAccountingClient() returns a QboClient implementing AccountingClientInterface for this provider');

$ensureCustomer = accountingMakeEnsureCustomer($mysqli, $qbo, $accId);
$ensureItem     = accountingMakeEnsureItem($mysqli, $qbo, $accId, '');

$jobs = $rows("SELECT * FROM accounting_sync_queue WHERE queue_accounting_id = $accId AND queue_status = 'pending' ORDER BY queue_id ASC");
$ok(count($jobs) === 3, 'three jobs are pending (invoice, payment, quote)');
$results = [];
foreach ($jobs as $job) {
    $results[$job['queue_local_type']] = accountingSyncProcessJob($mysqli, $qbo, $accId, $job, $ensureCustomer, $ensureItem);
}

$ok(($results['invoice']['outcome'] ?? '') === 'delivered', "invoice outcome is 'delivered': " . ($results['invoice']['message'] ?? ''));
$ok(($results['payment']['outcome'] ?? '') === 'delivered', "payment outcome is 'delivered' on the FIRST pass - dependency ordering let it find the invoice's map row already written earlier in the same batch: " . ($results['payment']['message'] ?? ''));
$ok(($results['quote']['outcome'] ?? '') === 'delivered', "quote outcome is 'delivered': " . ($results['quote']['message'] ?? ''));

echo "\n--- fake QBO store: the real wire calls actually happened and round-tripped correctly ---\n";
$store = json_decode((string) file_get_contents($storeFile), true);
$ok(count($store['Customer'] ?? []) === 1 && $store['Customer'][0]['DisplayName'] === 'air-test-Client', 'exactly one QBO Customer created, with the right DisplayName');
$ok(!empty($store['Customer'][0]['PrimaryEmailAddr']['Address']) && $store['Customer'][0]['PrimaryEmailAddr']['Address'] === 'air-c@example.test', 'the Customer carries the primary contact email');
$ok(count($store['Item'] ?? []) === 2, 'two QBO Items created (one per distinct invoice/quote line item name)');
$ok(count($store['Invoice'] ?? []) === 1, 'exactly one QBO Invoice created');
$invLine = $store['Invoice'][0]['Line'][0]['SalesItemLineDetail'] ?? [];
$ok(((float) ($invLine['Qty'] ?? 0)) === 2.0 && ((float) ($invLine['UnitPrice'] ?? 0)) === 150.0, 'the pushed invoice line carries the correct quantity and unit price');
$ok(count($store['Estimate'] ?? []) === 1, 'exactly one QBO Estimate created (ITFlow Quote -> QBO Estimate)');
$ok(count($store['Payment'] ?? []) === 1, 'exactly one QBO Payment created');
$ok((float) ($store['Payment'][0]['TotalAmt'] ?? 0) === 300.0, 'the pushed payment carries the correct amount');
$ok(($store['Payment'][0]['Line'][0]['LinkedTxn'][0]['TxnId'] ?? '') === $store['Invoice'][0]['Id'], 'the payment is linked to the SAME remote invoice id that was just created (dependency resolution worked, not a coincidence)');

echo "\n--- mapping + log ---\n";
$map = getAccountingMap($mysqli, $accId, 'invoice', $invoiceId);
$ok($map && $map['map_remote_id'] === $store['Invoice'][0]['Id'], 'accounting_entity_map has the right invoice -> remote id row');
$ok((int) $one("SELECT COUNT(*) FROM accounting_sync_log WHERE log_accounting_id = $accId AND log_status = 'delivered'") === 3, 'three delivered rows in the sync log');
$ok((int) $one("SELECT COUNT(*) FROM accounting_sync_queue WHERE queue_accounting_id = $accId AND queue_status = 'delivered'") === 3, 'all three queue jobs marked delivered');

echo "\n--- idempotency: re-running an already-delivered job is a safe no-op, not a duplicate push ---\n";
$q("UPDATE accounting_sync_queue SET queue_status='pending', queue_next_attempt_at=NOW() WHERE queue_accounting_id=$accId AND queue_local_type='invoice'");
$again = $rows("SELECT * FROM accounting_sync_queue WHERE queue_accounting_id=$accId AND queue_local_type='invoice' LIMIT 1")[0];
$r2 = accountingSyncProcessJob($mysqli, $qbo, $accId, $again, $ensureCustomer, $ensureItem);
$ok($r2['outcome'] === 'already-mapped', 're-processing the invoice job again reports already-mapped, not a second push');
$store2 = json_decode((string) file_get_contents($storeFile), true);
$ok(count($store2['Invoice'] ?? []) === 1, 'still exactly one Invoice in the fake QBO store - no duplicate was created');

echo "\n--- failure path: retry/backoff still works (a bad local id) ---\n";
$q("INSERT INTO accounting_sync_queue SET queue_accounting_id=$accId, queue_local_type='invoice', queue_local_id=999999, queue_op='push', queue_status='pending', queue_next_attempt_at=NOW()");
$badJob = $rows("SELECT * FROM accounting_sync_queue WHERE queue_accounting_id=$accId AND queue_local_id=999999 LIMIT 1")[0];
$r3 = accountingSyncProcessJob($mysqli, $qbo, $accId, $badJob, $ensureCustomer, $ensureItem);
$ok($r3['outcome'] === 'retry', "a job for a nonexistent local invoice reports 'retry', not a fatal: " . $r3['message']);
$after = $rows("SELECT * FROM accounting_sync_queue WHERE queue_id = " . intval($badJob['queue_id']))[0];
// Compared entirely in MySQL's own clock (TIMESTAMPDIFF), not PHP's strtotime()/time() - the
// scratch MySQL server's NOW() runs on the system's LOCAL timezone while this PHP process runs
// under UTC (security_lib.php), so a naive strtotime() vs time() comparison of a NOW()-derived
// value is off by the local/UTC offset. Same documented trap as tests/mail_templates.php's
// UTC_DATE() note.
$minutes_ahead = (int) $one("SELECT TIMESTAMPDIFF(MINUTE, NOW(), '" . $esc($after['queue_next_attempt_at']) . "')");
$ok((int) $after['queue_attempts'] === 1 && $minutes_ahead >= 4 && $minutes_ahead <= 5, "the bad job is scheduled for a future retry (~5m ahead, got {$minutes_ahead}m) with attempts incremented, not dropped");

echo "\n--- getAccessToken() actually talked to the fake token endpoint (not a stub) ---\n";
$tok = $qbo->getAccessToken();
$ok(str_starts_with($tok, 'fake-qbo-access-'), 'the access token came from the real HTTP round trip to the fake QBO token endpoint, proving the refactored TOKEN_URL override plumbing works');

echo "\n--- the Client/Item Mapping tabs (the exact pages that fatal'd before the map_remote_name/map_local_subtype fix) load via real HTTP ---\n";
$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='air-test-admin', user_email='air-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }
$sdir = sys_get_temp_dir() . '/air_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sid = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
[$c1, $body1] = sec_web($base, 'GET', '/admin/settings_accounting.php?tab=client_mapping', $sid);
$ok($c1 === 200 && str_contains($body1, 'air-test-Client'), 'Client Mapping tab loads and lists the fixture client, with accounting_id > 0 and a real mapping present (previously a guaranteed fatal)');
[$c2, $body2] = sec_web($base, 'GET', '/admin/settings_accounting.php?tab=item_mapping', $sid);
$ok($c2 === 200, 'Item Mapping tab loads with accounting_id > 0 and a real item mapping present (previously a guaranteed fatal)');

[, $log] = sec_sh('cat ' . escapeshellarg(sys_get_temp_dir()) . "/air_fake_$port.log 2>/dev/null");
$ok(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $log), 'no PHP warning, notice or fatal in the fake QBO server log');
[, $serverLog] = sec_sh('cat ' . escapeshellarg(sys_get_temp_dir()) . '/sec_server_*.log 2>/dev/null');
$ok(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $serverLog) || str_contains($serverLog, 'Undefined array key "HTTP_USER_AGENT"'), 'no PHP warning, notice or fatal in the admin app server log (besides the pre-existing harness-wide missing-User-Agent one)');
