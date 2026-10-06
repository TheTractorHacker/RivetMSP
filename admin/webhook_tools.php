<?php

/*
 * JSON helpers behind Administration > Webhooks (admin only, POST + CSRF except the read-only payload view):
 *   action=validate_template   live check of a custom body template (RivetCore PayloadTemplate::validate)
 *   action=preview             exactly what would be sent for a sample event, secrets redacted
 *   action=test                send a sample event through the real delivery path and report the outcome
 *   action=payload (GET)       the stored request body of one delivery, secret-looking keys masked
 * The form posts the same fields as the save handler; a webhook_id makes blank secrets fall back to the stored ones.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$out = static function (array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (!isset($session_is_admin) || !$session_is_admin) {
    $out(['ok' => false, 'error' => 'Your role does not have admin access.'], 403);
}

require_once __DIR__ . '/includes/webhook_form_lib.php';

use RivetCore\Webhooks\PayloadTemplate;

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'payload') {
    $id = intval($_GET['delivery_id'] ?? 0);
    $res = mysqli_query($mysqli, "SELECT wd.delivery_id, wd.event_type, wd.http_status, wd.request_payload_json, wd.created_at, w.webhook_name
        FROM webhook_deliveries wd LEFT JOIN webhooks w ON w.webhook_id = wd.webhook_id WHERE wd.delivery_id = $id LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row) {
        $out(['ok' => false, 'error' => 'Delivery not found.'], 404);
    }
    $out(['ok' => true, 'webhook' => (string) ($row['webhook_name'] ?? ''), 'event' => $row['event_type'], 'when' => $row['created_at'], 'http_status' => $row['http_status'],
        'body' => rivetWebhookRedactBody((string) $row['request_payload_json'])]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $out(['ok' => false, 'error' => 'POST required.'], 405);
}
if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals((string) $_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $out(['ok' => false, 'error' => 'CSRF token verification failed. Reload the page.'], 403);
}

if ($action === 'validate_template') {
    $enc = (string) ($_POST['webhook_template_encoding'] ?? 'json');
    $tpl = (string) ($_POST['webhook_template'] ?? '');
    $errors = strlen($tpl) > 8192 ? ['The template is longer than 8 KB.'] : PayloadTemplate::validate($tpl, $enc);
    $sample = '';
    if (!$errors) {
        try {
            $sample = PayloadTemplate::render($tpl, PayloadTemplate::sampleContext('ticket.created'), $enc);
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    $out(['ok' => !$errors, 'errors' => $errors, 'sample' => $sample]);
}

if ($action === 'preview' || $action === 'test') {
    $wid = intval($_POST['webhook_id'] ?? 0);
    $existing = null;
    if ($wid > 0) {
        $stmt = mysqli_prepare($mysqli, "SELECT * FROM webhooks WHERE webhook_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $wid);
        mysqli_stmt_execute($stmt);
        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
        if (!$existing) {
            $out(['ok' => false, 'errors' => ['Webhook not found.']], 404);
        }
    }
    $input = $_POST;
    if ($existing && !isset($_POST['webhook_name'])) {
        // "Send test" straight from the list: use the stored row as is.
        $sub = rivetWebhookSubsFromRow($existing, $wid);
        $auth = json_decode(decryptSetting((string) ($existing['webhook_auth_enc'] ?? '')), true) ?: [];
        $authRedacted = \RivetCore\Webhooks\Authentication::redact(['mode' => $existing['webhook_auth_mode'] ?? 'none'] + $auth);
        $collected = ['errors' => [], 'subscription' => $sub, 'auth_redacted' => $authRedacted];
    } else {
        $collected = rivetWebhookCollect($mysqli, $input, $existing);
    }
    if ($collected['errors'] || !$collected['subscription']) {
        $out(['ok' => false, 'errors' => $collected['errors'] ?: ['The form is incomplete.']]);
    }
    $sub = $collected['subscription'];

    if ($action === 'preview') {
        try {
            $p = rivetWebhookPreview($sub, $collected['auth_redacted']);
        } catch (\Throwable $e) {
            $out(['ok' => false, 'errors' => ['The payload could not be built: ' . $e->getMessage()]]);
        }
        $out(['ok' => true] + $p);
    }

    $r = rivetWebhookSendTest($mysqli, $sub);
    logAction('Settings', 'Webhook', "$session_name sent a test event to webhook " . ($existing['webhook_name'] ?? ($input['webhook_name'] ?? '')));
    $out(['ok' => true, 'delivered' => $r['ok'], 'http_status' => $r['http_status'], 'duration_ms' => $r['duration_ms'], 'error' => $r['error'], 'response' => $r['response']]);
}

$out(['ok' => false, 'error' => 'Unknown action.'], 400);
