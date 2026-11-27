<?php
// The setup wizard's restore must open an AES-encrypted in-app backup with the submitted backup passphrase (source-level: the REAL setup/index.php
// restore block sets the zip password before extractTo, and ZipArchive really extracts an AES zip only with it).
$src = file_get_contents(__DIR__ . '/../setup/index.php');
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$ok('setup restore sets the zip password before extractTo', preg_match('/setPassword\(\$restore_zip_pass\);.*?\$zip->extractTo\(\$tempDir\)/s', $src) === 1);
$ok('it reads the existing backup_passphrase form field', str_contains($src, "\$_POST['backup_passphrase']"));
$d = sys_get_temp_dir() . '/zp_' . bin2hex(random_bytes(3)); mkdir($d);
$z = new ZipArchive(); $z->open("$d/b.zip", ZipArchive::CREATE); $z->addFromString('db.sql', 'SELECT 1;'); $z->setEncryptionName('db.sql', ZipArchive::EM_AES_256, 'pass-pass-pass-123'); $z->close();
$z = new ZipArchive(); $z->open("$d/b.zip"); @mkdir("$d/o"); $ok('extract without the passphrase fails', @$z->extractTo("$d/o") === false); $z->close();
$z = new ZipArchive(); $z->open("$d/b.zip"); $z->setPassword('pass-pass-pass-123'); $ok('extract with the passphrase works', $z->extractTo("$d/o") && is_file("$d/o/db.sql")); $z->close();
array_map('unlink', glob("$d/o/*")); @rmdir("$d/o"); @unlink("$d/b.zip"); @rmdir($d);
exit($fail ? 1 : 0);
