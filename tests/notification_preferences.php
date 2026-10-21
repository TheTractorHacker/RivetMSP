<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * In-app notification preferences (Preferences > In-app Notifications, DB 2.6.106) and the daily update check, on a SCRATCH database with the
 * real code (same rules as tests/endpoint_agent_lib.php: scratch DB named in config.php, throwaway Redis, never a live database):
 *   - every notification type the code creates belongs to a category; locked categories cannot be muted;
 *   - a muted category is never INSERTed (appNotify() fan-out, notifyUser(), the direct INSERT sites) nor published to the user's live stream,
 *     other categories and other users are unaffected, a missing column fails open;
 *   - the updater step 2.6.105 -> 2.6.106 (run twice) and db.sql parity;
 *   - the Preferences card (render, escaping, CSRF, locked keys never stored, bogus keys dropped) over real HTTP;
 *   - the update check runs once a day at UPDATE_CHECK_HOUR (03:00 app time), driven through the real claim against the scratch DB.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=rivetmsp_scratch_x RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway redis> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/notification_preferences.php
 *
 * The live-stream check needs that throwaway Redis to be running (redis-server --port <port> --save '' --appendonly no); without it that one
 * section prints SKIP and everything else still runs.
 */
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/database_version.php";
require_once "$root/includes/update_check_schedule.php";

ea_reset();
$tokens = ea_seed_users();   // users 1 admin, 10 tech, 11, 12, 13, 14, 16 (all active technicians)
$q("DELETE FROM notifications");
$q("UPDATE user_settings SET user_config_inapp_muted_types = NULL");
$count = fn (int $uid, string $type) => (int) $one("SELECT COUNT(*) FROM notifications WHERE notification_user_id = $uid AND notification_type = '" . $esc($type) . "'");
$mute = fn (int $uid, ?array $keys) => $q("UPDATE user_settings SET user_config_inapp_muted_types = " . ($keys === null ? 'NULL' : "'" . $esc(json_encode($keys)) . "'") . " WHERE user_id = $uid");
$read = fn (string $f) => (string) file_get_contents("$root/$f");

echo "\n--- categories cover every notification type the code creates ---\n";
$cats = inapp_notification_categories();
$allTypes = [];
foreach ($cats as $k => $c) {
    $ok(is_string($c['label'] ?? null) && is_string($c['icon'] ?? null) && is_string($c['hint'] ?? null) && is_array($c['types'] ?? null) && $c['types'] !== [], "category '$k' has a label, icon, hint and types");
    foreach ($c['types'] as $t) { $allTypes[] = $t; }
}
$ok(count($allTypes) === count(array_unique($allTypes)), 'no type is in two categories');
$locked = array_keys(array_filter($cats, fn ($c) => !empty($c['locked'])));
sort($locked);
$ok($locked === ['rmm_escalation', 'security'], 'exactly security and rmm_escalation are locked');
$ok(!in_array('security', inapp_mutable_category_keys(), true) && in_array('cron', inapp_mutable_category_keys(), true) && in_array('updates', inapp_mutable_category_keys(), true), 'cron and updates are mutable on their own, security is not');
$ok(inapp_category_for_type('Cron') === 'cron' && inapp_category_for_type('Update') === 'updates' && inapp_category_for_type('Mail') === 'mail' && inapp_category_for_type('Master Key') === 'security', 'Cron, Update, Mail and Master Key each land in their own category');

