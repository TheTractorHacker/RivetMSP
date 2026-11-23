<?php
// MSP-DS-67 (re-review M3-5): the whole bypass through the REAL handlers. Client rejects via guest_post.php, a technician sets the change back
// to draft, edits its content (the DS-51 reset puts client_decision back to 'pending'), then unticks "requires client approval". The untick must
// still be refused (only an admin with a logged reason), and the status gate must keep blocking.
// Run: SCRATCH_TABLES="changes users user_roles logs modules user_role_permissions clients user_client_permissions audit_events audit_chain_head audit_chain_checkpoint contacts notifications settings companies api_tokens" bash tests/lib/with_scratch_db.sh php tests/msp_m3_5.php
require __DIR__ . '/lib/ds2_migrate.php';
require __DIR__ . '/lib/handler_run.php';
$m = ds2_open();
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (91,'Admin',1),(92,'Tech',0)");
$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status) VALUES (1,'Admin','a@x.test','x',91,1,1),(2,'Tech','t@x.test','x',92,1,1)");
$m->query("INSERT INTO modules (module_id, module_name) VALUES (50,'module_support')");
$m->query("INSERT INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) VALUES (92, 50, 2)");
$m->query("INSERT INTO settings (company_id) VALUES (1)");
$m->query("INSERT INTO companies (company_id, company_name) VALUES (1, 'Co')");
$m->query("INSERT INTO clients (client_id, client_name) VALUES (5, 'Acme')");
$m->query("INSERT INTO user_client_permissions (user_id, client_id) VALUES (2, 5)");
$m->query("INSERT INTO changes (change_id, title, risk, client_id, requires_client_approval, client_decision, status, url_key) VALUES (1, 'Orig', 'low', 5, 1, 'pending', 'awaiting_approval', 'k1')");
$row = fn () => $m->query("SELECT requires_client_approval r, client_decision d, status s FROM changes WHERE change_id=1")->fetch_assoc();
$guest = function (array $post): string {
    $p = proc_open([PHP_BINARY, __DIR__ . '/lib/run_guest_post.php', json_encode(['post' => $post])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $o = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p); return $o;
};
$edit = fn (int $id, array $o = []) => $o + ['edit_change' => 1, 'change_id' => $id, 'title' => 'Edited', 'risk' => 'low', 'client_id' => 5];
$guest(["reject_change" => 1, "url_key" => "k1"]);
ds2_t('client rejection recorded', $row()['d'] === 'rejected');
[$o, $a] = runPostHandler('change.php', 2, ['set_change_status' => 1, 'change_id' => 1, 'status' => 'draft'], [], 'agent');
ds2_t("back to draft ($a)", $row()['s'] === 'draft');
runPostHandler('change.php', 2, $edit(1, ['requires_client_approval' => 1]), [], 'agent');
ds2_t('content edit resets the decision to pending (DS-51 unchanged)', $row()['d'] === 'pending' && $row()['r'] === '1');
[$o, $a] = runPostHandler('change.php', 2, $edit(1), [], 'agent');
ds2_t("technician cannot untick afterwards ($a)", $row()['r'] === '1' && str_starts_with($a, 'error'));
runPostHandler('change.php', 2, ['set_change_status' => 1, 'change_id' => 1, 'status' => 'approved'], [], 'agent');
ds2_t('status gate still blocks approval', $row()['s'] === 'draft');
runPostHandler('change.php', 1, $edit(1), [], 'agent');
ds2_t('admin without a reason is refused', $row()['r'] === '1');
runPostHandler('change.php', 1, $edit(1, ['approval_override_reason' => 'Client agreed by phone']), [], 'agent');
ds2_t('admin with a reason can untick', $row()['r'] === '0');

// a brand-new draft that never had an approval requested stays freely editable
$m->query("INSERT INTO changes (change_id, title, risk, client_id, requires_client_approval, client_decision, status, url_key) VALUES (2, 'N', 'low', 5, 1, 'pending', 'draft', 'k2')");
runPostHandler('change.php', 2, $edit(2, ['title' => 'N']), [], 'agent');
ds2_t('fresh draft: technician can untick', (int) ds2_one($m, "SELECT requires_client_approval FROM changes WHERE change_id=2") === 0);
ds2_done();
