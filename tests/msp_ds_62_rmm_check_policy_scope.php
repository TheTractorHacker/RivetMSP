<?php
// MSP-DS-62: check policy push/remove/save/delete refuse client-restricted users; delete needs level 3.
require __DIR__ . '/lib/ds_src.php';
$s = ds_src('agent/post/rmm_check.php');
ds_t('restricted users refused for the 4 global actions', strpos($s, "in_array(\$action, ['push_policy', 'remove_policy', 'save_policy', 'delete_policy'], true) && !\$session_is_admin && !empty(\$client_access_string)") !== false);
ds_t('guard runs before any action branch', strpos($s, '!empty($client_access_string)') < strpos($s, "if (\$action === 'push_policy')"));
ds_t('delete needs level 3', preg_match("/action === 'delete_policy'\) \{[^}]*?enforceUserPermission\('module_rmm', 3\)/s", $s) === 1);
// the restriction predicate is the same one every list page uses
$u = ds_src('includes/load_user_session.php');
ds_t('predicate matches load_user_session', strpos($u, 'if ($client_access_string && !$session_is_admin)') !== false);
ds_done();
