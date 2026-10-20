<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Company-time helper (includes/rmm_time.php): zones, DST transitions (America/Chicago), UTC vs company-local storage, null/zero/invalid
 * safety, and the lint that keeps hard-coded "(UTC)" column headers out of the RMM / Endpoint agent / admin pages.   php tests/rmm_time.php
 */
$root = dirname(__DIR__);
require_once "$root/includes/rmm_time.php";
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { if ($c) { $pass++; } else { $fail++; echo "FAIL: $m\n"; } };
$tz = function (string $z) { $GLOBALS['config_timezone'] = $z; };

$tz('America/Chicago');
$ok(rivetRmmTime('2026-07-04 15:42:10') === '2026-07-04 10:42 CDT', 'UTC SQL value in July is CDT (UTC-5)');
$ok(rivetRmmTime('2026-01-15 15:42:10') === '2026-01-15 09:42 CST', 'UTC SQL value in January is CST (UTC-6)');
$ok(rivetRmmTime('2026-07-04T15:42:10Z') === '2026-07-04 10:42 CDT', 'RFC 3339 Z value');
$ok(rivetRmmTime('2026-07-04T15:42:10+02:00') === '2026-07-04 08:42 CDT', 'explicit offset wins over the storage flag');
$ok(rivetRmmTime('2026-07-04 10:42:10', false) === '2026-07-04 10:42 CDT', 'company-local value is only formatted, never converted twice');
$ok(rivetRmmTime('2026-01-15 10:42:10', false) === '2026-01-15 10:42 CST', 'company-local value gets the right label in winter');
$ok(rivetRmmTime(gmmktime(15, 42, 10, 7, 4, 2026)) === '2026-07-04 10:42 CDT', 'epoch int is an instant');
$ok(rivetRmmTime((string) gmmktime(15, 42, 10, 7, 4, 2026)) === '2026-07-04 10:42 CDT', 'numeric epoch string');
$ok(rivetRmmTime('2026-07-04 15:42:10', true, false) === '2026-07-04 10:42', 'label can be left out');
$ok(rivetRmmTime('2026-07-04 15:42:10', true, true, 'H:i:s') === '10:42:10 CDT', 'custom format');
$ok(rivetRmmDate('2026-07-05 02:00:00') === '2026-07-04', 'date only is the company date (UTC evening is still yesterday)');
// DST: Chicago springs forward 2026-03-08 08:00 UTC (02:00 CST -> 03:00 CDT) and falls back 2026-11-01 07:00 UTC (02:00 CDT -> 01:00 CST)
$ok(rivetRmmTime('2026-03-08 07:59:59') === '2026-03-08 01:59 CST', 'one second before spring forward');
$ok(rivetRmmTime('2026-03-08 08:00:00') === '2026-03-08 03:00 CDT', 'spring forward skips 02:00');
$ok(rivetRmmTime('2026-11-01 05:59:59') === '2026-11-01 00:59 CDT', 'before fall back, midnight hour is CDT');
$ok(rivetRmmTime('2026-11-01 06:30:00') === '2026-11-01 01:30 CDT', 'first 01:30 is CDT');
$ok(rivetRmmTime('2026-11-01 07:30:00') === '2026-11-01 01:30 CST', 'second 01:30 is CST (ambiguous wall time, distinct labels)');
$ok(rivetRmmTime('2026-11-01 08:00:00') === '2026-11-01 02:00 CST', 'after fall back');
$ok(rivetRmmTime('2026-03-08 02:30:00', false) === '2026-03-08 03:30 CDT', 'a local value inside the skipped hour is normalised, not crashed');
// other zones
$tz('Europe/London');
$ok(rivetRmmTime('2026-07-04 15:42:10') === '2026-07-04 16:42 BST' && rivetRmmTime('2026-01-15 15:42:10') === '2026-01-15 15:42 GMT', 'London BST / GMT');
$tz('Asia/Kolkata');
$ok(rivetRmmTime('2026-07-04 15:42:10') === '2026-07-04 21:12 IST', 'half-hour zone');
$tz('Australia/Lord_Howe');
$ok(rivetRmmTime('2026-07-15 00:00:00') === '2026-07-15 10:30 GMT+10:30', 'odd-offset zone without an abbreviation gets a GMT+ label: ' . rivetRmmTime('2026-07-15 00:00:00'));
$tz('UTC');
$ok(rivetRmmTime('2026-07-04 15:42:10') === '2026-07-04 15:42 UTC', 'a company set to UTC still shows UTC (honestly)');
$tz('Pacific/Auckland');
$ok(rivetRmmTime('2026-07-04 15:42:10') === '2026-07-05 03:42 NZST', 'date rolls over across zones');
// fallbacks
unset($GLOBALS['config_timezone']);
$_SESSION['session_timezone'] = 'America/Denver';
$ok(rivetRmmTime('2026-07-04 15:42:10') === '2026-07-04 09:42 MDT', 'session zone is the second source');
unset($_SESSION['session_timezone']);
date_default_timezone_set('America/Los_Angeles');
$ok(rivetRmmTime('2026-07-04 15:42:10') === '2026-07-04 08:42 PDT', 'server zone is the last fallback');
$tz('Not/AZone');
$ok(rivetRmmTime('2026-07-04 15:42:10') === '2026-07-04 08:42 PDT', 'an invalid configured zone falls through');
$tz('America/Chicago');
// null / zero / junk
foreach ([null, '', '   ', '0000-00-00 00:00:00', '0000-00-00', 0, '0', 'never', 'garbage', [], false, '2026-13-45 99:99:99'] as $i => $bad) {
    $ok(rivetRmmTime($bad) === '' && rivetRmmTime($bad, false, true, 'Y', '-') === '-', "empty/invalid input #$i yields the empty marker");
}
$ok(rivetRmmTimeParse(new DateTimeImmutable('2026-07-04 15:42:10', new DateTimeZone('UTC')))->format('H:i') === '10:42', 'DateTimeInterface input');
// tag + inputs
$ok(rivetRmmTimeTag('2026-07-04 15:42:10') === '<time datetime="2026-07-04T15:42:10Z">2026-07-04 10:42 CDT</time>', 'time tag keeps the UTC instant machine-readable');
$ok(rivetRmmTimeTag('2026-07-04 10:42:10', false) === '<time datetime="2026-07-04T15:42:10Z">2026-07-04 10:42 CDT</time>', 'time tag from a company-local value');
$ok(rivetRmmTimeTag(null, true, '<i>never</i>') === '<i>never</i>', 'time tag empty marker');
$ok(rivetRmmTimeInput('2026-07-04 15:42:10') === '2026-07-04T10:42', 'datetime-local value in company time');
$ok(rivetRmmTimeFromInput('2026-07-04T10:42') === '2026-07-04T15:42:00Z' && rivetRmmTimeFromInput('2026-01-15T10:42') === '2026-01-15T16:42:00Z', 'datetime-local back to UTC, DST aware');
$ok(rivetRmmTimeFromInput('nope') === null && rivetRmmTimeFromInput('') === null, 'bad input is null');
$ok(rivetRmmTime('<script>', true) === '', 'markup is not a date');

