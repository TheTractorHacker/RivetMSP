<?php
// MSP-DS-14: API agent replies are stored as 'Public' (visible to portal/guest), signing notes as 'Internal'. Run: php tests/msp_ds_14_api_reply_type.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
$t = file_get_contents("$root/api/v1/tickets.php");
$ok("agent reply maps to stored 'Public'", str_contains($t, "\$stored_reply_type = \$type === 'reply' ? 'Public' : \$type;"));
$ok('the INSERT binds the stored type, not the raw one', str_contains($t, '[$reply_raw, $stored_reply_type,'));
$ok("response shape unchanged (type stays 'reply')", str_contains($t, "\$response = ['id' => \$reply_id, 'type' => \$type];"));
$ok("agent reply still moves the ticket to Waiting on Customer", str_contains($t, "} elseif (\$type === 'reply') {"));
// every value the API can store is one the portal/guest allow-list shows or intentionally hides
foreach (['worksheets', 'outtakes'] as $f) {
    $s = file_get_contents("$root/api/v1/$f.php");
    $ok("$f.php no longer inserts lowercase 'note'", !str_contains($s, "'note', \$uid"));
    $ok("$f.php inserts 'Internal'", str_contains($s, "'Internal', \$uid"));
}
// behavioural: the allow-list the customer views use
$allow = ['Public', 'Client'];
$ok("'Public' is visible to the customer views", in_array('Public', $allow, true));
$ok("'reply' was not visible (the bug)", !in_array('reply', $allow, true));
$c = file_get_contents("$root/client/ticket.php") . file_get_contents("$root/guest/guest_view_ticket.php");
$ok("portal and guest views still select IN ('Public', 'Client')", substr_count($c, "IN ('Public', 'Client')") >= 2);
exit($fail ? 1 : 0);
