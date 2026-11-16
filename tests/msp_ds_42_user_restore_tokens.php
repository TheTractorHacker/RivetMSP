<?php
// MSP-DS-42: restore validates the password before reactivating; archive/disable/restore revoke remember-me tokens and sessions.
require __DIR__ . '/lib/ds_src.php';
$s = ds_src('admin/post/users.php');
function blk($s, $marker) { $i = strpos($s, $marker); return substr($s, $i, strpos($s, "\nif (isset(", $i + 10) - $i ?: 4000); }
$restore = blk($s, "isset(\$_POST['restore_user'])");
ds_t('policy check precedes the reactivating UPDATE', strpos($restore, 'secPasswordPolicyError') < strpos($restore, 'user_status = 1'));
ds_t('restore revokes remember-me', strpos($restore, 'secSessionsOnPasswordChange') !== false);
ds_t('archive revokes remember-me', strpos(blk($s, "isset(\$_POST['archive_user'])"), 'secSessionsOnPasswordChange') !== false);
ds_t('disable revokes remember-me', strpos(blk($s, "isset(\$_GET['disable_user'])"), 'secSessionsOnPasswordChange') !== false);
ds_done();
