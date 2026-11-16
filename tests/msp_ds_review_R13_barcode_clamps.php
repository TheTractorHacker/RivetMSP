<?php
// Review R13 (MSP-DS-111): md and wq..w9 (and th/ts) must be clamped too. Run: php tests/msp_ds_review_R13_barcode_clamps.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$_SERVER['REQUEST_METHOD'] = 'CLI';
require __DIR__ . '/../plugins/barcode/barcode.php';
$g = new barcode_generator();
$d2000 = str_repeat('A1b2', 500);
$cases = [
  'md=100 d=2000'      => ['qr', $d2000, ['md' => 100]],
  'sf=20 md=100 d=300' => ['qr', str_repeat('x', 300), ['sf' => 20, 'md' => 100]],
  'wm=20 md=100'       => ['qr', 'hello', ['wm' => 20, 'md' => 100]],
  'wm=1000'            => ['qr', 'hello', ['wm' => 1000]],
  'wq=wm=1000 sf=20'   => ['qr', str_repeat('x', 300), ['wq' => 1000, 'wm' => 1000, 'sf' => 20]],
  'negative widths'    => ['qr', 'hello', ['wm' => -50, 'wq' => -5]],
  'code128 ww/wn=1000' => ['c128', 'ABC123', ['ww' => 1000, 'wn' => 1000, 'w4' => 1000]],
  'th/ts huge'         => ['c128', 'ABC123', ['th' => 100000, 'ts' => 5000]],
];
foreach ($cases as $label => [$sym, $data, $opt]) {
    $t = microtime(true); $mem0 = memory_get_peak_usage();
    $im = $g->render_image($sym, $data, $opt);
    $dt = microtime(true) - $t;
    $ok($im && $dt < 3.0, "$label renders in " . round($dt, 2) . 's (limit 3s)');
    $ok($im && imagesx($im) <= 2048 && imagesy($im) <= 2048, "$label image <= 2048px (" . ($im ? imagesx($im) . 'x' . imagesy($im) : 'none') . ')');
    $ok(memory_get_peak_usage() < 64 * 1024 * 1024, "$label peak memory under 64 MB (" . round(memory_get_peak_usage() / 1048576) . ' MB)');
    $svg = $g->render_svg($sym, $data, $opt);
    $ok(is_string($svg) && strlen($svg) < 8 * 1024 * 1024, "$label svg bounded");
}
$im = $g->render_image('qr', 'https://example.com/otpauth', []);
$ok(imagesx($im) > 10 && imagesx($im) < 500, 'default QR unchanged');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
