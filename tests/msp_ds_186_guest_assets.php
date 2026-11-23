<?php
// MSP-DS-186: guest page asset paths and handlers. Run: php tests/msp_ds_186_guest_assets.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
$t = file_get_contents("$root/guest/guest_view_ticket.php");
$ok('contact avatar path uses the contact\'s client id', str_contains($t, "intval(\$row['contact_client_id'])") && !str_contains($t, 'uploads/clients/$ticket_reply_by/'));
$w = file_get_contents("$root/guest/worksheet_sign.php");
$ok('worksheet logo has the /uploads/settings/ prefix', str_contains($w, '<img src="/uploads/settings/<?= $co_logo ?>"'));
foreach (['worksheet_sign', 'outtake_sign'] as $f) {
    $s = file_get_contents("$root/guest/$f.php");
    $ok("$f loads rivet_dialog.js before it calls RivetDialog", strpos($s, '/js/rivet_dialog.js') !== false && strpos($s, '/js/rivet_dialog.js') < strpos($s, 'RivetDialog.alert'));
    $ok("$f wires the Print button (.js-print-page) itself", str_contains($s, "closest('.js-print-page')") && str_contains($s, 'window.print()'));
}
$ok('the script file exists', is_file("$root/js/rivet_dialog.js"));
exit($fail ? 1 : 0);
