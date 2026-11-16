<?php
// MSP-DS-153 (Comet settings read from $settings_row, comet.php includes totp via dirname), MSP-DS-154 (schedule dedupe list). Run: php tests/msp_ds_153_154_cron_fixes.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$cron = file_get_contents(__DIR__ . '/../cron/cron.php');
$ok(!preg_match('/config_comet_\w+\s*=.*\$row\[/', $cron), 'no Comet setting is read from $row');
$ok(preg_match_all('/\$config_comet_\w+\s*=.*\$settings_row\[/', $cron) >= 6, 'six Comet settings read from $settings_row');
$comet = file_get_contents(__DIR__ . '/../includes/comet.php');
$ok(!str_contains($comet, "DOCUMENT_ROOT'] . '/plugins/totp"), 'comet.php does not use DOCUMENT_ROOT (empty under CLI)');
$ok(substr_count($comet, "dirname(__DIR__) . '/plugins/totp/totp.php'") === 2, 'both TOTP includes use dirname(__DIR__)');
$ok(is_file(__DIR__ . '/../plugins/totp/totp.php'), 'the included file exists at that path');
preg_match("/in_array\(\\\$action\['action'\] \?\? '', \[(.*?)\], true\)/", $cron, $m);
foreach (['add_note', 'ai_triage', 'ai_sentiment', 'notify_assignee', 'escalate', 'dispatch_assign'] as $a) { $ok(str_contains($m[1] ?? '', "'$a'"), "schedule dedupe covers $a"); }
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
