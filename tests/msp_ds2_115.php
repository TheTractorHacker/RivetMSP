<?php
// MSP-DS-115: accounting entity-map rows carry the provider + realm/tenant they were written under and are only read for the CURRENT connection; the Xero callback picks
// the organisation this authorisation granted. Real functions against a scratch DB.
// Run: SCRATCH_TABLES="accounting_entity_map accounting_integrations platform_settings" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_115.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
require __DIR__ . '/../src/Platform/PlatformSettings.php';
require __DIR__ . '/../includes/accounting_functions.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$one = fn (string $s) => ($r = $mysqli->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$connect = fn (string $provider, ?string $realm) => $mysqli->query("UPDATE accounting_integrations SET accounting_provider = '$provider', accounting_realm_id = " . ($realm === null ? 'NULL' : "'$realm'") . " WHERE accounting_id = 1");
$mysqli->query("INSERT INTO accounting_integrations (accounting_id, accounting_provider, accounting_realm_id) VALUES (1, 'quickbooks_online', 'SANDBOX-1')");

// connected to the sandbox company: client 7 -> Customer 12, invoice 301 -> Invoice 145, product 5 -> Item 9
setClientMapping($mysqli, 1, 7, '12', 'Acme');
setAccountingMap($mysqli, 1, 'invoice', 301, '145', '3');
setItemMapping($mysqli, 1, 5, '9', 'Widget', '0', 'product');
$ok('rows are tagged with the provider and realm they were written under', $one("SELECT COUNT(*) FROM accounting_entity_map WHERE map_provider = 'quickbooks_online' AND map_realm_id = 'SANDBOX-1'") == 4);
$ok('while connected to that company the map is used', getAccountingMap($mysqli, 1, 'client', 7)['map_remote_id'] === '12' && getClientMapping($mysqli, 1, 7)['map_remote_id'] === '12'
    && getRemoteToLocalMap($mysqli, 1, 'invoice') === ['145' => 301] && isset(getAllClientMappings($mysqli, 1)[7]) && getItemMapping($mysqli, 1, 5)['map_remote_id'] === '9' && isset(getAllItemMappings($mysqli, 1)[5]));

// the admin switches to PRODUCTION (another realm) - the stale rows are still in the table (the callback's DELETE is NOT what protects this)
$connect('quickbooks_online', 'PROD-9');
$ok('ensureCustomer lookup does NOT return the sandbox Customer 12 for client 7', getAccountingMap($mysqli, 1, 'client', 7) === null && getClientMapping($mysqli, 1, 7) === null);
$ok("invoice 301's sandbox Invoice 145 is not 'already mapped' and cannot receive payments", getAccountingMap($mysqli, 1, 'invoice', 301) === null);
$ok("'Import payments' reverse lookup does not resolve a production Invoice 145 to local invoice 301", getRemoteToLocalMap($mysqli, 1, 'invoice') === [] && getRemoteToLocalMap($mysqli, 1, 'client') === []);
$ok('mapping lists and item lookups are empty for the new company', getAllClientMappings($mysqli, 1) === [] && getAllItemMappings($mysqli, 1) === [] && getItemMapping($mysqli, 1, 5) === null);
$ok('the stale rows are still physically there (ignored, not relied on being deleted)', intval($one("SELECT COUNT(*) FROM accounting_entity_map")) === 4);

// mapping client 7 in production overwrites the stale row (unique key) and re-tags it
setClientMapping($mysqli, 1, 7, '4001', 'Acme Prod');
$ok('a new mapping replaces the stale remote id and is tagged with the new realm', getClientMapping($mysqli, 1, 7)['map_remote_id'] === '4001' && $one("SELECT map_realm_id FROM accounting_entity_map WHERE map_local_type='client' AND map_local_id=7") === 'PROD-9');
$connect('quickbooks_online', 'SANDBOX-1');
$ok('going back to the sandbox does not resurrect the production id for that client', getClientMapping($mysqli, 1, 7) === null);

// provider switch with the SAME realm string: still a different connection
$connect('xero', 'PROD-9');
$ok('a provider switch is a different connection even with an identical id', getClientMapping($mysqli, 1, 7) === null);
// disconnected
$connect('quickbooks_online', null);
$ok('disconnected: nothing tagged to a realm is returned', getAccountingMap($mysqli, 1, 'client', 7) === null);

// the connect callbacks still drop a replaced company's rows (hygiene), and both tag-aware paths work together
$connect('quickbooks_online', 'SANDBOX-1');
$dropped = accountingBindConnection($mysqli, 1, 'quickbooks_online', 'PROD-9', ['accounting_provider' => 'quickbooks_online', 'accounting_realm_id' => 'SANDBOX-1']);
$ok("accountingBindConnection still deletes the previous company's rows ($dropped)", $dropped > 0 && intval($one("SELECT COUNT(*) FROM accounting_entity_map")) === 0);

// ---- Xero: the organisation this authorisation granted ----
$jwt = fn (array $claims) => 'h.' . rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=') . '.s';
$c = fn (string $t, string $ev) => ['tenantId' => $t, 'tenantName' => "Org $t", 'authEventId' => $ev];
$list = [$c('OLD-ORG', 'ev-old'), $c('NEW-ORG', 'ev-now'), $c('OLDER-ORG', 'ev-older')];
$ok('picks the organisation granted by THIS authorisation, not connections[0]', accountingXeroPickTenant($list, $jwt(['authentication_event_id' => 'ev-now']))['tenantId'] === 'NEW-ORG');
$two = [$c('A', 'ev-now'), $c('B', 'ev-now'), $c('C', 'ev-old')];
$ok('two granted at once and none bound: refused (no guessing)', accountingXeroPickTenant($two, $jwt(['authentication_event_id' => 'ev-now'])) === null);
$ok('two granted at once: the one already bound to this install wins', accountingXeroPickTenant($two, $jwt(['authentication_event_id' => 'ev-now']), 'B')['tenantId'] === 'B');
$ok('a token without the claim and a single connection is accepted', accountingXeroPickTenant([$c('ONLY', 'x')], 'not-a-jwt')['tenantId'] === 'ONLY');
$ok('a token without the claim and several connections is refused', accountingXeroPickTenant($list, 'not-a-jwt') === null);
$ok('no connections', accountingXeroPickTenant([], $jwt([])) === null);
$xs = file_get_contents(__DIR__ . '/../admin/oauth_xero_callback.php');
$ok('the Xero callback no longer takes connections[0]', !str_contains($xs, "(string) \$connections[0]['tenantId']") && str_contains($xs, 'accountingXeroPickTenant('));

// ---- direct-SQL map reads outside the helpers carry the scope too ----
$sa = file_get_contents(__DIR__ . '/../admin/settings_accounting.php');
$ok("the 'resync unmapped' join and the sync-status map read are scoped to the current connection", substr_count($sa, 'accountingMapScopeSql(') >= 2);
exit($fail ? 1 : 0);
