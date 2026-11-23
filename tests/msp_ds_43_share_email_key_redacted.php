<?php
// MSP-DS-43: after delivery the queue row no longer holds the #ek key of a credential share link.
require __DIR__ . '/lib/ds_src.php';
$s = ds_src('cron/mail_queue.php');
preg_match("#preg_replace\('(/\#ek=[^']+/)'#", $s, $m);
ds_t('redaction regex present', !empty($m[1]));
$key = 'Ab3-_xYz09QwErTy';
$body = "<a href=\'https://h/guest/guest_view_item.php?id=3&key=abc#ek=$key\'>go</a>";
$out = preg_replace($m[1], '#ek=[removed after delivery]', $body);
ds_t('key removed from body', strpos($out, $key) === false && strpos($out, 'id=3&key=abc') !== false);
ds_t('redaction runs in the sent (status 3) update', preg_match('/email_status = 3, [^;]*\$content_redacted_sql/', $s) === 1);
ds_done();
