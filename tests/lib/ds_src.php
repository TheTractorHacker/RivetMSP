<?php
// Tiny helpers for source-level regression tests (MSP-DS-*).
$GLOBALS['ds_fail'] = 0;
function ds_t($name, $cond) { echo ($cond ? 'ok' : 'FAIL') . " - $name\n"; if (!$cond) $GLOBALS['ds_fail'] = 1; }
function ds_src($rel) { $s = file_get_contents(__DIR__ . '/../../' . $rel); if ($s === false) { echo "FAIL - cannot read $rel\n"; exit(1); } return $s; }
function ds_done() { exit($GLOBALS['ds_fail']); }
