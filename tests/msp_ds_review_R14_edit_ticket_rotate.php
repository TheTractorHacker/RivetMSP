<?php
// Review R14: the full edit_ticket form rotates ticket_url_key when the contact changes (and only then). Executes the real handler fragment against a scratch DB.
// Run: SCRATCH_TABLES="tickets" bash tests/lib/with_scratch_db.sh php tests/msp_ds_review_R14_edit_ticket_rotate.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''"); $mysqli->query("SET FOREIGN_KEY_CHECKS=0");
require __DIR__ . '/../includes/ticket_hygiene.php';
$src = file_get_contents(__DIR__ . '/../agent/post/ticket.php');
$a = strpos($src, '$old_ticket_row = mysqli_fetch_assoc');
$u = strpos($src, 'mysqli_query($mysqli, "UPDATE tickets SET ticket_category = $category_id', $a);
$end = strpos($src, '$billable_kept_override_notice', $u);
$frag = substr($src, $a, $end - $a);
$ok(str_contains($frag, 'ticketRotateGuestKey'), 'edit_ticket fragment calls ticketRotateGuestKey');
$run = function (int $newContact) use ($mysqli, $frag) {
    $mysqli->query("DELETE FROM tickets");
    $mysqli->query("INSERT INTO tickets (ticket_id, ticket_client_id, ticket_url_key, ticket_contact_id, ticket_category) VALUES (1, 10, 'oldkey', 4, 0)");
    $ticket_id = 1; $contact_id = $newContact; $category_id = 0; $ticket_subject = 's'; $ticket_priority = 'Low'; $billable = 0; $details = ''; $due = 'NULL';
    $vendor_ticket_number = ''; $assigned_to = 0; $vendor_id = 0; $location_id = 0; $asset_id = 0; $project_id = 0; $delivery_method_sql_fragment = '';
    eval($frag);
    return $mysqli->query("SELECT ticket_url_key FROM tickets WHERE ticket_id = 1")->fetch_row()[0];
};
$ok($run(9) !== 'oldkey', 'contact 4 -> 9 rotates the guest key');
$ok($run(4) === 'oldkey', 'contact unchanged keeps the guest key');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
