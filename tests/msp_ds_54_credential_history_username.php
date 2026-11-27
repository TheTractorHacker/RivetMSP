<?php
// MSP-DS-54: the credential history never stores or shows a username in clear.
require __DIR__ . '/lib/ds_src.php';
$s = ds_src('agent/post/credential.php');
$i = strpos($s, "if (\$field === 'Username')");
ds_t('Username branch exists inside the history loop', $i !== false && $i < strpos($s, "history_field = '\$h_field'"));
$b = substr($s, $i, 500);
ds_t('Username row stores NULL old/new and skips the plaintext insert', strpos($b, "history_old_value = NULL, history_new_value = NULL") !== false && strpos($b, 'continue;') !== false);
$m = ds_src('agent/modals/credential/credential_edit.php');
ds_t('modal hides legacy Username rows', strpos($m, "\$h_field === 'Username'") !== false);
ds_done();
