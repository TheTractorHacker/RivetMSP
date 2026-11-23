<?php
// MSP-DS-146 (re-review MRR-13): the identity page warns while portal contacts use Microsoft sign-in and no Entra tenant is pinned. The default
// (blank tenant = 'organizations') is NOT changed. Runs the REAL helper against the scratch DB.
// Run: SCRATCH_TABLES="users security_settings" bash tests/lib/with_scratch_db.sh php tests/msp_mrr_13.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
$root = dirname(__DIR__);
require $root . '/includes/security_policy.php';
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_type,user_status,user_auth_method) VALUES
 (1,'A','a@x.test','x',2,1,'azure'),(2,'B','b@x.test','x',2,1,'azure'),(3,'C','c@x.test','x',2,1,'local'),(4,'D','d@x.test','x',2,0,'azure'),(5,'Staff','s@x.test','x',1,1,'azure')");
$ok('default authority is unchanged (multi-tenant)', secPortalEntraAuthority($m) === 'organizations');
$ok('blank tenant: counts only active portal azure accounts', secPortalEntraUnpinnedAzureUsers($m) === 2);
secSettingSet($m, 'portal_entra_tenant', 'contoso.onmicrosoft.com');
secSettingsAll($m, true);
$ok('tenant pinned: no warning', secPortalEntraUnpinnedAzureUsers($m) === 0);
secSettingSet($m, 'portal_entra_tenant', '');
secSettingsAll($m, true);
$ok('tenant cleared again: warning returns', secPortalEntraUnpinnedAzureUsers($m) === 2);
$m->query("UPDATE users SET user_auth_method = 'local' WHERE user_type = 2");
$ok('no Microsoft portal contacts: no warning', secPortalEntraUnpinnedAzureUsers($m) === 0);
$ok('identity page renders the warning through the helper', str_contains(file_get_contents($root . '/admin/identity_provider.php'), 'secPortalEntraUnpinnedAzureUsers($mysqli)'));
exit($fail ? 1 : 0);
