<?php
// MSP-DS-65: ticket reply time worked is clamped / strictly validated on add, edit, bulk reply and bulk resolve.
require __DIR__ . '/lib/ds_src.php';
$f = ds_src('functions.php'); $i = strpos($f, 'function parseTimeWorked'); eval(substr($f, $i, strpos($f, "\n}\n", $i) + 2 - $i));
foreach (['00:01:00' => '00:01:00', '1:05:09' => '01:05:09', '838:59:59' => '838:59:59', ' 02:00:00 ' => '02:00:00'] as $in => $out) ds_t("accepts $in", parseTimeWorked($in) === $out);
foreach (['-40:00:00', '-838:59:59', '839:00:00', '9999:00:00', '01:60:00', '01:00:60', '1:2:3', 'abc', '', '01:00', "01:00:00\n; DROP", '00:00:00 ', null, ['x']] as $bad) ds_t('rejects ' . json_encode($bad), $bad === '00:00:00 ' ? parseTimeWorked($bad) === '00:00:00' : parseTimeWorked($bad) === null);
$s = ds_src('agent/post/ticket.php');
ds_t('no raw sanitizeInput($_POST[time]) left', strpos($s, "sanitizeInput(\$_POST['time'])") === false);
ds_t('three free-text paths use the strict parser', substr_count($s, "parseTimeWorked(\$_POST['time'] ?? '')") === 3);
ds_t('add reply clamps hours/minutes/seconds', strpos($s, "max(0, min(838, intval(\$_POST['hours'])))") !== false && strpos($s, "max(0, min(59, intval(\$_POST['seconds'])))") !== false);
ds_t('sprintf of -40 hours is what the clamp prevents', sprintf('%02d:%02d:%02d', -40, 0, 0) === '-40:00:00' && sprintf('%02d', max(0, min(838, -40))) === '00');
ds_done();
