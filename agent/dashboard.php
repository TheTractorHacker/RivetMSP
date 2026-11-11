<?php

/*
 * agent/dashboard.php used to be a fixed-layout dashboard (stat tiles, Financial and Technical sections, cash flow chart, ...). Like RivetIT,
 * all of that is now widgets a user adds from agent/my_dashboard.php's Customise screen (src/Reports/Widgets/WidgetRegistry.php), and this
 * page just redirects there so every link, bookmark and nav entry keeps working. ?year= is forwarded because several widgets are "for a year".
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

$dash_redirect = 'my_dashboard.php';
if (isset($_GET['year']) && ctype_digit((string) $_GET['year'])) {
    $dash_redirect .= '?year=' . intval($_GET['year']);
}
header('Location: ' . $dash_redirect);
exit;
