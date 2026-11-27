<?php
// MSP-DS-172: runs the REAL scripts/setup_cli.php against a scratch DB.
//  (a) apostrophes/backslashes in company, address, admin name and email install cleanly and are stored as typed;
//  (b) a run that dies midway removes the config.php it wrote, so a re-run cannot skip setup and leave /setup open;
//  (c) deploy/install.sh no longer treats a config.php without the closing flag as "already set up".
// Run: bash tests/lib/with_scratch_db.sh php tests/msp_ds2_172.php     (loads the whole schema; slow on a busy server)
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
$cfg = $root . '/config.php';
if (file_exists($cfg)) { fwrite(STDERR, "refusing: $cfg already exists in this checkout\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$run = function (array $over = []) use ($root) {
    $a = ['host' => 'localhost', 'username' => getenv('RIVETMSP_TEST_DB_USER'), 'database' => getenv('RIVETMSP_TEST_DB_NAME'), 'base-url' => 'x.test', 'locale' => 'en_US', 'timezone' => 'UTC', 'currency' => 'USD',
          'company-name' => "Joe's IT \\ Co", 'country' => 'US', 'address' => "1 O'Hara St", 'city' => 'X', 'state' => 'Y', 'zip' => '1', 'phone' => '1', 'company-email' => 'a@x.test', 'website' => 'x.test',
          'user-name' => "Pat O'Neil", 'user-email' => "pat.o'neil@x.com"] + [];
    $a = $over + $a;
    $cmd = [PHP_BINARY, 'setup_cli.php'];
    foreach ($a as $k => $v) { $cmd[] = "--$k=$v"; }
    $cmd[] = '--non-interactive';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root . '/scripts', ['RIVETMSP_SKIP_KEYFILE' => '1', 'ITFLOW_DB_PASSWORD' => getenv('RIVETMSP_TEST_DB_PASS'), 'ITFLOW_ADMIN_PASSWORD' => 'Correct-Horse-Battery-9!x', 'PATH' => getenv('PATH')]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); proc_close($p);
    return $out;
};
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));

// (b) a run that fails after config.php is written
$out = $run(['user-name' => str_repeat('N', 400)]);
$ok('(b) the failing run did not report completion', !str_contains($out, 'Setup complete!'));
$ok('(b) the partly written config.php was removed', !file_exists($cfg));
if (file_exists($cfg)) { unlink($cfg); }

// (a) apostrophes and backslashes
$out = $run();
$ok('(a) setup completed with apostrophes in the operator values', str_contains($out, 'Setup complete!'));
$ok('(a) config.php carries the closing flag', file_exists($cfg) && (bool) preg_match('/^\$config_enable_setup\s*=\s*0\s*;/m', (string) file_get_contents($cfg)));
$r = $m->query("SELECT user_name, user_email FROM users WHERE user_id = 1")->fetch_assoc();
$ok('(a) admin name and email stored as typed', ($r['user_name'] ?? '') === "Pat O'Neil" && ($r['user_email'] ?? '') === "pat.o'neil@x.com");
$r = $m->query("SELECT company_name, company_address FROM companies WHERE company_id = 1")->fetch_assoc();
$ok('(a) company name and address stored as typed', ($r['company_name'] ?? '') === "Joe's IT \\ Co" && ($r['company_address'] ?? '') === "1 O'Hara St");
if (file_exists($cfg)) { unlink($cfg); }

// (c) install.sh re-run
$sh = file_get_contents($root . '/deploy/install.sh');
preg_match('/run_fresh_setup\(\) \{.*?\n\}\n/s', $sh, $fm);
$ok('(c) run_fresh_setup verifies the flag before skipping on an existing config.php', isset($fm[0]) && preg_match('/config\.php" \]\]; then.*?verify_setup_completed.*?skipping application setup/s', $fm[0]) === 1);
exit($fail ? 1 : 0);
