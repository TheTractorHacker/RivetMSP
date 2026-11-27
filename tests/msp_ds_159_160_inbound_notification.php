<?php
// MSP-DS-159 (no backslashes from double SQL-escaping in notification mail), MSP-DS-160 (sender HTML is not copied into staff mail).
// Run: php tests/msp_ds_159_160_inbound_notification.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$src = file_get_contents(__DIR__ . '/../functions.php');
$a = strpos($src, 'function rivetUnescapeSql'); $b = strpos($src, 'function addTicket(');
function nullable_htmlentities($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
eval(substr($src, $a, $b - $a));
$mysqli = new mysqli(); // only for the round trip below
foreach (["Can't print", 'O\'Brien "x" \\ back', "line1\nline2\r\n", "Pat's \\' tricky"] as $raw) {
    $esc = addcslashes($raw, "\0\n\r\\'\"\x1a"); // mysqli_real_escape_string equivalent for utf8
    $esc = str_replace(["\0","\n","\r","\x1a"], ['\\0','\\n','\\r','\\Z'], str_replace(['\\', "'", '"'], ['\\\\', "\\'", '\\"'], $raw));
    $ok('unescape round-trips ' . json_encode($raw), rivetUnescapeSql($esc) === $raw);
}
$html = '<p>Reset your password <a href="http://evil/p">here</a></p><img src="http://evil/t.gif"><form action="http://evil"><input name=p></form><script>alert(1)</script><br>second line &amp; more';
$out = rivetMailSafeExcerpt($html);
$ok('no tag survives (all text-escaped)', !preg_match('/<(?!br\s*\/?>)/', $out));
$ok('text is kept', str_contains($out, 'Reset your password') && str_contains($out, 'second line &amp; more'));
$ok('script text removed', !str_contains($out, 'alert(1)'));
$ok('addTicket/addReply no longer re-escape mail bodies', !preg_match('/\'(body|subject)\' => mysqli_real_escape_string\(\$mysqli, \$email_/', $src));
$ok('notification bodies use the excerpt', substr_count($src, 'rivetMailSafeExcerpt(') >= 3);
exit($fail ? 1 : 0);
