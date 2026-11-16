<?php
// MSP-DS-131: remote-session launches record getIP(), not the proxy address. MSP-DS-132: client 0 assets need admin (enforceClientAccess is unconditional).
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$r = fn($f) => file_get_contents(__DIR__ . '/../agent/post/' . $f);
foreach (['rmm_agent.php', 'rmm_remote_tools.php'] as $f) {
    $s = $r($f);
    $ok(strpos($s, "launchRemote(") !== false && preg_match('/launchRemote\([^;]*\$_SERVER\[\'REMOTE_ADDR\'\]/', $s) === 0 && preg_match('/launchRemote\([^;]*\(string\) getIP\(\)/', $s) === 1, "131: $f passes getIP()");
}
foreach (['rmm_script_run.php', 'rmm_live_data.php', 'rmm_run_status.php'] as $f) {
    $s = $r($f);
    $ok(preg_match('/^if \(\$(link|run)\[\'asset_client_id\'\]\) \{ enforceClientAccess/m', $s) === 0 && preg_match('/^enforceClientAccess\(intval\(\$(link|run)\[\'asset_client_id\'\]\)\);/m', $s) === 1, "132: $f enforces client access unconditionally");
}
// enforceClientAccess contract the fix relies on: a non-admin is refused for client 0
$fn = file_get_contents(__DIR__ . '/../functions.php');
$p = strpos($fn, 'function enforceClientAccess'); 
$ok($p !== false && strpos(substr($fn, $p, 900), 'empty($client_id)') !== false, '132: enforceClientAccess refuses an empty client id for non-admins');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
