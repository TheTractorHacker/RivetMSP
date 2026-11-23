<?php
// MSP-DS-49 (re-review MRR-9): legacy calendar feed links (HMAC of user id + installation id) can be ended by an admin ("End legacy links now"),
// the migration's window is 7 days, and the installation id is in no cron lock file name. Runs the REAL resolver, the REAL admin handler and the REAL lock-name helper.
// Run: SCRATCH_TABLES="settings users user_roles logs security_settings audit_events audit_chain_head audit_chain_checkpoint" bash tests/lib/with_scratch_db.sh php tests/msp_mrr_9.php
require __DIR__ . '/lib/ds2_migrate.php';
require __DIR__ . '/lib/handler_run.php';
require __DIR__ . '/../includes/calendar_feed_token.php';
require __DIR__ . '/../includes/cron_lock.php';
$m = ds2_open();
$m->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS user_calendar_token_hash char(64) DEFAULT NULL, ADD COLUMN IF NOT EXISTS user_calendar_token_rotated_at datetime DEFAULT NULL, ADD COLUMN IF NOT EXISTS user_calendar_legacy_until datetime DEFAULT NULL");
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (91,'Admin',1),(92,'Tech',0)");
$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status,user_calendar_legacy_until) VALUES
  (1,'Admin','a@x.test','x',91,1,1,NOW() + INTERVAL 5 DAY),(2,'Tech','t@x.test','x',92,1,1,NOW() + INTERVAL 5 DAY)");
$inst = 'install-secret-123';
$legacy = fn (int $id) => hash_hmac('sha256', (string) $id, $inst);
$r = fn (string $tok) => calendarFeedTokenResolve($m, $tok, $inst);
ds2_t('legacy link works inside the window while the setting is at its default', ($r($legacy(2))['user_id'] ?? 0) === 2);

[$o, $alert] = runPostHandler('settings_security.php', 2, ['end_calendar_legacy_links' => 1], [], 'admin');
ds2_t("non-admin cannot end legacy links ($alert)", ds2_one($m, "SELECT user_calendar_legacy_until FROM users WHERE user_id=2") !== null && ($r($legacy(2))['user_id'] ?? 0) === 2);

[$o, $alert] = runPostHandler('settings_security.php', 1, ['end_calendar_legacy_links' => 1], [], 'admin');
ds2_t("admin ends legacy links ($alert)", str_starts_with($alert, 'success'));
ds2_t('legacy link now refused for everyone', $r($legacy(1)) === null && $r($legacy(2)) === null);
ds2_t('window cleared on every user', (int) ds2_one($m, "SELECT COUNT(*) FROM users WHERE user_calendar_legacy_until IS NOT NULL") === 0);
$tok = calendarFeedTokenRotate($m, 2);
ds2_t('a per-user token still works after legacy links ended', ($r($tok)['user_id'] ?? 0) === 2);
ds2_t('the action is logged', (int) ds2_one($m, "SELECT COUNT(*) FROM logs WHERE log_description LIKE '%ended the legacy calendar feed links%'") >= 1);

// setting alone (without the cleared window) also refuses
$m->query("DELETE FROM security_settings WHERE setting_key='calendar_legacy_links'");
$m->query("UPDATE users SET user_calendar_legacy_until = NOW() + INTERVAL 5 DAY, user_calendar_token_hash = NULL WHERE user_id=1");
$m->query("INSERT INTO security_settings (setting_key, setting_value) VALUES ('calendar_legacy_links','0')");
$res = (new ReflectionFunction('secSettingsAll'))->invoke($m, true);
ds2_t('setting 0 refuses a legacy link even inside the window', $r($legacy(1)) === null);

// migration text
$src = file_get_contents(__DIR__ . '/../admin/database_updates.php');
ds2_t('migration window is 7 days, not 30', str_contains($src, "DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE `user_type` = 1") && !str_contains($src, "`user_calendar_legacy_until` = DATE_ADD(NOW(), INTERVAL 30 DAY)"));

// lock names
$n = rivetCronLockName('mail_queue', $inst);
ds2_t('lock name does not contain the installation id', !str_contains($n, $inst) && preg_match('/^itflow_mail_queue_[0-9a-f]{16}\.lock$/', $n) === 1);
ds2_t('lock name is stable per install and differs between installs', $n === rivetCronLockName('mail_queue', $inst) && $n !== rivetCronLockName('mail_queue', 'other'));
$bad = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS)) as $f) {
    $p = $f->getPathname();
    if (!preg_match('/\.(php|sh)$/', $p) || preg_match('#/(vendor|tests|node_modules|\.git)/#', $p)) { continue; }
    if (preg_match('/_\{?\$installation_id\}?\.lock|\$installation_id\}?[^;\n]*\.(lock|tmp|pid|log)\b/', (string) file_get_contents($p))) { $bad[] = $p; }
}
ds2_t('no source file builds a file name from installation_id' . ($bad ? ' (' . implode(',', $bad) . ')' : ''), !$bad);
ds2_done();
