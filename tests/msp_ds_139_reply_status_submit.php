<?php
// MSP-DS-139: a reply-status handler only fires for the real split-button items, and the server never stores a bogus status.
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$t = file_get_contents(__DIR__ . '/../agent/ticket.php');
$ok(strpos($t, "\$(document).on('click', '.reply-status-submit'") === false, 'bare class selector is gone');
$ok(strpos($t, "a.reply-status-submit[data-status-id]") !== false && strpos($t, "if (sid === undefined || sid === '') { return; }") !== false, 'handler needs data-status-id and ignores an empty one');
$p = file_get_contents(__DIR__ . '/../agent/post/ticket.php');
$a = strpos($p, 'MSP-DS-139');
$ok($a !== false && strpos($p, 'ticket_status_active = 1', $a) !== false && strpos($p, 'UPDATE tickets SET ticket_status = $ticket_status, ticket_updated_at', $a) > $a, 'status validated before the UPDATE');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
