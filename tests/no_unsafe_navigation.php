<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * Fails when first-party browser code can be steered into script execution through the page URL or a navigation target:
 *
 *  1. Navigation to a computed URL. For `location = X`, `location.href = X`, `location.assign(X)` and `location.replace(X)`, X may
 *     only be string literals joined with + and encodeURIComponent(...) values (the literal prefix fixes the scheme and path).
 *     Anything else - a server-supplied URL, a link's href - must be validated first (a path regex, or an http(s)-only check, so
 *     a javascript: or data: URL is never followed) and the reviewed site listed in tests/no_unsafe_navigation.allow as
 *     file|sink snippet|guard|reason. The guard is a piece of the validating code that must still be in that file, so deleting the
 *     check fails this test even though the sink line itself is unchanged. Setting location.hash is same-page and is not checked.
 *  2. The current URL rendered as HTML: one statement that reads location.href/.search/.hash/.pathname (or document.URL) and
 *     writes innerHTML, outerHTML, insertAdjacentHTML, document.write or jQuery .html(). Use textContent instead.
 *
 * Scans .js/.mjs files and the raw source of .php/.html files (inline scripts and PHP-emitted script); vendor/, plugins/, tests/,
 * docs/ and node_modules/ are skipped.
 *   php tests/no_unsafe_navigation.php
 */
$root = dirname(__DIR__);
$skipTop = ['vendor', 'plugins', 'tests', 'docs', 'node_modules', '.git', 'uploads'];

$allow = [];
foreach (file($root . '/tests/no_unsafe_navigation.allow', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if ($line[0] === '#') { continue; }
    $parts = explode('|', $line, 4);
    if (count($parts) < 4 || trim($parts[2]) === '' || trim($parts[3]) === '') { fwrite(STDERR, "bad allowlist line (file|snippet|guard|reason): $line\n"); exit(2); }
    $allow[] = ['file' => $parts[0], 'snippet' => $parts[1], 'guard' => $parts[2], 'used' => false];
}

/** True when a navigation target expression is only literals, + and encodeURIComponent(...). */
function un_safe_target(string $expr, bool $php): bool
{
    $e = trim($expr);
    // In a .php file a PHP value can be spliced into the JS target ('...' . $url . '...', or a short echo tag): always review those.
    if ($php && preg_match('/<\?|[\'"]\s*\.\s*[$\w(]|[$\w)]\s*\.\s*[\'"]/', $e)) { return false; }
    // Drop encodeURIComponent(...) calls (innermost first, so nested parentheses inside them are handled).
    do {
        $before = $e;
        $e = preg_replace('/encodeURIComponent\s*\(([^()]|\((?:[^()])*\))*\)/', '', $e);
    } while ($e !== $before);
    $e = preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"|`(?:[^`\\\\\$]|\\\\.|\\$(?!\\{))*`/", '', $e);
    return trim(str_replace('+', '', $e)) === '' && trim($expr) !== '';
}

/** @return list<array{0:string,1:string}> [problem, snippet] */
function un_findings(string $path, string $src): array
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $src = preg_replace('~/\*.*?\*/~s', ' ', $src);
    $src = preg_replace('~(?<![:\\\\\'"`])//[^\n]*~', ' ', $src);
    if ($ext === 'php' || $ext === 'html' || $ext === 'htm') {
        $src = preg_replace('~(?<![:\\\'"`])#[^\n]*~', ' ', $src);
        $src = str_replace(['\\"', "\\'"], ['"', "'"], $src);   // JS emitted from inside PHP strings
    }
    $out = [];
    // 1. navigation sinks
    $re = '~(?<![\w$.])(?:[A-Za-z_$][\w$]*\s*\.\s*)?location(?:\s*\.\s*href)?\s*=(?![=>])\s*([^;\n}]+)|(?<![\w$])location\s*\.\s*(?:assign|replace)\s*\(([^;\n]+?)\)\s*[;}\n]~';
    if (preg_match_all($re, $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $expr = ($hit[1] ?? '') !== '' ? $hit[1] : ($hit[2] ?? '');
            if (preg_match('~\.\s*location\s*\.\s*hash\b~', $hit[0])) { continue; }
            if (!un_safe_target($expr, $ext !== 'js' && $ext !== 'mjs')) { $out[] = ['navigation to a computed URL', trim(preg_replace('/\s+/', ' ', $hit[0]))]; }
        }
    }
    // 2. the page URL written as HTML
    foreach (preg_split('/[;\n]/', $src) as $stmt) {
        if (preg_match('~location\s*\.\s*(?:href|search|hash|pathname)\b|document\s*\.\s*(?:URL|documentURI)\b~', $stmt)
            && preg_match('~\b(?:innerHTML|outerHTML)\s*\+?=|insertAdjacentHTML\s*\(|document\s*\.\s*write(?:ln)?\s*\(|\.html\s*\(\s*[^)\s]~', $stmt)) {
            $out[] = ['page URL rendered as HTML', trim(substr(preg_replace('/\s+/', ' ', $stmt), 0, 140))];
        }
    }
    return $out;
}

$bad = [];
$scanned = 0;
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    function ($f) use ($skipTop, $root) {
        if ($f->isDir()) {
            return !(dirname($f->getPathname()) === $root && in_array($f->getFilename(), $skipTop, true))
                && !in_array($f->getFilename(), ['vendor', 'node_modules', '.git'], true);
        }
        return preg_match('/\.(php|js|mjs|html?)$/i', $f->getFilename()) && !preg_match('/\.min\.js$/', $f->getFilename());
    }
));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($root) + 1);
    $src = (string) file_get_contents($f->getPathname());
    if (!str_contains($src, 'location') && !str_contains($src, 'document.URL') && !str_contains($src, 'documentURI')) { continue; }
    $scanned++;
    foreach (un_findings($rel, $src) as [$why, $snip]) {
        $allowed = false;
        foreach ($allow as &$a) {
            if ($a['file'] === $rel && str_contains($snip, $a['snippet'])) {
                $a['used'] = true;
                $allowed = str_contains($src, $a['guard']);
                if (!$allowed) { $bad[] = "$rel: the validation this sink relies on is gone (expected: {$a['guard']})"; }
            }
        }
        unset($a);
        if (!$allowed) { $bad[] = "$rel: $why  ($snip)"; }
    }
}

$stale = array_map(static fn($a) => $a['file'] . '|' . $a['snippet'], array_filter($allow, static fn($a) => !$a['used']));
if ($bad || $stale) {
    if ($bad) {
        echo "FAIL: unvalidated navigation or the page URL rendered as HTML (validate the URL - path regex or http(s) only - and list the reviewed site in tests/no_unsafe_navigation.allow, or use textContent):\n  " . implode("\n  ", array_unique($bad)) . "\n";
    }
    if ($stale) {
        echo "FAIL: allowlist entries that no longer match anything (remove them from tests/no_unsafe_navigation.allow):\n  " . implode("\n  ", $stale) . "\n";
    }
    exit(1);
}
echo "PASS: $scanned first-party file(s) that touch location: every navigation target is a literal, encoded or reviewed, and no page URL is rendered as HTML (" . count($allow) . " reviewed site(s)).\n";