// ------------------------------------------------------------ lint: no hard-coded UTC labels on these pages
$pages = array_merge(
    ['admin/settings_endpoint_agent.php', 'agent/rmm_agent_device.php'],
    array_map(fn($f) => 'agent/' . basename($f), glob("$root/agent/rmm_*.php") ?: []),
    array_map(fn($f) => 'includes/' . basename($f), glob("$root/includes/rmm_*ui*.php") ?: []),
    ['includes/rmm_agent_build_ui.php', 'includes/rmm_agent_feed_ui.php', 'includes/rmm_agent_localbuild_ui.php', 'includes/rmm_mesh_admin.php', 'includes/rmm_scale_cards.php', 'includes/rmm_scale_admin.php', 'includes/rmm_automation.php']
);
// Allowed: input semantics that are genuinely UTC (cron expressions run in UTC) and the lines listed in tests/rmm_time_utc_allow.txt.
$allow = array_filter(array_map('trim', file("$root/tests/rmm_time_utc_allow.txt") ?: []), fn($l) => $l !== '' && $l[0] !== '#');
$bad = [];
foreach (array_unique($pages) as $rel) {
    $src = @file("$root/$rel");
    if ($src === false) { continue; }
    foreach ($src as $n => $line) {
        $code = trim($line);
        if ($code === '' || $code[0] === '*' || str_starts_with($code, '//') || str_starts_with($code, '/*')) { continue; }
        $code = preg_replace(['~\s//[^\'"]*$~', "~DateTimeZone\\('UTC'\\)~", '~UTC_TIMESTAMP~', "~strtotime\\((?:[^;]*?)' UTC'\\)~", "~/\\*\\*?[^*]*\\*/~"], '', $code);
        if (!preg_match('/\bUTC\b/', $code)) { continue; }
        $key = $rel . ' | ' . trim(substr($line, 0, 200));
        $hit = false;
        foreach ($allow as $a) { if (str_contains($key, $a)) { $hit = true; break; } }
        if (!$hit) { $bad[] = $rel . ':' . ($n + 1) . ' ' . trim(substr($line, 0, 120)); }
    }
}
$ok($bad === [], 'no hard-coded "(UTC)" / " UTC" label on the RMM, Endpoint agent and admin pages' . ($bad ? "\n  " . implode("\n  ", $bad) : ''));

echo "rmm_time: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
