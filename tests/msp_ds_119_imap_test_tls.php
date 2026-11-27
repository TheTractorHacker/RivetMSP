<?php
// MSP-DS-119: the IMAP "Test connection" verifies the certificate and always upgrades with STARTTLS for "tls".
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$s = file_get_contents(__DIR__ . '/../admin/post/settings_mail.php');
$a = strpos($s, "if (isset(\$_POST['test_email_imap'])");
$ok($a !== false, 'test_email_imap handler found');
$blk = substr($s, $a, 9000);
$ok(strpos($blk, "'verify_peer' => false") === false && strpos($blk, "'verify_peer_name' => false") === false && strpos($blk, "'allow_self_signed' => true") === false, 'certificate checks are not disabled');
$ok(strpos($blk, "'verify_peer' => true") !== false && strpos($blk, "'peer_name' => \$host") !== false, 'peer and host name verified');
$ok(strpos($blk, "if (\$encryption === 'tls') {") !== false && strpos($blk, "stripos(\$greeting, 'STARTTLS')") === false, 'STARTTLS no longer depends on the greeting');
$ok(strpos($blk, 'STARTTLS failed') !== false && strpos($blk, 'AUTHENTICATE XOAUTH2') > strpos($blk, 'STARTTLS failed'), 'a failed upgrade aborts before credentials are sent');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
