<?php
// MSP-DS-74: TOTP seed QR is rendered inline (data: URI), never as a GET query string. Run: php tests/msp_ds_74_mfa_qr_inline.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $root . '/includes/qr_data_uri.php';
$uri = qrPngDataUri('otpauth://totp/RivetMSP:a@b.c?secret=ABCDEFGHIJKLMNOP');
$ok(str_starts_with($uri, 'data:image/png;base64,'), 'data URI returned');
$png = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);
$ok($png !== false && str_starts_with($png, "\x89PNG"), 'payload is a PNG');
foreach (['agent/user/modals/user_mfa_modal.php', 'agent/user/mfa_enforcement.php'] as $f) {
    $s = file_get_contents("$root/$f");
    $ok(!str_contains($s, 'barcode.php?'), "$f has no barcode.php?d= URL");
    $ok(str_contains($s, 'qrPngDataUri($data)'), "$f uses inline QR");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
