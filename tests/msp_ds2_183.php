<?php
// MSP-DS-183: the shipped CSP carries no vendor domain or third-party origin by default; installation-specific sources come from config.php.
// Run: php tests/msp_ds2_183.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
require $root . '/includes/csp_policies.php';

unset($GLOBALS['config_csp_img_src'], $GLOBALS['config_csp_cloudflare_insights']);
$ok('default img-src is self/data/blob only', cspImgSrc() === "img-src 'self' data: blob:");
$ok('default adds no Cloudflare script or connect origin', cspCloudflareScript() === '' && cspCloudflareConnect() === '');

$GLOBALS['config_csp_img_src'] = 'https://*.example.com https://cdn.example.net:8443';
$ok('configured https origins are added', cspImgSrc() === "img-src 'self' data: blob: https://*.example.com https://cdn.example.net:8443");
// anything that could smuggle another directive or weaken the policy is dropped
$GLOBALS['config_csp_img_src'] = "http://plain.example https://evil.test;script-src https://* 'unsafe-inline' https: * data: https://ok.example";
$img = cspImgSrc();
$ok('non-origin, http, wildcard and directive-injecting tokens are dropped', $img === "img-src 'self' data: blob: https://ok.example");
$ok('no semicolon can reach the header', !str_contains($img, ';'));
$GLOBALS['config_csp_img_src'] = "https://a.example\nhttps://b.example";
$ok('newline-separated origins are accepted', cspImgSrc() === "img-src 'self' data: blob: https://a.example https://b.example");

$GLOBALS['config_csp_cloudflare_insights'] = true;
$ok('Cloudflare beacon only when switched on', cspCloudflareScript() === ' https://static.cloudflareinsights.com' && cspCloudflareConnect() === ' https://cloudflareinsights.com');

// No page may hard-code the vendor domain or the Cloudflare origin in a CSP any more.
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') { continue; }
    $rel = substr($f->getPathname(), strlen($root) + 1);
    if (preg_match('#^(tests|vendor|plugins|docs|node_modules|\.git)/#', $rel) || $rel === 'includes/csp_policies.php') { continue; }
    foreach (file($f->getPathname()) as $n => $line) {
        if (preg_match('/Content-Security-Policy/i', $line) && preg_match('/foleyit\.com|cloudflareinsights/i', $line)) { $bad[] = "$rel:" . ($n + 1); }
    }
}
$ok('no CSP line outside csp_policies.php names foleyit.com or cloudflareinsights' . ($bad ? ': ' . implode(', ', $bad) : ''), !$bad);
// The pages that build the policy use the helpers.
foreach (['includes/header.php', 'login.php', 'guest/includes/inc_all_guest.php', 'guest/guest_download_file.php', 'guest/guest_pay_invoice_stripe.php'] as $p) {
    $s = file_get_contents($root . '/' . $p);
    $ok("$p builds its CSP from the helpers", str_contains($s, 'cspCloudflareScript()') && (str_contains($s, 'cspImgSrc()') || $p === 'guest/guest_pay_invoice_stripe.php'));
}
// Each page loads config.php BEFORE it sends the header (the helpers read config.php's values).
foreach (['login.php' => 'require_once "config.php"', 'guest/guest_download_file.php' => 'require_once "../config.php"', 'guest/includes/inc_all_guest.php' => "/config.php';"] as $p => $needle) {
    $s = file_get_contents($root . '/' . $p);
    $ok("$p loads config.php before the CSP header", strpos($s, $needle) !== false && strpos($s, $needle) < strpos($s, 'Content-Security-Policy'));
}
exit($fail ? 1 : 0);
