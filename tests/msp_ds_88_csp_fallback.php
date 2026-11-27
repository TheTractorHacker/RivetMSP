<?php
// MSP-DS-88: portal pages without their own CSP get one; guest signing pages send the guest security headers. Run: php tests/msp_ds_88_csp_fallback.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$root = dirname(__DIR__);
require $root . '/includes/csp_policies.php';
$p = portalFallbackCsp('NONCE123');
$ok(str_contains($p, "default-src 'self'") && str_contains($p, "script-src 'self' 'nonce-NONCE123'"), 'portal fallback: default-src self + nonced scripts');
$ok(!preg_match("/script-src[^;]*unsafe-inline/", $p), 'portal fallback: no unsafe-inline for scripts');
$ok(str_contains($p, "frame-ancestors 'none'") && str_contains($p, "object-src 'none'"), 'portal fallback: no framing, no plugins');
$h = file_get_contents($root . '/client/includes/header.php');
$ok(preg_match("/if \(\\\$csp_sent === ''\) \{[^}]*portalFallbackCsp\(\\\$csp_nonce\)/s", $h) === 1, 'client header.php sends the fallback when no CSP was queued');
$ok(str_contains($h, "} elseif (stripos(\$csp_sent, 'script-src') === false) {"), 'pages with their own CSP keep the existing nonce handling');
foreach (['guest/outtake_sign.php', 'guest/worksheet_sign.php'] as $f) {
    $s = file_get_contents("$root/$f");
    $ok(preg_match('/csp_policies\.php.*\$csp_nonce = base64_encode\(random_bytes\(16\)\);\s*sendGuestSigningHeaders\(\$csp_nonce\);/s', $s) === 1, "$f sends the guest headers with a nonce before any output");
}
$g = file_get_contents($root . '/includes/csp_policies.php');
foreach (['Content-Security-Policy', 'X-Frame-Options: DENY', 'X-Content-Type-Options: nosniff', 'Referrer-Policy'] as $hd) {
    $ok(str_contains($g, $hd), "sendGuestSigningHeaders sends $hd");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
