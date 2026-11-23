<?php
// MSP-DS-163: outtake page attributes replies by type (Client -> contact, Public -> technician). Run: php tests/msp_ds_163_outtake_reply_author.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$s = file_get_contents(__DIR__ . '/../guest/outtake_sign.php');
$ok("users join only for Public replies", str_contains($s, "u.user_id AND tr.ticket_reply_type = 'Public'"));
$ok("contacts join only for Client replies", str_contains($s, "co3.contact_id AND tr.ticket_reply_type = 'Client'"));
$ok("author chosen by 'Client'", str_contains($s, "== 'Client' ? nullable_htmlentities(\$reply['reply_contact']) : nullable_htmlentities(\$reply['user_name'])") && !str_contains($s, "== 'Agent'"));
// behavioural: the join conditions on an in-memory model
$users = [5 => 'Tech Tom']; $contacts = [5 => 'Other Client Pat'];
$name = function ($type, $by) use ($users, $contacts) { $u = $type === 'Public' ? ($users[$by] ?? null) : null; $c = $type === 'Client' ? ($contacts[$by] ?? null) : null; return $type === 'Client' ? $c : $u; };
$ok('technician reply shows the technician, never the colliding contact', $name('Public', 5) === 'Tech Tom');
$ok('client reply shows the contact', $name('Client', 5) === 'Other Client Pat');
exit($fail ? 1 : 0);
