<?php
// MSP-DS-106: the Firebase service-account path does not depend on DOCUMENT_ROOT (empty under CLI/cron). Run: php tests/msp_ds_106_firebase_cli_path.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$tmp = sys_get_temp_dir() . '/msp_ds_106_' . bin2hex(random_bytes(4));
mkdir("$tmp/includes", 0700, true); mkdir("$tmp/config", 0700, true);
copy(dirname(__DIR__) . '/includes/firebase.php', "$tmp/includes/firebase.php");
file_put_contents("$tmp/config/firebase_service_account.json", json_encode(['project_id' => 'p', 'private_key' => 'k', 'client_email' => 'e']));
$_SERVER['DOCUMENT_ROOT'] = '';   // what cron sees
require "$tmp/includes/firebase.php";
$sa = firebase_load_sa();
$ok(is_array($sa) && $sa['project_id'] === 'p', 'service account found with an empty DOCUMENT_ROOT');
$_SERVER['DOCUMENT_ROOT'] = '/nonexistent';
$ok(is_array(firebase_load_sa()), 'and with a different DOCUMENT_ROOT');
unlink("$tmp/config/firebase_service_account.json");
$ok(firebase_load_sa() === null, 'missing file still returns null');
@rmdir("$tmp/config"); unlink("$tmp/includes/firebase.php"); @rmdir("$tmp/includes"); @rmdir($tmp);
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
