<?php
// MSP-DS-124 (calendar/contracts gates and event scope), 125 (money band by module), 126 (campaign send claimed atomically).
// Run: SCRATCH_TABLES="crm_campaigns" bash tests/lib/with_scratch_db.sh php tests/msp_ds_124_125_126_pages.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$r = fn($f) => file_get_contents(__DIR__ . '/../' . $f);
$c = $r('agent/calendar.php');
$ok(strpos($c, "enforceUserPermission('module_support')") !== false, '124: calendar needs module_support');
$ok(strpos($c, 'event_client_id IN ($client_access_string)') !== false && strpos($c, '$session_is_admin') !== false, '124: restricted users only get events of their clients (or client 0)');
$ok(substr_count($c, "lookupUserPermission('module_sales') >= 1") === 2, '124: invoice and quote feeds need module_sales');
$ok(strpos($r('agent/contracts.php'), "enforceUserPermission('module_sales')") !== false, '124: contracts page needs module_sales');
$d = $r('agent/includes/dashboard_today.php');
$ok(strpos($d, '$session_user_role == 1') === false && strpos($d, "lookupUserPermission('module_financial') >= 1") !== false, '125: band gated on module_financial, no role ids');
$ok(strpos($d, '$td_quotes_ok') !== false && strpos($d, "lookupUserPermission('module_sales')") !== false, '125: quotes column needs module_sales');
// 126: run the claim statement the handler uses, twice
$src = $r('agent/post/campaign.php');
preg_match('/mysqli_query\(\$mysqli, "(UPDATE crm_campaigns SET campaign_status = \'sending\' WHERE campaign_id = \$campaign_id AND [^"]+)"\);/', $src, $m);
$ok(!empty($m[1]), '126: claim statement present');
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
$mysqli->query("INSERT INTO crm_campaigns (campaign_id, campaign_name, campaign_status) VALUES (1,'c','draft'),(2,'d','sent')");
$campaign_id = 1;
$sql = str_replace('$campaign_id', '1', $m[1]);
$mysqli->query($sql); $first = $mysqli->affected_rows;
$mysqli->query($sql); $second = $mysqli->affected_rows;
$ok($first === 1 && $second === 0, '126: first request claims the campaign, a duplicate gets 0 rows');
$mysqli->query(str_replace('campaign_id = 1', 'campaign_id = 2', $sql));
$ok($mysqli->affected_rows === 0, '126: an already sent campaign cannot be claimed');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
