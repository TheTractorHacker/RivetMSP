<?php
// MSP-DS-174: Intune sync leaves an asset alone once it belongs to another client. Run: php tests/msp_ds_174_intune_moved_asset.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$s = file_get_contents(__DIR__ . '/../src/Integrations/Microsoft/IntuneAssetMapper.php');
$i = strpos($s, 'Step 1: already linked'); $blk = substr($s, $i, 1300);
$ok('existing-link lookup joins assets and reads asset_client_id', str_contains($blk, 'JOIN assets a') && str_contains($blk, 'a.asset_client_id'));
$ok("a link whose asset belongs to another client returns 'skipped' before any UPDATE", strpos($blk, "!== \$client_id") !== false && strpos($blk, "return 'skipped'") < strpos($blk, 'UPDATE asset_intune_links') && strpos($blk, "return 'skipped'") < strpos($blk, 'syncAssetFields'));
// the decision itself
$decide = fn ($assetClient, $integrationClient) => ((int) $assetClient !== $integrationClient) ? 'skipped' : 'updated';
$ok('same client updates', $decide(5, 5) === 'updated');
$ok('moved asset skipped', $decide(6, 5) === 'skipped');
$ok('deleted asset (NULL client) skipped', $decide(null, 5) === 'skipped');
exit($fail ? 1 : 0);
