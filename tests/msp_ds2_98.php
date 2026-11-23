<?php
// MSP-DS-98 (rest): no hard-coded role id 3 admin checks anywhere; the UI uses $session_is_admin (the role's role_is_admin flag).
// Run: php tests/msp_ds2_98.php
$root = dirname(__DIR__);
if (($argv[1] ?? '') === 'child') {   // header()+exit() cannot be observed in-process: each validateAdminRole() case runs in a child
    define('WORDING_ROLECHECK_FAILED', 'denied');
    preg_match('/function validateAdminRole\(\) \{.*?\n\}\n/s', file_get_contents($root . '/functions.php'), $m);
    eval($m[0]);   // the real function text, extracted from functions.php
    $session_user_role = (int) $argv[2];            // role id 7: not the legacy built-in 3
    $session_is_admin = $argv[3] === '1';
    $_SESSION = []; $_SERVER['HTTP_REFERER'] = 'x';
    validateAdminRole();
    echo 'PASSED'; exit;
}
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };

// 1) sweep: no PHP/JS source compares the session role id with 3 (or "> 2") any more
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $p = $file->getPathname();
    if (!preg_match('/\.(php|js)$/', $p) || preg_match('#/(vendor|node_modules|tests|\.git|demos?|android|ios)/#', $p)) { continue; }
    $src = file_get_contents($p);
    if (preg_match('/\$session_user_role\s*(==|===|!=|!==|<>)\s*[\'"]?3\b|\$session_user_role\s*>\s*2\b/', $src, $m, PREG_OFFSET_CAPTURE)) { $bad[] = substr($p, strlen($root) + 1) . ':' . (substr_count(substr($src, 0, $m[0][1]), "\n") + 1); }
    if (preg_match('/function\s+validateAdminRole\(\)[^}]*role[^}]*!=\s*3/s', $src)) { $bad[] = "$p validateAdminRole"; }
}
$ok('no $session_user_role comparison with 2/3 left (' . implode(', ', array_slice($bad, 0, 5)) . ')', $bad === []);

// 2) the files.php helper function reads the flag from globals (it has no other way to see it)
$f = file_get_contents($root . '/agent/files.php');
preg_match('/function display_folders\(.*?\n    global ([^;]+);/s', $f, $g);
$ok('display_folders() imports $session_is_admin', isset($g[1]) && strpos($g[1], '$session_is_admin') !== false && strpos($g[1], '$session_user_role') === false);

// 3) validateAdminRole() (the real function) accepts a second admin role and refuses a non-admin, in a child process
$child = fn (string $role, string $admin) => trim((string) shell_exec('php ' . escapeshellarg(__FILE__) . ' child ' . escapeshellarg($role) . ' ' . escapeshellarg($admin) . ' 2>/dev/null'));
$ok('second admin role (id 7, role_is_admin=1) passes', $child('7', '1') === 'PASSED');
$ok('built-in admin (id 3, role_is_admin=1) still passes', $child('3', '1') === 'PASSED');
$ok('role id 3 WITHOUT the admin flag is refused (the flag decides, not the id)', $child('3', '0') === '');
$ok('non-admin role refused', $child('2', '0') === '');
exit($fail ? 1 : 0);
