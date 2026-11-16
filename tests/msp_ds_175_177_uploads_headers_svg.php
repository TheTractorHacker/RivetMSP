<?php
// MSP-DS-175 (Apache uploads/.htaccess forces download + nosniff + CSP), MSP-DS-177 (no svg from inbound email). Run: php tests/msp_ds_175_177_uploads_headers_svg.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$h = file_get_contents(__DIR__ . '/../uploads/.htaccess');
$ok('php stays disabled in uploads', str_contains($h, 'php_flag engine off') && str_contains($h, 'Require all denied'));
$ok('Content-Disposition attachment', str_contains($h, 'Header always set Content-Disposition "attachment"'));
$ok('nosniff', str_contains($h, 'X-Content-Type-Options "nosniff"'));
$ok('sandboxing CSP', str_contains($h, "Content-Security-Policy \"default-src 'none'; sandbox\""));
$ok('headers sit in an IfModule so Apache without mod_headers still starts', preg_match('#<IfModule mod_headers\.c>.*Header always set Content-Disposition.*</IfModule>#s', $h) === 1);
$f = file_get_contents(__DIR__ . '/../functions.php');
preg_match('/function ticketAttachmentAllowedExtensions\(\): array \{\s*return \[(.*?)\];/s', $f, $m);
$exts = array_map(fn ($x) => trim($x, " '"), explode(',', $m[1] ?? ''));
$ok('svg removed from the inbound-email allow-list', !in_array('svg', $exts, true) && in_array('png', $exts, true) && in_array('pdf', $exts, true));
exit($fail ? 1 : 0);
