<?php
// MSP-DS-127: the client PDF export prints the decrypted TOTP seed, not the stored ciphertext.
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$s = file_get_contents(__DIR__ . '/../agent/post/client.php');
$ok(preg_match('/\$credential_totp_secret = getFallback\(nullable_htmlentities\(decryptOtpSecret\(/', $s) === 1, 'TOTP column goes through decryptOtpSecret before escaping');
$ok(strpos($s, "nullable_htmlentities(\$row['credential_otp_secret'])") === false, 'no raw ciphertext print left');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
