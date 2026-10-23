<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * Fails when first-party PHP builds a shell command from anything but literals and escaped values.
 *
 * For every call to exec(), shell_exec(), system(), passthru(), popen(), proc_open() and pcntl_exec(), and for every backtick
 * operator, the command (first argument) may only be made of:
 *   - single-quoted / non-interpolating string literals and constants,
 *   - escapeshellarg(...) or releaseGitCommand(...) (which escapes each argument itself),
 *   - an argv array literal ([...] / array(...)): proc_open() then runs the program directly, with no shell at all.
 * A variable, an interpolated "...$x..." string or any other call in the command is reported, unless the exact call is listed in
 * tests/no_unsafe_shell.allow (file|snippet|reason) after a review showed every piece of it is escaped or constant.
 * vendor/, plugins/ (third-party code), tests/, docs/ and node_modules/ are skipped.
 *   php tests/no_unsafe_shell.php
 */
$root = dirname(__DIR__);
$skipTop = ['vendor', 'plugins', 'tests', 'docs', 'node_modules', '.git', 'uploads'];
$shellFns = ['exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec'];
$escapers = ['escapeshellarg', 'releasegitcommand'];

$allow = [];
foreach (file($root . '/tests/no_unsafe_shell.allow', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if ($line[0] === '#') { continue; }
    $parts = explode('|', $line, 3);
    if (count($parts) < 3 || trim($parts[2]) === '') { fwrite(STDERR, "bad allowlist line (file|snippet|reason): $line\n"); exit(2); }
    $allow[] = ['file' => $parts[0], 'snippet' => $parts[1], 'used' => false];
}

/** Source text of a token list. */
function us_text(array $toks): string
{
    return implode('', array_map(static fn($t) => is_array($t) ? $t[1] : $t, $toks));
}

/** Index just past the matching ')' / ']' for the opener at $i. */
function us_skip_group(array $t, int $i): int
{
    $depth = 0;
    for ($n = count($t); $i < $n; $i++) {
        $v = is_array($t[$i]) ? $t[$i][1] : $t[$i];
        if ($v === '(' || $v === '[' || $v === '{' || (is_array($t[$i]) && in_array($t[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; }
        elseif ($v === ')' || $v === ']' || $v === '}') { $depth--; if ($depth === 0) { return $i + 1; } }
    }
    return $i;
}

/** Why a command expression is unsafe, or null when it only uses literals, constants and escapers. */
function us_problem(array $arg, array $escapers): ?string
{
    $arg = array_values(array_filter($arg, static fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    if ($arg === []) { return 'empty command'; }
    $first = $arg[0];
    if ($first === '[' || (is_array($first) && $first[0] === T_ARRAY)) { return null; }   // argv array: no shell
    for ($i = 0, $n = count($arg); $i < $n; $i++) {
        $tok = $arg[$i];
        if (!is_array($tok)) {
            if ($tok === '.' || $tok === '(' || $tok === ')') { continue; }
            return $tok === '"' ? 'interpolated string' : "unexpected '$tok'";
        }
        switch ($tok[0]) {
            case T_CONSTANT_ENCAPSED_STRING:
                if ($tok[1][0] === '"' && preg_match('/(?<!\\\\)\$/', $tok[1])) { return 'interpolated string'; }
                continue 2;
            case T_STRING:
            case T_NAME_FULLY_QUALIFIED:
                $name = strtolower(ltrim($tok[1], '\\'));
                $next = $arg[$i + 1] ?? null;
                if ($next === '(') {
                    if (in_array($name, $escapers, true)) { $i = us_skip_group($arg, $i + 1) - 1; continue 2; }
                    return "call to {$tok[1]}()";
                }
                continue 2;   // a constant such as PHP_BINARY or RELEASE_REMOTE
            case T_DIR:
            case T_FILE:
                continue 2;
            case T_VARIABLE:
                return "variable {$tok[1]}";
            default:
                return 'unexpected ' . token_name($tok[0]);
        }
    }
    return null;
}

$bad = [];
$calls = 0;
$scanned = 0;
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    function ($f) use ($skipTop, $root) {
        if ($f->isDir()) {
            return !(dirname($f->getPathname()) === $root && in_array($f->getFilename(), $skipTop, true))
                && !in_array($f->getFilename(), ['vendor', 'node_modules', '.git'], true);
        }
        return (bool) preg_match('/\.php$/i', $f->getFilename());
    }
));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($root) + 1);
    $src = (string) file_get_contents($f->getPathname());
    if (!preg_match('/\b(' . implode('|', $shellFns) . ')\s*\(|`/', $src)) { continue; }
    $scanned++;
    $t = token_get_all($src);
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        $tok = $t[$i];
        $report = null;
        if ($tok === '`') {
            $end = $i + 1;
            while ($end < $n && $t[$end] !== '`') { $end++; }
            $calls++;
            $report = ['backtick operator', us_text(array_slice($t, $i, $end - $i + 1))];
            $i = $end;
        } elseif (is_array($tok) && in_array($tok[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && in_array(strtolower(ltrim($tok[1], '\\')), $shellFns, true)) {
            // Next meaningful token must be '('; the previous one must not make this a method, static call or declaration.
            $j = $i + 1;
            while ($j < $n && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) { $j++; }
            if (($t[$j] ?? null) !== '(') { continue; }
            $p = $i - 1;
            while ($p >= 0 && is_array($t[$p]) && in_array($t[$p][0], [T_WHITESPACE, T_COMMENT], true)) { $p--; }
            $prev = $t[$p] ?? null;
            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) { continue; }
            $calls++;
            // First argument: up to the first ',' or the closing ')' at depth 1.
            $arg = [];
            $depth = 0;
            for ($k = $j; $k < $n; $k++) {
                $v = is_array($t[$k]) ? $t[$k][1] : $t[$k];
                if ($v === '(' || $v === '[' || $v === '{' || (is_array($t[$k]) && in_array($t[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                    if ($depth === 1) { continue; }
                } elseif ($v === ')' || $v === ']' || $v === '}') {
                    $depth--;
                    if ($depth === 0) { break; }
                } elseif ($v === ',' && $depth === 1) {
                    break;
                }
                $arg[] = $t[$k];
            }
            $problem = us_problem($arg, $escapers);
            if ($problem !== null) { $report = [$problem, $tok[1] . '(' . us_text($arg) . ')']; }
        }
        if ($report === null) { continue; }
        [$why, $code] = $report;
        $code = preg_replace('/\s+/', ' ', $code);
        $line = is_array($tok) ? $tok[2] : 0;
        $allowed = false;
        foreach ($allow as &$a) {
            if ($a['file'] === $rel && str_contains($code, $a['snippet'])) { $a['used'] = true; $allowed = true; }
        }
        unset($a);
        if (!$allowed) { $bad[] = "$rel" . ($line ? ":$line" : '') . ": $why  (" . substr($code, 0, 140) . ')'; }
    }
}

$stale = array_map(static fn($a) => $a['file'] . '|' . $a['snippet'], array_filter($allow, static fn($a) => !$a['used']));
if ($bad || $stale) {
    if ($bad) {
        echo "FAIL: shell commands built from unescaped values (escape every non-literal with escapeshellarg(), pass an argv array to proc_open(), or use a PHP function instead of the shell):\n  " . implode("\n  ", $bad) . "\n";
    }
    if ($stale) {
        echo "FAIL: allowlist entries that no longer match anything (remove them from tests/no_unsafe_shell.allow):\n  " . implode("\n  ", $stale) . "\n";
    }
    exit(1);
}
echo "PASS: $calls shell call(s) in $scanned first-party PHP file(s) use only literals, constants and escaped values (" . count($allow) . " reviewed exception(s)).\n";
