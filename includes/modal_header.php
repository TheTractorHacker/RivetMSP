<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

// check_login.php only verifies the session is logged in (any role) - admin/modals/*.php
// files are meant to be admin-only (mirroring admin/includes/inc_all_admin.php's own gate),
// but unlike full admin pages they don't go through that include, so a logged-in non-admin
// agent could otherwise load them directly and read admin-only config/secrets. Gate on the
// actually-requested script's path (not this included file's own path) so agent/modals/*.php
// is unaffected.
if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/modals/') !== false && (!isset($session_is_admin) || !$session_is_admin)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Your role does not have admin access.']);
    exit;
}

// MSP-INJ-9: agent/modals/* only needed a login. Add the module gate each folder's page already has, and for ticket modals the client check of the ticket's
// own client, so a support-only role cannot read payments/accounts/revenue and a client-restricted user cannot read another client's ticket data.
if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/agent/modals/') !== false) {
    $mh_dir = basename(dirname((string) $_SERVER['SCRIPT_NAME']));
    $mh_module = [
        'account' => 'module_financial', 'expense' => 'module_financial', 'recurring_expense' => 'module_financial', 'revenue' => 'module_financial',
        'transfer' => 'module_financial', 'payment' => 'module_financial',
        'invoice' => 'module_sales', 'quote' => 'module_sales', 'recurring_invoice' => 'module_sales', 'product' => 'module_sales',
        'ticket' => 'module_support', 'recurring_ticket' => 'module_support',
    ][$mh_dir] ?? null;
    $mh_deny = static function (string $why): void {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => $why]);
        exit;
    };
    if ($mh_module !== null && empty($session_is_admin) && (int) lookupUserPermission($mh_module) < 1) {
        $mh_deny('Your role does not have access to this.');
    }
    if ($mh_dir === 'ticket' && isset($_GET['ticket_id']) && empty($session_is_admin)) {
        $mh_ticket_client = (int) (mysqli_fetch_row(mysqli_query($mysqli, 'SELECT ticket_client_id FROM tickets WHERE ticket_id = ' . (int) $_GET['ticket_id'] . ' LIMIT 1'))[0] ?? 0);
        if ($mh_ticket_client > 0) {
            $mh_rows = [];
            $mh_res = mysqli_query($mysqli, 'SELECT client_id FROM user_client_permissions WHERE user_id = ' . (int) $session_user_id);
            while ($mh_res && ($mh_r = mysqli_fetch_row($mh_res))) {
                $mh_rows[] = (int) $mh_r[0];
            }
            if ($mh_rows !== [] && !in_array($mh_ticket_client, $mh_rows, true)) {
                $mh_deny('You do not have access to this client.');
            }
        }
    }
}

header('Content-Type: application/json');

// Check for the 'id' parameter
//if (!isset($_GET['id'])) {
//    echo json_encode(['error' => 'ID missing.']);
//    exit;
//}
