<?php
// MSP-DS-114 (complete): a queued MCP proposal remembers the OAuth grant it was proposed under (mcp_pending_actions.grant_id), and approval
// is refused once THAT grant is revoked/expired/scope-reduced or its client disabled, even if the same user has another live grant.
// Run: SCRATCH_TABLES="mcp_oauth_clients mcp_oauth_grants mcp_pending_actions mcp_oauth_config users clients" tests/lib/with_scratch_db.sh php tests/msp_ds2_114.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
spl_autoload_register(function ($c) { if (strpos($c, 'RivetMSP\\') === 0) { $f = __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, 9)) . '.php'; if (is_file($f)) require $f; } });
$db->query("INSERT INTO mcp_oauth_clients (client_id, client_name, redirect_uris) VALUES ('c1','A','[]')");
$g = function (string $scope = 'mcp:read mcp:write') use ($db): int {
    static $n = 0; $n++;
    $db->query("INSERT INTO mcp_oauth_grants (client_id,user_id,scope,resource,origin_code_hash,expires_at) VALUES ('c1',5,'$scope','r',UNHEX(SHA2('g$n',256)),UTC_TIMESTAMP() + INTERVAL 1 DAY)");
    return (int) $db->insert_id;
};
$gA = $g(); $gB = $g();
$pa = new RivetMSP\Mcp\PendingActions($db);

// queue() stores the grant id (real INSERT against the real column).
$q = $pa->queue(5, 'c1', 'rivetmsp_propose_ticket_reply', ['ticket_id' => 1, 'body' => 'hi'], 1, 1, 'k1', $gA);
$ok((int) ($q['action']['grant_id'] ?? -1) === $gA, 'queue() stores grant_id');
$q0 = $pa->queue(5, 'c1', 'rivetmsp_propose_ticket_reply', ['ticket_id' => 1, 'body' => 'legacy'], 1, 1, 'k2');
$ok((int) $q0['action']['grant_id'] === 0, 'queue() without a grant id stores 0 (legacy)');

$ok($pa->oauthSourceStillLive('c1', 5, $gA) === true, 'proposing grant live: approvable');
$db->query("UPDATE mcp_oauth_grants SET revoked_at = UTC_TIMESTAMP() WHERE grant_id = $gA");
$ok($pa->oauthSourceStillLive('c1', 5, $gA) === false, 'proposing grant revoked: refused even though a second live grant of the same user exists');
$ok($pa->oauthSourceStillLive('c1', 5, 0) === true, 'legacy proposal (grant 0) still falls back to any live grant of the user');
$ok($pa->oauthSourceStillLive('c1', 6, $gB) === false, 'grant of another user does not count');
$db->query("UPDATE mcp_oauth_grants SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE grant_id = $gB");
$ok($pa->oauthSourceStillLive('c1', 5, $gB) === false, 'proposing grant expired: refused');
$db->query("UPDATE mcp_oauth_grants SET expires_at = UTC_TIMESTAMP() + INTERVAL 1 DAY, scope = 'mcp:read' WHERE grant_id = $gB");
$ok($pa->oauthSourceStillLive('c1', 5, $gB) === false, 'proposing grant lost mcp:write: refused');
$db->query("UPDATE mcp_oauth_grants SET scope = 'mcp:read mcp:write' WHERE grant_id = $gB");
$ok($pa->oauthSourceStillLive('c1', 5, $gB) === true, 'restored: approvable');
$db->query("UPDATE mcp_oauth_clients SET disabled_at = NOW()");
$ok($pa->oauthSourceStillLive('c1', 5, $gB) === false, 'client disabled: refused');
$ok($pa->oauthSourceStillLive('', 5, 0) === true, 'no oauth client recorded (legacy token): unchanged');

$src = file_get_contents(__DIR__ . '/../src/Mcp/PendingActions.php');
$ok(str_contains($src, "(int) (\$row['grant_id'] ?? 0)"), 'approve() passes the stored grant id to the check');
$ok(str_contains(file_get_contents(__DIR__ . '/../mcp_server/index.php'), "(int) (\$who['grant_id'] ?? 0)"), 'MCP server hands the token grant id to WriteTools');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
