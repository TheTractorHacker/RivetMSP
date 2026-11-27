<?php
// MSP-DS-51: change approval is bound to the client and content shown. Run:
//   SCRATCH_TABLES="changes" bash tests/lib/with_scratch_db.sh php tests/msp_ds_51_change_approval_binding.php
require __DIR__ . '/lib/ds_src.php';
$src = ds_src('agent/post/change.php');
ds_t('url key rotated when the client changes', strpos($src, 'if ($change_client_id !== $old_client_id) {') !== false);
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { echo "skip - no scratch DB\n"; ds_done(); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
// run the real reset statement from the handler
preg_match('/\$reset = \$mysqli->prepare\(\s*("UPDATE changes SET client_decision.*?")\s*\);/s', $src, $m);
$sql = eval('return ' . $m[1] . ';');
function reset_run($mysqli, $sql, $id, $cid, $t, $risk, $r, $i, $ip, $rp) { $st = $mysqli->prepare($sql); $st->bind_param('iissssss', $id, $cid, $t, $risk, $r, $i, $ip, $rp); $st->execute(); $n = $st->affected_rows; $st->close(); return $n; }
function fresh($mysqli) { $mysqli->query("DELETE FROM changes"); $mysqli->query("INSERT INTO changes (change_id, title, reason, impact, risk, implementation_plan, rollback_plan, client_id, requires_client_approval, client_decision, client_decided_at, url_key) VALUES (1, 'T', 'R', NULL, 'low', 'P', 'B', 5, 1, 'approved', NOW(), 'k')"); }
fresh($mysqli);
ds_t('same client, same content: decision kept', reset_run($mysqli, $sql, 1, 5, 'T', 'low', 'R', null, 'P', 'B') === 0 && $mysqli->query("SELECT client_decision FROM changes")->fetch_row()[0] === 'approved');
foreach (['title' => ['T2', 'low', 'R', null, 'P', 'B'], 'case-only title' => ['t', 'low', 'R', null, 'P', 'B'], 'risk' => ['T', 'high', 'R', null, 'P', 'B'], 'impact' => ['T', 'low', 'R', 'new', 'P', 'B'], 'plan' => ['T', 'low', 'R', null, 'P2', 'B'], 'rollback' => ['T', 'low', 'R', null, 'P', 'B2']] as $what => $v) {
    fresh($mysqli);
    reset_run($mysqli, $sql, 1, 5, ...$v);
    $row = $mysqli->query("SELECT client_decision, client_decided_at FROM changes")->fetch_row();
    ds_t("$what edit after approval resets the decision", $row[0] === 'pending' && $row[1] === null);
}
fresh($mysqli); reset_run($mysqli, $sql, 1, 6, 'T', 'low', 'R', null, 'P', 'B');
ds_t('moving to another client resets the decision', $mysqli->query("SELECT client_decision FROM changes")->fetch_row()[0] === 'pending');
ds_done();
