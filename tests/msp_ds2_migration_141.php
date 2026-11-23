<?php
// DB 2.6.141 (deep-scan group D): mcp_pending_actions.grant_id (MSP-DS-114) and users.user_session_version (MSP-DS-145) exist in a fresh db.sql, and the
// REAL admin/database_updates.php step adds them to a database built from the PREVIOUS db.sql (7992dd4ff), keeps existing rows, and is idempotent.
// Run: bash tests/lib/with_scratch_db.sh php tests/msp_ds2_migration_141.php     (loads the whole schema twice: takes a few minutes)
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$col = fn (string $t, string $c) => ($r = $m->query("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '$t' AND column_name = '$c'")) ? $r->fetch_row() : null;

// 1. fresh install (the scratch DB was just built from the current db.sql)
$ok('fresh db.sql: mcp_pending_actions.grant_id int NOT NULL DEFAULT 0', $col('mcp_pending_actions', 'grant_id') === ['int(11)', 'NO', '0']);
$ok('fresh db.sql: users.user_session_version int NOT NULL DEFAULT 0', $col('users', 'user_session_version') === ['int(11)', 'NO', '0']);
$ok('LATEST_DATABASE_VERSION is at least 2.6.141', version_compare(preg_match('/"(\d+\.\d+\.\d+)"/', file_get_contents($root . '/includes/database_version.php'), $vm) ? $vm[1] : '0', '2.6.141', '>='));

// 2. upgrade path: rebuild the schema from the previous release's db.sql
$old = tempnam(sys_get_temp_dir(), 'olddb');
file_put_contents($old, (string) shell_exec('git -C ' . escapeshellarg($root) . ' show 7992dd4ff:db.sql'));
$ok('previous db.sql obtained', filesize($old) > 100000);
$cmd = sprintf('mysql -u%s -p%s %s < %s 2>&1', escapeshellarg((string) getenv('RIVETMSP_TEST_DB_USER')), escapeshellarg((string) getenv('RIVETMSP_TEST_DB_PASS')), escapeshellarg((string) getenv('RIVETMSP_TEST_DB_NAME')), escapeshellarg($old));
$out = (string) shell_exec($cmd);
unlink($old);
$ok('previous schema loaded', !preg_match('/ERROR/', $out));
$ok('old schema has neither column', $col('mcp_pending_actions', 'grant_id') === null && $col('users', 'user_session_version') === null);

$m->query("DELETE FROM settings");
$m->query("INSERT INTO settings (company_id, config_current_database_version) VALUES (1, '2.6.140')");
$m->query("INSERT INTO users (user_id, user_name, user_email, user_password, user_type, user_status) VALUES (20, 'Pat', 'pat@x.test', 'x', 2, 1)");
$m->query("INSERT INTO mcp_pending_actions (requested_by, oauth_client_id, tool, args_json, args_hash, status, idempotency_key, expires_at) VALUES (5, 'c1', 'rivetmsp_propose_ticket_reply', '{}', 'h', 'pending', 'k1', NOW() + INTERVAL 1 DAY)");
$ok('old rows inserted', $m->errno === 0);

$run = function (string $latest, string $current) {
    $p = proc_open([PHP_BINARY, __DIR__ . '/lib/run_db_update.php', $latest, $current], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $o = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p);

    return trim($o);
};
$ok('Update Database step runs without error', ($r = $run('2.6.141', '2.6.140')) === 'OK' ? true : (print("   $r\n") && false));
$ok('grant_id added with the same definition as db.sql', $col('mcp_pending_actions', 'grant_id') === ['int(11)', 'NO', '0']);
$ok('user_session_version added with the same definition as db.sql', $col('users', 'user_session_version') === ['int(11)', 'NO', '0']);
$ok('existing proposal kept, grant_id 0 (legacy: falls back to the client + user check)', $m->query("SELECT grant_id FROM mcp_pending_actions WHERE idempotency_key = 'k1'")->fetch_row()[0] === '0');
$ok('existing portal user kept, version 0', $m->query("SELECT user_session_version FROM users WHERE user_id = 20")->fetch_row()[0] === '0');
$ok('version is at least 2.6.141 (later steps may follow)', $m->query("SELECT config_current_database_version FROM settings WHERE company_id = 1")->fetch_row()[0] !== null && version_compare($m->query("SELECT config_current_database_version FROM settings WHERE company_id = 1")->fetch_row()[0], '2.6.141', '>='));

// 3. idempotent: run the step again (as if the version write had been lost)
$m->query("UPDATE users SET user_session_version = 3 WHERE user_id = 20");
$m->query("UPDATE settings SET config_current_database_version = '2.6.140' WHERE company_id = 1");
$ok('second run is clean (no duplicate-column error)', ($r = $run('2.6.141', '2.6.140')) === 'OK' ? true : (print("   $r\n") && false));
$ok('second run keeps data (version 3 not reset)', $m->query("SELECT user_session_version FROM users WHERE user_id = 20")->fetch_row()[0] === '3');
$ok('and still at least 2.6.141 after the re-run', $m->query("SELECT config_current_database_version FROM settings WHERE company_id = 1")->fetch_row()[0] !== null && version_compare($m->query("SELECT config_current_database_version FROM settings WHERE company_id = 1")->fetch_row()[0], '2.6.141', '>='));
exit($fail ? 1 : 0);
