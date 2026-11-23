<?php
// MSP-DS-146 (complete): the portal Microsoft sign-in authority follows the tenant pin (Admin > Identity Provider, security_settings.portal_entra_tenant);
// blank keeps the multi-tenant 'organizations' authority; the state token is random and one-use; the portal switch-off is honoured.
// Run: SCRATCH_TABLES="security_settings" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_146.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
require_once $root . '/includes/security_policy.php';
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$guid = '3f2b8c1e-4d5a-4e6f-9a7b-0c1d2e3f4a5b';

$ok('default (nothing set): multi-tenant organizations authority is kept', secPortalEntraAuthority($m) === 'organizations');
$ok('GUID accepted', secPortalEntraTenantNormalize(strtoupper($guid)) === $guid);
$ok('verified domain accepted', secPortalEntraTenantNormalize('Contoso.onmicrosoft.com') === 'contoso.onmicrosoft.com');
foreach (['common', 'organizations', 'consumers', '', '  '] as $v) {
    $ok("'" . $v . "' is not a tenant and reads as unpinned", secPortalEntraTenantNormalize($v) === '');
}
foreach (['evil.com/../x', 'a b.com', "x.com\nevil.com", 'https://evil.test', 'nodots', '-a.com', str_repeat('a', 70) . '.com'] as $v) {
    $ok('rejected: ' . json_encode(substr($v, 0, 24)), secPortalEntraTenantNormalize($v) === '');
}
$ok('stored through the real setter (normalised)', secSettingSet($m, 'portal_entra_tenant', ' ' . strtoupper($guid) . ' ') === true);
$ok('authority is now the pinned tenant', secPortalEntraAuthority($m) === $guid);
secSettingSet($m, 'portal_entra_tenant', 'junk/path');
$ok('a junk value falls back to unpinned instead of building a bad URL', secPortalEntraAuthority($m) === 'organizations');
secSettingSet($m, 'portal_entra_tenant', 'contoso.onmicrosoft.com');
$ok('pinned to a domain', secPortalEntraAuthority($m) === 'contoso.onmicrosoft.com');

$src = file_get_contents($root . '/client/login_microsoft.php');
$ok('login_microsoft builds BOTH endpoints from the pinned authority', str_contains($src, '$auth_code_url = "https://login.microsoftonline.com/$entra_authority/oauth2/v2.0/authorize"')
    && str_contains($src, '$token_grant_url = "https://login.microsoftonline.com/$entra_authority/oauth2/v2.0/token"') && !str_contains($src, 'microsoftonline.com/organizations/'));
$ok('state is random, stored server-side, compared in constant time and one-use (not the session id)', str_contains($src, "bin2hex(random_bytes(32))") && str_contains($src, 'hash_equals((string) $_SESSION[\'portal_ms_state\']') && !str_contains($src, 'session_id()'));
$ok('portal switch-off is checked before anything else', strpos($src, 'config_client_portal_enable') < strpos($src, 'portal_ms_state'));
$ok('admin form saves the pin', str_contains(file_get_contents($root . '/admin/post/identity_provider.php'), "secSettingSet(\$mysqli, 'portal_entra_tenant'")
    && str_contains(file_get_contents($root . '/admin/identity_provider.php'), 'name="portal_entra_tenant"'));
exit($fail ? 1 : 0);
