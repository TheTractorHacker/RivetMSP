<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Values are bound as statement parameters in certificate.php, so they are cleaned (tags/whitespace) but not SQL-escaped here.
$name = cleanInput($_POST['name']);
$description = cleanInput($_POST['description']);
$domain = cleanInput($_POST['domain']);
$issued_by = cleanInput($_POST['issued_by']);
$expire = cleanInput($_POST['expire']);
$public_key = cleanInput($_POST['public_key']);
$notes = cleanInput($_POST['notes']);
$domain_id = intval($_POST['domain_id'] ?? 0);
