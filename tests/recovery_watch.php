<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * Integration sync watcher (RivetMSP 26.10.20, DB 2.6.92): dormant integrations, real-error failures, per-integration overrides (mute, expected
 * interval), the 24 h repeat window, recovered notices, the panel and its handlers. Built from the exact false positives seen on a live install:
 * a UniFi sync that moved to an external daily script (94-day-old in-app runs), and an external per-minute loop that wrote the
 * "Endpoint agent devices are managed from the device page" error against the previous integration's row.
 * Part 1 is pure; Part 2 needs the migrated (2.6.92) scratch database:
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=...scratch... RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... [RIVETMSP_TEST_DB_SOCKET=...] php tests/recovery_watch.php
 */
require_once __DIR__ . '/../vendor/autoload.php';

use RivetMSP\Recovery\RecoveryAlerts;
use RivetMSP\Recovery\RecoverySettings;
use RivetMSP\Recovery\SyncWatch;
use RivetMSP\Recovery\WatchOverrides;

$fails = 0; $n = 0;
$ok = function (bool $c, string $l) use (&$fails, &$n) { $n++; echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

// ---------------------------------------------------------------- Part 1: pure rules
$now = 1_800_000_000; $DAY = 86400;
$run = fn (string $st, int $ago, ?string $err = null) => ['status' => $st, 'started' => $now - $ago, 'finished' => $now - $ago + 5, 'errors' => $err];
$S = SyncWatch::SENTINEL;

// the live UniFi case: scheduled in-app runs every 17 minutes that stopped 94 days ago
$unifi = [];
for ($i = 0; $i < 10; $i++) { $unifi[] = $run('success', 94 * $DAY + $i * 17 * 60); }
$interval = SyncWatch::inferInterval(array_column($unifi, 'started'));
$ok($interval === 17, 'the live UniFi case: the interval is inferred as 17 min from the old runs');
$e = SyncWatch::evaluate($unifi, $now - 400 * $DAY, $now, $interval, 3, 14, null);
$ok($e['state'] === 'dormant' && !$e['notice'] && str_starts_with($e['detail'], 'dormant since ') && $e['last_ts'] === $now - 94 * $DAY, 'UniFi, newest run 94 days old: dormant, no notice (it went quiet long ago), "dormant since <date>"');
$ok(SyncWatch::assess($unifi, null, $now, 17, 3)['state'] === 'stale', 'sanity: the OLD rule called that case stale (the 94-day email)');
$e = SyncWatch::evaluate([$run('success', 15 * $DAY)], null, $now, 15, 3, 14, null);
$ok($e['state'] === 'dormant' && $e['notice'] === true, 'just went dormant (15 days) after a good run: one notice is due');
$e = SyncWatch::evaluate([$run('failed', 15 * $DAY, 'boom')], null, $now, 15, 3, 14, null);
$ok($e['state'] === 'dormant' && $e['notice'] === false, 'went dormant after a failed run: it was not healthy before, no notice');
$e = SyncWatch::evaluate([$run('success', 13 * $DAY)], null, $now, 15, 3, 14, null);
$ok($e['state'] === 'stale', '13 days old with the default setting of 14: stale, not yet dormant');
$e = SyncWatch::evaluate([$run('success', 94 * $DAY)], null, $now, 15, 3, 0, null);
$ok($e['state'] === 'stale', 'dormant days 0 switches the dormant rule off');
$e = SyncWatch::evaluate([], $now - 30 * $DAY, $now, 15, 3, 14, null);
$ok($e['state'] === 'dormant' && !$e['notice'], 'never ran and set up 30 days ago: dormant');
$e = SyncWatch::evaluate([], $now - 600, $now, 15, 3, 14, null);
$ok($e['state'] === 'healthy', 'never ran, set up ten minutes ago: healthy (no runs yet)');

// overrides
$e = SyncWatch::evaluate([$run('success', 30 * 3600)], null, $now, 15, 3, 14, null);
$ok($e['state'] === 'stale', 'a daily external script looks stale against the global 15 min interval');
$e = SyncWatch::evaluate([$run('success', 30 * 3600)], null, $now, 15, 3, 14, ['muted' => false, 'interval_s' => 86400, 'note' => '']);
$ok($e['state'] === 'healthy' && $e['interval_s'] === 86400, 'declared "every 24 h": a run 30 h ago is healthy (limit 72 h)');
$e = SyncWatch::evaluate([$run('success', 80 * 3600)], null, $now, 15, 3, 14, ['muted' => false, 'interval_s' => 86400, 'note' => '']);
$ok($e['state'] === 'stale', 'declared "every 24 h": 80 h without a run is stale');
$e = SyncWatch::evaluate([$run('success', 94 * $DAY)], null, $now, 15, 3, 14, ['muted' => false, 'interval_s' => 86400, 'note' => '']);
$ok($e['state'] === 'stale', 'a DECLARED interval opts out of dormancy: a script that should run daily and is 94 days silent is stale');
$e = SyncWatch::evaluate([$run('failed', 60, 'x'), $run('failed', 120, 'x'), $run('failed', 180, 'x')], null, $now, 15, 3, 14, ['muted' => true, 'interval_s' => null, 'note' => 'retired']);
$ok($e['state'] === 'muted' && $e['detail'] === 'muted by an administrator', 'muted wins over failing');

// real errors only
$ok(SyncWatch::isSentinelError("x $S, not through an RMM client.") && SyncWatch::isSentinelError("Endpoint agent devices are managed from the device page, not through an RMM client.") && !SyncWatch::isSentinelError('HTTP 500') && !SyncWatch::isSentinelError(null), 'the sentinel is recognised');
$ok(SyncWatch::runErrored($run('failed', 60, 'HTTP 502'), $now) && !SyncWatch::runErrored($run('failed', 60, $S . ', not through an RMM client.'), $now) && !SyncWatch::runErrored($run('failed', 60, ''), $now) && !SyncWatch::runErrored($run('failed', 60, null), $now), 'a failed run counts only with a real, non-sentinel message');
$ok(SyncWatch::runErrored(['status' => 'failed', 'started' => $now - 60, 'finished' => null], $now), 'a caller that carries no message keeps the plain status rule');
$e = SyncWatch::evaluate([$run('failed', 60, 'HTTP 502'), $run('failed', 120, 'HTTP 502'), $run('failed', 180, 'HTTP 502')], null, $now, 15, 3, 14, null);
$ok($e['state'] === 'failing', 'three real errors: failing');
$e = SyncWatch::evaluate([$run('failed', 60, 'HTTP 502'), $run('failed', 120, ''), $run('failed', 180, 'HTTP 502')], null, $now, 15, 3, 14, null);
$ok($e['state'] === 'healthy', 'a failed row with no message in between is not a real error');
$ok(SyncWatch::humanInterval(86400) === '1 day' && SyncWatch::humanInterval(172800) === '2 days' && SyncWatch::humanInterval(7200) === '2 h' && SyncWatch::humanInterval(1020) === '17 min', 'intervals are shown in words');
$ok(WatchOverrides::cleanInterval(0) === null && WatchOverrides::cleanInterval(-5) === null && WatchOverrides::cleanInterval(5) === 60 && WatchOverrides::cleanInterval(86400) === 86400 && WatchOverrides::cleanInterval(9_999_999_999) === 2592000, 'declared intervals are clamped to 1 minute .. 30 days');
$ok(SyncWatch::UNWATCHED_RMM_TYPES === ['rivetit_agent'], 'rivetit_agent is the unwatched type');

if (getenv('RIVETMSP_TEST_DB') !== '1') { echo $fails === 0 ? "ALL $n PASSED (pure part only)\n" : "$fails FAILED\n"; exit($fails === 0 ? 0 : 1); }

// ---------------------------------------------------------------- Part 2: scratch database (migrated to 2.6.92)
if (!preg_match('/scratch|test/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'), 3306, getenv('RIVETMSP_TEST_DB_SOCKET') ?: null);
if ($mysqli->connect_errno) { fwrite(STDERR, "cannot connect: {$mysqli->connect_error}\n"); exit(2); }
$mysqli->set_charset('utf8mb4');
$q = fn (string $sql) => $mysqli->query($sql);
$one = fn (string $sql) => ($r = $mysqli->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;

$GLOBALS['notified'] = []; $GLOBALS['mailed'] = []; $GLOBALS['events'] = []; $GLOBALS['logged'] = [];
function appNotify($t, $d, $a = null, $c = 0, $e = 0, $p = true) { $GLOBALS['notified'][] = $d; }
function logApp($c, $t, $d) {}
function addToMailQueue($data) { foreach ($data as $e) { $GLOBALS['mailed'][] = $e; } }
function rivetEmitEvent(string $e, array $d): void { $GLOBALS['events'][] = $e; }
function validateCSRFToken($t) {}
function flash_alert($m, $t = 'success') { $GLOBALS['flash'][] = [$t, $m]; }
function logAction($a, $b, $c) { $GLOBALS['logged'][] = "$a/$b: $c"; }
class RedirectSignal extends Exception {}
function redirect(...$a) { throw new RedirectSignal(); }
function nullable_htmlentities($s) { return htmlentities((string) $s, ENT_QUOTES, 'UTF-8'); }

$mails = fn () => count($GLOBALS['mailed']);
$reset = function () use ($q) {
    foreach (['recovery_alerts', 'recovery_settings', 'rmm_sync_log', 'unifi_sync_log', 'rmm_integrations', 'unifi_integrations', 'recovery_watch_overrides'] as $t) { $q("DELETE FROM `$t`"); }
    $GLOBALS['notified'] = []; $GLOBALS['mailed'] = []; $GLOBALS['events'] = []; $GLOBALS['logged'] = []; $GLOBALS['flash'] = [];
};
$reset();
$q("INSERT INTO user_roles (role_name, role_is_admin) VALUES ('Watch test admin', 1)");
$q("INSERT INTO users (user_name, user_email, user_password, user_role_id, user_type, user_status) VALUES ('Adm', 'admin@example.test', 'x', " . (int) $mysqli->insert_id . ", 1, 1)");
$q("UPDATE settings SET config_mail_from_email = 'noreply@example.test', config_mail_from_name = 'RivetMSP' WHERE company_id = 1");
$rmm = fn (int $id, string $name, string $type = 'tactical_rmm', int $createdDaysAgo = 400) => $q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES ($id, '" . $mysqli->real_escape_string($name) . "', '$type', 'https://x', 'x', 1, NOW() - INTERVAL $createdDaysAgo DAY)");
$log = fn (int $id, string $st, string $ago, ?string $err = null) => $q("INSERT INTO rmm_sync_log (integration_id, started_at, finished_at, status, errors, triggered_by) VALUES ($id, NOW() - INTERVAL $ago, NOW() - INTERVAL $ago, '$st', " . ($err === null ? 'NULL' : "'" . $mysqli->real_escape_string($err) . "'") . ', 0)');
$byId = function (array $snap, string $src, int $id) { foreach ($snap as $r) { if ($r['source'] === $src && $r['id'] === $id) return $r; } return null; };

$ok($one("SHOW TABLES LIKE 'recovery_watch_overrides'") === 'recovery_watch_overrides' && WatchOverrides::ready($mysqli), 'table recovery_watch_overrides exists (DB 2.6.92)');
$ok(RecoverySettings::get($mysqli, 'sync_dormant_days') === '14' && RecoverySettings::get($mysqli, 'alert_repeat_hours') === '24' && RecoveryAlerts::repeatHours($mysqli) === 24, 'defaults: dormant after 14 days, repeat after 24 h');

// ---- the live UniFi case, against the real tables
$q("INSERT INTO unifi_integrations (id, name, api_key_enc, enabled, created_at) VALUES (951, 'Foley IT', 'x', 1, NOW() - INTERVAL 400 DAY)");
for ($i = 0; $i < 8; $i++) { $q("INSERT INTO unifi_sync_log (integration_id, started_at, finished_at, status, triggered_by) VALUES (951, NOW() - INTERVAL " . (94 * 1440 + $i * 17) . " MINUTE, NOW() - INTERVAL " . (94 * 1440 + $i * 17) . " MINUTE, 'success', 0)"); }
$p = SyncWatch::run($mysqli, true);
$snap = SyncWatch::snapshot($mysqli, true);
$u = $byId($snap, 'unifi', 951);
$ok($p === [] && $mails() === 0 && $GLOBALS['notified'] === [] && $u && $u['state'] === 'dormant' && str_contains($u['detail'], 'dormant since '), 'UniFi with 94-day-old in-app runs: dormant, NO email, no notification');
SyncWatch::run($mysqli, true); SyncWatch::run($mysqli, true);
$ok($mails() === 0 && (int) $one("SELECT COUNT(*) FROM recovery_alerts") === 0, '... and still nothing on later ticks');

// ---- the live Sophos case: an external loop wrote the sentinel against its row, mixed with its own runs; rivetit_agent never watched
$rmm(902, 'Sophos Firewalls', 'sophos_central'); $rmm(903, 'Endpoint agents', 'rivetit_agent');
for ($i = 1; $i <= 6; $i++) { $log(902, 'failed', "$i MINUTE", "Endpoint agent devices are managed from the device page, not through an RMM client."); }
$log(902, 'success', '7 MINUTE'); $log(902, 'success', '40 MINUTE');
for ($i = 1; $i <= 5; $i++) { $log(903, 'failed', "$i MINUTE", "Endpoint agent devices are managed from the device page, not through an RMM client."); }
$p = SyncWatch::run($mysqli, true);
$snap = SyncWatch::snapshot($mysqli, true);
$s = $byId($snap, 'rmm', 902);
$ok($s && $s['state'] === 'healthy' && $s['runs'] === 2 && $byId($snap, 'rmm', 903) === null && $mails() === 0, 'Sophos with sentinel-error rows mixed in: not failing (the sentinel rows are not its runs); the rivetit_agent integration is not watched at all');
// Sophos with ONLY sentinel rows and an old real run: the sentinel rows do not keep it fresh
$q("DELETE FROM rmm_sync_log WHERE integration_id = 902 AND status = 'success'");
$p = SyncWatch::run($mysqli, true);
$s = $byId(SyncWatch::snapshot($mysqli, true), 'rmm', 902);
$ok($s && $s['state'] === 'dormant' && $s['runs'] === 0 && $mails() === 0, 'only sentinel rows: no runs of its own (they do not keep it fresh), not failing, dormant since it was set up, no email');
// a type-less 'failed' row with no message is not an error either
$q("DELETE FROM rmm_sync_log"); for ($i = 1; $i <= 4; $i++) { $log(902, 'failed', "$i MINUTE", null); } $log(902, 'success', '1 MINUTE');
$ok(SyncWatch::run($mysqli, true) === [] && $mails() === 0, 'failed rows with no message are not real errors');
$q("DELETE FROM rmm_sync_log"); $q("DELETE FROM rmm_integrations"); $q("DELETE FROM unifi_sync_log"); $q("DELETE FROM unifi_integrations"); $reset();

// ---- a genuinely failing integration: one email, then nothing for 24 h, immediate first occurrence, recovered once
$rmm(904, 'Tactical Main');
$log(904, 'success', '2 HOUR');
foreach ([30, 20, 10] as $m) { $log(904, 'failed', "$m MINUTE", 'HTTP 502 from the vendor API'); }
$p = SyncWatch::run($mysqli, true);
$ok(count($p) === 1 && $p[0]['state'] === 'failing' && $p[0]['alerted'] && $mails() === 1 && $GLOBALS['events'] === ['integration.sync_failed'] && count($GLOBALS['notified']) === 1, 'three real errors: ONE email, one notification, integration.sync_failed (immediate)');
SyncWatch::run($mysqli, true); SyncWatch::run($mysqli, true);
$ok($mails() === 1, 'the next ticks: no more mail');
$q("UPDATE recovery_alerts SET alert_last_sent_at = NOW() - INTERVAL 23 HOUR + INTERVAL 5 MINUTE WHERE alert_key = 'sync.rmm.904.failing'");
SyncWatch::run($mysqli, true);
$ok($mails() === 1, 'at 22 h 55 min: still suppressed (was 6 h)');
$q("UPDATE recovery_alerts SET alert_last_sent_at = NOW() - INTERVAL 24 HOUR - INTERVAL 1 MINUTE WHERE alert_key = 'sync.rmm.904.failing'");
SyncWatch::run($mysqli, true);
$ok($mails() === 2 && (int) $one("SELECT alert_send_count FROM recovery_alerts WHERE alert_key = 'sync.rmm.904.failing'") === 2, 'after 24 h the same problem is mailed again');
// the repeat window is a setting
RecoverySettings::set($mysqli, 'alert_repeat_hours', '48');
$q("UPDATE recovery_alerts SET alert_last_sent_at = NOW() - INTERVAL 30 HOUR WHERE alert_key = 'sync.rmm.904.failing'");
SyncWatch::run($mysqli, true);
$ok($mails() === 2, 'repeat window set to 48 h: 30 h is still suppressed');
$q("UPDATE recovery_alerts SET alert_last_sent_at = NOW() - INTERVAL 49 HOUR WHERE alert_key = 'sync.rmm.904.failing'");
SyncWatch::run($mysqli, true);
$ok($mails() === 3, '... and 49 h repeats');
RecoverySettings::set($mysqli, 'alert_repeat_hours', '24');
// recovery announced once
$log(904, 'success', '1 MINUTE');
$p = SyncWatch::run($mysqli, true);
$ok($p === [] && $mails() === 4 && str_contains($GLOBALS['mailed'][3]['subject'], 'working again') && (int) $one("SELECT COUNT(*) FROM recovery_alerts WHERE alert_key LIKE 'sync.rmm.904.%'") === 0, 'it recovers: ONE "working again" notice and the alert state is forgotten');
SyncWatch::run($mysqli, true); SyncWatch::run($mysqli, true);
$ok($mails() === 4, '... the recovery is announced only once');
// failing again right after a recovery alerts immediately
foreach ([5, 4, 3] as $m) { $log(904, 'failed', "$m SECOND", 'HTTP 500 again'); }
$q("DELETE FROM rmm_sync_log WHERE status = 'success'");
$p = SyncWatch::run($mysqli, true);
$ok(count($p) === 1 && $p[0]['alerted'] && $mails() === 5, 'a new failure after a recovery is mailed immediately (first occurrence)');
$q("DELETE FROM rmm_sync_log"); $q("DELETE FROM rmm_integrations"); $reset();

// ---- a quiet integration that is healthy again after a stale alert gets one recovered notice; no notice for an alert never sent
$rmm(905, 'Level');
$log(905, 'success', '5 HOUR');
$p = SyncWatch::run($mysqli, true);
$ok(count($p) === 1 && $p[0]['state'] === 'stale' && $mails() === 1 && $GLOBALS['events'] === ['integration.sync_stale'], 'stale (5 h, expected 15 min): one email, integration.sync_stale');
$log(905, 'success', '1 MINUTE');
SyncWatch::run($mysqli, true);
$ok($mails() === 2 && str_contains($GLOBALS['mailed'][1]['subject'], 'running again'), 'running again: one recovered notice');
$q("DELETE FROM rmm_sync_log"); $q("DELETE FROM recovery_alerts"); $GLOBALS['mailed'] = [];
// stale -> failing does not leave two alerts that would both announce recovery
$log(905, 'success', '5 HOUR'); SyncWatch::run($mysqli, true);
foreach ([3, 2, 1] as $m) { $log(905, 'failed', "$m MINUTE", 'bad token'); }
SyncWatch::run($mysqli, true);
$ok($one("SELECT GROUP_CONCAT(alert_key) FROM recovery_alerts") === 'sync.rmm.905.failing', 'stale then failing: only the failing alert state remains');
$q("DELETE FROM rmm_sync_log"); $q("DELETE FROM rmm_integrations"); $reset();

// ---- expected-interval override: a daily external script
$rmm(906, 'Daily script', 'tactical_rmm');
$log(906, 'success', '26 HOUR');
SyncWatch::run($mysqli, true);
$ok($mails() === 1, 'without a declaration, a daily script (last run 26 h ago) is stale and mailed');
$GLOBALS['mailed'] = []; $q("DELETE FROM recovery_alerts");
$ok(WatchOverrides::set($mysqli, 'rmm', 906, false, 86400, 'synced by /opt/scripts/x.py', 1), 'the override is saved');
SyncWatch::run($mysqli, true);
$r = $byId(SyncWatch::snapshot($mysqli, true), 'rmm', 906);
$ok($mails() === 0 && $r['state'] === 'healthy' && $r['interval_s'] === 86400, 'declared every 24 h: the same integration is healthy, no email');
$q("UPDATE rmm_sync_log SET started_at = NOW() - INTERVAL 80 HOUR");
SyncWatch::run($mysqli, true);
$ok($mails() === 1 && $GLOBALS['events'][count($GLOBALS['events']) - 1] === 'integration.sync_stale', 'declared every 24 h and silent for 80 h: stale, mailed');
$q("UPDATE rmm_sync_log SET started_at = NOW() - INTERVAL 94 DAY");
$GLOBALS['mailed'] = []; $q("DELETE FROM recovery_alerts");
SyncWatch::run($mysqli, true);
$r = $byId(SyncWatch::snapshot($mysqli, true), 'rmm', 906);
$ok($r['state'] === 'stale' && $mails() === 1, 'declared and 94 days silent: still stale (a declared interval is never dormant)');
$ok(WatchOverrides::set($mysqli, 'rmm', 906, false, null, '', 1) && (int) $one('SELECT COUNT(*) FROM recovery_watch_overrides') === 0, 'an override with nothing set is removed');
$q("DELETE FROM rmm_sync_log"); $q("DELETE FROM rmm_integrations"); $reset();

// ---- mute
$rmm(907, 'Muted one');
foreach ([30, 20, 10] as $m) { $log(907, 'failed', "$m MINUTE", 'HTTP 502'); }
WatchOverrides::mute($mysqli, 'rmm', 907, 1);
$p = SyncWatch::run($mysqli, true);
$r = $byId(SyncWatch::snapshot($mysqli, true), 'rmm', 907);
$ok($p === [] && $mails() === 0 && $r['state'] === 'muted', 'a muted failing integration: state muted, no email');
WatchOverrides::set($mysqli, 'rmm', 907, false, null, 'note stays', 1);
$p = SyncWatch::run($mysqli, true);
$ok(count($p) === 1 && $mails() === 1, 'unmuted: it is reported (no stale cached state)');
$q("DELETE FROM rmm_sync_log"); $q("DELETE FROM rmm_integrations"); $reset();

// ---- dormant notice: only if healthy before, once per 30 days
$rmm(908, 'Went quiet'); $log(908, 'success', '15 DAY');
SyncWatch::run($mysqli, true);
$ok($mails() === 1 && str_contains($GLOBALS['mailed'][0]['subject'], 'no longer running') && str_contains($GLOBALS['mailed'][0]['subject'], 'dormant since'), 'went dormant after a good run: ONE "no longer running" notice');
SyncWatch::run($mysqli, true); SyncWatch::run($mysqli, true);
$q("UPDATE recovery_alerts SET alert_last_sent_at = NOW() - INTERVAL 29 DAY WHERE alert_key = 'sync.rmm.908.dormant'");
SyncWatch::run($mysqli, true);
$ok($mails() === 1, '... not again within 30 days (29 days later: still nothing)');
$q("UPDATE recovery_alerts SET alert_last_sent_at = NOW() - INTERVAL 31 DAY WHERE alert_key = 'sync.rmm.908.dormant'");
SyncWatch::run($mysqli, true);
$ok($mails() === 2, '... and once more after 30 days');
$log(908, 'success', '1 MINUTE'); SyncWatch::run($mysqli, true);
$ok($mails() === 2 && (int) $one("SELECT COUNT(*) FROM recovery_alerts WHERE alert_key LIKE 'sync.rmm.908.%'") === 0, 'it runs again: dormant state cleared with no recovered notice');
$q("DELETE FROM rmm_sync_log"); $log(908, 'failed', '15 DAY', 'boom'); $GLOBALS['mailed'] = []; $q("DELETE FROM recovery_alerts");
SyncWatch::run($mysqli, true);
$ok($mails() === 0, 'dormant after a FAILED last run: no notice (it was not healthy before)');
$ok(SyncWatch::run($mysqli, false) === [] && $byId(SyncWatch::snapshot($mysqli, false), 'rmm', 908) === null, 'RMM is not watched while the RMM module is off');
$q("DELETE FROM rmm_sync_log"); $q("DELETE FROM rmm_integrations"); $reset();

// ---- forget history
$rmm(909, 'Old'); $rmm(910, 'Other'); $log(909, 'success', '40 DAY'); $log(909, 'success', '41 DAY'); $log(910, 'success', '1 MINUTE');
$q("INSERT INTO recovery_alerts (alert_key, alert_last_sent_at) VALUES ('sync.rmm.909.stale', NOW())");
$ok(WatchOverrides::forgetHistory($mysqli, 'rmm', 909) === 2 && (int) $one("SELECT COUNT(*) FROM rmm_sync_log WHERE integration_id = 910") === 1 && (int) $one("SELECT COUNT(*) FROM recovery_alerts") === 0, 'forget history removes only that integration\'s runs and its alert state');
$ok(WatchOverrides::forgetHistory($mysqli, 'bogus', 910) === 0 && WatchOverrides::forgetHistory($mysqli, 'rmm', 0) === 0, 'forget history refuses an unknown source / id');
$q("DELETE FROM rmm_sync_log"); $q("DELETE FROM rmm_integrations"); $reset();

// ---- a signed-in page sets the session time zone to the user's offset; the verdict must not move with it
$rmm(920, 'TZ probe'); $log(920, 'success', '2 MINUTE');
$tzOf = fn () => (string) $one('SELECT @@session.time_zone');
$st0 = $byId(SyncWatch::snapshot($mysqli, true), 'rmm', 920);
$q("SET SESSION time_zone = '-10:00'");
$st1 = $byId(SyncWatch::snapshot($mysqli, true), 'rmm', 920);
$ok($st0['state'] === 'healthy' && $st1['state'] === 'healthy' && $st1['last_ts'] === $st0['last_ts'] && $tzOf() === '-10:00', 'time zone: the snapshot is the same under a user offset of -10:00, and the session zone is restored');
$q("SET SESSION time_zone = SYSTEM"); $q("DELETE FROM rmm_sync_log"); $q("DELETE FROM rmm_integrations");

// ---- the panel and its handlers
$appRoot = realpath(__DIR__ . '/..');
define('FROM_POST_HANDLER', true);
$_SESSION = ['csrf_token' => 'tok123'];
$session_name = 'Test Admin'; $session_user_id = 7; $dbusername = 'x'; $backup_dir = sys_get_temp_dir();
$config_module_enable_rmm = 1;
$rmm(911, 'Edge <script>alert(1)</script>', 'tactical_rmm'); $log(911, 'success', '40 DAY');
$rmm(912, 'Failing GW', 'sophos_central'); foreach ([3, 2, 1] as $m) { $log(912, 'failed', "$m MINUTE", 'HTTP 401'); }
$rmm(913, 'Healthy', 'level'); $log(913, 'success', '1 MINUTE');
$rmm(914, 'Quiet daily', 'tactical_rmm'); $log(914, 'success', '30 HOUR'); WatchOverrides::set($mysqli, 'rmm', 914, false, 86400, 'by <b>script</b>', 1);
$rmm(915, 'Muted one', 'tactical_rmm'); WatchOverrides::mute($mysqli, 'rmm', 915, 1);
$rmm(916, 'Stale one', 'action1'); $log(916, 'success', '5 HOUR');
$rmm(917, 'Agents', 'rivetit_agent');
$q("INSERT INTO unifi_integrations (id, name, api_key_enc, enabled, created_at) VALUES (952, 'UniFi Old', 'x', 1, NOW() - INTERVAL 400 DAY)");
ob_start(); include $appRoot . '/admin/backup_recovery_panel.php'; $html = ob_get_clean();
$q = fn (string $sql) => $mysqli->query($sql);   // the panel reuses $q
$ok(str_contains($html, 'Integration sync watcher') && str_contains($html, 'id="sync-watch"'), 'panel: the watcher card renders');
foreach (['dormant' => 'secondary', 'failing' => 'danger', 'healthy' => 'success', 'stale' => 'warning', 'muted' => 'info'] as $st => $cls) {
    $ok(str_contains($html, '<span class="badge text-bg-' . $cls . '">' . $st . '</span>'), "panel: a '$st' badge is shown");
}
$ok(str_contains($html, 'Forget history') && str_contains($html, 'name="mute_watch"') && str_contains($html, 'name="unmute_watch"') && str_contains($html, 'name="save_watch_override"'), 'panel: Mute, Unmute, Forget history and the edit form are offered');
$ok(!str_contains($html, '<script>alert(1)') && str_contains($html, '&lt;script&gt;alert(1)') && !str_contains($html, '<b>script</b>') && str_contains($html, 'by &lt;b&gt;script&lt;/b&gt;'), 'panel: names and notes are HTML-escaped');
$ok(str_contains($html, 'every 1 day') && str_contains($html, '(declared)') && str_contains($html, 'every 15 min'), 'panel: expected interval shown (declared and default)');
$ok(str_contains($html, 'dormant since ') && str_contains($html, 'UniFi Old') && !str_contains($html, '>Agents<') && substr_count($html, 'name="csrf_token" value="tok123"') >= 7 && !str_contains($html, 'onclick='), 'panel: dormant reason, UniFi row, no rivetit_agent row, CSRF tokens, no inline handlers');
$ok(str_contains($html, 'name="sync_dormant_days"') && str_contains($html, 'name="alert_repeat_hours"'), 'panel: the dormant-days and repeat-hours settings are there');

$post = function (array $p) use ($appRoot, $mysqli, &$session_name, &$session_user_id, &$dbusername): bool {
    $_POST = $p; $GLOBALS['flash'] = [];
    try { include $appRoot . '/admin/post/backup_recovery.php'; } catch (RedirectSignal $e) { return true; }
    return false;
};
$w = fn (array $extra) => $extra + ['csrf_token' => 'tok123', 'watch_source' => 'rmm', 'watch_integration_id' => 913];
$GLOBALS['logged'] = [];
$post($w(['save_watch_override' => '1', 'watch_expected_hours' => '24', 'watch_note' => "synced   by\nscript", 'watch_muted' => '1']));
$o = WatchOverrides::all($mysqli)['rmm:913'] ?? null;
$ok($o && $o['muted'] && $o['interval_s'] === 86400 && $o['note'] === 'synced by script', 'handler: save stores mute, 24 h and a one-line note');
$ok(count($GLOBALS['logged']) === 1 && str_contains($GLOBALS['logged'][0], 'Test Admin') && str_contains($GLOBALS['logged'][0], 'integration #913 (Healthy)') && str_contains($GLOBALS['logged'][0], 'muted') && str_contains($GLOBALS['logged'][0], '24 h'), 'handler: the change is audited (who, which integration, new values)');
$post($w(['unmute_watch' => '1']));
$o = WatchOverrides::all($mysqli)['rmm:913'] ?? null;
$ok($o && !$o['muted'] && $o['interval_s'] === 86400 && str_contains(end($GLOBALS['logged']), 'unmuted'), 'handler: unmute keeps the declared interval and is audited');
$post($w(['save_watch_override' => '1', 'watch_expected_hours' => '999999', 'watch_note' => str_repeat('n', 400)]));
$o = WatchOverrides::all($mysqli)['rmm:913'];
$ok($o['interval_s'] === 2592000 && strlen($o['note']) === 255, 'handler: interval clamped to 30 days, note to 255');
$post($w(['save_watch_override' => '1']));
$ok(!isset(WatchOverrides::all($mysqli)['rmm:913']), 'handler: saving with nothing set removes the override');
$post($w(['mute_watch' => '1', 'watch_integration_id' => 911]));
$ok((WatchOverrides::all($mysqli)['rmm:911']['muted'] ?? false) === true && str_contains(end($GLOBALS['logged']), 'muted the sync watcher'), 'handler: Mute is one click and audited');
$before = count($GLOBALS['logged']);
$post($w(['mute_watch' => '1', 'watch_integration_id' => 99999]));
$post($w(['mute_watch' => '1', 'watch_source' => 'evil`; DROP', 'watch_integration_id' => 911]));
$ok(count($GLOBALS['logged']) === $before && !isset(WatchOverrides::all($mysqli)['rmm:99999']) && $GLOBALS['flash'][0][0] === 'error', 'handler: an unknown integration or source is refused, nothing audited');
$runsBefore = (int) $one('SELECT COUNT(*) FROM rmm_sync_log WHERE integration_id = 911');
$post($w(['forget_watch_history' => '1', 'watch_integration_id' => 911]));
$ok((int) $one('SELECT COUNT(*) FROM rmm_sync_log WHERE integration_id = 911') === $runsBefore && $runsBefore === 1, 'handler: forget history without the confirmation box removes nothing');
$post($w(['forget_watch_history' => '1', 'watch_confirm' => '1', 'watch_integration_id' => 911]));
$ok((int) $one('SELECT COUNT(*) FROM rmm_sync_log WHERE integration_id = 911') === 0 && str_contains(end($GLOBALS['logged']), 'forgot the sync history') && str_contains(end($GLOBALS['logged']), '1 log rows'), 'handler: forget history (confirmed) removes the runs and is audited');
$post(['csrf_token' => 'tok123', 'save_recovery_settings' => '1', 'sync_dormant_days' => '9999', 'alert_repeat_hours' => '0', 'sync_error_threshold' => '3', 'backup_stale_hours' => '26', 'sync_interval_rmm' => '15', 'sync_interval_unifi' => 'auto']);
$ok(RecoverySettings::get($mysqli, 'sync_dormant_days') === '365' && RecoverySettings::get($mysqli, 'alert_repeat_hours') === '1', 'handler: dormant days and repeat hours are clamped (365 / 1)');
$post(['csrf_token' => 'tok123', 'save_recovery_settings' => '1', 'sync_dormant_days' => '21', 'alert_repeat_hours' => '12']);
$ok(RecoverySettings::get($mysqli, 'sync_dormant_days') === '21' && RecoverySettings::get($mysqli, 'alert_repeat_hours') === '12', 'handler: the new settings save');

// ---- cleanup
$q("DELETE FROM rmm_sync_log"); $q("DELETE FROM rmm_integrations"); $q("DELETE FROM unifi_integrations"); $reset();
$q("DELETE FROM users WHERE user_email = 'admin@example.test'"); $q("DELETE FROM user_roles WHERE role_name = 'Watch test admin'");
echo $fails === 0 ? "ALL $n PASSED\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
