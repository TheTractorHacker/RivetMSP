<?php
// MSP-DS-141 (re-review MRR4): the 2.6.138 staff-e-mail dedupe must group under the SAME collation as the unique index (utf8mb4_general_ci folds accents and
// non-ASCII case), otherwise accent/case-equal pairs were not renamed and ADD UNIQUE failed with 1062 (install stuck at 2.6.137).
// Runs the REAL 2.6.137 -> 2.6.138 step (tests/lib/ds2_migrate.php).
// Run: SCRATCH_TABLES="settings users user_roles user_settings tickets invoices quotes ticket_replies logs" bash tests/lib/with_scratch_db.sh php tests/msp_mrr_4.php
require __DIR__ . '/lib/ds2_migrate.php';
$m = ds2_open(); ds2_downgrade($m);
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (92,'Tech',0)");
$m->query("SET NAMES utf8mb4");
$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status) VALUES
 (1,'Accent upper','É@x.test','x',92,1,1),
 (2,'Accent lower','e@x.test','x',92,1,1),
 (3,'Accent mixed','é@X.TEST','x',92,1,1),
 (4,'Jose acc','josé@x.test','x',92,1,1),
 (5,'Jose plain','JOSE@x.test','x',92,1,1),
 (6,'Jose plain 2','jose@x.test','x',92,1,1),
 (7,'Ascii case','Pat@x.test','x',92,1,1),
 (8,'Ascii case 2','pat@x.test','x',92,1,1),
 (9,'Unique','solo@x.test','x',92,1,1),
 (10,'Portal','e@x.test','x',0,2,1)");
$before = (int) ds2_one($m, "SELECT COUNT(*) FROM users");
$out = ds2_migrate();
ds2_t('migration ran without error (no 1062)', str_contains($out, 'MIGRATION_DONE') && !str_contains($out, 'Fatal') && !str_contains($out, 'Duplicate'));
ds2_t('unique index exists afterwards', ds2_one($m, "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND INDEX_NAME='uniq_users_staff_email'") >= 1);
ds2_t('no user deleted', (int) ds2_one($m, "SELECT COUNT(*) FROM users") === $before);
$email = fn (int $id) => ds2_one($m, "SELECT user_email FROM users WHERE user_id=$id");
ds2_t('lowest-id active account of the accent group keeps its address', $email(1) === 'É@x.test');
ds2_t('accent/case-equal later accounts are renamed', $email(2) === 'e+dup2@x.test' && $email(3) !== 'é@X.TEST' && str_contains((string) $email(3), '+dup3'));
ds2_t('josé / JOSE / jose group: first keeps, others renamed', $email(4) === 'josé@x.test' && str_contains((string) $email(5), '+dup5') && str_contains((string) $email(6), '+dup6'));
ds2_t('ASCII case pair still handled', $email(7) === 'Pat@x.test' && $email(8) === 'pat+dup8@x.test');
ds2_t('unique address and portal account untouched', $email(9) === 'solo@x.test' && $email(10) === 'e@x.test');
ds2_t('every rename is logged', (int) ds2_one($m, "SELECT COUNT(*) FROM logs WHERE log_description LIKE 'MSP-DS-141:%'") === 5);
ds2_t('database now rejects a case/accent variant', $m->query("INSERT INTO users (user_name,user_email,user_password,user_type) VALUES ('x','SOLO@x.test','x',1)") === false && $m->errno === 1062);
ds2_done();
