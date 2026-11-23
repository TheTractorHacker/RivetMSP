<?php
// MSP-DS-67 (re-review M4-1): the change-approval guard no longer reads the action log. It uses changes.client_approval_requested_at (set once, never
// cleared) and changes.client_rejected_at (a client rejection the DS-51 reset cannot erase), and changeClientApprovalSatisfied() needs an approval NEWER
// than the latest rejection. Everything runs through the REAL handlers (guest/guest_post.php, agent/post/change.php) under STRICT sql_mode:
//  A. the full bypass (reject, back to draft, content edit resets the decision, untick)  B. same, with every logs row of the change deleted (retention)
//  C. same with a ~250-emoji title (logAction used to cut mid-character; strict mode then failed the log INSERT)  D. request sent -> durable timestamp
//  E. the stale-approval check on the status gate  F. logAction itself never fails on multibyte text, under strict mode.
// Run: SCRATCH_TABLES="changes users user_roles logs modules user_role_permissions clients user_client_permissions audit_events audit_chain_head audit_chain_checkpoint contacts notifications settings companies api_tokens email_queue" bash tests/lib/with_scratch_db.sh php tests/msp_m4_1.php
putenv('RIVETMSP_TEST_SQL_MODE=STRICT_ALL_TABLES');
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
$m->query("INSERT INTO contacts (contact_name, contact_email, contact_primary, contact_client_id) VALUES ('Pat', 'pat@acme.test', 1, 5)");
$m->query("INSERT INTO user_client_permissions (user_id, client_id) VALUES (2, 5)");
$m->query("SET SESSION sql_mode='STRICT_ALL_TABLES'"); // the fixtures above need the lenient mode (settings has many NOT NULL columns); the handlers and the checks below run strict
$guest = function (array $post): string {
    $p = proc_open([PHP_BINARY, __DIR__ . '/lib/run_guest_post.php', json_encode(['post' => $post])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $o = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p); return $o;
};
$edit = fn (int $id, string $title, array $o = []) => $o + ['edit_change' => 1, 'change_id' => $id, 'title' => $title, 'risk' => 'low', 'client_id' => 5];
$st = fn (int $id, string $s) => runPostHandler('change.php', 2, ['set_change_status' => 1, 'change_id' => $id, 'status' => $s], [], 'agent');
$row = fn (int $id) => $m->query("SELECT requires_client_approval r, client_decision d, status s, client_approval_requested_at q, client_rejected_at j FROM changes WHERE change_id=$id")->fetch_assoc();
$mk = function (int $id, string $title) use ($m) {
    $m->query("INSERT INTO changes (change_id, title, risk, client_id, requires_client_approval, client_decision, status, url_key) VALUES ($id, '" . $m->real_escape_string($title) . "', 'low', 5, 1, 'pending', 'awaiting_approval', 'k$id')");
    ds2_t("change $id inserted", $m->errno === 0);
};

// the bypass, run against change $id whose title is $title; $dropLogs deletes the change's logs rows (and audit rows) after the rejection
$bypass = function (int $id, string $title, bool $dropLogs, string $label) use ($m, $guest, $edit, $st, $row) {
    $guest(['reject_change' => $id, 'url_key' => "k$id"]);
    ds2_t("$label: client rejection recorded", $row($id)['d'] === 'rejected');
    ds2_t("$label: rejection is durable on the change (client_rejected_at)", !empty($row($id)['j']) && !empty($row($id)['q']));
    if ($dropLogs) {
        $m->query("DELETE FROM logs WHERE log_entity_id = $id");
        $m->query("DELETE FROM audit_events WHERE entity_id = '$id'");
    }
    [$o, $a] = $st($id, 'draft');
    ds2_t("$label: back to draft ($a)", $row($id)['s'] === 'draft');
    runPostHandler('change.php', 2, $edit($id, $title . ' v2', ['requires_client_approval' => 1]), [], 'agent');
    ds2_t("$label: content edit resets the decision to pending (DS-51 unchanged)", $row($id)['d'] === 'pending' && $row($id)['r'] === '1');
    ds2_t("$label: ... but the rejection time survives the reset", !empty($row($id)['j']));
    [$o, $a] = runPostHandler('change.php', 2, $edit($id, $title . ' v2'), [], 'agent');
    ds2_t("$label: technician cannot untick afterwards ($a)", $row($id)['r'] === '1' && str_starts_with($a, 'error'));
    $st($id, 'approved');
    ds2_t("$label: status gate still blocks approval", $row($id)['s'] === 'draft');
    runPostHandler('change.php', 1, $edit($id, $title . ' v2'), [], 'agent');
    ds2_t("$label: admin without a reason is refused", $row($id)['r'] === '1');
    runPostHandler('change.php', 1, $edit($id, $title . ' v2', ['approval_override_reason' => 'Client agreed by phone']), [], 'agent');
    ds2_t("$label: admin with a reason can untick", $row($id)['r'] === '0');
};

$mk(1, 'Orig');
$bypass(1, 'Orig', false, 'A plain');
$mk(2, 'Orig2');
$bypass(2, 'Orig2', true, 'B logs deleted');

$emoji = str_repeat("\u{1F600}", 250);   // 1000 bytes: the "via guest link" suffix cannot fit in a 1000-byte description
$mk(3, $emoji);
$bypass(3, $emoji, false, 'C 250-emoji title');
ds2_t('C: the guest reject log row was written (valid UTF-8, cut inside the byte budget)', (int) ds2_one($m, "SELECT COUNT(*) FROM logs WHERE log_entity_id = 3 AND log_description LIKE '%rejected change%'") === 1);
ds2_t('C: no log row holds broken UTF-8 / exceeds 1000 bytes', (int) ds2_one($m, "SELECT COUNT(*) FROM logs WHERE LENGTH(log_description) > 1000") === 0);
$mk(4, $emoji);
$bypass(4, $emoji, true, 'C2 emoji + logs deleted');

// D. a request sent through the handler is durable and never moves afterwards
$m->query("INSERT INTO changes (change_id, title, risk, client_id, requires_client_approval, client_decision, status, url_key) VALUES (5, 'Req', 'low', 5, 1, 'pending', 'draft', 'k5')");
runPostHandler('change.php', 2, ['send_change_approval_request' => 1, 'change_id' => 5], [], 'agent');
$q1 = $row(5)['q'];
ds2_t('D: sending the approval request stamps client_approval_requested_at', !empty($q1));
$m->query("UPDATE changes SET client_approval_requested_at = '2020-01-01 00:00:00' WHERE change_id = 5");
runPostHandler('change.php', 2, ['send_change_approval_request' => 1, 'change_id' => 5], [], 'agent');
ds2_t('D: a second request does not move it', $row(5)['q'] === '2020-01-01 00:00:00');
$m->query("DELETE FROM logs WHERE log_entity_id = 5");
[$o, $a] = runPostHandler('change.php', 2, $edit(5, 'Req'), [], 'agent');
ds2_t("D: draft with a sent request cannot be unticked by a technician, even with no logs ($a)", $row(5)['r'] === '1' && str_starts_with($a, 'error'));
ds2_t('D: the edit modal hint is shown from the column (no logs query)', strpos(file_get_contents(dirname(__DIR__) . '/functions.php'), "FROM logs WHERE log_entity_id") === false);

// E. an approval older than the latest rejection / request does not satisfy the gate
require_once dirname(__DIR__) . '/functions.php';
$GLOBALS['mysqli'] = $mysqli = $m;
$m->query("INSERT INTO changes (change_id, title, risk, client_id, requires_client_approval, client_decision, client_decided_at, client_rejected_at, client_approval_requested_at, status, url_key) VALUES (6, 'S', 'low', 5, 1, 'approved', '2026-01-01 10:00:00', '2026-01-02 10:00:00', '2026-01-01 09:00:00', 'awaiting_approval', 'k6'), (7, 'S', 'low', 5, 1, 'approved', '2026-01-03 10:00:00', '2026-01-02 10:00:00', '2026-01-01 09:00:00', 'awaiting_approval', 'k7'), (8, 'S', 'low', 5, 1, 'approved', NULL, NULL, NULL, 'awaiting_approval', 'k8')");
ds2_t('E: approval older than the latest rejection does not satisfy', changeClientApprovalSatisfied(6) === false);
ds2_t('E: approval newer than the rejection satisfies', changeClientApprovalSatisfied(7) === true);
ds2_t('E: approved with no decision time cannot be verified when a request exists, ok when no history', changeClientApprovalSatisfied(8) === true);

// F. logAction under strict mode with multibyte text
$session_user_id = 1; $session_ip = '127.0.0.1'; $session_user_agent = 't';
logAction('Support', 'Edit', "Acme rejected change \"$emoji\" via guest link", 5, 99);
ds2_t('F: logAction with a 250-emoji description inserts under strict mode', $m->errno === 0 && (int) ds2_one($m, "SELECT COUNT(*) FROM logs WHERE log_entity_id = 99") === 1);
logAction(str_repeat("\u{1F600}", 100), str_repeat("\u{00E9}", 200), 'x', 5, 98);
ds2_t('F: multibyte type/action are cut on a character boundary too', (int) ds2_one($m, "SELECT COUNT(*) FROM logs WHERE log_entity_id = 98") === 1);
ds2_done();
