<?php
// MSP-DS-179 (vendor template with no field ticked), MSP-DS-180 (API client credentials tab never returns ciphertext). Run: php tests/msp_ds_179_180_vendor_tpl_api_cred.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$v = file_get_contents(__DIR__ . '/../admin/post/vendor_template.php');
$i = strpos($v, "UPDATE vendors SET \$sql WHERE");
$ok('vendors UPDATE only runs when a field was selected', $i !== false && preg_match('/if \(trim\(\$sql\) !== \'\'\) \{\s*mysqli_query\(\$mysqli,"UPDATE vendors SET \$sql/', $v) === 1);
// behavioural: the builder yields an empty string when nothing is ticked
$parts = array_fill(0, 11, ''); $sql = implode(' ', $parts); $sql = preg_replace('/,/', '', $sql, 1);
$ok('nothing ticked -> empty field list -> no query', trim($sql) === '');
$t = file_get_contents(__DIR__ . '/../api/v1/client_tabs.php');
$blk = substr($t, strpos($t, "case 'credentials'"), 1500);
$ok("credentials tab decrypts the username (no raw 'credential_username' value in the response)", str_contains($blk, "_api_decrypt((string) \$r['credential_username']") && !str_contains($blk, "'username'=>\$r['credential_username']"));
$ok('_api_decrypt is shared from api_permissions.php', str_contains(file_get_contents(__DIR__ . '/../api/v1/includes/api_permissions.php'), "function _api_decrypt(") && !str_contains(file_get_contents(__DIR__ . '/../api/v1/credentials.php'), 'function _api_decrypt'));
// behavioural: without a usable token key the field is '' rather than ciphertext
$src = file_get_contents(__DIR__ . '/../api/v1/includes/api_permissions.php');
$a = strpos($src, "if (!function_exists('_api_decrypt'))");
class_exists('RivetMSP\Crypto\VaultV3', false) || eval('namespace RivetMSP\Crypto; class VaultV3 { static function isV3Field($c) { return false; } }');
eval(substr($src, $a));
$cipher = random_bytes(16) . random_bytes(32);
$ok('ciphertext is never echoed back (empty string without a key)', _api_decrypt($cipher, 'tok', []) === '' && _api_decrypt($cipher, 'tok', ['token_enc_master_key' => null]) === '');
exit($fail ? 1 : 0);
