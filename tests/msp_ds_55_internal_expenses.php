<?php
// MSP-DS-55: expense / recurring expense handlers do not call enforceClientAccess() for client 0 (it refuses every non-admin on an empty id).
require __DIR__ . '/lib/ds_src.php';
foreach (['agent/post/expense.php', 'agent/post/recurring_expense.php'] as $f) {
    $s = ds_src($f);
    ds_t("$f has no bare enforceClientAccess() call", !preg_match('/^\s*enforceClientAccess\(\);/m', $s));
    ds_t("$f has guarded checks", substr_count($s, 'if ($client_id) { enforceClientAccess(); }') >= 3);
}
foreach (['agent/modals/expense/expense_copy.php', 'agent/modals/expense/expense_refund.php'] as $f) {
    ds_t("$f guards the check", strpos(ds_src($f), 'if ($expense_client_id) { enforceClientAccess($expense_client_id); }') !== false);
}
// the reason: the real function refuses an empty client for a non-admin
$fn = ds_src('functions.php'); $i = strpos($fn, 'function enforceClientAccess');
ds_t('enforceClientAccess still refuses empty client ids (so the callers must guard)', strpos(substr($fn, $i, 900), 'empty($client_id)') !== false);
ds_done();
