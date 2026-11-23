<?php
// MSP-DS-60: revenue add/edit/delete need module_financial (2/2/3), refuse negative amounts and transfer-owned rows.
require __DIR__ . '/lib/ds_src.php';
$s = ds_src('agent/post/revenue.php');
ds_t('no module_sales left', strpos($s, 'module_sales') === false);
ds_t('add needs financial 2', preg_match("/add_revenue'\]\)\) \{\s+validateCSRFToken\(\\\$_POST\['csrf_token'\]\);\s+enforceUserPermission\('module_financial', 2\)/", $s) === 1);
ds_t('edit needs financial 2', preg_match("/edit_revenue'\]\)\) \{\s+validateCSRFToken\(\\\$_POST\['csrf_token'\]\);\s+enforceUserPermission\('module_financial', 2\)/", $s) === 1);
ds_t('delete needs financial 3', preg_match("/delete_revenue'\]\)\) \{\s+validateCSRFToken\(\\\$_GET\['csrf_token'\]\);\s+enforceUserPermission\('module_financial', 3\)/", $s) === 1);
ds_t('negative amounts refused on add and edit', substr_count($s, 'if ($amount < 0)') === 2);
ds_t('transfer rows protected on edit and delete', substr_count($s, 'transfer_revenue_id = $revenue_id') === 2);
ds_t('page button follows the financial module', strpos(ds_src('agent/revenues.php'), 'lookupUserPermission("module_financial") >= 2') !== false);
ds_done();
