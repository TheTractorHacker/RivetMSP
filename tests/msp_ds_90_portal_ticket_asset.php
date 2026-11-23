<?php
// MSP-DS-90: a portal ticket keeps only an asset of the portal user's own client. Run:
// SCRATCH_TABLES="assets" tests/lib/with_scratch_db.sh php tests/msp_ds_90_portal_ticket_asset.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$src = file_get_contents(__DIR__ . '/../client/post.php');
preg_match('/(\$asset = intval\(\$_POST\[\'asset\'\] \?\? 0\);\s*\/\/ MSP-DS-90.*?\n    \})\n/s', $src, $m);
$ok('guard present in add_ticket', !empty($m[1]));
$mysqli->query("INSERT INTO assets (asset_id, asset_name, asset_client_id, asset_archived_at) VALUES (1,'mine',5,NULL),(2,'theirs',6,NULL),(3,'mine archived',5,NOW())");
function resolve($mysqli, string $code, int $posted, int $session_client_id): int { $_POST = ['asset' => $posted]; eval($code); return $asset; }
$ok('own asset kept', resolve($mysqli, $m[1], 1, 5) === 1);
$ok('another client\'s asset dropped', resolve($mysqli, $m[1], 2, 5) === 0);
$ok('archived asset dropped', resolve($mysqli, $m[1], 3, 5) === 0);
$ok('unknown asset dropped', resolve($mysqli, $m[1], 99, 5) === 0);
$ok('no asset stays 0', resolve($mysqli, $m[1], 0, 5) === 0);
exit($fail ? 1 : 0);
