<?php
// The restore drill's REAL readZip() opens an AES-encrypted in-app backup with the backup passphrase (and fails clearly without it).
// Run: SCRATCH_TABLES="backup_runs settings history endpoint_agent_settings" bash tests/lib/with_scratch_db.sh php tests/msp_backup_aes_drill.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../vendor/autoload.php';
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$pass = 'correct horse battery staple 1';
$tmp = sys_get_temp_dir() . '/aesdr_' . bin2hex(random_bytes(4)); mkdir($tmp, 0700);
$zipPath = "$tmp/itflow_20260101000000_manual.zip";
$z = new ZipArchive(); $z->open($zipPath, ZipArchive::CREATE);
$z->addFromString('db.sql', "CREATE TABLE `t` (a int);\nINSERT INTO `t` VALUES (1);\n");
$z->addFromString('version.txt', "RivetMSP Backup Metadata\nGenerated: 2026-01-01 00:00:00\nType: manual\nDB Version: 2.6.142\n");
for ($i = 0; $i < $z->numFiles; $i++) { $z->setEncryptionIndex($i, ZipArchive::EM_AES_256, $pass); }
$z->close();
$call = function (string $passOpt) use ($mysqli, $tmp, $zipPath) {
    $d = new \RivetMSP\Recovery\RestoreDrill($mysqli, $tmp, ['passphrase' => $passOpt]);
    $rp = new ReflectionProperty($d, 'work'); $rp->setAccessible(true); $w = "$tmp/work" . bin2hex(random_bytes(2)); mkdir($w, 0700); $rp->setValue($d, $w);
    $m = new ReflectionMethod($d, 'readZip'); $m->setAccessible(true); $res = [];
    try { $out = $m->invokeArgs($d, [$zipPath, &$res]); return [true, $out]; } catch (Throwable $e) { return [false, $e->getMessage()]; }
};
[$good, $out] = $call($pass);
$ok('drill reads the encrypted zip with the right passphrase', $good);
[$bad] = $call('wrong passphrase here!');
$ok('drill refuses it with a wrong passphrase', $bad === false);
array_map('unlink', array_merge(glob("$tmp/*.zip"))); 
exit($fail ? 1 : 0);
