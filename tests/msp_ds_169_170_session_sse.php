<?php
// MSP-DS-169 (revoked session cannot be re-registered after its row is purged), MSP-DS-170 (portal SSE drops internal-note events).
// Run: SCRATCH_TABLES="user_sessions" tests/lib/with_scratch_db.sh php tests/msp_ds_169_170_session_sse.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
require __DIR__ . '/../includes/security_sessions.php';
session_id('testsession' . getmypid()); @session_start();
$mysqli->query("DELETE FROM user_sessions");
$ok('a brand-new session registers a row', secSessionTrack($mysqli, 7) === true && ($_SESSION['sec_row'] ?? 0) > 0);
$row = (int) $_SESSION['sec_row'];
$ok('a live session stays allowed', secSessionTrack($mysqli, 7) === true);
$mysqli->query("UPDATE user_sessions SET session_revoked_at = NOW() WHERE session_row_id = $row");
$ok('revoked session is refused', secSessionTrack($mysqli, 7) === false);
$mysqli->query("UPDATE user_sessions SET session_last_seen_at = NOW() - INTERVAL 40 DAY WHERE session_row_id = $row");
secSessionPurge($mysqli);
$n = (int) $mysqli->query("SELECT COUNT(*) FROM user_sessions WHERE session_row_id = $row")->fetch_row()[0];
$ok('purge keeps a revoked row past 30 days', $n === 1);
$mysqli->query("DELETE FROM user_sessions WHERE session_row_id = $row");   // even if the row is gone...
$ok('...a session that had a row is NOT re-registered', secSessionTrack($mysqli, 7) === false);
// SSE filter
$s = file_get_contents(__DIR__ . '/../includes/sse_ticket_stream.php');
eval(substr($s, strpos($s, 'function ssePortalMayReceive'), strpos($s, "header('Content-Type") - strpos($s, 'function ssePortalMayReceive')));
$ok('Internal reply event hidden from customers', !ssePortalMayReceive(json_encode(['type' => 'reply', 'reply_type' => 'Internal', 'by' => 'Tech'])));
$ok('Labor/System reply events hidden', !ssePortalMayReceive('{"type":"reply","reply_type":"Labor"}') && !ssePortalMayReceive('{"type":"reply","reply_type":"System"}'));
$ok('Public and Client replies still delivered', ssePortalMayReceive('{"type":"reply","reply_type":"Public"}') && ssePortalMayReceive('{"type":"reply","reply_type":"Client"}'));
$ok('status events still delivered, garbage dropped', ssePortalMayReceive('{"type":"status"}') && !ssePortalMayReceive('not json'));
$ok('client wrapper sets the portal flag', str_contains(file_get_contents(__DIR__ . '/../client/sse_ticket_stream.php'), '$sse_portal_view = true'));
exit($fail ? 1 : 0);
