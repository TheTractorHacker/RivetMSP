<?php
// MSP-DS-118: the legacy Settings > Mail Microsoft connect clears a stale mail_oauth_mailbox_id.
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$s = file_get_contents(__DIR__ . '/../admin/post/settings_mail.php');
$a = strpos($s, "\$_SESSION['mail_oauth_scope'] = \$scope;");
$ok($a !== false, 'legacy flow sets the scope');
$ok($a !== false && strpos(substr($s, $a, 400), "unset(\$_SESSION['mail_oauth_mailbox_id'])") !== false, 'and clears mail_oauth_mailbox_id before redirecting');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
