<?php
// MSP-DS-70: project page, project kanban and Gantt feed apply the client restriction to every ticket-joined query. Run:
//   SCRATCH_TABLES="tasks tickets" bash tests/lib/with_scratch_db.sh php tests/msp_ds_70_project_client_scope.php
require __DIR__ . '/lib/ds_src.php';
$d = ds_src('agent/project_details.php');
ds_t('closed/resolved counts scoped', substr_count($d, 'ticket_project_id = $project_id $project_ticket_scope AND ticket_') === 2);
ds_t('time, collaborators and budget scoped', substr_count($d, 'ticket_reply_archived_at IS NULL AND ticket_project_id = $project_id $project_ticket_scope') === 3);
ds_t('tasks scoped (with the OR parenthesised)', strpos($d, "(tickets.ticket_project_id = \$project_id\n        OR tasks.task_project_id = \$project_id)\n        \$project_task_scope") !== false);
ds_t('kanban scoped', strpos(ds_src('agent/project_kanban.php'), '$project_task_scope' . "\n     ORDER BY") !== false);
ds_t('gantt scoped', strpos(ds_src('agent/ajax.php'), '$gantt_task_scope' . "\n         AND tasks.task_start") !== false);
if (getenv('RIVETMSP_TEST_DB') === '1' && preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
    $m->query("INSERT INTO tickets (ticket_id, ticket_client_id, ticket_project_id, ticket_prefix, ticket_number) VALUES (1, 5, 9, 'T', 1), (2, 6, 9, 'T', 2), (3, 0, 9, 'T', 3)");
    $m->query("INSERT INTO tasks (task_id, task_name, task_ticket_id, task_project_id) VALUES (1,'mine',1,NULL),(2,'other client',2,NULL),(3,'internal ticket',3,NULL),(4,'project only',0,9)");
    $client_access_string = '5'; $project_id = 9;
    $project_task_scope = "AND (tickets.ticket_id IS NULL OR tickets.ticket_client_id = 0 OR tickets.ticket_client_id IN ($client_access_string))";
    $r = $m->query("SELECT tasks.task_name FROM tasks LEFT JOIN tickets ON tickets.ticket_id = tasks.task_ticket_id WHERE (tickets.ticket_project_id = $project_id OR tasks.task_project_id = $project_id) $project_task_scope ORDER BY task_id");
    $names = array_column($r->fetch_all(MYSQLI_ASSOC), 'task_name');
    ds_t('restricted to client 5 sees: ' . implode(', ', $names), $names === ['mine', 'internal ticket', 'project only']);
}
ds_done();
