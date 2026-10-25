<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Server status page (GitHub roadmap #12's remaining gap - the Maintenance page's Server section only had
 * Redis; this adds CPU load, memory and disk), against the REAL code on a SCRATCH database, real HTTP
 * (same harness as tests/security_*.php).
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/server_status.php
 *
 * Covers: RivetMSP\Ops\ServerStatus's three resource checks directly (CPU/memory/disk each return a
 * well-formed ok/warn/fail row, never throw even when something is unreadable); the admin page renders all
 * three plus the application/database checks; a non-admin is refused the page entirely (this is admin-only,
 * same gate as every other page under admin/); the Maintenance directory lists the new page.
 */
require __DIR__ . '/support/security_lib.php';

echo "\n--- RivetMSP\\Ops\\ServerStatus, direct ---\n";
$status = new \RivetMSP\Ops\ServerStatus($db, $root);
$groups = $status->run();
$ok(count($groups) === 2 && $groups[0]['title'] === 'Application and database' && $groups[1]['title'] === 'CPU, memory and disk', 'run() returns the two groups in order');

$resourceLabels = array_column($groups[1]['checks'], 'label');
$ok(in_array('CPU load', $resourceLabels, true), 'CPU load is checked');
$ok(in_array('Memory', $resourceLabels, true), 'Memory is checked');
$ok(in_array('Disk space', $resourceLabels, true), 'Disk space is checked');
foreach ($groups as $g) {
    foreach ($g['checks'] as $c) {
        $ok(in_array($c['status'], ['ok', 'warn', 'fail'], true), "{$c['label']}: status is one of ok/warn/fail");
        $ok($c['detail'] !== '', "{$c['label']}: has a non-empty detail string");
    }
}

$sum = \RivetMSP\Ops\ServerStatus::summarize($groups);
$ok(($sum['ok'] + $sum['warn'] + $sum['fail']) === array_sum(array_map('count', array_column($groups, 'checks'))), 'summarize() accounts for every check exactly once');

echo "\n--- a real server never throws, even against a path that cannot be statted ---\n";
$statusBadPath = new \RivetMSP\Ops\ServerStatus($db, '/this/path/does/not/exist/at/all');
$groupsBadPath = $statusBadPath->run();
$diskCheck = null;
foreach ($groupsBadPath[1]['checks'] as $c) { if ($c['label'] === 'Disk space') { $diskCheck = $c; } }
$ok($diskCheck !== null && $diskCheck['status'] === 'warn', 'an unreadable app root degrades disk space to a WARN, not a fatal error');

echo "\n--- fixtures ---\n";
$q("DELETE FROM user_roles WHERE role_name = 'srvstat-test-NonAdmin'");
$q("DELETE FROM users WHERE user_email = 'srvstat-test-nonadmin@example.test'");
$q("INSERT INTO user_roles SET role_name='srvstat-test-NonAdmin', role_is_admin=0, role_type=1");
$roleNonAdmin = (int) $db->insert_id;
$q("INSERT INTO users SET user_name='srvstat-test-nonadmin', user_email='srvstat-test-nonadmin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$roleNonAdmin");
$nonAdmin = (int) $db->insert_id;
$q("INSERT INTO user_settings SET user_id=$nonAdmin ON DUPLICATE KEY UPDATE user_id=user_id");

$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='srvstat-test-admin', user_email='srvstat-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

$sdir = sys_get_temp_dir() . '/srvstat_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sidAdmin = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
$sidNonAdmin = sec_forge_session($sdir, ['logged' => true, 'user_id' => $nonAdmin, 'csrf_token' => 'csrftok12345678']);

echo "\n--- the page itself, as an admin ---\n";
[$c, $body] = sec_web($base, 'GET', '/admin/server_status.php', $sidAdmin);
$ok($c === 200, 'the page loads for an admin');
$ok(str_contains($body, 'Server status'), 'the page title renders');
foreach (['CPU load', 'Memory', 'Disk space', 'Database version', 'Application version', 'PHP extensions'] as $label) {
    $ok(str_contains($body, $label), "the page shows the \"$label\" check");
}

echo "\n--- refused for a non-admin ---\n";
[$c, $body] = sec_web($base, 'GET', '/admin/server_status.php', $sidNonAdmin);
$ok($c !== 200 || !str_contains($body, 'CPU load'), 'a non-admin never sees the server status content - same admin-only gate as every other admin/ page');

echo "\n--- Maintenance directory lists the new page ---\n";
[$c, $body] = sec_web($base, 'GET', '/admin/maintenance.php', $sidAdmin);
$ok($c === 200 && str_contains($body, 'Server status') && str_contains($body, 'server_status.php'), 'the Maintenance page links to Server status');

$thisPort = (int) explode(':', $base)[2];
$logText = (string) @file_get_contents(sys_get_temp_dir() . "/sec_server_$thisPort.log");
$otherWarnings = preg_replace('/^.*Undefined array key "HTTP_USER_AGENT".*$/m', '', $logText);
$ok(!str_contains($otherWarnings, 'PHP Warning') && !str_contains($otherWarnings, 'PHP Fatal') && !str_contains($otherWarnings, 'PHP Notice'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');

echo "\n--- cleanup ---\n";
$q("DELETE FROM users WHERE user_id = $nonAdmin");
$q("DELETE FROM user_roles WHERE user_role_id = $roleNonAdmin");