// Scan first-party PHP for literal notification types: appNotify('X', ...), notifyUser($uid, 'X', ...), INSERT INTO notifications ... notification_type = 'X', and
// EndpointEscalation's TYPE constant. A new type added anywhere without a category fails here.
$found = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname(); $rel = substr($p, strlen($root) + 1);
    if (!str_ends_with($p, '.php') || preg_match('#^(vendor|tests|node_modules|uploads|backups|ui_kits)/#', $rel)) continue;
    $src = (string) file_get_contents($p);
    if (preg_match_all('/\bappNotify\(\s*([\'"])([^\'"$]+)\1/', $src, $m)) { foreach ($m[2] as $t) { $found[$t][] = $rel; } }
    if (preg_match_all('/\bnotifyUser\((?!\s*\))\s*[^,]+,\s*([\'"])([^\'"$]+)\1/', $src, $m)) { foreach ($m[2] as $t) { $found[$t][] = $rel; } }   // (?!\)): not the "notifyUser()" of a comment
    if (preg_match_all('/INSERT INTO notifications SET notification_type = \'([^\'$]+)\'/', $src, $m)) { foreach ($m[1] as $t) { $found[$t][] = $rel; } }
}
if (preg_match("/const TYPE = '([^']+)'/", $read('src/Core/Adapter/Endpoint/EndpointEscalation.php'), $m)) { $found[$m[1]][] = 'src/Core/Adapter/Endpoint/EndpointEscalation.php'; }
$ok(count($found) >= 30, 'the scan found the notification types (' . count($found) . ' distinct)');
$unmapped = array_values(array_filter(array_keys($found), fn ($t) => inapp_category_for_type($t) === null));
$ok($unmapped === [], 'every notification type used in the code has an in-app category' . ($unmapped ? ' - missing: ' . implode(', ', $unmapped) : ''));
foreach (['Cron', 'Update', 'Ticket', 'Mail', 'Vault', 'RMM Alert Escalation', 'Recovery', 'Document Review Due', 'CRM Follow-up', 'Stale assets retired'] as $t) {
    $ok(isset($found[$t]), "the scan sees '$t' in the code");
}

echo "\n--- fail-open before DB 2.6.106 (new code, old database) ---\n";
$q("ALTER TABLE user_settings DROP COLUMN IF EXISTS user_config_inapp_muted_types");
$q("ALTER TABLE settings DROP COLUMN IF EXISTS config_update_check_last_at");
$q("UPDATE settings SET config_current_database_version = '2.6.105'");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);   // the web app's default (PHP 8.1+): a missing column THROWS, it does not just return false
appNotify('Cron', 'fail-open probe');
$ok($count(1, 'Cron') === 1 && $count(10, 'Cron') === 1, 'without the column every user still gets the notification');
notifyUser(10, 'Ticket', 'fail-open probe');
$ok($count(10, 'Ticket') === 1, 'notifyUser still delivers without the column');
$ok(inapp_muted_categories_by_user() === [] && inapp_muted_categories_for_user(10) === [], 'nothing reads as muted');
$ok(updateCheckClaim($db, time()) === false, 'without its column the update check is not claimed (no fallback to every run)');
mysqli_report(MYSQLI_REPORT_OFF);
$ok(inapp_muted_categories_for_user(10) === [] && updateCheckClaim($db, time()) === false, 'same answers with error reporting off');

echo "\n--- updater 2.6.105 -> 2.6.106 (run twice) and db.sql ---\n";
for ($i = 0; $i < 2; $i++) { shell_exec('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db </dev/null 2>&1'); }
$ok(LATEST_DATABASE_VERSION === '2.6.106', 'LATEST_DATABASE_VERSION is 2.6.106');
$ok((string) $one('SELECT config_current_database_version FROM settings') === LATEST_DATABASE_VERSION, 'the updater reaches the latest version');
$colA = $rows("SHOW COLUMNS FROM user_settings LIKE 'user_config_inapp_muted_types'")[0] ?? [];
$colB = $rows("SHOW COLUMNS FROM settings LIKE 'config_update_check_last_at'")[0] ?? [];
$ok(strtolower($colA['Type'] ?? '') === 'text' && ($colA['Null'] ?? '') === 'YES' && $colA['Default'] === null, 'user_settings.user_config_inapp_muted_types: TEXT NULL');
$ok(strtolower($colB['Type'] ?? '') === 'datetime' && ($colB['Null'] ?? '') === 'YES' && $colB['Default'] === null, 'settings.config_update_check_last_at: DATETIME NULL');
$ok((int) $one("SELECT COUNT(*) FROM user_settings WHERE user_config_inapp_muted_types IS NOT NULL") === 0, 'existing users start with nothing muted');
$ok(str_contains($read('db.sql'), "`user_config_inapp_muted_types` text DEFAULT NULL") && str_contains($read('db.sql'), "`config_update_check_last_at` datetime DEFAULT NULL"), 'db.sql carries both columns');

