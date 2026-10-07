<?php
/*
 * Regression checks for two security fixes, no database or network:  php tests/webhook_queue_delivery.php
 *   1. cron/cron.php legacy webhook_queue delivery must use the shared URL policy + pinned, non-redirecting curl
 *      (it used file_get_contents(), which follows redirects and re-resolves DNS after the save-time check).
 *   2. client/post.php edit_contact must refuse a non-primary editor changing a contact with technical/billing rights.
 */
$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$cron = file_get_contents(__DIR__ . '/../cron/cron.php');
$start = strpos($cron, 'WEBHOOK DELIVERY');
$end = strpos($cron, 'Prune old delivered/failed webhook entries');
$ok($start !== false && $end !== false && $end > $start, 'legacy webhook delivery section found');
$sec = substr($cron, (int) $start, (int) $end - (int) $start);
$ok(strpos($sec, 'file_get_contents') === false, 'delivery does not use file_get_contents (follows redirects)');
$ok(strpos($sec, 'rivetWebhookResolveTarget($wq_url)') !== false, 'URL is vetted with the shared policy at call time');
$ok(strpos($sec, 'WebhookDispatcher::curlOptions(') !== false && strpos($sec, 'WebhookDispatcher::pinnedUrl(') !== false, 'connection is pinned via WebhookDispatcher::curlOptions/pinnedUrl');
$ok(strpos($sec, "decryptSetting((string) \$wq['webhook_url'])") !== false, 'stored (encrypted) URL is decrypted before use');
$ok(strpos($sec, 'curl_init(') < strpos($sec, 'curl_exec(') && strpos($sec, 'rivetWebhookResolveTarget') < strpos($sec, 'curl_init('), 'vet happens before the request is made');

$post = file_get_contents(__DIR__ . '/../client/post.php');
$s = strpos($post, "if (isset(\$_POST['edit_contact']))");
$e = strpos($post, "if (isset(\$_GET['add_payment_by_provider']))");
$blk = substr($post, (int) $s, (int) $e - (int) $s);
$ok($s !== false && $e > $s, 'edit_contact handler found');
$ok(preg_match('/\$session_contact_primary != 1 && \$contact_id !== \$session_contact_id && \(intval\(\$row\[.contact_technical.\]\) === 1 \|\| intval\(\$row\[.contact_billing.\]\) === 1\)/', $blk) === 1, 'non-primary editors are refused for technical/billing contacts');
$ok(strpos($blk, 'Only the primary contact can edit') < strpos($blk, 'UPDATE users SET'), 'the refusal comes before any users row is changed');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
