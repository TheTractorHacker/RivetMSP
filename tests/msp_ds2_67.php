<?php
// MSP-DS-67 residuals: template-task edit is admin-only, change approval gate, existence oracles scoped, CSV templates permission-checked. Run:
//   SCRATCH_TABLES="changes" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_67.php
require __DIR__ . '/lib/ds_src.php';
function blk($src, $marker, $len = 1800) { $i = strpos($src, $marker); return $i === false ? '' : substr($src, $i, $len); }
$task = ds_src('agent/post/task.php');
$b = blk($task, "isset(\$_POST['edit_ticket_template_task'])", 700);
ds_t('edit_ticket_template_task requires admin', strpos($b, 'enforceAdminPermission()') !== false && strpos($b, "enforceUserPermission('module_support'") === false);
$aj = ds_src('agent/ajax.php');
ds_t('template task reorder requires admin', strpos(blk($aj, "isset(\$_POST['update_task_templates_order'])", 600), 'enforceAdminPermission()') !== false);
ds_t('client_duplicate_check is client-scoped', strpos(blk($aj, "isset(\$_GET['client_duplicate_check'])", 900), '$access_permission_query') !== false);
ds_t('contact_email_check is client-scoped', strpos(blk($aj, "isset(\$_GET['contact_email_check'])", 1200), '$access_permission_query') !== false);
$as = ds_src('agent/post/asset.php');
foreach (['download_assets_csv_template', 'download_client_asset_interfaces_csv_template'] as $n) {
    $b = blk($as, "isset(\$_GET['$n'])", 900);
    ds_t("$n checks permission and client access", strpos($b, "enforceUserPermission('module_support', 2)") !== false && strpos($b, 'enforceClientAccess(') !== false);
    ds_t("$n checks before reading the client/asset name", strpos($b, 'enforceUserPermission') < strpos($b, 'SELECT'));
}
$vc = blk(ds_src('agent/post/vendor_contact.php'), "isset(\$_GET['download_vendor_contacts_csv_template'])", 900);
ds_t('vendor contact template checks permission and client access', strpos($vc, "enforceUserPermission('module_client', 2)") !== false && strpos($vc, 'enforceClientAccess(') !== false);
$ch = ds_src('agent/post/change.php');
$b = blk($ch, "isset(\$_POST['set_change_status'])", 1800);
ds_t('set_change_status checks client approval before setStatus', strpos($b, 'changeClientApprovalSatisfied(') !== false && strpos($b, 'changeClientApprovalSatisfied(') < strpos($b, '->setStatus('));
if (getenv('RIVETMSP_TEST_DB') === '1' && preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
    $mysqli->query("SET SESSION sql_mode=''");
    $f = ds_src('functions.php'); $i = strpos($f, 'function changeClientApprovalSatisfied'); eval(substr($f, $i, strpos($f, "\n}\n", $i) + 2)); // the real helper, extracted from functions.php
    $ins = function ($id, $req, $dec) use ($mysqli) { $mysqli->query("INSERT INTO changes (change_id, title, risk, client_id, requires_client_approval, client_decision) VALUES ($id, 'T', 'low', 1, $req, '$dec')"); };
    $ins(1, 0, 'pending'); $ins(2, 1, 'pending'); $ins(3, 1, 'approved'); $ins(4, 1, 'rejected');
    ds_t('no client approval required: satisfied', changeClientApprovalSatisfied(1) === true);
    ds_t('required and pending: NOT satisfied', changeClientApprovalSatisfied(2) === false);
    ds_t('required and approved: satisfied', changeClientApprovalSatisfied(3) === true);
    ds_t('required and rejected: NOT satisfied', changeClientApprovalSatisfied(4) === false);
    ds_t('unknown change: left to setStatus', changeClientApprovalSatisfied(99) === true);
}
ds_done();
