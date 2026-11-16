<?php
// MSP-DS-134 (guest key rotated on watcher removal / contact change), 135 (cross-client merge moves replies as Internal),
// 136 (moving a ticket to another client drops watchers, guest key, contract and old-client links).
// Run: SCRATCH_TABLES="tickets ticket_watchers assets ticket_assets" bash tests/lib/with_scratch_db.sh php tests/msp_ds_134_135_136_ticket_hygiene.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$mysqli->query("SET FOREIGN_KEY_CHECKS=0");
require __DIR__ . '/../includes/ticket_hygiene.php';
$mysqli->query("INSERT INTO tickets (ticket_id, ticket_client_id, ticket_url_key, ticket_contract_id, ticket_asset_id, ticket_location_id, ticket_vendor_id, ticket_project_id, ticket_contact_id) VALUES (1, 10, 'oldkey', 55, 7, 8, 9, 3, 4)");
$mysqli->query("INSERT INTO ticket_watchers (watcher_email, watcher_ticket_id) VALUES ('a@old.test', 1), ('b@old.test', 1)");
$mysqli->query("INSERT INTO assets (asset_id, asset_name, asset_client_id) VALUES (7,'x',10)");
$mysqli->query("INSERT INTO ticket_assets (ticket_id, asset_id) VALUES (1, 7)");
$new = ticketRotateGuestKey($mysqli, 1);
$cur = $mysqli->query("SELECT ticket_url_key FROM tickets WHERE ticket_id = 1")->fetch_row()[0];
$ok($new === $cur && $cur !== 'oldkey' && strlen($cur) === 32, '134: rotation replaces the key with a fresh random value');
ticketDetachFromOldClient($mysqli, 1);
$t = $mysqli->query("SELECT * FROM tickets WHERE ticket_id = 1")->fetch_assoc();
$ok((int) $mysqli->query("SELECT COUNT(*) FROM ticket_watchers WHERE watcher_ticket_id = 1")->fetch_row()[0] === 0, '136: watchers removed');
$ok((int) $mysqli->query("SELECT COUNT(*) FROM ticket_assets WHERE ticket_id = 1")->fetch_row()[0] === 0, '136: extra assets removed');
$ok($t['ticket_contract_id'] === null && (int) $t['ticket_asset_id'] === 0 && (int) $t['ticket_location_id'] === 0 && (int) $t['ticket_vendor_id'] === 0 && (int) $t['ticket_project_id'] === 0, '136: contract and asset/location/vendor/project links cleared');
$ok($t['ticket_url_key'] !== $cur, '136: guest key rotated again');
$ok((int) $t['ticket_client_id'] === 10 && (int) $t['ticket_contact_id'] === 4, 'client and contact columns are left to the handler');
$s = file_get_contents(__DIR__ . '/../agent/post/ticket.php');
$ok(strpos($s, 'ticketRotateGuestKey($mysqli, $ticket_id)') !== false && substr_count($s, 'ticketRotateGuestKey(') >= 2, '134: watcher removal and contact change rotate the key');
$ok(strpos($s, "ticket_reply_type IN ('Public', 'Client'), 'Internal'") !== false && strpos($s, '$source_client_id !== $client_id') !== false, '135: cross-client merge turns moved Public/Client replies Internal');
$ok(strpos($s, 'ticketDetachFromOldClient($mysqli, $ticket_id)') !== false && strpos($s, 'That contact does not belong to the selected client') !== false, '136: change_client_ticket detaches and validates the contact');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
