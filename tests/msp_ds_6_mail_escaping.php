<?php
// MSP-DS-6: queued customer email carries no literal backslash-quote. Run: SCRATCH_TABLES="email_queue tickets" tests/lib/with_scratch_db.sh php tests/msp_ds_6_mail_escaping.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
// 1. static: no double-quoted body still builds an attribute with a backslash-quote
$bad = [];
foreach (['agent/ajax.php','agent/post/invoice.php','agent/post/quote.php','agent/post/payment.php','agent/post/recurring_invoice.php','agent/post/task.php','agent/post/ticket.php','client/post.php','guest/guest_post.php','guest/guest_pay_invoice_stripe.php','guest/payment_webhook.php','cron/cron.php'] as $f) {
    foreach (file("$root/$f") as $i => $line) { if (preg_match('/(href|style)=\\\\+\'/', $line)) { $bad[] = "$f:" . ($i + 1); } }
}
$ok('no href=\\\' / style=\\\' left in customer mail bodies' . ($bad ? ' (' . implode(',', $bad) . ')' : ''), !$bad);
// 2. behavioural: the unescape helper used by addToMailQueue
$src = file_get_contents("$root/functions.php");
preg_match('/function mailQueueUnescape\(.*?\n}\n/s', $src, $m);
$ok('helper present', !empty($m[0]));
eval($m[0]);
$ok("O\\'Brien -> O'Brien", mailQueueUnescape("O\\'Brien IT") === "O'Brien IT");
$ok('href with quotes survives', mailQueueUnescape("<a href=\\'https://h/x\\'>l</a>") === "<a href='https://h/x'>l</a>");
$ok('plain text untouched', mailQueueUnescape('C:\\temp and 5 > 3') === 'C:\\temp and 5 > 3');
$ok('addToMailQueue applies it to subject, body and names', substr_count($src, 'mailQueueUnescape(') >= 5);
exit($fail ? 1 : 0);