echo "\n--- a muted category is never created; others and other users are unaffected ---\n";
$q("DELETE FROM notifications");
$mute(10, ['cron']);
appNotify('Cron', 'Cron successfully executed', '/admin/audit_log.php');
$ok($count(10, 'Cron') === 0, 'appNotify: user 10 muted cron, no Cron row was stored for them');
$ok($count(1, 'Cron') === 1 && $count(12, 'Cron') === 1, 'appNotify: users who did not mute it still get Cron');
appNotify('Update', 'New Updates are Available [v9]', '/admin/update.php');
appNotify('Ticket', 'a new ticket');
$ok($count(10, 'Update') === 1 && $count(10, 'Ticket') === 1, 'user 10 still gets Update and Ticket (only cron is muted)');
notifyUser(10, 'Cron', 'targeted cron');
notifyUser(12, 'Cron', 'targeted cron');
$ok($count(10, 'Cron') === 0 && $count(12, 'Cron') === 2, 'notifyUser: muted user gets nothing, the other gets theirs');

$mute(12, ['updates']);
appNotify('Update', 'New Updates are Available [v10]', '/admin/update.php');
$ok($count(12, 'Update') === 1 && $count(10, 'Update') === 2, 'muting updates for user 12 stops new Update rows for 12 only');
appNotify('Cron', 'Cron successfully executed');
$ok($count(12, 'Cron') === 3, 'user 12 still gets Cron after muting updates');

$mute(10, ['cron', 'updates', 'mail', 'tickets', 'invoices']);
appNotify('Mail', 'Failed to send email #1');
appNotify('Invoice Paid', 'Invoice INV-1 paid');
appNotify('Quote Viewed', 'Quote viewed');
$ok($count(10, 'Mail') === 0 && $count(10, 'Invoice Paid') === 0 && $count(10, 'Quote Viewed') === 1, 'several muted categories at once; an unmuted one (quotes) still arrives');

echo "\n--- locked categories, unmapped types, malformed and hostile stored values ---\n";
$mute(10, ['security', 'rmm_escalation', 'cron']);
$ok(inapp_muted_categories_for_user(10) === ['cron'], 'locked keys in a stored list are ignored when read');
notifyUser(10, 'Vault', 'reveal limit');
notifyUser(10, 'RMM Alert Escalation', 'disk failing');
appNotify('Master Key', 'retrieved the master key');
$ok($count(10, 'Vault') === 1 && $count(10, 'RMM Alert Escalation') === 1 && $count(10, 'Master Key') === 1, 'security and RMM escalation notifications still arrive even if a stored list names them');
appNotify('Brand New Kind', 'a type with no category yet');
$ok($count(10, 'Brand New Kind') === 1, 'an unmapped type is always delivered');
$q("UPDATE user_settings SET user_config_inapp_muted_types = '{not json' WHERE user_id = 10");
$before = $count(10, 'Cron');
appNotify('Cron', 'probe');
$ok($count(10, 'Cron') === $before + 1, 'malformed stored JSON = nothing muted (fails open)');
$q("UPDATE user_settings SET user_config_inapp_muted_types = '" . $esc(json_encode(['cron' => 'x', 0 => ['tickets'], 1 => 5])) . "' WHERE user_id = 10");
$ok(inapp_muted_categories_for_user(10) === [], 'non-string entries and keys-as-values never count as muted');
$q("DELETE FROM user_settings WHERE user_id = 13");
$before = $count(13, 'Cron');
notifyUser(13, 'Cron', 'no settings row');
$ok($count(13, 'Cron') === $before + 1, 'a user with no user_settings row gets everything');
$q("INSERT INTO user_settings SET user_id = 13");

