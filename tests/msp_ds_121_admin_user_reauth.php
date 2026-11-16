<?php
// MSP-DS-121: admin user-management: own sensitive edits and creating an administrator need the acting admin's password.
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$u = file_get_contents(__DIR__ . '/../admin/post/users.php');
$a = strpos($u, "isset(\$_POST['add_user'])"); $addBlk = substr($u, $a, 1500);
$ok(strpos($addBlk, 'secReauthPassword(') !== false && strpos($addBlk, 'role_is_admin') !== false, 'add_user of an administrator needs re-auth');
$e = strpos($u, "isset(\$_POST['edit_user'])"); $editBlk = substr($u, $e, 2200);
$ok(strpos($editBlk, 'secReauthPassword(') !== false && strpos($editBlk, 'intval($session_user_id)') !== false, 'edit_user of yourself needs re-auth');
$ok(strpos($editBlk, "'2fa'") !== false && strpos($editBlk, 'user_email') !== false && strpos($editBlk, 'user_role_id') !== false && strpos($editBlk, '$new_password !==') !== false, 'covers password, 2FA disable, email and role changes');
$ok(strpos($editBlk, 'secReauthPassword(') < strpos($editBlk, 'secPasswordPolicyError'), 'check runs before any change is made');
$ok(strpos(file_get_contents(__DIR__ . '/../admin/modals/user/user_edit.php'), 'admin_current_password') !== false, 'edit modal has the password field for yourself');
$ok(strpos(file_get_contents(__DIR__ . '/../admin/modals/user/user_add.php'), 'admin_current_password') !== false, 'add modal has the password field');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
