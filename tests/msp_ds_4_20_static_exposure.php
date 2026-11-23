<?php
// MSP-DS-4 / MSP-DS-20: dotfiles, config/ and tooling trees are not served by the shipped Apache/nginx/Docker configs. Run: php tests/msp_ds_4_20_static_exposure.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$root = dirname(__DIR__);
$ht = file_get_contents("$root/.htaccess");
preg_match('/RedirectMatch 404 (\S+)/', $ht, $m);
$re = '#' . str_replace('#', '\#', $m[1]) . '#';
foreach (['/.env', '/.env.example', '/config/firebase_service_account.json', '/deploy/backup.sh', '/.git/config'] as $p) { $ok(preg_match($re, $p) === 1, ".htaccess blocks $p"); }
foreach (['/agent/index.php', '/uploads/tickets/1/a.png', '/css/app.css'] as $p) { $ok(preg_match($re, $p) !== 1, ".htaccess still serves $p"); }
foreach (['docker/nginx.conf', 'deploy/templates/itflow-locations.conf'] as $f) {
    $c = file_get_contents("$root/$f");
    $ok(str_contains($c, 'location ~ /\.(?!well-known/)'), "$f denies dotfiles");
    $ok(str_contains($c, '(config|deploy|docker|docs|scripts|backups)'), "$f denies config/deploy/docker/docs/scripts/backups");
    // the dotfile regex itself
    $ok(preg_match('#/\.(?!well-known/)#', '/.env') === 1 && preg_match('#/\.(?!well-known/)#', '/.well-known/acme-challenge/x') !== 1, "$f dotfile regex keeps .well-known");
}
$s = file_get_contents("$root/admin/post/settings_notification.php");
$ok(str_contains($s, "Require all denied") && str_contains($s, 'chmod($config_path, 0600)'), 'Firebase key file is written 0600 into a deny-all config/');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
