<?php
// Review R17: calendar 'Clients Added' feed and the New Event client picker are scoped to the user's clients and skip archived clients.
// Run: SCRATCH_TABLES="clients contacts" bash tests/lib/with_scratch_db.sh php tests/msp_ds_review_R17_calendar_client_scope.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$root = dirname(__DIR__);
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$mysqli->query("INSERT INTO clients (client_id, client_name, client_created_at) VALUES (1,'Mine',NOW()),(2,'Other',NOW()),(3,'Archived',NOW())");
$mysqli->query("UPDATE clients SET client_archived_at = NOW() WHERE client_id = 3");
$access_permission_query = "AND clients.client_id IN (1)";   // exactly what includes/load_user_session.php builds for a restricted tech
// pull the real statements out of the two files and run them
$feeds = ['agent/calendar.php' => '/mysqli_query\(\$mysqli, "(SELECT client_id, client_name, client_created_at FROM clients[^"]*)"\)/', 'agent/modals/calendar/calendar_event_add.php' => '/mysqli_query\(\$mysqli, "(SELECT \* FROM clients LEFT JOIN contacts[^"]*)"\)/'];
foreach ($feeds as $f => $re) {
    $src = file_get_contents("$root/$f");
    $found = preg_match($re, $src, $m);
    $ok((bool) $found, "$f: client query found");
    if (!$found) { continue; }
    $ok(str_contains($m[1], '$access_permission_query'), "$f: query carries \$access_permission_query");
    $res = $mysqli->query(str_replace('$access_permission_query', $access_permission_query, $m[1]));
    $names = []; while ($res && ($r = $res->fetch_assoc())) { $names[] = $r['client_name']; }
    $ok($names === ['Mine'], "$f: restricted user sees only own non-archived client (" . implode(',', $names) . ')');
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
