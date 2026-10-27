<?php
/*
 * Minimal Pax8-shaped loopback double, for `php -S` under tests/pax8_cost_sync.php. Not a real
 * Pax8 sandbox - see includes/class_pax8_client.php's header for why that distinction matters.
 * Read-only: the test seeds companies/subscriptions directly into the FAKE_PAX8_STORE JSON file
 * before starting this server (Pax8 is a pull-only integration - there is nothing to create).
 */
header('Content-Type: application/json');

$store_path = getenv('FAKE_PAX8_STORE') ?: (sys_get_temp_dir() . '/fake_pax8_store.json');
$store = is_file($store_path) ? (json_decode((string) file_get_contents($store_path), true) ?: []) : [];
$companies = $store['companies'] ?? [];
$subscriptions = $store['subscriptions'] ?? [];

$path = trim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$query = [];
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST' && str_contains($path, 'oauth/token')) {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    if (empty($body['client_id']) || empty($body['client_secret'])) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_client']);
        exit;
    }
    echo json_encode([
        'access_token' => 'fake-pax8-access-' . bin2hex(random_bytes(4)),
        'expires_in'   => 3600,
        'token_type'   => 'Bearer',
    ]);
    exit;
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!str_starts_with($auth, 'Bearer fake-pax8-access-')) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_token']);
    exit;
}

if ($path === 'v1/companies' || $path === 'companies') {
    $limit  = max(1, (int) ($query['limit'] ?? 200));
    $offset = max(0, (int) ($query['offset'] ?? 0));
    echo json_encode(['content' => array_slice($companies, $offset, $limit)]);
    exit;
}

if ($path === 'v1/subscriptions' || $path === 'subscriptions') {
    $companyId = (string) ($query['companyId'] ?? '');
    $limit  = max(1, (int) ($query['limit'] ?? 200));
    $offset = max(0, (int) ($query['offset'] ?? 0));
    $matching = array_values(array_filter($subscriptions, fn ($s) => ($s['companyId'] ?? '') === $companyId));
    echo json_encode(['content' => array_slice($matching, $offset, $limit)]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => "fake_pax8_router: no handler for $method /$path"]);
