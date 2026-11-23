<?php
// MSP-DS-18 / MSP-DS-31: recurring-ticket frequency whitelist (save + cron) and cron contact-belongs-to-client check. Run: php tests/msp_ds_18_recurring_frequency.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$cron = file_get_contents(__DIR__ . '/../cron/cron.php');
$model = file_get_contents(__DIR__ . '/../agent/post/recurring_ticket_model.php');
$modal = file_get_contents(__DIR__ . '/../agent/modals/recurring_ticket/recurring_ticket_add.php');
preg_match_all("/<option>([A-Za-z ]+)<\/option>/", substr($modal, strpos($modal, 'name="frequency"'), 1200), $m);
$ui = array_map('strtolower', $m[1]);
$ok(count($ui) >= 6, 'form offers ' . implode(', ', $ui));
preg_match("/in_array\(\\\$frequency, \[(.*?)\], true\)/", $model, $w);
$allowed = array_map(fn ($x) => trim($x, " '"), explode(',', $w[1] ?? ''));
foreach ($ui as $u) { $ok(in_array($u, $allowed, true), "save accepts the form value '$u'"); }
preg_match("/chain|if \(\\\$frequency == \"three days\"\)/", $cron);
foreach ($allowed as $a) { $ok(str_contains($cron, "\$frequency == \"$a\""), "cron schedules '$a'"); }
$pos = strpos($cron, "in_array(\$frequency, ['three days'");
$ins = strpos($cron, 'INSERT INTO tickets', $pos);
$ok($pos !== false && $pos < $ins, 'cron rejects an unknown frequency before creating the ticket');
$ok(str_contains($cron, 'contact_client_id = $client_id') && strpos($cron, 'contact_client_id = $client_id') < $ins, 'cron drops a contact that is not the client\'s (MSP-DS-31)');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
