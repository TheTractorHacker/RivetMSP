<?php
// MSP-DS-64: task approval gate enforced server side. Run:
//   SCRATCH_TABLES="tasks task_approvals" bash tests/lib/with_scratch_db.sh php tests/msp_ds_64_task_approval_gate.php
require __DIR__ . '/lib/ds_src.php';
$t = ds_src('agent/post/task.php');
$add = substr($t, strpos($t, "isset(\$_POST['add_ticket_task_approver'])"), 1800);
ds_t('scope/type whitelisted', strpos($add, "in_array(\$scope, ['internal', 'client'], true)") !== false && strpos($add, "in_array(\$type, ['any', 'technical', 'billing', 'specific'], true)") !== false);
ds_t('specific approver cannot be the creator', strpos($add, '$required_user_id === intval($session_user_id)') !== false);
$ap = substr($t, strpos($t, "isset(\$_GET['approve_ticket_task'])"), 4000);
ds_t('approve: client access enforced', strpos($ap, 'enforceClientAccess($approval_client_id)') !== false);
ds_t('approve: pending required before and in the UPDATE', strpos($ap, "approval_status'] !== 'pending'") !== false && strpos($ap, "AND approval_status = 'pending'") !== false && strpos($ap, 'mysqli_affected_rows') !== false);
ds_t('approve: creator can never approve, whatever the type', strpos($ap, 'if ($created_by == $session_user_id)') !== false && strpos($ap, "\$type == 'any'") === false);
$del = substr($t, strpos($t, "isset(\$_GET['delete_ticket_task_approver'])"), 2500);
ds_t('delete: client access before the DELETE', strpos($del, 'enforceClientAccess($del_client_id)') < strpos($del, 'DELETE FROM task_approvals'));
ds_t('complete_task refuses while pending/declined', strpos($t, 'taskApprovalBlocksCompletion($task_id)') !== false);
ds_t('project kanban Done refuses too', strpos(ds_src('agent/ajax.php'), "\$status_raw === 'Done' && taskApprovalBlocksCompletion(\$task_id)") !== false);
if (getenv('RIVETMSP_TEST_DB') === '1' && preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
    $mysqli->query("SET SESSION sql_mode=''"); $mysqli->query("SET FOREIGN_KEY_CHECKS=0");
    $f = ds_src('functions.php'); $i = strpos($f, 'function taskApprovalBlocksCompletion'); eval(substr($f, $i, strpos($f, "\n}\n", $i) + 2 - $i));
    $mysqli->query("INSERT INTO tasks (task_id, task_name, task_ticket_id) VALUES (1,'a',9),(2,'b',9),(3,'c',9),(4,'d',9)");
    $mysqli->query("INSERT INTO task_approvals (approval_scope, approval_type, approval_status, approval_created_by, approval_url_key, approval_task_id) VALUES ('internal','any','pending',1,'k1',1),('internal','any','declined',1,'k2',2),('internal','any','approved',1,'k3',3)");
    ds_t('pending blocks', taskApprovalBlocksCompletion(1)); ds_t('declined blocks', taskApprovalBlocksCompletion(2));
    ds_t('approved does not block', !taskApprovalBlocksCompletion(3)); ds_t('no approval does not block', !taskApprovalBlocksCompletion(4));
    // the real complete_all UPDATE
    preg_match('/mysqli_query\(\$mysqli, "(UPDATE tasks SET task_completed_at = NOW\(\), task_completed_by = \$session_user_id WHERE task_ticket_id = \$ticket_id AND task_completed_at IS NULL AND NOT EXISTS[^"]*)"\);/', $t, $m);
    $sql = str_replace(['$session_user_id', '$ticket_id'], ['1', '9'], $m[1]); $mysqli->query($sql);
    $done = array_column($mysqli->query("SELECT task_id FROM tasks WHERE task_completed_at IS NOT NULL ORDER BY task_id")->fetch_all(MYSQLI_ASSOC), 'task_id');
    ds_t('complete-all skips tasks with pending/declined approvals: ' . json_encode($done), $done === ['3', '4'] || $done === [3, 4]);
}
ds_done();