echo "\n--- the direct INSERT sites are gated too ---\n";
$mute(10, ['tickets']);
$ok(inapp_notification_allowed_for_user(10, 'Ticket') === false && inapp_notification_allowed_for_user(12, 'Ticket') === true, 'the gate the direct INSERT sites call answers per user');
foreach (['agent/post/task.php' => 2, 'client/post.php' => 1, 'guest/guest_post.php' => 1] as $f => $n) {
    $src = $read($f);
    $all = preg_match_all('/INSERT INTO notifications SET/', $src);
    $gated = preg_match_all('/if \(inapp_notification_allowed_for_user\(\$\w+, \'Ticket\'\)\) \{\s*\n\s*mysqli_query\(\$mysqli, "INSERT INTO notifications SET/', $src);
    $ok($all === $n && $gated === $n, "$f: all $n per-user notification INSERT(s) sit behind the preference check");
}
$fn = $read('functions.php');
$ok((bool) preg_match('/\$muted_by_user = inapp_muted_categories_by_user\(\);.*?if \(inapp_type_muted\(\$type, \$muted_by_user\[\$user_id\] \?\? \[\]\)\) \{\s*continue;\s*\}\s*mysqli_query\(\$mysqli, "INSERT INTO notifications/s', $fn), 'appNotify checks before its INSERT, publish and push');

echo "\n--- a muted notification is not published to the live stream either ---\n";
$redisPort = (int) getenv('RIVETMSP_REDIS_PORT');
$sock = @fsockopen('127.0.0.1', $redisPort, $en, $es, 0.5);
if ($sock && trim((string) (fwrite($sock, "PING\r\n") ? fgets($sock) : '')) === '+PONG') {
    fclose($sock);
    $mon = sys_get_temp_dir() . '/np_monitor_' . bin2hex(random_bytes(4)) . '.log';
    $proc = proc_open(['redis-cli', '-h', '127.0.0.1', '-p', (string) $redisPort, 'monitor'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $mon, 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    usleep(400000);
    $mute(10, ['cron']); $mute(12, null);
    appNotify('Cron', 'stream probe');
    usleep(400000);
    proc_terminate($proc); proc_close($proc);
    $log = (string) @file_get_contents($mon); @unlink($mon);
    $ok(str_contains($log, '"user.12.notifications"') && str_contains($log, 'stream probe'), 'the unmuted user\'s stream got the Cron notification');
    $ok(!str_contains($log, '"user.10.notifications"'), 'nothing was published to the muted user\'s stream');
} else {
    echo "SKIP  live-stream check: no throwaway Redis answering on 127.0.0.1:$redisPort\n";
}

echo "\n--- Preferences > In-app Notifications over HTTP ---\n";
$mute(10, ['cron']);
$sdir = sys_get_temp_dir() . '/np_sess_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/rmm_golden/router.php', [], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$sid = ea_forge_session($sdir, 10);
[$c, $page] = web($wb, 'GET', '/agent/user/user_preferences.php', $sid);
$ok($c === 200 && str_contains($page, 'id="inapp-notifications"'), 'the page renders the In-app Notifications card');
$ok(substr_count($page, 'name="inapp_categories[]"') === count($cats), 'one switch per category (' . count($cats) . ')');
$ok((bool) preg_match('/id="my_inapp_cat_cron"\s+name="inapp_categories\[\]" value="cron"\s+(?!checked)/', $page), 'the muted cron switch renders off');
$ok((bool) preg_match('/id="my_inapp_cat_updates"\s+name="inapp_categories\[\]" value="updates"\s+checked/', $page), 'an unmuted switch renders on');
$ok((bool) preg_match('/id="my_inapp_cat_security"\s+name="inapp_categories\[\]" value="security"\s+checked disabled/', $page), 'a locked switch renders on and disabled');
$ok(str_contains($page, '&quot;Cron successfully executed&quot;') && !str_contains($page, '"Cron successfully executed", after'), 'hint text is HTML-escaped');
$ok(!preg_match('/\b(alert|confirm|prompt)\(/', substr($page, (int) strpos($page, 'id="inapp-notifications"'), 6000)), 'the card uses no native dialog');

$REF = ['Referer: ' . $wb . '/agent/user/user_preferences.php'];
$enabledNow = array_values(array_diff(inapp_mutable_category_keys(), ['cron', 'updates']));
$body = http_build_query(['csrf_token' => 'csrftok1', 'save_my_inapp_categories' => '1']) . '&' . implode('&', array_map(fn ($k) => 'inapp_categories%5B%5D=' . rawurlencode($k), $enabledNow));
$post = function (string $raw, string $sid) use ($wb, $REF) {
    $ch = curl_init("$wb/agent/user/post.php");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIE => "PHPSESSID=$sid", CURLOPT_HTTPHEADER => $REF, CURLOPT_TIMEOUT => 60]);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return $code;
};
$code = $post($body, $sid);
$stored = json_decode((string) $one('SELECT user_config_inapp_muted_types FROM user_settings WHERE user_id = 10'), true);
sort($stored);
$ok(in_array($code, [200, 302], true) && $stored === ['cron', 'updates'], 'save: switches left off are stored as muted (cron, updates)');
$before10 = $count(10, 'Update'); $before1 = $count(1, 'Update');
appNotify('Update', 'after save');
$ok($count(10, 'Update') === $before10 && $count(1, 'Update') === $before1 + 1, 'after saving from the page, Update is no longer created for user 10 (user 1 still gets it)');

