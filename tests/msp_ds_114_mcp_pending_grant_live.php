<?php
// MSP-DS-114: a queued MCP proposal cannot be approved once its OAuth consent is revoked/expired or its client disabled.
// Run: SCRATCH_TABLES="mcp_oauth_clients mcp_oauth_grants" tests/lib/with_scratch_db.sh php tests/msp_ds_114_mcp_pending_grant_live.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
spl_autoload_register(function ($c) { if (strpos($c, 'RivetMSP\\') === 0) { $f = __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, 9)) . '.php'; if (is_file($f)) require $f; } });
$db->query("INSERT INTO mcp_oauth_clients (client_id, client_name, redirect_uris) VALUES ('c1','A','[]')");
$db->query("INSERT INTO mcp_oauth_grants (client_id,user_id,scope,resource,origin_code_hash,expires_at) VALUES ('c1',5,'mcp:read mcp:write','r',UNHEX(REPEAT('01',32)),UTC_TIMESTAMP() + INTERVAL 1 DAY)");
$pa = new RivetMSP\Mcp\PendingActions($db);
$ok($pa->oauthSourceStillLive('c1', 5) === true, 'live grant with mcp:write: approvable');
$ok($pa->oauthSourceStillLive('c1', 6) === false, 'other user has no grant');
$db->query("UPDATE mcp_oauth_clients SET disabled_at = NOW()");
$ok($pa->oauthSourceStillLive('c1', 5) === false, 'client disabled: refused');
$db->query("UPDATE mcp_oauth_clients SET disabled_at = NULL");
$db->query("UPDATE mcp_oauth_grants SET revoked_at = UTC_TIMESTAMP()");
$ok($pa->oauthSourceStillLive('c1', 5) === false, 'grant revoked: refused');
$db->query("UPDATE mcp_oauth_grants SET revoked_at = NULL, expires_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR");
$ok($pa->oauthSourceStillLive('c1', 5) === false, 'grant expired: refused');
$db->query("UPDATE mcp_oauth_grants SET expires_at = UTC_TIMESTAMP() + INTERVAL 1 DAY, scope = 'mcp:read'");
$ok($pa->oauthSourceStillLive('c1', 5) === false, 'grant lost mcp:write: refused');
$ok($pa->oauthSourceStillLive('', 5) === true, 'no oauth client recorded: unchanged');
$src = file_get_contents(__DIR__ . '/../src/Mcp/PendingActions.php');
$ok(strpos($src, 'oauthSourceStillLive((string) $row') !== false, 'approve() calls the check');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
