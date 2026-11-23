<?php
// MSP-DS-171: TOTP single-use store is app-private and fails closed. Run: php tests/msp_ds_171_totp_replay_store.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
require __DIR__ . '/../plugins/totp/totp.php';
$cls = null; foreach (get_declared_classes() as $c) { if (method_exists($c, 'verifyOnce')) { $cls = $c; } }
$secret = 'JBSWY3DPEHPK3PXP' . 'TEST' . getmypid(); $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', 'A', $secret));
$code = $cls::getTokenCode($secret);
$ok('first use of a valid code accepted', $cls::verifyOnce($secret, $code) === true);
$ok('replay of the same code refused', $cls::verifyOnce($secret, $code) === false);
$dir = __DIR__ . '/../uploads/totp_replay';
$ok('store directory is app-private and 0700', is_dir($dir) && (fileperms($dir) & 0777) === 0700);
$src = file_get_contents(__DIR__ . '/../plugins/totp/totp.php');
$blk = substr($src, strpos($src, 'public static function verifyOnce'), 1800);
$ok('unusable store returns false (fail closed), not true', preg_match('/if \(!\$fh\) \{\s*return false;/', $blk) === 1 && !preg_match('/if \(!\$fh\) \{\s*return true;/', $blk));
@unlink($dir . '/' . hash('sha256', $secret)); @rmdir($dir);
exit($fail ? 1 : 0);
