<?php
declare(strict_types=1);
// Regression guards for MSP-INJ-1 (archive/restore/delete client need per-client access) and MSP-INJ-2 (rmm assign_client needs access to the
// asset's current client; credentials under an asset are limited to the asset's client). Source-level: no database, no network.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$fail = 0;
$ok = function (string $n, bool $c) use (&$fail): void { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$src = static fn (string $f): string => (string) file_get_contents($root . '/' . $f);

$c = $src('agent/post/client.php');
foreach (['archive_client' => 'client_archived_at = NOW()', 'restore_client' => 'client_archived_at = NULL', 'delete_client' => 'DELETE FROM api_keys'] as $k => $mutation) {
    $start = strpos($c, "if (isset(\$_GET['$k']))");
    $enf = $start === false ? false : strpos($c, 'enforceClientAccess($client_id)', $start);
    $mut = $start === false ? false : strpos($c, $mutation, $start);
    $ok("$k: enforceClientAccess runs before the first change", $enf !== false && $mut !== false && $enf < $mut);
}

$s = $src('agent/post/rmm_sync.php');
$a = strpos($s, "if (\$action === 'assign_client')");
$upd = strpos($s, 'UPDATE assets SET asset_client_id', (int) $a);
$cur = strpos($s, "enforceClientAccess((int) \$asset_row['asset_client_id'])", (int) $a);
$ok('assign_client: the asset\'s current client is checked before the UPDATE', $a !== false && $cur !== false && $upd !== false && $cur < $upd);

foreach (['agent/asset_details.php', 'agent/assets.php', 'agent/modals/asset/asset_details.php', 'agent/rmm_asset.php'] as $f) {
    $t = $src($f);
    $ok("$f: credentials are limited to the asset's client", str_contains($t, '(SELECT asset_client_id FROM assets WHERE asset_id = $asset_id)'));
}
exit($fail ? 1 : 0);
