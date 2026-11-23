<?php
// MSP-DS-14 (rest): the 2.6.138 migration maps OLD api rows 'reply' -> 'Public' and 'note' -> 'Internal' so the portal/guest views (IN ('Public','Client')) treat them like web rows.
// Run: SCRATCH_TABLES="settings users tickets invoices quotes ticket_replies logs" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_14.php
require __DIR__ . '/lib/ds2_migrate.php';
$m = ds2_open(); ds2_downgrade($m);
$m->query("INSERT INTO ticket_replies (ticket_reply_id, ticket_reply, ticket_reply_type, ticket_reply_ticket_id) VALUES
 (1,'agent answer','reply',1),(2,'Worksheet signed by Bob','note',1),(3,'web reply','Public',1),(4,'cust','Client',1),(5,'priv','Internal',1),(6,'rmm','Automation',1),(7,'sys','System',1)");
$out = ds2_migrate();
ds2_t('migration ran', str_contains($out, 'MIGRATION_DONE') && !str_contains($out, 'Fatal'));
$type = fn (int $id) => ds2_one($m, "SELECT ticket_reply_type FROM ticket_replies WHERE ticket_reply_id=$id");
ds2_t("'reply' row is now Public", $type(1) === 'Public');
ds2_t("'note' row is now Internal", $type(2) === 'Internal');
ds2_t('Public/Client/Internal/Automation/System rows untouched', [$type(3), $type(4), $type(5), $type(6), $type(7)] === ['Public', 'Client', 'Internal', 'Automation', 'System']);
ds2_t('customer view query now lists the old agent reply', (int) ds2_one($m, "SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id=1 AND ticket_reply_type IN ('Public','Client') AND ticket_reply_id=1") === 1);
ds2_t('no reply/note rows remain', (int) ds2_one($m, "SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_type IN ('reply','note')") === 0);
ds2_t('version advanced', ds2_one($m, "SELECT config_current_database_version FROM settings") === '2.6.138');
// idempotent: a re-run of the step changes nothing
$m->query("UPDATE settings SET config_current_database_version='2.6.137'");
$out = ds2_migrate();
ds2_t('re-run is clean', str_contains($out, 'MIGRATION_DONE') && !str_contains($out, 'Fatal') && $type(1) === 'Public');
// the write path: the API stores Public for agent replies, Internal for signing notes
$t = file_get_contents(__DIR__ . '/../api/v1/tickets.php');
ds2_t('API stores Public for an agent reply', str_contains($t, "\$stored_reply_type = \$type === 'reply' ? 'Public' : \$type;"));
foreach (['worksheets', 'outtakes'] as $f) { ds2_t("$f.php stores Internal", str_contains(file_get_contents(__DIR__ . "/../api/v1/$f.php"), "'Internal', \$uid")); }
ds2_done();
