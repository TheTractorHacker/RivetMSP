<?php
// MSP-DS-9: bulk mail needs Write and a configured From; agent add_category/add_tag are admin only. Run: php tests/msp_ds_9_bulk_mail_category_tag.php
require dirname(__DIR__) . '/includes/bulk_mail_scope.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$cfg = ['Support@msp.test', 'billing@msp.test', '', 'not-an-email'];
$ok('configured address (any case) allowed', bulkMailFromAllowed('support@msp.test', $cfg) && bulkMailFromAllowed(' BILLING@msp.test ', $cfg));
$ok('spoofed address refused', !bulkMailFromAllowed('ceo@victim.test', $cfg));
$ok('empty From refused even though an empty config entry exists', !bulkMailFromAllowed('', $cfg));
$ok('invalid configured entry is not an allowed value', !bulkMailFromAllowed('not-an-email', $cfg));
$root = dirname(__DIR__);
$c = file_get_contents("$root/agent/post/client.php"); $i = strpos($c, "isset(\$_POST['bulk_send_client_email'])"); $b = substr($c, $i, 900);
$ok('client bulk mail requires module_client write', strpos($b, "enforceUserPermission('module_client', 2)") !== false);
$ok('client bulk mail pins From', strpos(substr($c, $i, 2500), 'bulkMailFromAllowed') !== false);
$c = file_get_contents("$root/agent/post/contact.php"); $i = strpos($c, "isset(\$_POST['send_bulk_mail_now'])"); $b = substr($c, $i, 2500);
$ok('contact bulk mail requires module_client write', strpos($b, "enforceUserPermission('module_client', 2)") !== false);
$ok('contact bulk mail pins From', strpos($b, 'bulkMailFromAllowed') !== false);
$ok('contact bulk mail checks each contact\'s own client', strpos($b, 'enforceClientAccess($client_id)') !== false);
foreach (['category', 'tag'] as $f) {
    $c = file_get_contents("$root/agent/post/$f.php");
    $ok("agent $f creation is admin only", preg_match('/validateCSRFToken.*enforceAdminPermission\(\).*INSERT/s', $c) === 1);
}
exit($fail ? 1 : 0);