$post(http_build_query(['csrf_token' => 'csrftok1', 'save_my_inapp_categories' => '1']) . '&inapp_categories%5B%5D=tickets&inapp_categories%5B%5D=%3Cscript%3E&inapp_categories%5B%5D=security', $sid);
$stored = json_decode((string) $one('SELECT user_config_inapp_muted_types FROM user_settings WHERE user_id = 10'), true);
$ok(is_array($stored) && !in_array('security', $stored, true) && !in_array('rmm_escalation', $stored, true) && !in_array('<script>', $stored, true) && !in_array('tickets', $stored, true) && count($stored) === count(inapp_mutable_category_keys()) - 1, 'save: locked and unknown keys are never stored; only real mutable keys');

$post(http_build_query(['csrf_token' => 'csrftok1', 'save_my_inapp_categories' => '1', 'inapp_categories' => 'cron']), $sid);
$ok(count(json_decode((string) $one('SELECT user_config_inapp_muted_types FROM user_settings WHERE user_id = 10'), true) ?: []) === count(inapp_mutable_category_keys()), 'save: a scalar instead of a list is treated as nothing switched on (no error)');

$allOn = http_build_query(['csrf_token' => 'csrftok1', 'save_my_inapp_categories' => '1']) . '&' . implode('&', array_map(fn ($k) => 'inapp_categories%5B%5D=' . rawurlencode($k), inapp_mutable_category_keys()));
$post($allOn, $sid);
$ok($one('SELECT user_config_inapp_muted_types FROM user_settings WHERE user_id = 10') === null, 'save: everything on stores NULL (back to the default)');

$q("UPDATE user_settings SET user_config_inapp_muted_types = NULL WHERE user_id = 10");
$post(str_replace('csrftok1', 'wrongtok', http_build_query(['csrf_token' => 'csrftok1', 'save_my_inapp_categories' => '1'])), $sid);
$ok($one('SELECT user_config_inapp_muted_types FROM user_settings WHERE user_id = 10') === null, 'save: a wrong CSRF token changes nothing');
$sid12 = ea_forge_session($sdir, 12); $mute(12, null);
$post(http_build_query(['csrf_token' => 'csrftok1', 'save_my_inapp_categories' => '1', 'user_id' => '12']), $sid);
$ok($one('SELECT user_config_inapp_muted_types FROM user_settings WHERE user_id = 12') === null, 'save: only ever writes the signed-in user\'s row (a posted user_id is ignored)');
$ok($one('SELECT user_config_inapp_muted_types FROM user_settings WHERE user_id = 10') !== null, '...it wrote user 10 (the session user) instead');
$q("DELETE FROM user_settings WHERE user_id = 14");
$sid14 = ea_forge_session($sdir, 14);
$post(http_build_query(['csrf_token' => 'csrftok1', 'save_my_inapp_categories' => '1']), $sid14);
$ok($one('SELECT user_config_inapp_muted_types FROM user_settings WHERE user_id = 14') !== null, 'save: creates the user_settings row when it is missing');
$sidNone = 'eatestnone' . bin2hex(random_bytes(4));
$post(http_build_query(['csrf_token' => 'csrftok1', 'save_my_inapp_categories' => '1']), $sidNone);
$ok((int) $one('SELECT COUNT(*) FROM user_settings WHERE user_id = 0') === 0, 'save: no session, nothing written');

