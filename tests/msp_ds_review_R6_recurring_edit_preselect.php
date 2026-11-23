<?php
// Review R6: the edit modal must preselect the stored frequency whatever its case (model stores lowercase). Run: php tests/msp_ds_review_R6_recurring_edit_preselect.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
function renderSelected(string $stored): array {
    $recurring_ticket_frequency = $stored;
    $src = file_get_contents(__DIR__ . '/../agent/modals/recurring_ticket/recurring_ticket_edit.php');
    $start = strpos($src, 'name="frequency"');
    $chunk = substr($src, $start, strpos($src, '</select>', $start) - $start);
    $chunk = substr($chunk, strpos($chunk, '>') + 1);
    ob_start(); eval('?>' . $chunk); $html = ob_get_clean();
    preg_match_all('/<option selected>([^<]+)<\/option>/', $html, $m);
    return $m[1];
}
foreach (['three days' => 'Three Days', 'weekly' => 'Weekly', 'biweekly' => 'Biweekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'biannually' => 'Biannually', 'annually' => 'Annually'] as $stored => $label) {
    $ok(renderSelected($stored) === [$label], "stored lowercase '$stored' preselects $label");
    $ok(renderSelected($label) === [$label], "legacy TitleCase '$label' still preselects");
}
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
