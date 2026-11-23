<?php
// MSP-DS-53: GET unlink handlers on the contact page validate the GET csrf token (the links carry it in the query string).
require __DIR__ . '/lib/ds_src.php';
$s = ds_src('agent/post/contact.php');
foreach (['unlink_asset_from_contact', 'unlink_software_from_contact'] as $h) {
    $i = strpos($s, "isset(\$_GET['$h'])");
    $b = substr($s, $i, 200);
    ds_t("$h validates the GET token", strpos($b, "validateCSRFToken(\$_GET['csrf_token'])") !== false && strpos($b, '$_POST') === false);
}
$d = ds_src('agent/contact_details.php');
ds_t('links carry csrf_token in the query', preg_match('/unlink_asset_from_contact[^"\']*csrf_token=/', $d) === 1 && preg_match('/unlink_software_from_contact[^"\']*csrf_token=/', $d) === 1);
ds_done();
