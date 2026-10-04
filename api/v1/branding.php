<?php
// GET /api/v1/branding   public company identity for the mobile apps
// Returns only what the public web login already shows: the company name and
// uploaded logo path. No authentication, no secrets.
defined('FROM_API') || die();
if ($method !== 'GET') api_error(405, 'Method not allowed');

$row = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT company_name, company_logo FROM companies WHERE company_id = 1 LIMIT 1"
));

$logo = $row['company_logo'] ?? '';
// Only a plain file name is ever published, and always under uploads/settings/.
$logo_path = ($logo !== '' && basename($logo) === $logo)
    ? '/uploads/settings/' . rawurlencode($logo)
    : null;

api_response(200, [
    'name'      => $row['company_name'] ?? '',
    'logo_path' => $logo_path,
]);
