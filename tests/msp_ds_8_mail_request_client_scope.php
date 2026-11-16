<?php
// MSP-DS-8: the mail request view modal and the convert handler apply the mailbox default-client scope. Run: php tests/msp_ds_8_mail_request_client_scope.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
$v = file_get_contents("$root/agent/modals/mail_request/mail_request_view.php");
$ok('view modal selects mailbox_default_client_id', strpos($v, 'm.mailbox_default_client_id') !== false);
$ok('view modal enforces client access before rendering', preg_match('/enforceClientAccess\(\$mr_default_client_id\).*mail_request_attachments/s', $v) === 1);
$p = file_get_contents("$root/agent/post/mail_requests.php");
$i = strpos($p, "isset(\$_POST['convert_mail_request'])"); $j = strpos($p, 'convertMailRequestToTicket', $i);
$blk = substr($p, $i, $j - $i);
$ok('convert handler looks up the stored request mailbox default client', strpos($blk, 'mailbox_default_client_id') !== false);
$ok('convert handler enforces it before converting', strpos($blk, 'enforceClientAccess($mr_default_client_id)') !== false);
exit($fail ? 1 : 0);
