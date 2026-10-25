<?php
/*
 * Minimal QuickBooks-Online-shaped loopback double, for `php -S` under tests/
 * accounting_interface_refactor.php. Proves the real QboClient (now implementing
 * AccountingClientInterface) still talks the exact same wire protocol after the interface
 * extraction - this is NOT a real QBO sandbox, just enough of the wire shape for
 * accounting_functions.php's real sync pipeline to run against end to end.
 *
 * State (customers/items/invoices/estimates/payments) persists across requests in a JSON file
 * named by the FAKE_QBO_STORE env var, so a single test run's sequence of pushes is stateful.
 */
header('Content-Type: application/json');

$store_path = getenv('FAKE_QBO_STORE') ?: (sys_get_temp_dir() . '/fake_qbo_store.json');
$store = is_file($store_path) ? (json_decode((string) file_get_contents($store_path), true) ?: []) : [];
foreach (['Customer', 'Item', 'Invoice', 'Estimate', 'Payment', '_seq'] as $k) {
    if (!isset($store[$k])) {
        $store[$k] = ($k === '_seq') ? 0 : [];
    }
}
function fqbo_save(&$store, $path) { file_put_contents($path, json_encode($store)); }
function fqbo_next_id(&$store) { $store['_seq']++; return (string) $store['_seq']; }

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query  = [];
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$body   = json_decode((string) file_get_contents('php://input'), true) ?: [];

// ── Token endpoint ───────────────────────────────────────────────────────────
if (str_contains($path, '/token')) {
    echo json_encode([
        'access_token'  => 'fake-qbo-access-' . bin2hex(random_bytes(4)),
        'refresh_token' => 'fake-qbo-refresh-' . bin2hex(random_bytes(4)),
        'expires_in'    => 3600,
    ]);
    exit;
}

// ── Query endpoint (SELECT ... FROM Customer|Item|Account WHERE ...) ─────────
if ($method === 'GET' && str_contains($path, '/query')) {
    $q = (string) ($query['query'] ?? '');
    if (stripos($q, 'FROM Account') !== false) {
        echo json_encode(['QueryResponse' => ['Account' => [['Id' => 'acct-1', 'Name' => 'Sales', 'Code' => 'SALES-1']]]]);
        exit;
    }
    foreach (['Customer', 'Item'] as $entity) {
        if (stripos($q, "FROM $entity") === false) {
            continue;
        }
        $rows = $store[$entity];
        // Honour a simple "WHERE Field = 'value'" exact match the real client builds.
        if (preg_match("/WHERE\\s+(\\w+)\\s*=\\s*'([^']*)'/", $q, $m)) {
            [, $field, $value] = $m;
            $rows = array_values(array_filter($rows, function ($r) use ($field, $value) {
                return isset($r[$field]) && $r[$field] === $value;
            }));
        } elseif (preg_match("/WHERE\\s+(\\w+)\\s+LIKE\\s+'([^']*)%'/", $q, $m)) {
            [, $field, $value] = $m;
            $rows = array_values(array_filter($rows, function ($r) use ($field, $value) {
                return isset($r[$field]) && str_starts_with($r[$field], $value);
            }));
        }
        echo json_encode(['QueryResponse' => [$entity => $rows]]);
        exit;
    }
    echo json_encode(['QueryResponse' => []]);
    exit;
}

// ── Create endpoints ──────────────────────────────────────────────────────────
$create_map = ['customer' => 'Customer', 'item' => 'Item', 'invoice' => 'Invoice', 'estimate' => 'Estimate', 'payment' => 'Payment'];
foreach ($create_map as $slug => $entity) {
    if ($method === 'POST' && preg_match('#/' . $slug . '(\?|$)#', $path)) {
        $row = $body;
        $row['Id'] = fqbo_next_id($store);
        $row['SyncToken'] = '0';
        $store[$entity][] = $row;
        fqbo_save($store, $store_path);
        echo json_encode([$entity => $row]);
        exit;
    }
}

http_response_code(404);
echo json_encode(['error' => "fake_qbo_router: no handler for $method $path"]);