echo "\n--- update check: once a day at 03:00 app time ---\n";
$ok(UPDATE_CHECK_HOUR === 3, 'UPDATE_CHECK_HOUR is 3 (03:00, the overnight window of the backup 02:30 and restore drill 03:30/03:45 jobs)');
$cron = $read('cron/cron.php');
$ok(substr_count($cron, 'fetchUpdates()') === 1 && (bool) preg_match('/if \(updateCheckClaim\(\$mysqli, time\(\)\)\) \{\s*\$updates = fetchUpdates\(\);.*?appNotify\("Update"/s', $cron), 'cron/cron.php only fetches (and notifies) inside the daily claim');

$prevTz = date_default_timezone_get();
date_default_timezone_set('America/New_York');   // spans the 2026-11-01 daylight-saving change
$q("UPDATE settings SET config_update_check_last_at = NULL");
$start = (new DateTimeImmutable('2026-10-30 12:00:00'))->getTimestamp();
$end = (new DateTimeImmutable('2026-11-03 12:00:00'))->getTimestamp();
$claims = []; $ticks = 0;
for ($t = $start; $t <= $end; $t += 300) {   // a cron run every 5 minutes (deploy/install.sh)
    $ticks++;
    if (updateCheckClaim($db, $t)) { $claims[] = $t; }
}
$fmt = fn (int $t) => (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d H:i');
$got = array_map($fmt, $claims);
$ok($got === ['2026-10-30 12:00', '2026-10-31 03:00', '2026-11-01 03:00', '2026-11-02 03:00', '2026-11-03 03:00'], "$ticks five-minute cron runs over 4 days: one catch-up check, then exactly one a day at 03:00 (" . implode(', ', $got) . ')');
$gaps = []; for ($i = 2; $i < count($claims); $i++) { $gaps[] = $claims[$i] - $claims[$i - 1]; }
$ok($gaps === [90000, 86400, 86400], 'checks are a day apart (25 h across the fall-back night, otherwise 86400 s)');
$ok(count($claims) < 6 && $ticks > 1000, 'not hourly, not every run: ' . count($claims) . " checks for $ticks runs");

$q("UPDATE settings SET config_update_check_last_at = '2026-11-02 02:00:00'");
$ok(updateCheckClaim($db, (new DateTimeImmutable('2026-11-03 14:00:00'))->getTimestamp()) === true, 'missed 03:00 (cron off overnight): the next run that day checks');
$ok(updateCheckClaim($db, (new DateTimeImmutable('2026-11-03 14:05:00'))->getTimestamp()) === false, '...and the run after it does not');
$ok(updateCheckClaim($db, (new DateTimeImmutable('2026-11-04 02:59:59'))->getTimestamp()) === false, 'not before 03:00 the next day');
$ok(updateCheckClaim($db, (new DateTimeImmutable('2026-11-04 03:00:00'))->getTimestamp()) === true, 'at 03:00 the next day it checks');
$ok($one('SELECT config_update_check_last_at FROM settings') === '2026-11-04 03:00:00', 'the claim records the check time');
$ok(updateCheckLatestSlot((new DateTimeImmutable('2026-11-04 00:30:00'))->getTimestamp())->format('Y-m-d H:i') === '2026-11-03 03:00', 'before 03:00 the latest slot is yesterday 03:00');
date_default_timezone_set('UTC');
$q("UPDATE settings SET config_update_check_last_at = NULL");
$ok(updateCheckClaim($db, (new DateTimeImmutable('2026-11-05 03:00:00'))->getTimestamp()) === true && $one('SELECT config_update_check_last_at FROM settings') === '2026-11-05 03:00:00', 'the hour follows the app time zone (UTC here)');
date_default_timezone_set($prevTz);
$q("UPDATE settings SET config_update_check_last_at = NULL");
