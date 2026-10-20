<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * Fails when first-party code calls a native browser dialog: window.alert(), window.confirm(), window.prompt() (also the bare
 * alert( / confirm( / prompt( forms, window['alert'](...) and inline on*="...confirm(..." attributes). Use RivetDialog (js/rivet_dialog.js).
 * Scans .js/.mjs files and the <script> blocks and on*= attributes of .php/.html files; vendored code (vendor/, plugins/), tests/ and
 * docs/ are skipped. 'beforeunload' is reported too (the browser's leave-page prompt cannot be replaced); only the files named in
 * tests/no_native_dialogs.allow may use it.
 *   php tests/no_native_dialogs.php
 */
$root = dirname(__DIR__);
$skipDirs = ['vendor', 'plugins', 'tests', 'docs', 'node_modules', '.git', 'uploads', 'deploy', 'scripts'];
$allow = [];
foreach (file($root . '/tests/no_native_dialogs.allow', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if ($line[0] === '#') { continue; }
    $parts = explode('|', $line);
    if (count($parts) < 3) { fwrite(STDERR, "bad allowlist line: $line\n"); exit(2); }
    $allow[$parts[0] . '|' . $parts[1]] = true;
}

/** Findings for one chunk of JavaScript: [token, snippet]. */
function rd_scan_js(string $js): array
{
    $js = preg_replace('~/\*.*?\*/~s', ' ', $js);
    $js = preg_replace('~(?<![:\\\\\'"`])//[^\n]*~', ' ', $js);
    $out = [];
    if (preg_match_all('~(?<![\w$.\'"`-])(?<!function )(?:(?:window|self|top|parent|globalThis)\s*\.\s*)?(alert|confirm|prompt)\s*\((?!s\))~', $js, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $i => $hit) { $out[] = [$hit[0], trim(substr($js, $m[0][$i][1], 60))]; }
    }
    if (preg_match_all('~\[\s*[\'"](alert|confirm|prompt)[\'"]\s*\]\s*\(~', $js, $m)) {
        foreach ($m[1] as $t) { $out[] = [$t, "[\"$t\"]("]; }
    }
    if (preg_match('~beforeunload~i', $js)) { $out[] = ['beforeunload', 'beforeunload']; }
    return $out;
}

function rd_findings(string $path, string $src): array
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'js' || $ext === 'mjs') { return rd_scan_js($src); }
    $out = [];
    if ($ext === 'php') {
        // Browser code echoed from PHP strings (e.g. "<script>alert(...)</script>") lives inside the PHP blocks: scan the raw source too.
        // PHP methods and functions named alert()/confirm() are not dialogs: -> and :: calls, declarations and flash_alert() are skipped.
        $raw = preg_replace('~/\*.*?\*/~s', ' ', $src);
        $raw = preg_replace('~(?<![:\\\'"`])(?://|#)[^\n]*~', ' ', $raw);
        if (preg_match_all('~(?<![\w$.:\'"`-])(?<!->)(?<!function )(?:(?:window)\s*\.\s*)?(alert|confirm|prompt)\s*\((?!s\))~', $raw, $m)) {
            foreach ($m[1] as $t) { $out[] = [$t, "$t( (PHP-emitted browser code)"]; }
        }
    }
    $src = preg_replace('~<\?(?:php|=).*?\?>~s', '0', $src);   // PHP output is not browser code
    if (preg_match_all('~<script\b[^>]*>(.*?)</script>~is', $src, $m)) {
        foreach ($m[1] as $block) { $out = array_merge($out, rd_scan_js($block)); }
    }
    if (preg_match_all('~\bon[a-z]+\s*=\s*"([^"]*)"|\bon[a-z]+\s*=\s*\'([^\']*)\'~i', $src, $m)) {
        foreach (array_merge($m[1], $m[2]) as $attr) { if ($attr !== '') { $out = array_merge($out, rd_scan_js($attr)); } }
    }
    return $out;
}

$bad = [];
$scanned = 0;
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    function ($f) use ($skipDirs, $root) {
        if ($f->isDir()) { return !in_array($f->getFilename(), $skipDirs, true) || dirname($f->getPathname()) !== $root && !in_array($f->getFilename(), ['vendor', 'plugins', 'node_modules', '.git'], true); }
        return preg_match('/\.(php|js|mjs|html?)$/i', $f->getFilename()) && !preg_match('/\.min\.js$/', $f->getFilename());
    }
));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($root) + 1);
    $scanned++;
    foreach (rd_findings($rel, (string) file_get_contents($f->getPathname())) as [$tok, $snip]) {
        if (!isset($allow[$rel . '|' . $tok])) { $bad[] = "$rel: $tok  ($snip)"; }
    }
}

if ($bad) {
    echo "FAIL: native browser dialogs found (use RivetDialog.alert/confirm/prompt, js/rivet_dialog.js):\n  " . implode("\n  ", array_unique($bad)) . "\n";
    exit(1);
}
echo "OK: no native dialogs in $scanned first-party files\n";
