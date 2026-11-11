<?php

/*
 * The vendor page was replaced by the vendor details modal (agent/modals/vendor/vendor_details.php, opened from the vendor list). The old page read the
 * vendor_contacts table, which was merged into contacts and dropped, so it fataled. Old links and bookmarks land on the vendor list instead.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

header('Location: vendors.php');
exit;
