<?php
/*
 * Old address of the single-page Add / Edit webhook form, kept so bookmarks and the links in older mails still land somewhere useful.
 * The flow is now two pages built on RivetCore's shared wizard (js/webhook_wizard.js): webhook_new.php (Add, four steps) and
 * webhook_edit.php (Edit, in tabs). Query strings are mapped; nothing here reads or writes data. Administrators only, like the pages it
 * points at (those check again).
 */
require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

if (!isset($session_is_admin) || !$session_is_admin) {
    http_response_code(403);
    exit('Your role does not have admin access.');
}

$wid = intval($_GET['id'] ?? 0);
if ($wid > 0) {
    $tab = in_array($_GET['tab'] ?? '', ['connection', 'events', 'advanced', 'deliveries'], true) ? '&tab=' . $_GET['tab'] : '';
    redirect('webhook_edit.php?id=' . $wid . $tab);
}
$dest = (string) ($_GET['dest'] ?? $_GET['destination'] ?? '');
$step = in_array($_GET['step'] ?? '', ['connect', 'events', 'review'], true) ? '&step=' . $_GET['step'] : '';
redirect($dest !== '' ? 'webhook_new.php?dest=' . rawurlencode($dest) . $step : 'webhook_new.php');
