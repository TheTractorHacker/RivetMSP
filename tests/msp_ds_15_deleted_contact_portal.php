<?php
// MSP-DS-15: delete_contact removes the portal user; the portal gate ends a session whose contact is gone or archived. Run: php tests/msp_ds_15_deleted_contact_portal.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
$c = file_get_contents("$root/agent/post/contact.php");
$i = strpos($c, "isset(\$_GET['delete_contact'])"); $j = strpos($c, "\nif (isset(", $i + 10); $b = substr($c, $i, $j - $i);
$ok('delete_contact selects contact_user_id', strpos($b, 'SELECT contact_name, contact_client_id, contact_user_id FROM contacts') !== false);
$ok('delete_contact deletes the portal user (type 2 only)', strpos($b, 'DELETE FROM users WHERE user_id = $contact_user_id AND user_type = 2') !== false);
$ok('delete_contact handles a missing contact', strpos($b, 'if (!$row)') !== false);
$l = file_get_contents("$root/client/includes/check_login.php");
$ok('portal gate rejects a missing contact', preg_match('/\$contact = mysqli_fetch_assoc\(\$contact_sql\);\s*.*?if \(!\$contact \|\| \$contact\[\'contact_archived_at\'\] !== null\) \{\s*session_unset\(\);\s*session_destroy\(\);\s*redirect\("\/login.php"\);/s', $l) === 1);
$ok('the gate runs before the contact is read', strpos($l, "!\$contact ||") < strpos($l, "\$contact['contact_name']"));
exit($fail ? 1 : 0);
