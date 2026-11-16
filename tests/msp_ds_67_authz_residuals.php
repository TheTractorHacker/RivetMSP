<?php
// MSP-DS-67 (fixed parts): edit_ticket_reply type whitelist; add_ticket_to_invoice same-client check.
require __DIR__ . '/lib/ds_src.php';
$t = ds_src('agent/post/ticket.php'); $i = strpos($t, "isset(\$_POST['edit_ticket_reply'])"); $b = substr($t, $i, 2500);
ds_t('edit_ticket_reply whitelists Public/Internal', strpos($b, "in_array(\$ticket_reply_type, ['Public', 'Internal'], true)") !== false);
ds_t('whitelist precedes the UPDATE', strpos($b, "in_array(\$ticket_reply_type") < strpos($b, 'UPDATE ticket_replies'));
$m = ds_src('agent/modals/ticket/ticket_reply_edit.php');
ds_t('the edit form only offers Public and Internal', preg_match_all('/name="ticket_reply_type" value="(\w+)"/', $m, $mm) && $mm[1] === ['Internal', 'Public']);
$v = ds_src('agent/post/invoice.php'); $i = strpos($v, "isset(\$_POST['add_ticket_to_invoice'])"); $b = substr($v, $i, 1500);
ds_t('invoice client must equal the ticket client before linking', strpos($b, "getFieldById('invoices', \$invoice_id, 'invoice_client_id')") !== false && strpos($b, 'ticket_invoice_id = $invoice_id') > strpos($b, '$link_invoice_client_id !== $client_id'));
ds_done();
