<?php
// MSP-DS-141: profile email must be valid and neither name nor email may collide with another staff member's name/email;
// the mobile API login prefers the email match.
// Run: SCRATCH_TABLES="users" bash tests/lib/with_scratch_db.sh php tests/msp_ds_141_profile_identity.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$mysqli->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_type,user_status) VALUES (1,'victim@corp.test','victim@corp.test','x',1,1),(2,'Mallory','mal@corp.test','x',1,1),(3,'Bob','bob@corp.test','x',1,1)");
$p = file_get_contents(__DIR__ . '/../agent/user/post/profile.php');
preg_match('/mysqli_query\(\$mysqli, "(SELECT COUNT\(\*\) FROM users WHERE user_id != \$session_user_id[^"]+)"\)\);/s', $p, $m);
$ok(!empty($m[1]), 'collision query present');
$check = function (int $uid, string $name, string $email) use ($mysqli, $m) {
    $session_user_id = $uid; $q = str_replace(['$session_user_id', '$email', '$name'], [$uid, $mysqli->real_escape_string($email), $mysqli->real_escape_string($name)], $m[1]);
    return (int) $mysqli->query($q)->fetch_row()[0];
};
$ok($check(2, 'Mallory', 'victim@corp.test') > 0, 'taking another staff email is refused');
$ok($check(2, 'victim@corp.test', 'mal@corp.test') > 0, 'using another staff email as the display name is refused');
$ok($check(2, 'Mallory M', 'mal2@corp.test') === 0, 'a fresh name and email pass');
$ok($check(2, 'Mallory', 'mal@corp.test') === 0, 'keeping your own values passes');
$ok(strpos($p, 'FILTER_VALIDATE_EMAIL') !== false, 'email format validated');
$a = file_get_contents(__DIR__ . '/../api/v1/auth.php');
$ok(strpos($a, "ORDER BY (user_email = '\$esc_user') DESC, user_id ASC") !== false, 'API login prefers the email match');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
