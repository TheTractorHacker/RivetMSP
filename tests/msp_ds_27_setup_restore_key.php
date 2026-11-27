<?php
// MSP-DS-27: a hostile settings_enc_key in an uploaded backup manifest must never reach config.php. Run: php tests/msp_ds_27_setup_restore_key.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
require __DIR__ . '/../setup/setup_functions.php';
$dir = sys_get_temp_dir() . '/msp_ds27_' . bin2hex(random_bytes(4)); mkdir($dir);
$cfg = $dir . '/config.php';
$orig = "<?php\n\$config_settings_enc_key = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';\n";
$try = function (string $key) use ($dir, $cfg, $orig) {
    file_put_contents($cfg, $orig);
    file_put_contents($dir . '/backup-manifest.json', json_encode(['settings_enc_key' => $key]));
    $r = applyManifestSettingsEncKey($dir, null, $cfg, null);
    return [$r, file_get_contents($cfg)];
};
[$r, $c] = $try("deadbeef'; file_put_contents('/tmp/pwned_ds27','x'); \$x='");
$ok($r['status'] !== 'applied' && $c === $orig, 'injection payload refused, config.php untouched');
[$r, $c] = $try(str_repeat('ab', 20) . '\\');
$ok($c === $orig, 'non-hex key refused');
[$r, $c] = $try(str_repeat('0123456789abcdef', 4));
$ok($r['status'] === 'applied' && str_contains($c, "'" . str_repeat('0123456789abcdef', 4) . "'"), 'a real hex key is still applied');
$ok(!file_exists('/tmp/pwned_ds27'), 'nothing executed');
@unlink($cfg); @unlink($dir . '/backup-manifest.json'); @rmdir($dir);
$idx = file_get_contents(__DIR__ . '/../setup/index.php');
$ok(preg_match("/if \(isset\(\\\$_POST\['restore'\]\)\) \{.{0,900}Users already exist/s", $idx) === 1, 'restore handler refuses once users exist');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
