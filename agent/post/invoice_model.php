<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$date = sanitizeInput($_POST['date']);
$category = intval($_POST['category']);
$scope = sanitizeInput($_POST['scope']);
$invoice_discount = round(floatval($_POST['invoice_discount']), 2);
$recurring_discount = floatval($_POST['recurring_discount'] ?? 0);

$config_invoice_prefix = sanitizeInput($config_invoice_prefix);
