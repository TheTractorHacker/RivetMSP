<?php
// MSP-DS-157: the shipped PHP hardening must not disable functions the app calls from the web. Run: php tests/msp_ds_157_php_hardening_proc_open.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$ini = file_get_contents(__DIR__ . '/../deploy/templates/php-hardening.ini');
preg_match('/^disable_functions\s*=\s*(.+)$/m', $ini, $m);
$list = array_map('trim', explode(',', $m[1] ?? ''));
$ok('proc_open is not disabled', !in_array('proc_open', $list, true));
$called = file_get_contents(__DIR__ . '/../includes/rmm_data_store_ui.php');
$ok('the app still calls proc_open from a web path', str_contains($called, 'proc_open('));
foreach (['exec', 'shell_exec', 'popen'] as $f) { $ok("$f stays enabled", !in_array($f, $list, true)); }
$ok('still disables the genuinely unused ones', in_array('passthru', $list, true) && in_array('system', $list, true));
exit($fail ? 1 : 0);
