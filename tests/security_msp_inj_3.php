<?php
declare(strict_types=1);
// MSP-INJ-3: RMM writes need more than Read. Source-level (no database).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__); $fail = 0;
$ok = function (string $n, bool $c) use (&$fail): void { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$a = (string) file_get_contents("$root/agent/post/rmm_action.php");
$ok('run_command needs module_rmm_scripts >= 3 and reboot >= 2', str_contains($a, "enforceUserPermission('module_rmm_scripts', \$action === 'run_command' ? 3 : 2)"));
$ok('the scripts check precedes the device lookup', strpos($a, "enforceUserPermission('module_rmm_scripts'") < strpos($a, 'FROM asset_rmm_links'));
$l = (string) file_get_contents("$root/agent/post/rmm_link.php");
$ok('link/unlink need module_rmm >= 2', str_contains($l, "enforceUserPermission('module_rmm', 2)") && !preg_match("/enforceUserPermission\('module_rmm'\);/", $l));
$s = (string) file_get_contents("$root/agent/post/rmm_sync.php");
$ok('sync and assign_client need Write on module_rmm_sync', substr_count($s, "lookupUserPermission('module_rmm_sync') < 2") === 2);
$ok('sync_scripts needs Write on module_rmm_scripts', str_contains($s, "lookupUserPermission('module_rmm_scripts') < 2"));
exit($fail ? 1 : 0);
