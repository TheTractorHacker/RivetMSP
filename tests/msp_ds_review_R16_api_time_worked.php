<?php
// Review R16: api/v1/tickets.php reply and /time reject negative / oversized / malformed time_worked (MSP-DS-65 twin). Executes the real handler fragments.
// Run: php tests/msp_ds_review_R16_api_time_worked.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
function parseTimeWorked($input): ?string {   // verbatim contract of functions.php; asserted equal below
    if (!is_string($input) || !preg_match('/^(\d{1,3}):([0-5]\d):([0-5]\d)$/', trim($input), $m)) { return null; }
    $hours = intval($m[1]); if ($hours > 838) { return null; }
    return sprintf('%02d:%02d:%02d', $hours, intval($m[2]), intval($m[3]));
}
$fn = file_get_contents(__DIR__ . '/../functions.php');
$ok(str_contains($fn, "preg_match('/^(\\d{1,3}):([0-5]\\d):([0-5]\\d)\$/', trim(\$input), \$m)") && str_contains($fn, 'if ($hours > 838)'), 'stub matches functions.php parseTimeWorked');
function api_error($code, $msg) { throw new RuntimeException("$code:$msg"); }
$src = file_get_contents(__DIR__ . '/../api/v1/tickets.php');
$r0 = strpos($src, '// MSP-DS-65 (review R16): same range');
$r1 = strpos($src, '$time_w_raw = $time_w_parsed;', $r0);
$replyFrag = substr($src, $r0, strpos($src, "}\n", $r1) + 2 - $r0);
$t0 = strpos($src, "\$time_worked = parseTimeWorked(");
$t1 = strpos($src, "api_error(400, 'time_worked required", $t0);
$timeFrag = substr($src, $t0, strpos($src, "\n", $t0) - $t0) . "\n" . substr($src, strrpos(substr($src, 0, $t1), "if (\$time_worked"), strpos($src, "\n", $t1) - strrpos(substr($src, 0, $t1), "if (\$time_worked"));
$reply = function ($raw) use ($replyFrag) { $time_w_raw = $raw; try { eval($replyFrag); return $time_w_raw; } catch (RuntimeException $e) { return 'ERR'; } };
$time = function ($raw) use ($timeFrag) { $body = ['time_worked' => $raw]; try { eval($timeFrag); return $time_worked; } catch (RuntimeException $e) { return 'ERR'; } };
foreach (['-800:00:00', '999:00:00', '1:2', 'abc', '00:60:00', '-0:30:00'] as $bad) {
    $ok($reply($bad) === 'ERR', "reply refuses '$bad'"); $ok($time($bad) === 'ERR', "/time refuses '$bad'");
}
$ok($reply('') === '', 'reply: empty stays "no time"');
$ok($reply('1:30:00') === '01:30:00', 'reply: 1:30:00 normalised');
$ok($time('02:00:00') === '02:00:00', '/time: 02:00:00 accepted');
$ok($time('') === 'ERR', '/time: empty still refused');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
