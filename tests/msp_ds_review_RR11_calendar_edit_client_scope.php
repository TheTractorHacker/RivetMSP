<?php
// Re-review RR11: the Edit Event modal's client dropdown is scoped like the New Event picker (restricted tech: own clients only, no archived
// clients except the event's own). Runs the REAL query from agent/modals/calendar/calendar_event_edit.php. Run:
//   SCRATCH_TABLES="clients contacts" tests/lib/with_scratch_db.sh php tests/msp_ds_review_RR11_calendar_edit_client_scope.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$mysqli->query("INSERT INTO clients (client_id, client_name, client_created_at) VALUES (1,'Mine',NOW()),(2,'Other',NOW()),(3,'Archived',NOW()),(4,'MineArchived',NOW())");
$mysqli->query("UPDATE clients SET client_archived_at = NOW() WHERE client_id IN (3,4)");
$src = file_get_contents(dirname(__DIR__) . '/agent/modals/calendar/calendar_event_edit.php');
$ok((bool) preg_match('/mysqli_query\(\$mysqli, "(SELECT \* FROM clients LEFT JOIN contacts[^"]*)"\)/', $src, $m), 'client query found');
$names = function (string $client_id, string $acl) use ($mysqli, $m) {
    $q = str_replace(['$access_permission_query', '$client_id'], [$acl, $client_id], $m[1]);
    $res = $mysqli->query($q); $n = []; while ($res && ($r = $res->fetch_assoc())) { $n[] = $r['client_name']; } return $n;
};
$ok($names('0', 'AND clients.client_id IN (1)') === ['Mine'], 'restricted tech opening a client-less event sees only their own client');
$ok($names('1', 'AND clients.client_id IN (1)') === ['Mine'], 'restricted tech, event of client 1: own client only');
$n = $names('0', ''); sort($n);
$ok($n === ['Mine', 'Other'], 'unrestricted user: all active clients (' . implode(',', $n) . ')');
$ok($names('4', 'AND clients.client_id IN (1,4)') === ['Mine', 'MineArchived'], "the event's own (archived) client stays selectable");
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
