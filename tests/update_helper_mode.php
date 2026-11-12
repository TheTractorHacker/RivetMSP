<?php
declare(strict_types=1);
// Update page mode choice (rivet-core#39): helper / legacy / unsupported. Pure file checks; touches nothing outside a temp dir.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/update_helper.php';
$fail = 0;
$ok = function (string $n, bool $c) use (&$fail): void { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };

$d = sys_get_temp_dir() . '/upd_' . bin2hex(random_bytes(4));
mkdir($d . '/scripts', 0700, true);
touch($d . '/scripts/update_cli.php');
$ok('own checkout, no helper -> legacy (unless the helper is installed here)', updateHelperMode($d) === (updateHelperInstalled() ? 'helper' : 'legacy'));
$ok('missing update_cli.php -> not legacy', updateHelperMode($d . '/nope') !== 'legacy');
$src = file_get_contents(__DIR__ . '/../admin/post/update.php');
$ok('page asks the mode before any git command', strpos($src, 'updateHelperMode(') < strpos($src, 'git pull'));
$ok('FORCE update is refused when the helper is installed', str_contains($src, "isset(\$_GET['force_update'])") && str_contains($src, 'not offered through the page'));
$ok('helper path is not under the web root', !str_starts_with(UPDATE_HELPER_PATH, dirname(__DIR__)));
$sh = file_get_contents(__DIR__ . '/../deploy/web-update.sh');
$ok('helper validates branch name and goes forward only', str_contains($sh, 'is-ancestor') && str_contains($sh, '[A-Za-z0-9]'));
array_map('unlink', [$d . '/scripts/update_cli.php']); rmdir($d . '/scripts'); rmdir($d);
exit($fail ? 1 : 0);
