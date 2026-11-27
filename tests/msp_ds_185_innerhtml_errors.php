<?php
// MSP-DS-185: third-party error strings are written as text on the mailbox access check and Pax8 test pages. Run: php tests/msp_ds_185_innerhtml_errors.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = file_get_contents(__DIR__ . '/../admin/modals/mailbox/mailbox_check_access.php');
$ok('mailbox check: d.error is escaped', str_contains($m, 'escapeHtml(d.error)') && !preg_match('/\+ d\.error \+/', $m));
$ok('mailbox check: escapeHtml helper exists', str_contains($m, 'function escapeHtml'));
$p = file_get_contents(__DIR__ . '/../admin/settings_pax8.php');
$ok('pax8: data.error never concatenated into innerHTML', !preg_match('/innerHTML[^;]*data\.error/s', $p) && str_contains($p, 'box.textContent'));
$ok('pax8: result is replaced with a text-only node', str_contains($p, 'result.replaceChildren(box)'));
exit($fail ? 1 : 0);
