<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$name = sanitizeInput($_POST['name']);
$type = intval($_POST['type']);
$color = sanitizeInput($_POST['color']);
$icon = substr(\RivetCore\Ui\IconCatalog::normalize($_POST['icon'] ?? '', 'fa-tag'), 3); // stored bare ('fire'); templates render fa-<icon>
