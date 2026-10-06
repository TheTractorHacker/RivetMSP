<?php
/*
 * Filter - Head
 * Sets the paging/sort for use in limit/order by
 * Sets the default search query from GET to $q
 *
 * Should not be accessed directly, but called from other pages
 */

// Unset Array Var to prevent Duplicate Get VARs
$get_copy = $_GET; // create a copy of the $_GET array
//unset($get_copy['page']);
unset($get_copy['sort']);
unset($get_copy['order']);
//Rebuild URL
$url_query_strings_sort = http_build_query($get_copy);

// Paging
if (isset($_GET['page'])) {
    $page = intval($_GET['page']);
    $record_from = (($page)-1)*$user_config_records_per_page;
    $record_to = $user_config_records_per_page;
} else {
    $record_from = 0;
    $record_to = $user_config_records_per_page;
    $page = 1;
}

if (isset($_GET['order']) && $_GET['order'] == 'ASC') {
    $order = "ASC";
    $disp = "DESC";
}

if (isset($_GET['order']) && $_GET['order'] == 'DESC') {
    $order = "DESC";
    $disp = "ASC";
}

// Order
if(isset($order) && $order == "ASC") {
    $disp = "DESC";
    $order_icon = "<i class='fas fa-sort-down'></i>";
} else {
    $disp = "ASC";
    $order_icon = "<i class='fas fa-sort-up'></i>";
}

// Search
if (isset($_GET['q'])) {
    $q = sanitizeInput($_GET['q']);
    //Phone Numbers
    $phone_query = preg_replace("/[^0-9]/", '', $q);
    if (empty($phone_query)) {
        $phone_query = $q;
    }
} else {
    $q = "";
    $phone_query = "";
}

// Sortby
if (!empty($_GET['sort'])) {
    $sort = sanitizeInput(preg_replace('/[^a-z_]/', '', $_GET['sort'])); // JQ 2023-05-09 - See issue #673 on GitHub to see the reasoning why we used preg_replace technically sanitizeInput() should have been enough to escape SQL Commands
}

// Date Handling (RivetCore DateRange; see includes/date_range.php)
// Resolves canned_date / dtf / dtt in the app timezone into $date_range, and keeps the legacy $dtf / $dtt strings
// (all time = 1970-01-01 .. 2099-12-31) and the "$_GET['canned_date'] is always set" behaviour every page relies on.
require_once __DIR__ . '/date_range.php';
require_once __DIR__ . '/date_range_picker.php';

$date_range = dateRangeFromRequest($_GET);
$dtf = $date_range->from();
$dtt = $date_range->to();

if (empty($_GET['canned_date'])) {
    // Prevents lots of undefined variable errors on pages that read it directly.
    $_GET['canned_date'] = 'custom';
}

// Archived
if (isset($_GET['archived']) && $_GET['archived'] == 1) {
    $archived = 1;
    $archive_query = "archived_at IS NOT NULL";
} else {
    $archived = 0;
    $archive_query = "archived_at IS NULL";
}
