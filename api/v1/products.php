<?php
// GET /api/v1/products   - list products/services for charge selection
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
if ($method !== 'GET') api_error(405, 'Method not allowed');

// MSP-DS-84: the product list is the charge picker for tickets, so it needs ticket (module_support) or sales (module_sales) access.
if (!api_module_permission_ok($mysqli, intval($api_user_id), 'module_support') && !api_module_permission_ok($mysqli, intval($api_user_id), 'module_sales')) {
    api_error(403, 'Insufficient permissions');
}

$type   = mysqli_real_escape_string($mysqli, $_GET['type'] ?? '');
$search = mysqli_real_escape_string($mysqli, $_GET['search'] ?? '');
$where  = ['product_archived_at IS NULL'];
if ($type)   $where[] = "product_type = '$type'";
if ($search) $where[] = "product_name LIKE '%$search%'";
$w = implode(' AND ', $where);

$products = [];
$sql = mysqli_query($mysqli,
    "SELECT product_id, product_name, product_type, product_description,
            product_price, product_currency_code, product_code
     FROM products WHERE $w ORDER BY product_type, product_name ASC LIMIT 100"
);
while ($row = mysqli_fetch_assoc($sql)) {
    $products[] = [
        'id'          => intval($row['product_id']),
        'name'        => $row['product_name'],
        'type'        => $row['product_type'],
        'description' => $row['product_description'],
        'price'       => floatval($row['product_price']),
        'currency'    => $row['product_currency_code'],
        'code'        => $row['product_code'],
    ];
}
api_response(200, $products);
