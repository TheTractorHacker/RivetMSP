<?php
// MSP-DS-67 (re-review MRR-14): the REAL agent/post/change.php edit_change will not let a technician switch "requires client approval" off once
// the approval has started (status past draft, or the client already decided) - an administrator can, with a logged reason. Before that a
// draft can be edited freely, and the status gate (changeClientApprovalSatisfied) therefore keeps blocking a rejected change.
// Run: SCRATCH_TABLES="changes users user_roles logs modules user_role_permissions clients user_client_permissions audit_events audit_chain_head audit_chain_checkpoint" bash tests/lib/with_scratch_db.sh php tests/msp_mrr_14.php
require __DIR__ . '/lib/ds2_migrate.php';
require __DIR__ . '/lib/handler_run.php';
$m = ds2_open();
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (91,'Admin',1),(92,'Tech',0)");
$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status) VALUES (1,'Admin','a@x.test','x',91,1,1),(2,'Tech','t@x.test','x',92,1,1)");
$m->query("INSERT INTO modules (module_id, module_name) VALUES (50,'module_support')");
$m->query("INSERT INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) VALUES (92, 50, 2)");
$m->query("INSERT INTO clients (client_id, client_name) VALUES (5, 'Acme')");
$m->query("INSERT INTO user_client_permissions (user_id, client_id) VALUES (2, 5)");
$mk = function (int $id, string $status, string $decision, int $req = 1) use ($m) {
    $m->query("DELETE FROM changes WHERE change_id = $id");
    $m->query("INSERT INTO changes (change_id, title, risk, client_id, requires_client_approval, client_decision, status, url_key) VALUES ($id, 'T', 'low', 5, $req, '$decision', '$status', 'k$id')");
};
$req = fn (int $id) => (int) ds2_one($m, "SELECT requires_client_approval FROM changes WHERE change_id = $id");
$edit = fn (int $id, array $o = []) => $o + ['edit_change' => 1, 'change_id' => $id, 'title' => 'T', 'risk' => 'low', 'client_id' => 5];

// rejected change: a technician unticking is refused and the gate still blocks
$mk(1, 'awaiting_approval', 'rejected');
[$out, $alert] = runPostHandler('change.php', 2, $edit(1), [], 'agent');
ds2_t("technician cannot untick on a rejected change ($alert)", $req(1) === 1 && str_starts_with($alert, 'error'));
[$out, $alert] = runPostHandler('change.php', 2, $edit(1, ['client_id' => 0]), [], 'agent');
ds2_t('technician cannot clear the client (which also clears the flag)', $req(1) === 1 && (int) ds2_one($m, "SELECT client_id FROM changes WHERE change_id=1") === 5);
[$out, $alert] = runPostHandler('change.php', 2, $edit(1, ['requires_client_approval' => 1]), [], 'agent');
ds2_t("technician can still edit with the box ticked ($alert)", $req(1) === 1 && str_starts_with($alert, 'success'));

// awaiting_approval, undecided: also refused for a technician
$mk(2, 'awaiting_approval', 'pending');
runPostHandler('change.php', 2, $edit(2), [], 'agent');
ds2_t('technician cannot untick once the change is awaiting approval', $req(2) === 1);

// admin without a reason: refused; with a reason: allowed and logged
runPostHandler('change.php', 1, $edit(1), [], 'agent');
ds2_t('admin without a reason is refused', $req(1) === 1);
[$out, $alert] = runPostHandler('change.php', 1, $edit(1, ['approval_override_reason' => 'Client withdrew the requirement by phone']), [], 'agent');
ds2_t("admin with a reason can untick ($alert)", $req(1) === 0);
ds2_t('the reason is in the log', (int) ds2_one($m, "SELECT COUNT(*) FROM logs WHERE log_description LIKE '%removed the client approval requirement%' AND log_description LIKE '%Client withdrew%'") === 1);

// a draft change that has not started approval is freely editable by a technician
$mk(3, 'draft', 'pending');
[$out, $alert] = runPostHandler('change.php', 2, $edit(3), [], 'agent');
ds2_t("technician can untick a draft ($alert)", $req(3) === 0);

// turning it on is never blocked
$mk(4, 'awaiting_approval', 'pending', 0);
runPostHandler('change.php', 2, $edit(4, ['requires_client_approval' => 1]), [], 'agent');
ds2_t('ticking the requirement on is allowed', $req(4) === 1);
ds2_done();
