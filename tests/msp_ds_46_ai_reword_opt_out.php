<?php
// MSP-DS-46: Reword honours clients.client_ai_opt_out (ai_create_document_template carries no client data: not changed).
require __DIR__ . '/lib/ds_src.php';
$a = ds_src('agent/ajax.php');
$b = substr($a, strpos($a, "isset(\$_GET['ai_reword'])"), 3500);
ds_t('ticket id resolves to the ticket client', strpos($b, 'SELECT ticket_client_id FROM tickets WHERE ticket_id = $reword_ticket_id') !== false);
ds_t('client access enforced', strpos($b, 'enforceClientAccess($reword_client_id)') !== false);
ds_t('opted-out client refused before the provider call', strpos($b, 'aiEnabled($reword_client_id)') !== false && strpos($b, 'aiEnabled($reword_client_id)') < strpos($b, 'aiChat('));
ds_t('aiChat gets the client id', strpos($b, "'client_id' => \$reword_client_id") !== false);
$js = ds_src('js/app.js');
ds_t('all 3 reword buttons send the context', substr_count($js, 'rewordContext(editor)') === 4);
ds_done();
