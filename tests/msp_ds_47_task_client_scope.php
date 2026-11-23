<?php
// MSP-DS-47 + MSP-DS-45. Run:
//   SCRATCH_TABLES="tasks tickets projects" tests/lib/with_scratch_db.sh php tests/msp_ds_47_task_client_scope.php
require __DIR__ . '/lib/ds_src.php';
$src = ds_src('agent/ajax.php');
// MSP-DS-45: kanban drag skips the client check for internal (client 0) tickets
$k = substr($src, strpos($src, "isset(\$_POST['update_kanban_ticket'])"), 1500);
ds_t('45: enforceClientAccess only when the ticket has a client', preg_match('/if \(\$client_id\) \{\s+enforceClientAccess\(\);/', $k) === 1);
// MSP-DS-47: both endpoints go through the helper
foreach (['update_task_schedule', 'update_project_task_kanban'] as $ep) {
    $b = substr($src, strpos($src, "isset(\$_POST['$ep'])"), 2200);
    ds_t("47: $ep resolves clients via ajaxTaskClientIds", strpos($b, 'ajaxTaskClientIds(') !== false && strpos($b, 'enforceClientAccess($task_client_id)') !== false);
}
if (getenv('RIVETMSP_TEST_DB') === '1' && preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
    $mysqli->query("SET SESSION sql_mode=''");
    $i = strpos($src, 'function ajaxTaskClientIds'); $j = strpos($src, "\n}\n", $i) + 2;
    eval(substr($src, $i, $j - $i));
    $mysqli->query("INSERT INTO projects (project_id, project_name, project_client_id) VALUES (7, 'p', 22)");
    $mysqli->query("INSERT INTO tickets (ticket_id, ticket_client_id, ticket_project_id) VALUES (1, 11, 0), (2, 0, 0), (3, 11, 7)");
    $mysqli->query("INSERT INTO tasks (task_id, task_ticket_id, task_project_id) VALUES (1, 1, NULL), (2, 2, NULL), (3, 0, 7), (4, 3, NULL), (5, 0, NULL)");
    ds_t('47: ticket task resolves to the ticket client (the old code resolved nothing)', ajaxTaskClientIds(1) === [11]);
    ds_t('47: internal ticket task has no client', ajaxTaskClientIds(2) === []);
    ds_t('47: project task resolves to the project client', ajaxTaskClientIds(3) === [22]);
    ds_t('47: ticket task in a project checks both clients', ajaxTaskClientIds(4) === [11, 22]);
    ds_t('47: task with neither ticket nor project is refused', ajaxTaskClientIds(5) === null);
    ds_t('47: unknown task', ajaxTaskClientIds(99) === null);
} else { echo "skip - DB part needs scratch DB\n"; }
ds_done();
