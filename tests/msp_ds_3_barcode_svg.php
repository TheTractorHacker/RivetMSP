<?php
// MSP-DS-3: barcode SVG colours cannot break out of fill="...". Run: php tests/msp_ds_3_barcode_svg.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$_SERVER['REQUEST_METHOD'] = 'CLI';
require __DIR__ . '/../plugins/barcode/barcode.php';
$g = new barcode_generator();
$evil = '"/><script>alert(1)</script><rect x="';
foreach (['cm', 'cs', 'c2', 'bc'] as $k) {
    foreach (['qr', 'code128'] as $sym) {
        $svg = $g->render_svg($sym, 'abc', [$k => $evil, 'cs' => $evil]);
        $ok(stripos($svg, '<script') === false, "$sym with hostile $k has no <script>");
    }
}
$good = $g->render_svg('qr', 'abc', ['cm' => '112233', 'bc' => 'FFFFFF']);
$ok(str_contains($good, 'fill="#112233"') && str_contains($good, 'fill="#FFFFFF"'), 'hex colours still work, named ones fall back');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
