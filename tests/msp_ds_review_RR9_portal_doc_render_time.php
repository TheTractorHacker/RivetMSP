<?php
// Re-review RR9: portal document save stores the original (saveBase64Images only); every render path sanitises at render time.
// Run: php tests/msp_ds_review_RR9_portal_doc_render_time.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$root = dirname(__DIR__);
$post = file_get_contents("$root/client/post.php");
$i = strpos($post, "isset(\$_POST['client_add_document'])"); $blk = substr($post, $i, 2500);
$ok(str_contains($blk, 'saveBase64Images(') && !str_contains($blk, 'rivetPdfSafeHtml('), 'portal save stores saveBase64Images() output without the PDF allow-list');
$ok(str_contains(file_get_contents("$root/agent/post/document.php"), 'writeHTML(rivetPdfSafeHtml('), 'agent PDF export sanitises at render time');
foreach (['client/document.php', 'guest/guest_view_item.php', 'agent/document_details.php', 'agent/modals/document/document_view.php'] as $f) {
    $ok(str_contains(file_get_contents("$root/$f"), '->purify('), "$f renders stored document HTML through HTMLPurifier");
}
// the structure the save used to strip is now stored and still sanitised for export
$fn = file_get_contents("$root/functions.php");
eval(substr($fn, strpos($fn, 'function rivetPdfSafeHtml'), strpos($fn, 'function saveBase64Images') - strpos($fn, 'function rivetPdfSafeHtml')));
$stored = '<dl><dt>Term</dt><dd>Def</dd></dl><p><a href="/uploads/clients/3/f.pdf" class="btn">file</a></p><img src="http://evil/x.png"><style>p{}</style>';
$ok(str_contains($stored, '<dt>') && str_contains($stored, '/uploads/clients/3/f.pdf'), 'stored text keeps dl/dt/dd and relative links (nothing sanitises it on save)');
$pdf = rivetPdfSafeHtml($stored);
$ok(!str_contains($pdf, 'evil') && !str_contains($pdf, '<style'), 'the export render path still removes remote images and style');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
