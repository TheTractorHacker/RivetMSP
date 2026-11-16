<?php
// MSP-DS-151 (PDF export never fetches remote images), MSP-DS-152 (portal contract download gate). Run: php tests/msp_ds_151_152_portal_doc_contract.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$fn = file_get_contents(__DIR__ . '/../functions.php');
$a = strpos($fn, 'function rivetPdfSafeHtml'); $b = strpos($fn, 'function saveBase64Images');
eval(substr($fn, $a, $b - $a));
$html = '<p>x</p><img src="http://169.254.169.254/latest"><img src=\'https://evil/x.png\'><img src="//evil/x.png"><img src="file:///etc/passwd"><img src="/etc/passwd"><img src="/uploads/../config.php"><img src="ht&#x74;p://evil/a.gif"><img src="  http://evil/b.gif"><img src="/uploads/documents/5/ok.png"><img src="data:image/png;base64,iVBORw0KGgo=">';
$out = rivetPdfSafeHtml($html);
$ok(substr_count($out, '<img') === 2, 'only the two local/inline images survive: ' . substr_count($out, '<img'));
$ok(!str_contains($out, 'evil') && !str_contains($out, '169.254') && !str_contains($out, 'passwd'), 'no remote or path src left');
$ok(str_contains($out, '/uploads/documents/5/ok.png') && str_contains($out, 'data:image/png'), 'legit images kept');
$ok(str_contains(file_get_contents(__DIR__ . '/../agent/post/document.php'), 'writeHTML(rivetPdfSafeHtml('), 'export_document purifies before writeHTML');
$post = file_get_contents(__DIR__ . '/../client/post.php');
$ok(str_contains($post, 'rivetPdfSafeHtml(saveBase64Images('), 'portal save filters document html');
$i = strpos($post, "isset(\$_GET['client_serve_contract_doc'])");
$blk = substr($post, $i, 1200);
$ok(str_contains($blk, 'contract_archived_at IS NULL') && str_contains($blk, '$session_contact_primary == 0 && !$session_contact_is_technical_contact'), 'contract download: archived excluded, primary/technical required');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
