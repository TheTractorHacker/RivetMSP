<?php
// MSP-DS-104: a view-limited share link serves at most `limit` views, even when requests run in parallel. Run:
// SCRATCH_TABLES="shared_items" tests/lib/with_scratch_db.sh php tests/msp_ds_104_share_view_claim.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$connect = fn () => new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
require __DIR__ . '/../includes/share_views.php';
if (($argv[1] ?? '') === 'child') { $m = $connect(); echo claimSharedItemView($m, (int) $argv[2]) ? 'WON' : 'LOST'; exit; }
$mysqli = $connect();
$mysqli->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$mysqli->query("INSERT INTO shared_items (item_id, item_active, item_views, item_view_limit, item_key, item_type, item_client_id, item_related_id) VALUES (1,1,0,1,'k','Document',1,1),(2,1,0,0,'k','Document',1,1),(3,0,0,0,'k','Document',1,1),(4,1,0,3,'k','Document',1,1)");
$ok('first view of a 1-view link is granted', claimSharedItemView($mysqli, 1));
$ok('second view is refused', !claimSharedItemView($mysqli, 1));
$ok('counter stopped at the limit', (int) $mysqli->query("SELECT item_views FROM shared_items WHERE item_id=1")->fetch_row()[0] === 1);
$ok('unlimited link (0) always granted', claimSharedItemView($mysqli, 2) && claimSharedItemView($mysqli, 2));
$ok('inactive link refused', !claimSharedItemView($mysqli, 3));
// 8 parallel processes against a 3-view link
$procs = [];
for ($i = 0; $i < 8; $i++) { $procs[] = popen('php ' . escapeshellarg(__FILE__) . ' child 4 2>/dev/null', 'r'); }
$won = 0; foreach ($procs as $p) { if (trim((string) stream_get_contents($p)) === 'WON') { $won++; } pclose($p); }
$ok("8 parallel requests on a 3-view link: exactly 3 granted (got $won)", $won === 3);
$ok('counter is exactly 3', (int) $mysqli->query("SELECT item_views FROM shared_items WHERE item_id=4")->fetch_row()[0] === 3);
$src = file_get_contents(__DIR__ . '/../guest/guest_view_item.php') . file_get_contents(__DIR__ . '/../guest/guest_download_file.php');
$ok('no read-modify-write view counter left', !str_contains($src, '$new_item_views'));
exit($fail ? 1 : 0);
