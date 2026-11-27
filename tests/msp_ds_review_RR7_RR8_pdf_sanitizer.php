<?php
// Re-review RR7 (a stray </div> must not truncate the document) and RR8 (no <style>/<cssarray>/url()/@import may reach TCPDF's regex pre-pass).
// Runs the REAL rivetPdfSafeHtml (extracted from functions.php) and the real TCPDF getHtmlDomArray. Run: php tests/msp_ds_review_RR7_RR8_pdf_sanitizer.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$fn = file_get_contents(__DIR__ . '/../functions.php');
$a = strpos($fn, 'function rivetPdfSafeHtml'); $b = strpos($fn, 'function saveBase64Images');
eval(substr($fn, $a, $b - $a));

// RR7: truncation
$cases = [
    ['<div><p>Section 1</p></div></div><h2>Section 2</h2><p>secret steps</p>', ['Section 1', 'Section 2', 'secret steps']],
    ['<p>intro</p></div><p>rest of document</p>', ['intro', 'rest of document']],
    ['<p>a</p></div></div></div><p>b</p>', ['a', 'b']],
    ['<p>a</p></body><p>b</p></html><p>c</p>', ['a', 'b', 'c']],
    ['<p>x</p><body onload="1"><p>y</p><html><p>z</p>', ['x', 'y', 'z']],
    ['<table><tr><td>1</td></tr></table></div><p>after table</p>', ['1', 'after table']],
];
foreach ($cases as [$in, $need]) {
    $out = rivetPdfSafeHtml($in);
    $miss = array_filter($need, fn($n) => !str_contains($out, $n));
    $ok(!$miss, 'nothing lost after stray closers: ' . substr($in, 0, 50) . ($miss ? ' missing ' . implode(',', $miss) : ''));
}

// RR8: output never carries markup TCPDF's regex pre-pass would act on
$vectors = [
    "<a href='https://x/<style>p{display:none}</style>'>l</a><p>visible</p>",
    '<a href="https://x/&lt;style&gt;p{display:none}&lt;/style&gt;">l</a><p>visible</p>',
    "<a href='https://x/<cssarray>{&quot;a&quot;:1}</cssarray>'>l</a><p>v</p>",
    '<a href="mailto:a@b.c?x=<link type=&quot;text/css&quot; href=&quot;http://evil/x.css&quot;>">l</a>',
    '<a href="https://x/url(http://evil/a.png)">l</a>',
    '<p style="background:u&#x72;l(http://evil/a.png)">t</p><p style="a:b<style>">t</p>',
    '<p title="<style>p{}</style>" alt="x">t</p><font face="<style>">t</font>',
    '<p>&lt;style&gt;p{display:none}&lt;/style&gt; <![CDATA[<style>x</style>]]><!-- <style>y</style> --></p>',
    '<pre><style>p{}</style></pre><xmp><style>q{}</style></xmp><textarea><style>r{}</style></textarea><noscript><style>s{}</style></noscript>',
    '<a href="https://x/\'><style>p{}</style>">l</a>',
];
$tcpdfPath = __DIR__ . '/../plugins/TCPDF/tcpdf.php';
$haveTcpdf = is_file($tcpdfPath);
if ($haveTcpdf) {
    require_once $tcpdfPath;
    class RRTcpdf extends TCPDF { public function dom(string $h): array { return $this->getHtmlDomArray($h); } }
    $pdf = new RRTcpdf();
}
foreach ($vectors as $v) {
    $out = rivetPdfSafeHtml($v);
    $bad = preg_match('/<\s*\/?\s*(style|cssarray|link|script)\b/i', $out) || preg_match('/url\s*\(|@import/i', $out);
    $ok(!$bad, 'no style/cssarray/link/script opener or url()/@import in output: ' . substr($v, 0, 55) . ' => ' . substr($out, 0, 70));
    if ($haveTcpdf) {
        $hidden = false; foreach ($pdf->dom($out) as $d) { if (!empty($d['hide'])) { $hidden = true; } }
        $ok(!$hidden, 'TCPDF getHtmlDomArray hides nothing for it');
    }
}
// legit hrefs still work
$o = rivetPdfSafeHtml('<a href="https://example.com/a?b=1&c=2#x">l</a> <a href="mailto:a@b.co">m</a> <a href="tel:+15551234">t</a> <a href="#sec">s</a>');
$ok(substr_count($o, 'href=') === 4 && str_contains($o, 'https://example.com/a?b=1&amp;c=2#x'), 'legit https/mailto/tel/# hrefs kept');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
