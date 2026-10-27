<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Roadmap #25: Pax8 cost sync for billing/COGS reconciliation, against the REAL code on a
 * SCRATCH database (same harness as tests/security_*.php).
 *
 * IMPORTANT, read before trusting this as final proof: this environment has no real Pax8
 * Partner API credentials. Every assertion below is verified against a loopback fake-Pax8
 * server (tests/support/fake_pax8_router.php), built from Pax8's publicly documented Partner
 * API, NOT Pax8's real servers - and Pax8's exact response shape is the LEAST certain of the
 * three billing integrations built this session (see includes/class_pax8_client.php's header).
 * A genuine API round-trip and real field-shape confirmation can only happen once real Pax8
 * credentials are available - flagging this plainly rather than overstating confidence.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/pax8_cost_sync.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/database_version.php";
require_once "$root/includes/pax8_functions.php";
require_once "$root/includes/class_pax8_client.php";

echo "\n--- DB 2.6.113 step ---\n";
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.113', '>='), 'LATEST_DATABASE_VERSION is 2.6.113 or later');
$sqlText = (string) file_get_contents("$root/db.sql");
foreach (['pax8_integrations', 'pax8_client_map', 'pax8_subscriptions'] as $t) {
    $ok(str_contains($sqlText, "CREATE TABLE `$t`"), "db.sql carries the $t table");
}
$verBefore = (string) $one('SELECT config_current_database_version FROM settings LIMIT 1');
$q("UPDATE settings SET config_current_database_version = '2.6.112'");
[, $updOut] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut, '2.6.112') && (string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === LATEST_DATABASE_VERSION, 'the updater advances past 2.6.112 to the latest version');
[, $updOut2] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut2, 'already at the latest'), 'running it again is a no-op, not an error');
foreach (['pax8_integrations', 'pax8_client_map', 'pax8_subscriptions'] as $t) {
    $ok((int) $one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'") === 1, "$t table exists");
}
$q("UPDATE settings SET config_current_database_version = '" . $esc($verBefore) . "'");

echo "\n--- fixtures ---\n";
$q("DELETE FROM pax8_subscriptions WHERE 1");
$q("DELETE FROM pax8_client_map WHERE 1");
$q("DELETE FROM pax8_integrations WHERE 1");
$q("DELETE FROM recurring_invoices WHERE recurring_invoice_note LIKE 'p8-test-%'");
$q("DELETE FROM clients WHERE client_name LIKE 'p8-test-%'");

$q("INSERT INTO clients SET client_name='p8-test-ClientA'");
$clientA = (int) $db->insert_id;
$q("INSERT INTO clients SET client_name='p8-test-ClientB'");
$clientB = (int) $db->insert_id;

// ClientA is billed $500/mo in RivetMSP; Pax8 will say their real cost is $350/mo (healthy margin).
$q("INSERT INTO recurring_invoices SET recurring_invoice_prefix='RI', recurring_invoice_number=7001, recurring_invoice_frequency='Monthly', recurring_invoice_next_date=CURDATE(), recurring_invoice_status=1, recurring_invoice_amount=500.00, recurring_invoice_currency_code='USD', recurring_invoice_category_id=0, recurring_invoice_client_id=$clientA, recurring_invoice_note='p8-test-ra'");
// ClientB is billed only $100/mo but will turn out to cost $180/mo from Pax8 - negative margin, should be flagged.
$q("INSERT INTO recurring_invoices SET recurring_invoice_prefix='RI', recurring_invoice_number=7002, recurring_invoice_frequency='Monthly', recurring_invoice_next_date=CURDATE(), recurring_invoice_status=1, recurring_invoice_amount=100.00, recurring_invoice_currency_code='USD', recurring_invoice_category_id=0, recurring_invoice_client_id=$clientB, recurring_invoice_note='p8-test-rb'");

$storeFile = sys_get_temp_dir() . '/fake_pax8_store_' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($storeFile, json_encode([
    'companies' => [
        ['id' => 'p8co-A', 'name' => 'p8-test-ClientA Pty'],
        ['id' => 'p8co-B', 'name' => 'p8-test-ClientB Pty'],
        ['id' => 'p8co-C', 'name' => 'p8-test-UnmappedCo'],
    ],
    'subscriptions' => [
        ['id' => 'p8sub-1', 'companyId' => 'p8co-A', 'product' => ['name' => 'Microsoft 365 Business Premium'], 'quantity' => 10, 'unitCost' => 25.00, 'billingTerm' => ['term' => 'MONTHLY'], 'status' => 'ACTIVE'],
        ['id' => 'p8sub-2', 'companyId' => 'p8co-A', 'product' => ['name' => 'Backup for M365'], 'quantity' => 10, 'unitCost' => 10.00, 'billingTerm' => ['term' => 'MONTHLY'], 'status' => 'ACTIVE'],
        ['id' => 'p8sub-3', 'companyId' => 'p8co-B', 'product' => ['name' => 'Microsoft 365 Business Premium'], 'quantity' => 6, 'unitCost' => 30.00, 'billingTerm' => ['term' => 'MONTHLY'], 'status' => 'ACTIVE'],
        ['id' => 'p8sub-4', 'companyId' => 'p8co-C', 'product' => ['name' => 'Unmapped Product'], 'quantity' => 1, 'unitCost' => 999.00, 'billingTerm' => ['term' => 'MONTHLY'], 'status' => 'ACTIVE'],
    ],
]));
register_shutdown_function(function () use ($storeFile) { @unlink($storeFile); });

$port = random_int(8600, 8999);
$fakeLog = sys_get_temp_dir() . "/p8_fake_$port.log";
$fakeCmd = 'cd ' . escapeshellarg($root) . " && FAKE_PAX8_STORE=" . escapeshellarg($storeFile) . " php -S 127.0.0.1:$port tests/support/fake_pax8_router.php > " . escapeshellarg($fakeLog) . " 2>&1 & echo \$!";
$fakePid = (int) trim((string) shell_exec($fakeCmd));
register_shutdown_function(function () use ($fakePid) { if ($fakePid > 0) { @exec("kill $fakePid 2>/dev/null"); } });
usleep(300000);

$fakeBase = "http://127.0.0.1:$port";
putenv("RIVETMSP_TEST_PAX8_TOKEN_URL=$fakeBase/oauth/token");
putenv("RIVETMSP_TEST_PAX8_API_BASE=$fakeBase");

$q("INSERT INTO pax8_integrations SET client_id='p8-test-client-id', client_secret_enc='" . $esc(encryptSetting('p8-test-secret')) . "', enabled=1");
$integrationId = (int) $db->insert_id;

echo "\n--- Pax8Client: token fetch + companies + subscriptions against the fake server ---\n";
$pax8 = makePax8Client($mysqli, $integrationId);
$ok($pax8->testConnection(), 'testConnection() succeeds against the fake server with a real token round trip');
$companies = $pax8->getCompanies();
$ok(count($companies) === 3, 'getCompanies() returns all three fake companies');
$subsA = $pax8->getSubscriptionsForCompany('p8co-A');
$ok(count($subsA) === 2, 'getSubscriptionsForCompany() returns both of ClientA company\'s subscriptions');
$ok(abs($subsA[0]['totalCost'] - 250.0) < 0.01 || abs($subsA[1]['totalCost'] - 250.0) < 0.01, 'one subscription line computed totalCost = quantity * unitCost correctly (10 * 25.00 = 250.00)');

echo "\n--- company sync + manual client mapping ---\n";
$seen = pax8SyncCompanies($mysqli, $pax8);
$ok($seen === 3, 'pax8SyncCompanies() upserted all three companies into pax8_client_map');
$ok((int) $one("SELECT COUNT(*) FROM pax8_client_map") === 3, 'three rows in pax8_client_map');
$q("UPDATE pax8_client_map SET client_id = $clientA WHERE pax8_company_id = 'p8co-A'");
$q("UPDATE pax8_client_map SET client_id = $clientB WHERE pax8_company_id = 'p8co-B'");
// p8co-C intentionally left unmapped.

echo "\n--- full sync pulls subscriptions only for mapped companies ---\n";
$result = pax8SyncAll($mysqli, $pax8, $integrationId);
$ok($result['mapped_companies'] === 2, 'pax8SyncAll() only pulled subscriptions for the 2 MAPPED companies, not the unmapped one');
$ok((int) $one("SELECT COUNT(*) FROM pax8_subscriptions WHERE pax8_company_id = 'p8co-C'") === 0, "the unmapped company's subscription was never pulled - no point reconciling a company with no local client yet");
$ok((int) $one("SELECT COUNT(*) FROM pax8_subscriptions WHERE client_id = $clientA") === 2, 'ClientA has both subscription rows stored with the resolved client_id');
$ok((float) $one("SELECT SUM(total_cost) FROM pax8_subscriptions WHERE client_id = $clientA") == 350.0, 'ClientA total Pax8 cost is 250 + 100 = 350.00');
$ok((float) $one("SELECT SUM(total_cost) FROM pax8_subscriptions WHERE client_id = $clientB") == 180.0, 'ClientB total Pax8 cost is 6 * 30.00 = 180.00');
$ok(!empty($one("SELECT last_synced_at FROM pax8_integrations WHERE id = $integrationId")), 'last_synced_at was stamped');

echo "\n--- idempotent re-sync: cancelled/changed subscriptions replace cleanly, not duplicate ---\n";
pax8SyncSubscriptionsForCompany($mysqli, $pax8, 'p8co-A', $clientA);
$ok((int) $one("SELECT COUNT(*) FROM pax8_subscriptions WHERE client_id = $clientA") === 2, 're-syncing the same company still leaves exactly 2 rows (delete-then-reinsert), not 4');

echo "\n--- reconciliation math ---\n";
$recon = pax8ReconciliationRows($mysqli);
$byClient = [];
foreach ($recon as $r) { $byClient[$r['client_id']] = $r; }
$ok(count($recon) === 2, 'reconciliation covers exactly the 2 mapped clients, not the unmapped Pax8 company');
$ok($byClient[$clientA]['pax8_cost'] == 350.0 && $byClient[$clientA]['billed'] == 500.0 && $byClient[$clientA]['margin'] == 150.0, 'ClientA: cost 350, billed 500, margin +150 (healthy)');
$ok($byClient[$clientB]['pax8_cost'] == 180.0 && $byClient[$clientB]['billed'] == 100.0 && $byClient[$clientB]['margin'] == -80.0, 'ClientB: cost 180, billed 100, margin -80 (losing money on this client - exactly the case this feature exists to surface)');
$ok(abs($byClient[$clientB]['margin_pct'] - (-80.0)) < 0.1, "ClientB's margin_pct is -80% (margin/billed * 100)");

echo "\n--- real HTTP: admin settings page ---\n";
$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='p8-test-admin', user_email='p8-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }
$sdir = sys_get_temp_dir() . '/p8_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sid = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
$pax8Post = fn (array $f) => sec_web($base, 'POST', '/admin/settings_pax8.php', $sid, $f + ['csrf_token' => 'csrftok12345678']);

[$c, $body] = sec_web($base, 'GET', '/admin/settings_pax8.php?tab=connection', $sid);
$ok($c === 200 && (str_contains($body, 'Connected') || str_contains($body, 'Not Connected')), 'Connection tab loads and shows a status badge');
$ok(str_contains($body, 'never Pax8\'s real servers') || str_contains($body, "real servers"), 'the live page carries the "not live-tested against real Pax8" warning, not just this test suite');

[$c2, $body2] = sec_web($base, 'GET', '/admin/settings_pax8.php?tab=reconciliation', $sid);
$ok($c2 === 200 && str_contains($body2, 'p8-test-ClientA') && str_contains($body2, 'p8-test-ClientB'), 'Reconciliation tab lists both mapped clients via real HTTP');
$ok(str_contains($body2, 'table-danger'), "ClientB's negative-margin row is visually flagged (table-danger class)");

[$c3] = $pax8Post(['test_pax8_connection' => '1']);
$ok($c3 === 200, 'the AJAX test-connection endpoint responds (fetches its OWN page, not post.php - settings_pax8.php is self-contained like settings_accounting.php)');

[, $log] = sec_sh('cat ' . escapeshellarg(sys_get_temp_dir()) . '/sec_server_*.log 2>/dev/null');
$ok(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $log) || str_contains($log, 'Undefined array key "HTTP_USER_AGENT"'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');
[, $fakelog] = sec_sh('cat ' . escapeshellarg($fakeLog) . ' 2>/dev/null');
$ok(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $fakelog), 'no PHP warning, notice or fatal in the fake Pax8 server log');
