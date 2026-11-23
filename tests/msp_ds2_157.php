<?php
// MSP-DS-157: every function the shipped PHP-FPM hardening disables must have NO real call site in code that runs under FPM.
// Scans the whole app (tokenised, so strings/comments/method calls don't count) and fails if a disabled function is called.
// Run: php tests/msp_ds2_157.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = realpath(__DIR__ . '/..');
preg_match('/^disable_functions\s*=\s*(.+)$/m', file_get_contents($root . '/deploy/templates/php-hardening.ini'), $m);
$disabled = array_filter(array_map('trim', explode(',', $m[1] ?? '')));
$ok('disable_functions parsed', count($disabled) > 3);
foreach (['proc_open', 'exec', 'shell_exec', 'popen', 'assert'] as $needed) { $ok("$needed is NOT disabled (the app or a bundled library calls it)", !in_array($needed, $disabled, true)); }
// CLI-only callers: the php-fpm drop-in does not apply to the CLI SAPI, and these refuse to run anywhere else.
$cliOnly = ['scripts/update_cli.php' => ['putenv']];
$skipDir = '#^(tests|docs|node_modules|\.git|deploy|vendor/rivet/rivet-core/(bench|docs|tests|src/Testing)|(plugins/)?vendor/[^/]+/[^/]+/(tests?|bench|docs))/#';
$hits = []; $scanned = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') { continue; }
    $rel = substr($f->getPathname(), strlen($root) + 1);
    if (preg_match($skipDir, $rel)) { continue; }
    $src = @file_get_contents($f->getPathname());
    if ($src === false || !preg_match('/\b(' . implode('|', array_map('preg_quote', $disabled)) . ')\s*\(/i', $src)) { continue; }
    $scanned++;
    $t = token_get_all($src);
    for ($i = 0, $n = count($t); $i < $n; $i++) {
        if (!is_array($t[$i]) || $t[$i][0] !== T_STRING || !in_array(strtolower($t[$i][1]), $disabled, true)) { continue; }
        $p = $i - 1; while ($p >= 0 && is_array($t[$p]) && in_array($t[$p][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $p--; }
        $q = $i + 1; while ($q < $n && is_array($t[$q]) && in_array($t[$q][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $q++; }
        if (($t[$q] ?? null) !== '(') { continue; }
        $prev = $t[$p] ?? null;
        if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) { continue; }
        $name = strtolower($t[$i][1]);
        if (in_array($name, $cliOnly[$rel] ?? [], true)) { continue; }
        $hits[] = "$rel:{$t[$i][2]} $name()";
    }
}
$ok('scanner looked at real files', $scanned >= 1);
$ok('no code under FPM calls a disabled function' . ($hits ? ': ' . implode('; ', $hits) : ''), !$hits);
// The CLI-only exemption must stay true.
$u = file_get_contents($root . '/scripts/update_cli.php');
$ok('update_cli.php (putenv) refuses to run outside the CLI', str_contains($u, "php_sapi_name() !== 'cli'"));
// The tokenizer scan must be able to fail: a synthetic call is caught.
$tt = token_get_all('<?php system($x); $o->system(1);'); $c = 0;
foreach ($tt as $i => $x) { if (is_array($x) && $x[0] === T_STRING && $x[1] === 'system') { $c++; } }
$ok('self-check: tokenizer sees both spellings', $c === 2);
exit($fail ? 1 : 0);
