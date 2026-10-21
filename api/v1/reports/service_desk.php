<?php
// GET /api/v1/reports/service-desk?from=YYYY-MM-DD&to=YYYY-MM-DD[&maintenance=include|exclude|only]
// Service-desk & SLA operations metrics — mirrors agent/reports/service_desk.php.
// Shares getServiceDeskReport() in functions.php so web + API return identical numbers.
defined('FROM_API') || die();

api_require_module_permission($mysqli, $api_user_id, 'module_support');

// Accept an explicit from/to date range; default to year-to-date.
$from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : date('Y-01-01');
$to   = isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])   ? $_GET['to']   : date('Y-m-d');

// maintenance=include|exclude|only: tickets made from RMM alerts (anything else = include). SLA numbers never count no-SLA tickets either way.
$maintenance = isset($_GET['maintenance']) && is_string($_GET['maintenance']) && in_array($_GET['maintenance'], ['include', 'exclude', 'only'], true) ? $_GET['maintenance'] : 'include';

api_response(200, getServiceDeskReport($mysqli, $from, $to, !empty($api_key_client_id) ? intval($api_key_client_id) : null, $maintenance));
