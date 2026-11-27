<?php
// MSP-DS-145: portal sessions end when the portal is off, the client is archived, or the password changed.
// Run: SCRATCH_TABLES="settings users clients" bash tests/lib/with_scratch_db.sh php tests/msp_ds_145_portal_session_recheck.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$src = file_get_contents(__DIR__ . '/../client/includes/check_login.php');
preg_match('/\$portal_gate = mysqli_fetch_assoc\(mysqli_query\(\$mysqli, "(SELECT .*?)"\)\);/s', $src, $m);
$ok(!empty($m[1]), 'gate query present');
$mysqli->query("INSERT INTO settings (company_id, config_client_portal_enable) VALUES (1, 1)");
$mysqli->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_type,user_status) VALUES (7,'c','c@x','hashA',2,1)");
$mysqli->query("INSERT INTO clients (client_id, client_name) VALUES (3,'Acme')");
$gate = function () use ($mysqli, $m) {
    $session_user_id = 7; $session_client_id = 3;
    $q = str_replace(['$session_user_id', '$session_client_id'], [7, 3], $m[1]);
    $r = $mysqli->query($q)->fetch_assoc();
    return $r;
};
$g = $gate();
$ok($g && (int) $g['config_client_portal_enable'] === 1 && $g['client_archived_at'] === null, 'portal on, client active: session stays');
$mysqli->query("UPDATE settings SET config_client_portal_enable = 0");
$g = $gate(); $ok((int) $g['config_client_portal_enable'] !== 1, 'portal switched off: gate sees it');
$mysqli->query("UPDATE settings SET config_client_portal_enable = 1; ");
$mysqli->query("UPDATE clients SET client_archived_at = NOW() WHERE client_id = 3");
$g = $gate(); $ok($g['client_archived_at'] !== null, 'client archived: gate sees it');
$mysqli->query("UPDATE clients SET client_archived_at = NULL");
$fp = hash('sha256', $gate()['user_password']);
$mysqli->query("UPDATE users SET user_password = 'hashB' WHERE user_id = 7");
$ok(!hash_equals($fp, hash('sha256', $gate()['user_password'])), 'password changed: fingerprint differs, old session ends');
$ok(strpos($src, "hash_equals((string) \$_SESSION['portal_pw_fp']") !== false && strpos($src, 'session_destroy()', strpos($src, 'MSP-DS-145')) !== false, 'check_login destroys the session on any of them');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
