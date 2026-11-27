<?php
// MSP-DS-17: portal set_recurring_payment needs the billing gate and a real saved-card row. Run: php tests/msp_ds_17_portal_autopay_gate.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$c = file_get_contents(dirname(__DIR__) . '/client/post.php');
$i = strpos($c, "isset(\$_POST['set_recurring_payment'])"); $j = strpos($c, "\nif (isset(", $i + 10); $b = substr($c, $i, $j - $i);
$g = strpos($b, '$session_contact_primary != 1 && !$session_contact_is_billing_contact');
$ok('billing gate present', $g !== false);
$ok('gate runs before any recurring_payments write', $g !== false && $g < strpos($b, 'recurring_payments'));
$ok('gate runs right after the CSRF check', $g !== false && $g < strpos($b, 'SELECT * FROM recurring_invoices'));
$ok('missing saved-card row is rejected before the INSERT', preg_match('/\$row = mysqli_fetch_assoc\(\$sql\);\s*(\/\/[^\n]*\n\s*)*if \(!\$row\) \{[^}]*redirect\(\);\s*\}.*INSERT INTO recurring_payments/s', $b) === 1);
exit($fail ? 1 : 0);
