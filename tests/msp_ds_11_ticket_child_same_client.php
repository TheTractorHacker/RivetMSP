<?php
// MSP-DS-11: a ticket's contact/asset/vendor/location/project must belong to the ticket's client. Run:
//   SCRATCH_TABLES="contacts assets vendors locations projects" tests/lib/with_scratch_db.sh php tests/msp_ds_11_ticket_child_same_client.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
require dirname(__DIR__) . '/includes/same_client.php';
// client A = 1, client B = 2; ids: A objects 11, B objects 22, shared project 30
$m->query("INSERT INTO contacts (contact_id, contact_name, contact_client_id) VALUES (11,'a',1),(22,'b',2)");
$m->query("INSERT INTO assets (asset_id, asset_name, asset_client_id) VALUES (11,'a',1),(22,'b',2)");
$m->query("INSERT INTO vendors (vendor_id, vendor_name, vendor_client_id) VALUES (11,'a',1),(22,'b',2)");
$m->query("INSERT INTO locations (location_id, location_name, location_client_id) VALUES (11,'a',1),(22,'b',2)");
$m->query("INSERT INTO projects (project_id, project_name, project_client_id) VALUES (11,'a',1),(22,'b',2),(30,'shared',0)");
foreach (['contact', 'asset', 'vendor', 'location', 'project'] as $k) {
    $ok("$k of the same client is accepted", childBelongsToClient($m, $k, 11, 1));
    $ok("$k of another client is refused", !childBelongsToClient($m, $k, 22, 1));
    $ok("$k 0 (none) is accepted", childBelongsToClient($m, $k, 0, 1));
    $ok("$k that does not exist is refused", !childBelongsToClient($m, $k, 999, 1));
    $ok("$k of a client cannot hang on an internal ticket (client 0)", !childBelongsToClient($m, $k, 11, 0));
}
$ok('shared project (client 0) fits any client', childBelongsToClient($m, 'project', 30, 1) && childBelongsToClient($m, 'project', 30, 2));
$ok('a shared contact-like owner 0 is NOT accepted for a client ticket', !childBelongsToClient($m, 'asset', 11, 2));
$ok('additional assets: all same client', childrenBelongToClient($m, 'asset', ['11', 0], 1));
$ok('additional assets: one foreign id fails the lot', !childrenBelongToClient($m, 'asset', [11, 22], 1));
$ok('unknown kind refused', !childBelongsToClient($m, 'ticket', 5, 1));
$src = file_get_contents(dirname(__DIR__) . '/agent/post/ticket.php');
foreach (['add_ticket', 'edit_ticket', 'edit_ticket_contact', 'edit_ticket_project', 'edit_ticket_asset', 'edit_ticket_vendor', 'bulk_add_ticket_project', 'change_client_ticket'] as $h) {
    $i = strpos($src, "isset(\$_POST['$h'])"); $j = strpos($src, "\nif (isset(", $i + 10);
    $ok("handler $h calls assertSameClient", $i !== false && strpos(substr($src, $i, ($j ?: strlen($src)) - $i), 'assertSameClient(') !== false);
}
exit($fail ? 1 : 0);
