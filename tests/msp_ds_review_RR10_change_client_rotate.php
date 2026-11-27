<?php
// Re-review RR10: change_client_ticket rotates the guest key when the contact changes within the SAME client (and when the client changes),
// and keeps it when nothing changes. Executes the real handler fragment (extracted from agent/post/ticket.php) on a scratch DB. Run:
//   SCRATCH_TABLES="assets tickets ticket_replies ticket_watchers ticket_assets" tests/lib/with_scratch_db.sh php tests/msp_ds_review_RR10_change_client_rotate.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''"); $mysqli->query("SET FOREIGN_KEY_CHECKS=0");
require __DIR__ . '/../includes/ticket_hygiene.php';
$src = file_get_contents(__DIR__ . '/../agent/post/ticket.php');
$a = strpos($src, "if (isset(\$_POST['change_client_ticket']))");
$b = strpos($src, "if (isset(\$_GET['resolve_ticket']))", $a);
$blk = substr($src, $a, $b - $a);
// run from the current-client lookup to the update/detach block; skip CSRF/permission/access calls and the redirect tail
$s = strpos($blk, '$current_client_row = mysqli_fetch_assoc');
$e = strpos($blk, 'logAction(', $s);
$frag = substr($blk, $s, $e - $s);
function enforceClientAccess($id = 0) {}
function assertSameClient($t, $id, $c) {}
function flash_alert($m, $t = '') {}
function redirect($u = '') { throw new Exception('redirect'); }
$run = function (int $newClient, int $newContact) use ($mysqli, $frag) {
    $mysqli->query("DELETE FROM tickets");
    $mysqli->query("INSERT INTO tickets (ticket_id, ticket_client_id, ticket_url_key, ticket_contact_id) VALUES (1, 10, 'oldkey', 4)");
    $ticket_id = 1; $client_id = $newClient; $contact_id = $newContact;
    eval($frag);
    $r = $mysqli->query("SELECT ticket_url_key, ticket_contact_id, ticket_client_id FROM tickets WHERE ticket_id = 1")->fetch_row();
    return $r;
};
$r = $run(10, 9);  $ok($r[1] == 9 && $r[0] !== 'oldkey' && strlen($r[0]) === 32, 'same client, contact 4 -> 9: guest key rotated');
$r = $run(10, 4);  $ok($r[0] === 'oldkey', 'same client, same contact: guest key kept');
$r = $run(11, 7);  $ok($r[0] !== 'oldkey', 'different client: guest key rotated (MSP-DS-136 unchanged)');
$r = $run(10, 0);  $ok($r[0] !== 'oldkey', 'same client, contact removed: guest key rotated');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
