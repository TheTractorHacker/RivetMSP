<?php
// MSP-DS-172: setup_cli escapes operator values before its INSERTs. Run: SCRATCH_TABLES="users companies" tests/lib/with_scratch_db.sh php tests/msp_ds_172_setup_cli_escape.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);   // PHP 8.1+ default: a syntax error throws
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$src = file_get_contents(__DIR__ . '/../scripts/setup_cli.php');
$a = strpos($src, '// MSP-DS-172 begin'); $b = strpos($src, '// MSP-DS-172 end');
$esc = substr($src, $a, $b - $a);
preg_match('/mysqli_query\(\$mysqli,"(INSERT INTO users SET user_name = [^"]*)"\);/', $src, $u);
preg_match('/mysqli_query\(\$mysqli,"(INSERT INTO companies SET company_name = [^"]*)"\);/', $src, $c);
$ok('both INSERTs found in the script', !empty($u[1]) && !empty($c[1]));
$mysqli->query("DELETE FROM users"); $mysqli->query("DELETE FROM companies");
$company_name = "Joe's IT"; $user_name = "Pat O'Neil"; $user_email = "pat.o'neil@x.com"; $country = 'US'; $address = "1 O'Hara St"; $city = $state = $zip = $phone = $company_email = $website = ''; $locale = 'en_US'; $timezone = 'UTC'; $currency_code = 'USD';
$password_hash = 'h'; $user_specific_encryption_ciphertext = 'c';
eval($esc);
try {
    $mysqli->query(eval('return "' . addcslashes($u[1], '"') . '";') ?: '');
    $mysqli->query(eval('return "' . addcslashes($c[1], '"') . '";') ?: '');
    $ok('INSERTs with apostrophes succeed', true);
} catch (mysqli_sql_exception $e) { $ok('INSERTs with apostrophes succeed: ' . $e->getMessage(), false); }
$r = $mysqli->query("SELECT user_name, user_email FROM users")->fetch_assoc();
$ok('stored values are the originals, not escaped copies', ($r['user_name'] ?? '') === "Pat O'Neil" && ($r['user_email'] ?? '') === "pat.o'neil@x.com");
$r = $mysqli->query("SELECT company_name FROM companies")->fetch_assoc();
$ok('company name stored intact', ($r['company_name'] ?? '') === "Joe's IT");
exit($fail ? 1 : 0);
