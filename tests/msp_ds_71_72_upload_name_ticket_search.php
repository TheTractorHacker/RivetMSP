<?php
// MSP-DS-71: checkFileUpload names always match the callers' [A-Za-z0-9] pattern. MSP-DS-72: ticket search term reaches the vendor ticket number.
require __DIR__ . '/lib/ds_src.php';
function randomString(int $length = 16): string { $b = random_bytes((int) ceil($length * 3 / 4)); return substr(rtrim(strtr(base64_encode($b), '+/', '-_'), '='), 0, $length); }
$f = ds_src('functions.php'); $i = strpos($f, "\nfunction checkFileUpload(") + 1; eval(substr($f, $i, strpos($f, "\n}\n", $i) + 2 - $i));
$tmp = tempnam(sys_get_temp_dir(), 'up'); file_put_contents($tmp, 'x');
$bad = 0; for ($n = 0; $n < 20000; $n++) { $name = checkFileUpload(['name' => 'a.png', 'tmp_name' => $tmp, 'size' => 1], ['png']); if (!is_string($name) || !preg_match('/^[a-zA-Z0-9]+\.[a-zA-Z0-9]+$/', $name)) { $bad++; } }
unlink($tmp);
ds_t("20000 generated names all match the caller pattern (bad: $bad)", $bad === 0);
$t = ds_src('agent/tickets.php');
ds_t("vendor ticket number searched with the term", strpos($t, "ticket_vendor_ticket_number LIKE '%\$q%'") !== false && strpos($t, "LIKE '%q%'") === false);
ds_t('billable vars initialised before the if', strpos($t, '$billable = 0;') < strpos($t, "isset(\$_GET['billable'])"));
ds_t('kanban search is the same shape', strpos(ds_src('agent/ticket_kanban.php'), "ticket_vendor_ticket_number LIKE '%\$q%'") !== false);
ds_done();
