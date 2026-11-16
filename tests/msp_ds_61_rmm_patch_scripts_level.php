<?php
// MSP-DS-61: patch_scan needs module_rmm_scripts >= 2, patch_install >= 3 (Core's EndpointAccessPolicy), on top of module_rmm write.
require __DIR__ . '/lib/ds_src.php';
$s = ds_src('agent/post/rmm_action.php');
ds_t('patch branch checks the scripts module', strpos($s, "enforceUserPermission('module_rmm_scripts', \$action === 'patch_install' ? 3 : 2);") !== false);
ds_t('module_rmm write still required', strpos($s, "} else {\n    enforceUserPermission('module_rmm', 2);") !== false);
$pol = @file_get_contents(__DIR__ . '/../src/Core/Adapter/Endpoint/EndpointAccessPolicy.php');
if ($pol !== false) { ds_t('matches EndpointAccessPolicy (install>=3, manage>=2)', preg_match('/PATCH_INSTALL[^\n]*3/', $pol) === 1 && preg_match('/PATCH_MANAGE[^\n]*2/', $pol) === 1); }
ds_done();
