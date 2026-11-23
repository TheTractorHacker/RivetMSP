<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$name = sanitizeInput($_POST['name']);
$description = sanitizeInput($_POST['description']);
$country = sanitizeInput($_POST['country']);
$address = sanitizeInput($_POST['address']);
$city = sanitizeInput($_POST['city']);
$state = sanitizeInput($_POST['state']);
$zip = sanitizeInput($_POST['zip']);
$phone = preg_replace("/[^0-9]/", '',$_POST['phone']);
$phone_country_code = preg_replace("/[^0-9]/", '',$_POST['phone_country_code']);
$extension = preg_replace("/[^0-9]/", '',$_POST['extension']);
$fax = preg_replace("/[^0-9]/", '',$_POST['fax']);
$fax_country_code = preg_replace("/[^0-9]/", '',$_POST['fax_country_code']);
$hours = sanitizeInput($_POST['hours']);

// WAN IP address(es): one or more public IPs for the site's internet connection (comma or space separated, e.g. a primary and a backup line).
$wan_ip_in = preg_split('/[\s,;]+/', trim((string) ($_POST['wan_ip'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
foreach ($wan_ip_in as $wan_ip_one) {
    if (filter_var($wan_ip_one, FILTER_VALIDATE_IP) === false) {
        flash_alert("<strong>" . htmlspecialchars($wan_ip_one) . "</strong> is not a valid IP address. Location not saved.", 'error');
        redirect();
    }
}
$wan_ip = sanitizeInput(implode(', ', array_values(array_unique($wan_ip_in))));
$notes = sanitizeInput($_POST['notes']);
$contact = intval($_POST['contact'] ?? 0);
$location_primary = intval($_POST['location_primary'] ?? 0);
