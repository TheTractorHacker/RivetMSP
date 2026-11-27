<?php
// MSP-DS-141 (rest): one STAFF account per e-mail, enforced by a UNIQUE index (client portal accounts, user_type 2, may share an address). The 2.6.138 migration renames later
// duplicates deterministically (never deletes), logs them, and the profile handler still refuses a taken address.
// Run: SCRATCH_TABLES="settings users user_roles user_settings tickets invoices quotes ticket_replies logs" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_141.php
require __DIR__ . '/lib/ds2_migrate.php';
require __DIR__ . '/lib/handler_run.php';
$m = ds2_open(); ds2_downgrade($m);
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (92,'Tech',0)");
$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status,user_archived_at) VALUES
 (1,'Old archived','dup@corp.test','x',92,1,0,NOW()),
 (2,'Active one','DUP@corp.test','x',92,1,1,NULL),
 (3,'Active two','dup@corp.test','x',92,1,1,NULL),
 (4,'Solo','solo@corp.test','x',92,1,1,NULL),
 (5,'Portal A','dup@corp.test','x',0,2,1,NULL),
 (6,'Portal B','dup@corp.test','x',0,2,1,NULL),
 (7,'NoAt','noat','x',92,1,1,NULL),(8,'NoAt2','noat','x',92,1,1,NULL),
 (9,'Blank1','','x',92,1,1,NULL),(10,'Blank2','','x',92,1,1,NULL)");
$before = (int) ds2_one($m, "SELECT COUNT(*) FROM users");
$out = ds2_migrate();
ds2_t('migration ran without error', str_contains($out, 'MIGRATION_DONE') && !str_contains($out, 'Fatal') && !str_contains($out, 'Duplicate'));
ds2_t('no user was deleted', (int) ds2_one($m, "SELECT COUNT(*) FROM users") === $before);
$email = fn (int $id) => ds2_one($m, "SELECT user_email FROM users WHERE user_id=$id");
ds2_t('the active account with the lowest id keeps the address (archived one loses it)', strcasecmp($email(2), 'dup@corp.test') === 0);
ds2_t('later duplicate renamed deterministically', $email(3) === 'dup+dup3@corp.test' && $email(1) === 'dup+dup1@corp.test');
ds2_t('address without @ gets a suffix', $email(7) === 'noat' && $email(8) === 'noat.dup8');
ds2_t('unique staff address untouched', $email(4) === 'solo@corp.test');
ds2_t('portal accounts are not renamed and may share an address', $email(5) === 'dup@corp.test' && $email(6) === 'dup@corp.test');
ds2_t('empty addresses do not collide', $email(9) === '' && $email(10) === '');
ds2_t('renames are logged (3)', (int) ds2_one($m, "SELECT COUNT(*) FROM logs WHERE log_description LIKE 'MSP-DS-141:%'") === 3);
ds2_t('a second staff account with a taken address is refused by the database', $m->query("INSERT INTO users (user_name,user_email,user_password,user_type) VALUES ('x','Solo@Corp.Test','x',1)") === false && $m->errno === 1062);
ds2_t('a portal account may still reuse a staff address', $m->query("INSERT INTO users (user_name,user_email,user_password,user_type) VALUES ('p','solo@corp.test','x',2)") !== false);
ds2_t('UPDATE onto another staff address is refused', $m->query("UPDATE users SET user_email='solo@corp.test' WHERE user_id=2") === false);
// code-level enforcement: the real profile handler refuses a taken address (before it reaches the index)
$m->query("INSERT INTO user_settings (user_id, user_config_signature) VALUES (4,'')");
$base = ['edit_your_user_details' => 1, 'name' => 'Solo', 'email' => 'solo@corp.test', 'title' => '', 'phone' => '', 'signature' => '', 'current_password' => 'x'];
[$o, $alert] = runPostHandler('../user/post/profile.php', 4, ['email' => 'dup@corp.test'] + $base);
ds2_t('profile handler refuses a taken address', $email(4) === 'solo@corp.test' && !str_starts_with($alert, 'success'));
$u = file_get_contents(__DIR__ . '/../admin/post/users.php');
ds2_t('admin user create/edit check for a taken address before the INSERT/UPDATE', substr_count($u, "SELECT user_id FROM users WHERE user_email = '\$email'") >= 2);
// idempotent re-run
$m->query("UPDATE settings SET config_current_database_version='2.6.137'");
$out = ds2_migrate();
ds2_t('re-run is clean', str_contains($out, 'MIGRATION_DONE') && !str_contains($out, 'Fatal') && (int) ds2_one($m, "SELECT COUNT(*) FROM logs WHERE log_description LIKE 'MSP-DS-141:%'") === 3);
ds2_done();
