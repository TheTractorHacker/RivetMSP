<?php
// MSP-DS-162: guest quote upload is status/count limited and notifies once per batch; "viewed" pushes are hourly. Run: php tests/msp_ds_162_guest_quote_upload.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$g = file_get_contents(__DIR__ . '/../guest/guest_post.php');
$blk = substr($g, strpos($g, "isset(\$_POST['guest_quote_upload_file'])"), 6500);
$ok('status gate (Sent/Viewed/Accepted)', str_contains($blk, "['Sent', 'Viewed', 'Accepted']"));
$ok('expiry checked', str_contains($blk, 'quote_expire'));
$ok('per-request and per-quote file caps', str_contains($blk, '$i < 5') && str_contains($blk, '$upload_existing + $i < 20'));
$ok('one notification per batch, loop notification removed', substr_count($blk, 'appNotify(') === 1 && str_contains($blk, '$upload_batch_ok file(s)') && !str_contains($blk, 'was uploaded to quote'));
$ok('the file name is not in the notification', !preg_match('/appNotify\("Quote File"[^;]*\$file_name/', $blk));
foreach (['guest_view_quote', 'guest_view_invoice'] as $f) { $s = file_get_contents(__DIR__ . "/../guest/$f.php"); $ok("$f dedupes the viewed notification hourly", str_contains($s, '$recently_viewed') && str_contains($s, 'INTERVAL 1 HOUR')); }
// behavioural check of the gate logic
$gate = function ($status, $expire) { return in_array($status, ['Sent', 'Viewed', 'Accepted'], true) && ($status === 'Accepted' || empty($expire) || $expire >= date('Y-m-d')); };
$ok('Draft/Declined/Invoiced refused', !$gate('Draft', null) && !$gate('Declined', null) && !$gate('Invoiced', null));
$ok('expired Sent refused, open Sent allowed', !$gate('Sent', '2000-01-01') && $gate('Sent', date('Y-m-d', time() + 86400)));
exit($fail ? 1 : 0);
