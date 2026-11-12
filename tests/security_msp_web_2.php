<?php
declare(strict_types=1);
// MSP-WEB-2: the client portal and guest ticket views show only Public and Client replies (never Internal, System, Labor, AI, RMM, merge notes). Source-level.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__); $fail = 0;
$ok = function (string $n, bool $c) use (&$fail): void { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
foreach (['client/ticket.php', 'guest/guest_view_ticket.php', 'guest/outtake_sign.php'] as $f) {
    $s = (string) file_get_contents("$root/$f");
    $ok("$f: replies are a whitelist, not 'everything but Internal'", str_contains($s, "ticket_reply_type IN ('Public', 'Client')") && !str_contains($s, "ticket_reply_type != 'Internal'"));
}
exit($fail ? 1 : 0);
