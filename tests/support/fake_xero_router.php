<?php
/*
 * Minimal Xero-shaped loopback double, for `php -S` under tests/accounting_xero.php. Not a real
 * Xero sandbox - see includes/class_xero_client.php's header for why that distinction matters.
 * State persists across requests in a JSON file named by the FAKE_XERO_STORE env var.
 */
header('Content-Type: application/json');

$store_path = getenv('FAKE_XERO_STORE') ?: (sys_get_temp_dir() . '/fake_xero_store.json');
$store = is_file($store_path) ? (json_decode((string) file_get_contents($store_path), true) ?: []) : [];
foreach (['Contacts', 'Items', 'Invoices', 'Quotes', 'Payments', '_seq'] as $k) {
    if (!isset($store[$k])) {
        $store[$k] = ($k === '_seq') ? 0 : [];
    }
}
function fxero_save(&$store, $path) { file_put_contents($path, json_encode($store)); }
function fxero_next_id(&$store, $prefix) { $store['_seq']++; return $prefix . '-' . $store['_seq']; }

$method = $_SERVER['REQUEST_METHOD'];
$path   = trim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$query  = [];
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$body   = json_decode((string) file_get_contents('php://input'), true) ?: [];
$where  = (string) ($query['where'] ?? '');

// ── Token endpoint ───────────────────────────────────────────────────────────
if (str_contains($path, 'token')) {
    echo json_encode([
        'access_token'  => 'fake-xero-access-' . bin2hex(random_bytes(4)),
        'refresh_token' => 'fake-xero-refresh-' . bin2hex(random_bytes(4)),
        'expires_in'    => 1800,
    ]);
    exit;
}

// ── /connections (tenant discovery) ──────────────────────────────────────────
if (str_contains($path, 'connections')) {
    echo json_encode([
        ['tenantId' => 'fake-tenant-001', 'tenantName' => 'Fake Co Pty Ltd', 'tenantType' => 'ORGANISATION'],
    ]);
    exit;
}

// ── Accounts (default income/bank account lookup) ───────────────────────────
if ($method === 'GET' && $path === 'Accounts') {
    if (str_contains($where, 'SALES')) {
        echo json_encode(['Accounts' => [['AccountID' => 'acct-sales-1', 'Code' => '200', 'Type' => 'SALES']]]);
    } elseif (str_contains($where, 'BANK')) {
        echo json_encode(['Accounts' => [['AccountID' => 'acct-bank-1', 'Code' => '090', 'Type' => 'BANK']]]);
    } else {
        echo json_encode(['Accounts' => []]);
    }
    exit;
}

// ── Contacts / Items: GET (search/find) and PUT (create) ────────────────────
foreach (['Contacts' => 'Name', 'Items' => 'Name'] as $entity => $field) {
    if ($path !== $entity) {
        continue;
    }
    if ($method === 'GET') {
        $rows = $store[$entity];
        if (preg_match('/' . preg_quote($field, '/') . '=="([^"]*)"/', $where, $m)) {
            $value = $m[1];
            $rows = array_values(array_filter($rows, fn ($r) => ($r[$field] ?? '') === $value));
        } elseif (preg_match('/' . preg_quote($field, '/') . '\.StartsWith\("([^"]*)"\)/', $where, $m)) {
            $value = $m[1];
            $rows = array_values(array_filter($rows, fn ($r) => str_starts_with((string) ($r[$field] ?? ''), $value)));
        } elseif (preg_match('/EmailAddress=="([^"]*)"/', $where, $m)) {
            $value = $m[1];
            $rows = array_values(array_filter($rows, fn ($r) => ($r['EmailAddress'] ?? '') === $value));
        }
        echo json_encode([$entity => $rows]);
        exit;
    }
    if ($method === 'PUT') {
        $idField = $entity === 'Contacts' ? 'ContactID' : 'ItemID';
        $created = [];
        foreach ($body[$entity] ?? [] as $row) {
            $row[$idField] = fxero_next_id($store, $entity === 'Contacts' ? 'contact' : 'item');
            $store[$entity][] = $row;
            $created[] = $row;
        }
        fxero_save($store, $store_path);
        echo json_encode([$entity => $created]);
        exit;
    }
}

// ── Invoices / Quotes / Payments: PUT (create) only (search intentionally unsupported) ──────
$create_map = ['Invoices' => 'InvoiceID', 'Quotes' => 'QuoteID', 'Payments' => 'PaymentID'];
foreach ($create_map as $entity => $idField) {
    if ($path === $entity && $method === 'PUT') {
        $created = [];
        foreach ($body[$entity] ?? [] as $row) {
            $row[$idField] = fxero_next_id($store, strtolower(substr($entity, 0, -1)));
            if ($entity === 'Invoices' && empty($row['InvoiceNumber'])) {
                $row['InvoiceNumber'] = 'INV-' . $row[$idField];
            }
            if ($entity === 'Quotes' && empty($row['QuoteNumber'])) {
                $row['QuoteNumber'] = 'QU-' . $row[$idField];
            }
            $store[$entity][] = $row;
            $created[] = $row;
        }
        fxero_save($store, $store_path);
        echo json_encode([$entity => $created]);
        exit;
    }
}

http_response_code(404);
echo json_encode(['error' => "fake_xero_router: no handler for $method /$path"]);
