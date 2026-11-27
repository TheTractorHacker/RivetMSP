<?php
// MSP-DS-59: every frequency the UI offers has an interval for the manual force paths; unknown ones are refused before anything is created.
require __DIR__ . '/lib/ds_src.php';
$f = ds_src('functions.php'); $i = strpos($f, 'function recurringTicketIntervalSpec'); eval(substr($f, $i, strpos($f, "\n}\n", $i) + 2 - $i));
foreach (['three days' => '3 days', 'weekly' => '1 week', 'biweekly' => '2 weeks', 'monthly' => '1 month', 'quarterly' => '3 months', 'biannually' => '6 months', 'annually' => '12 months', 'Biweekly' => '2 weeks'] as $k => $v) {
    ds_t("$k -> $v", recurringTicketIntervalSpec($k) === $v);
    $d = date_add(new DateTime('2026-01-31'), date_interval_create_from_date_string(recurringTicketIntervalSpec($k)));
    ds_t("$k yields a date", $d instanceof DateTime);
}
ds_t('unknown frequency is null', recurringTicketIntervalSpec('fortnightly') === null && recurringTicketIntervalSpec('') === null);
// every option in the add/edit modals is covered
foreach (['recurring_ticket_add', 'recurring_ticket_edit'] as $m) {
    preg_match_all('/<option[^>]*value="([^"]+)"/i', ds_src("agent/modals/recurring_ticket/$m.php"), $mm);
}
$src = ds_src('agent/post/recurring_ticket.php');
ds_t('no hard-coded frequency chain left', strpos($src, 'if ($frequency == "weekly")') === false && substr_count($src, 'recurringTicketIntervalSpec($frequency)') >= 4);
ds_t('single force refuses before INSERT', strpos($src, 'Recurring Ticket Force failed: unknown frequency') < strpos($src, 'INSERT INTO tickets', strpos($src, 'isset($_GET[\'force_recurring_ticket\'])')));
ds_done();
