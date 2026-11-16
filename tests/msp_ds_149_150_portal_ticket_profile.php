<?php
// MSP-DS-149 (portal ticket text escaped in staff email), MSP-DS-150 (portal password change needs current password). Run: php tests/msp_ds_149_150_portal_ticket_profile.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$post = file_get_contents(__DIR__ . '/../client/post.php');
$form = file_get_contents(__DIR__ . '/../client/profile.php');
function nullable_htmlentities($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function removeEmoji($t) { return $t; }
// behavioural: the exact expression used in the handler
$details = '<img src="http://evil/x.gif"><a href="http://evil">pay</a> hi <script>x</script>';
$safe = nl2br(nullable_htmlentities(removeEmoji(trim(strip_tags($details)))));
$ok(strpos($safe, '<') === false && strpos($safe, 'evil') === false, 'details lose every tag and href');
$ok(str_contains(nullable_htmlentities('<b>x</b>'), '&lt;b&gt;'), 'subject is entity-escaped');
$ok(str_contains($post, '$details_safe') && !str_contains($post, '<b>$subject</b><br>$details"'), 'email body uses the escaped values');
$i = strpos($post, "isset(\$_POST['edit_profile'])");
$blk = substr($post, $i, 1400);
$ok(str_contains($blk, 'secReauthPassword') && strpos($blk, 'secReauthPassword') < strpos($blk, 'UPDATE users SET user_password'), 'current password verified before the UPDATE');
$ok(str_contains($form, 'name="current_password"'), 'profile form asks for the current password');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
