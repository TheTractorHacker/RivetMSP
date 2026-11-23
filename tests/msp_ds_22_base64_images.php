<?php
// MSP-DS-22: saveBase64Images() must only store real raster images. Run: php tests/msp_ds_22_base64_images.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
preg_match('/^function saveBase64Images\(.*?^}\n/ms', file_get_contents(__DIR__ . '/../functions.php'), $m);
eval($m[0]);
$tmp = sys_get_temp_dir() . '/msp_ds22_' . bin2hex(random_bytes(4));
mkdir($tmp);
$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
$evil = base64_encode('{"content":"<script>alert(1)</script>"}');
$out = saveBase64Images('<p><img src="data:image/png;base64,' . $evil . '"><img src="data:image/png;base64,' . $png . '"></p>', $tmp, 'uploads/documents', 7);
$files = glob($tmp . '/7/*') ?: [];
$ok(count($files) === 1 && str_ends_with($files[0], '.png'), 'only the real image is written');
$ok(!str_contains($out, 'img_') || substr_count($out, 'img_') === 1, 'the fake image is not given a stored path');
$out2 = saveBase64Images('<img src="data:image/gif;base64,' . $png . '">', $tmp, 'uploads/documents', 8);
$f2 = glob($tmp . '/8/*') ?: [];
$ok(count($f2) === 1 && str_ends_with($f2[0], '.png'), 'extension comes from the detected type, not the declared one');
foreach (array_merge($files, $f2) as $f) { unlink($f); }
@rmdir("$tmp/7"); @rmdir("$tmp/8"); @rmdir($tmp);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
