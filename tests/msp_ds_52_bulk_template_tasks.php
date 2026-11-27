<?php
// MSP-DS-52: every ticket of a bulk run gets the template tasks (cursor rewound per ticket). Run:
//   SCRATCH_TABLES="task_templates" bash tests/lib/with_scratch_db.sh php tests/msp_ds_52_bulk_template_tasks.php
require __DIR__ . '/lib/ds_src.php';
foreach (['agent/post/client.php', 'agent/post/ticket.php'] as $f) {
    $s = ds_src($f);
    ds_t("$f rewinds before draining", preg_match('/mysqli_data_seek\(\$sql_task_templates, 0\);\s+while \(\$row = mysqli_fetch_assoc\(\$sql_task_templates\)\)/', $s) === 1);
}
if (getenv('RIVETMSP_TEST_DB') === '1') {
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
    $mysqli->query("INSERT INTO task_templates (task_template_name, task_template_order, task_template_ticket_template_id) VALUES ('a',1,9),('b',2,9)");
    $rs = mysqli_query($mysqli, "SELECT * FROM task_templates WHERE task_template_ticket_template_id = 9");
    $counts = [];
    foreach ([1, 2, 3] as $t) { $n = 0; mysqli_data_seek($rs, 0); while (mysqli_fetch_assoc($rs)) { $n++; } $counts[] = $n; }
    ds_t('3 iterations each see both template tasks', $counts === [2, 2, 2]);
}
ds_done();
