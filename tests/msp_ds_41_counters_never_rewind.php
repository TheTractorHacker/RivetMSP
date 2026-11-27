<?php
// MSP-DS-41: settings forms may raise the ticket/invoice/quote number counters, never rewind them.
require __DIR__ . '/lib/ds_src.php';
foreach (['settings_ticket' => ['config_ticket_next_number'], 'settings_invoice' => ['config_invoice_next_number', 'config_recurring_invoice_next_number'], 'settings_quote' => ['config_quote_next_number']] as $f => $cols) {
    $s = ds_src("admin/post/$f.php");
    foreach ($cols as $c) {
        ds_t("$f: $c written with GREATEST", strpos($s, "$c = GREATEST($c, \$$c)") !== false);
        ds_t("$f: no bare write of $c", !preg_match('/' . $c . ' = \$' . $c . '/', $s));
    }
    exec('php -l ' . escapeshellarg(__DIR__ . "/../admin/post/$f.php") . ' 2>&1', $o, $rc); ds_t("lint $f", $rc === 0);
}
ds_done();
