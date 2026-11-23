<?php
// MSP-DS-58: agent auto-pay setup only accepts this client's saved method. Run:
//   SCRATCH_TABLES="clients payment_providers client_saved_payment_methods" bash tests/lib/with_scratch_db.sh php tests/msp_ds_58_autopay_saved_card_client.php
require __DIR__ . '/lib/ds_src.php';
$s = ds_src('agent/post/recurring_invoice.php');
$i = strpos($s, "SELECT * FROM payment_providers\n            LEFT JOIN client_saved_payment_methods");
ds_t('lookup is scoped to the client and an active provider', $i !== false && strpos(substr($s, $i, 400), 'saved_payment_client_id = $client_id') !== false && strpos(substr($s, $i, 400), 'payment_provider_active = 1') !== false);
ds_t('missing row is refused before the INSERT', strpos($s, 'Saved payment method not found for this client.') !== false && strpos($s, 'Saved payment method not found') < strpos($s, 'INSERT INTO recurring_payments SET recurring_payment_currency_code'));
if (getenv('RIVETMSP_TEST_DB') === '1') {
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
    $m->query("SET SESSION sql_mode=''");
    $m->query("SET FOREIGN_KEY_CHECKS=0");
    $m->query("INSERT INTO payment_providers (payment_provider_id, payment_provider_name, payment_provider_active) VALUES (1, 'Stripe', 1)");
    $m->query("INSERT INTO client_saved_payment_methods (saved_payment_id, saved_payment_provider_id, saved_payment_client_id, saved_payment_description) VALUES (10, 1, 5, 'Visa 4242 - Own'), (11, 1, 6, 'Visa 1111 - Other')");
    preg_match('/mysqli_query\(\$mysqli, "\s*(SELECT \* FROM payment_providers.*?)"\);/s', $s, $mm);
    foreach ([[10, 5, true], [11, 5, false], [10, 6, false]] as [$sp, $cl, $exp]) {
        $q = str_replace(['$saved_payment_id', '$client_id'], [$sp, $cl], $mm[1]);
        $r = $m->query($q)->fetch_assoc();
        ds_t("saved $sp for client $cl " . ($exp ? 'found' : 'not found'), (bool) $r === $exp);
    }
}
ds_done();
