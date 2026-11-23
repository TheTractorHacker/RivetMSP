<?php
// MSP-DS-41 (rest): UNIQUE (prefix, number) on tickets/invoices/quotes, duplicates renumbered by the 2.6.138 migration (oldest row keeps the number), counters lifted above the
// highest number in use, and the settings forms can no longer rewind a counter (real admin handlers).
// Run: SCRATCH_TABLES="settings users user_roles user_settings tickets invoices quotes ticket_replies logs" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_41.php
require __DIR__ . '/lib/ds2_migrate.php';
require __DIR__ . '/lib/handler_run.php';
$m = ds2_open(); ds2_downgrade($m);
$m->query("UPDATE settings SET config_ticket_next_number = 3, config_invoice_next_number = 2, config_quote_next_number = 1, config_ticket_prefix='TKT-', config_invoice_prefix='INV-', config_quote_prefix='QUO-'");
$m->query("INSERT INTO tickets (ticket_id, ticket_prefix, ticket_number, ticket_subject) VALUES (10,'TKT-',5,'a'),(11,'TKT-',5,'b'),(12,'TKT-',5,'c'),(13,'TKT-',6,'d'),(14,'OLD-',5,'e'),(15,NULL,0,'f'),(16,NULL,0,'g')");
$m->query("INSERT INTO invoices (invoice_id, invoice_prefix, invoice_number) VALUES (20,'INV-',100),(21,'INV-',100),(22,'INV-',101)");
$m->query("INSERT INTO quotes (quote_id, quote_prefix, quote_number) VALUES (30,'QUO-',7),(31,'QUO-',7)");
$out = ds2_migrate();
ds2_t('migration ran without error', str_contains($out, 'MIGRATION_DONE') && !str_contains($out, 'Fatal') && !str_contains($out, 'Duplicate'));
$num = fn (string $t, string $c, string $id, int $v) => (int) ds2_one($m, "SELECT $c FROM $t WHERE $id = $v");
ds2_t('oldest duplicate ticket keeps its number', $num('tickets', 'ticket_number', 'ticket_id', 10) === 5);
$n11 = $num('tickets', 'ticket_number', 'ticket_id', 11); $n12 = $num('tickets', 'ticket_number', 'ticket_id', 12);
ds2_t('later duplicates get new, distinct numbers above the old maximum', $n11 > 6 && $n12 > 6 && $n11 !== $n12);
ds2_t('different prefix with the same number is not touched', $num('tickets', 'ticket_number', 'ticket_id', 14) === 5);
ds2_t('NULL-prefix rows are left alone', $num('tickets', 'ticket_number', 'ticket_id', 15) === 0 && $num('tickets', 'ticket_number', 'ticket_id', 16) === 0);
ds2_t('duplicate invoice renumbered, oldest kept', $num('invoices', 'invoice_number', 'invoice_id', 20) === 100 && $num('invoices', 'invoice_number', 'invoice_id', 21) > 101);
ds2_t('duplicate quote renumbered, oldest kept', $num('quotes', 'quote_number', 'quote_id', 30) === 7 && $num('quotes', 'quote_number', 'quote_id', 31) > 7);
$c = $m->query("SELECT config_ticket_next_number t, config_invoice_next_number i, config_quote_next_number q FROM settings")->fetch_assoc();
ds2_t('counters are above every number in use', (int) $c['t'] > max($n11, $n12) && (int) $c['i'] > $num('invoices', 'invoice_number', 'invoice_id', 21) && (int) $c['q'] > $num('quotes', 'quote_number', 'quote_id', 31));
ds2_t('renumbering is logged', (int) ds2_one($m, "SELECT COUNT(*) FROM logs WHERE log_description LIKE 'MSP-DS-41:%'") === 4);
foreach (['tickets' => 'uniq_tickets_prefix_number', 'invoices' => 'uniq_invoices_prefix_number', 'quotes' => 'uniq_quotes_prefix_number'] as $t => $i) {
    ds2_t("$t unique index exists and is UNIQUE", (int) ds2_one($m, "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND INDEX_NAME='$i' AND NON_UNIQUE=0") === 2);
}
ds2_t('a duplicate ticket number is now refused by the database', $m->query("INSERT INTO tickets (ticket_prefix, ticket_number, ticket_subject) VALUES ('TKT-',5,'dup')") === false && $m->errno === 1062);
ds2_t('a duplicate invoice number is refused', $m->query("INSERT INTO invoices (invoice_prefix, invoice_number) VALUES ('INV-',100)") === false);
ds2_t('a duplicate quote number is refused', $m->query("INSERT INTO quotes (quote_prefix, quote_number) VALUES ('QUO-',7)") === false);
// idempotent re-run
$m->query("UPDATE settings SET config_current_database_version='2.6.137'");
$out = ds2_migrate();
ds2_t('re-run is clean and renumbers nothing more', str_contains($out, 'MIGRATION_DONE') && (int) ds2_one($m, "SELECT COUNT(*) FROM logs WHERE log_description LIKE 'MSP-DS-41:%'") === 4);

// The settings forms (real handlers) cannot rewind a counter, but can raise it.
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (91,'Admin',1)");
$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status) VALUES (1,'adm','adm@x.test','x',91,1,1)");
$m->query("UPDATE settings SET config_ticket_next_number = 500, config_invoice_next_number = 500, config_recurring_invoice_next_number = 50, config_quote_next_number = 500, config_project_next_number = 500");
$cnt = fn (string $col) => (int) ds2_one($m, "SELECT $col FROM settings");
runPostHandler('settings_ticket.php', 1, ['edit_ticket_settings' => 1, 'config_ticket_prefix' => 'TKT-', 'config_ticket_next_number' => 100, 'config_ticket_autoclose_hours' => 72], [], 'admin');
ds2_t('ticket settings save with a stale (lower) counter does not rewind it', $cnt('config_ticket_next_number') === 500);
runPostHandler('settings_ticket.php', 1, ['edit_ticket_settings' => 1, 'config_ticket_prefix' => 'TKT-', 'config_ticket_next_number' => 900, 'config_ticket_autoclose_hours' => 72], [], 'admin');
ds2_t('ticket settings save may raise the counter', $cnt('config_ticket_next_number') === 900);
runPostHandler('settings_invoice.php', 1, ['edit_invoice_settings' => 1, 'config_invoice_prefix' => 'INV-', 'config_invoice_next_number' => 1, 'config_recurring_invoice_prefix' => 'REC-', 'config_recurring_invoice_next_number' => 1], [], 'admin');
ds2_t('invoice settings do not rewind invoice or recurring counters', $cnt('config_invoice_next_number') === 500 && $cnt('config_recurring_invoice_next_number') === 50);
runPostHandler('settings_quote.php', 1, ['edit_quote_settings' => 1, 'config_quote_prefix' => 'QUO-', 'config_quote_next_number' => 1], [], 'admin');
ds2_t('quote settings do not rewind the counter', $cnt('config_quote_next_number') === 500);
runPostHandler('settings_project.php', 1, ['edit_project_settings' => 1, 'config_project_prefix' => 'PRJ-', 'config_project_next_number' => 1], [], 'admin');
ds2_t('project settings do not rewind the counter', $cnt('config_project_next_number') === 500);
ds2_done();
