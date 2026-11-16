<?php
// MSP-DS-111: barcode.php caps image size, scale, padding and data length. Run: php tests/msp_ds_111_barcode_bounds.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$_SERVER['REQUEST_METHOD'] = 'CLI';
require __DIR__ . '/../plugins/barcode/barcode.php';
$g = new barcode_generator();
$im = $g->render_image('qr', 'x', ['w' => 20000, 'h' => 20000]);
$ok(imagesx($im) <= 2000 && imagesy($im) <= 2000, 'w/h=20000 clamped to <= 2000 px');
$im = $g->render_image('qr', 'x', ['sf' => 5000]);
$ok(imagesx($im) <= 2000 && imagesy($im) <= 2000, 'huge scale clamped');
$im = $g->render_image('qr', 'x', ['p' => 100000]);
$ok(imagesx($im) <= 2000, 'huge padding clamped');
$im = $g->render_image('qr', 'https://example.com/otpauth', []);
$ok(imagesx($im) > 10 && imagesx($im) < 500, 'default QR still renders at normal size');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
