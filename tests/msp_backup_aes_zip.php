<?php
// In-app backup zip is fully AES-256 encrypted: REAL build_backup() on a scratch DB. Every entry (db.sql, uploads.zip, version.txt,
// manifest, table snapshot) must refuse to open without the passphrase and with a wrong one, and read back with the right one. The old
// "allow unencrypted" override is no longer needed for the build to run.
// Run: SCRATCH_TABLES="backup_runs settings history endpoint_agent_settings" bash tests/lib/with_scratch_db.sh php tests/msp_backup_aes_zip.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
chdir(__DIR__ . '/../admin');
define('FROM_POST_HANDLER', true);
$_SERVER['DOCUMENT_ROOT'] = sys_get_temp_dir();
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$GLOBALS['config_backup_passphrase'] = 'correct horse battery staple 1';
$GLOBALS['config_backup_allow_unencrypted_override'] = 0;
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../admin/post/backup.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$dir = sys_get_temp_dir() . '/aesbk_' . bin2hex(random_bytes(4)); mkdir($dir, 0700);
try { $r = build_backup($mysqli, 'manual', $dir); } catch (Throwable $e) { $ok('build runs with the override OFF: ' . $e->getMessage(), false); exit(1); }
$ok('build runs with the override OFF', is_file($r['path']));
$names = ['db.sql', 'uploads.zip', 'version.txt', 'backup-manifest.json.enc'];
$z = new ZipArchive(); $z->open($r['path']);
foreach ($names as $n) {
    $st = $z->statName($n);
    $ok("$n is AES encrypted (method 99)", $st !== false && $st['encryption_method'] === ZipArchive::EM_AES_256);
    $ok("$n unreadable without the passphrase", @$z->getFromName($n) === false);
}
$z->close();
$z = new ZipArchive(); $z->open($r['path']); $z->setPassword('wrong passphrase!!');
$ok('db.sql unreadable with a wrong passphrase', @$z->getFromName('db.sql') === false); $z->close();
$z = new ZipArchive(); $z->open($r['path']); $z->setPassword($GLOBALS['config_backup_passphrase']);
$sql = $z->getFromName('db.sql');
$ok('db.sql reads back with the right passphrase', is_string($sql) && str_contains($sql, 'CREATE TABLE'));
$ok('version.txt reads back', str_contains((string) $z->getFromName('version.txt'), 'Backup Metadata'));
$raw = file_get_contents($r['path']);
$ok('no plain "CREATE TABLE" bytes anywhere in the file', !str_contains($raw, 'CREATE TABLE'));
$z->close();
array_map('unlink', glob("$dir/*")); rmdir($dir);
exit($fail ? 1 : 0);
