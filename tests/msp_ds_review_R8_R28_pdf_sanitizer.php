<?php
// Review R8/R28 (MSP-DS-151): rivetPdfSafeHtml must survive data-src, duplicate src and <link> CSS. Run: php tests/msp_ds_review_R8_R28_pdf_sanitizer.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$fn = file_get_contents(__DIR__ . '/../functions.php');
$a = strpos($fn, 'function rivetPdfSafeHtml'); $b = strpos($fn, 'function saveBase64Images');
eval(substr($fn, $a, $b - $a));

// Emulate TCPDF 6.11.3 getHtmlDomArray: every name="value" pair, lowercase name, last duplicate wins.
function tcpdfAttrs(string $tag): array {
    preg_match_all('/([^=\s]*)[\s]*=[\s]*"([^"]*)"/', $tag, $m, PREG_SET_ORDER);
    $o = []; foreach ($m as $x) { $o[strtolower($x[1])] = $x[2]; } return $o;
}
function srcsAfter(string $out): array {
    preg_match_all('/<img\b[^>]*>/i', $out, $t); $r = [];
    foreach ($t[0] as $tag) { $r[] = tcpdfAttrs($tag)['src'] ?? null; } return $r;
}
$bad = [
  'data-src' => '<img data-src="/uploads/a.png" src="http://169.254.169.254/latest/meta-data/">',
  'dup src'  => '<img src="/uploads/a.png" src="http://10.0.0.5/admin">',
  'dup src, title trick' => '<img title=\'src="/uploads/a.png"\' src="http://attacker/x">',
  'dup src uppercase' => '<img src="/uploads/a.png" SRC="http://10.0.0.5/admin">',
  'data-src only' => '<img data-src="http://evil/x.png">',
  'srcset' => '<img srcset="http://evil/x.png 1x" src="/uploads/a.png">',
  'single quote dup' => "<img src='/uploads/a.png' src='http://evil/x'>",
];
foreach ($bad as $label => $h) {
    $out = rivetPdfSafeHtml($h);
    $ok(!preg_match('#https?://|169\.254|10\.0\.0\.5|attacker|evil#i', $out), "$label: no remote reference survives: $out");
    foreach (srcsAfter($out) as $s) { $ok($s === null || preg_match('#^/?uploads/|^data:image/#', $s), "$label: TCPDF src is local ($s)"); }
}
$links = ['<link rel="stylesheet" type="text/css" href="http://127.0.0.1:6380/x"><p>hi</p>', '<LINK type="text/css" href="http://127.0.0.1/x">',
  '<style>@import url(http://evil/x.css); p{background:url(http://evil/y)}</style><p>x</p>', '<p style="background:url(http://evil/a)">x</p>',
  '<svg><image href="http://evil/a.png"/></svg>', '<object data="http://evil/a"></object><iframe src="http://evil/"></iframe>',
  '<div style="x:\\75rl(http://evil/a)">x</div>', '<table background="http://evil/bg.png"><tr><td background="http://evil/b.png">x</td></tr></table>',
  '<body background="http://evil/b.png"><p>x</p></body>'];
foreach ($links as $h) {
    $out = rivetPdfSafeHtml($h);
    $ok(!preg_match('#https?://|evil|127\.0\.0\.1|<link|<style|@import|url\s*\(#i', $out), 'neutralised: ' . substr($h, 0, 60) . ' => ' . $out);
}
// legitimate content is preserved
$good = '<h2 style="color:#333">Title</h2><p>Hello <b>wor</b>ld &amp; ünï</p><table border="1"><tr><td colspan="2" style="text-align:center">a</td></tr></table><ul><li>x</li></ul><a href="https://example.com/a">l</a>'
      . '<img src="/uploads/documents/5/ok.png" width="100"><img src="data:image/png;base64,iVBORw0KGgo=">';
$out = rivetPdfSafeHtml($good);
foreach (['<h2 style="color:#333">Title</h2>', '<b>wor</b>', 'ünï', 'colspan="2"', 'text-align:center', '<li>x</li>', 'href="https://example.com/a"', 'src="/uploads/documents/5/ok.png"', 'width="100"', 'data:image/png;base64,iVBORw0KGgo='] as $needle) {
    $ok(str_contains($out, $needle), "kept: $needle");
}
$ok(rivetPdfSafeHtml('plain text') === 'plain text', 'plain text passes through');
$ok(rivetPdfSafeHtml('') === '', 'empty');
$ok(!str_contains(rivetPdfSafeHtml('<p onclick="x()" class="a">t</p><script>alert(1)</script>'), 'script'), 'script removed');
$ok(!str_contains(rivetPdfSafeHtml('<p onclick="x()">t</p>'), 'onclick'), 'event attrs removed');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
