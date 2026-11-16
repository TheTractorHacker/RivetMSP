<?php
// MSP-DS-49 (rest): per-user calendar feed token (only a hash stored), rotatable through the real handler, old HMAC links valid only inside the migration's grace period
// and only until their owner generates a new link. Runs the real resolver (includes/calendar_feed_token.php) and handler (agent/post/calendar_feed.php).
// Run: SCRATCH_TABLES="settings users user_roles user_settings tickets invoices quotes ticket_replies logs" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_49.php
require __DIR__ . '/lib/ds2_migrate.php';
require __DIR__ . '/lib/handler_run.php';
require __DIR__ . '/../includes/calendar_feed_token.php';
$m = ds2_open(); ds2_downgrade($m);
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (92,'Tech',0)");
$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status) VALUES (1,'Ann','a@x.test','x',92,1,1),(2,'Bob','b@x.test','x',92,1,1),(3,'Gone','g@x.test','x',92,1,0),(4,'Portal','p@x.test','x',0,2,1)");
$out = ds2_migrate();
ds2_t('migration ran without error', str_contains($out, 'MIGRATION_DONE') && !str_contains($out, 'Fatal'));
$inst = 'install-secret-123';
$legacy = fn (int $id) => hash_hmac('sha256', (string) $id, $inst);
$r = fn (string $tok) => calendarFeedTokenResolve($m, $tok, $inst);
ds2_t('existing HMAC link still works after the upgrade (grace period)', ($r($legacy(1))['user_id'] ?? 0) === 1 && ($r($legacy(2))['user_id'] ?? 0) === 2);
ds2_t('grace period is about 30 days', (int) ds2_one($m, "SELECT DATEDIFF(user_calendar_legacy_until, NOW()) FROM users WHERE user_id=1") >= 29);
ds2_t('portal accounts get no grace', ds2_one($m, "SELECT user_calendar_legacy_until FROM users WHERE user_id=4") === null);
ds2_t('inactive user link refused', $r($legacy(3)) === null);

// rotate through the REAL handler as user 1
$t1 = calendarFeedTokenRotate($m, 1);
ds2_t('token is 64 hex chars', (bool) preg_match('/^[0-9a-f]{64}$/', $t1));
$hash = ds2_one($m, "SELECT user_calendar_token_hash FROM users WHERE user_id=1");
ds2_t('only the sha256 is stored, never the token', $hash === hash('sha256', $t1) && !str_contains((string) ds2_one($m, "SELECT CONCAT_WS('|',user_calendar_token_hash) FROM users WHERE user_id=1"), $t1));
ds2_t('new token resolves to its user', ($r($t1)['user_id'] ?? 0) === 1);
ds2_t('rotating ends the old HMAC link for that user', $r($legacy(1)) === null);
ds2_t('other users keep their grace link', ($r($legacy(2))['user_id'] ?? 0) === 2);
ds2_t('a token cannot be used for another user', ($r($t1)['user_id'] ?? 0) !== 2);

[$o, $alert] = runPostHandler('calendar_feed.php', 1, ['regenerate_calendar_feed_token' => 1]);
$h2 = ds2_one($m, "SELECT user_calendar_token_hash FROM users WHERE user_id=1");
ds2_t("handler regenerates (hash changes) ($alert)", $h2 !== $hash && str_starts_with($alert, 'success'));
ds2_t('the previous token stops working after Regenerate', $r($t1) === null);
ds2_t('handler only touches the signed-in user', ds2_one($m, "SELECT user_calendar_token_hash FROM users WHERE user_id=2") === null);
ds2_t('regeneration is logged', (int) ds2_one($m, "SELECT COUNT(*) FROM logs WHERE log_description LIKE '%calendar feed%'") >= 1);

// garbage / short / empty tokens
ds2_t('short, empty and wrong tokens are refused', $r('') === null && $r('abc') === null && $r(str_repeat('a', 64)) === null);
ds2_t('portal user cannot get a token', calendarFeedTokenRotate($m, 4) !== '' && ds2_one($m, "SELECT user_calendar_token_hash FROM users WHERE user_id=4") === null);

// grace period expiry
$m->query("UPDATE users SET user_calendar_legacy_until = NOW() - INTERVAL 1 DAY WHERE user_id=2");
ds2_t('the HMAC link stops working once the grace period has passed', $r($legacy(2)) === null);
$m->query("UPDATE users SET user_status = 0 WHERE user_id=1");
ds2_t('a deactivated user token is refused', $r(calendarFeedTokenRotate($m, 1)) === null);

// the real feed script uses the resolver and no longer derives tokens itself
$f = file_get_contents(__DIR__ . '/../agent/calendar_feed.php');
ds2_t('calendar_feed.php resolves through calendarFeedTokenResolve', str_contains($f, 'calendarFeedTokenResolve(') && !str_contains($f, "hash_hmac('sha256', intval"));
ds2_t('calendar page no longer derives the HMAC token', !str_contains(file_get_contents(__DIR__ . '/../agent/calendar.php'), 'hash_hmac'));
ds2_done();
