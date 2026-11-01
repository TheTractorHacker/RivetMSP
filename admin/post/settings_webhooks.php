<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Events a webhook can subscribe to: the RivetCore catalog (ids and patterns such as "ticket.*"), the older static list and the
// audit event types this install has recorded (see admin/includes/webhook_events.php and webhook_form_lib.php). Everything an
// add/edit/test posts is validated by rivetWebhookCollect() (RivetCore Destination, Authentication, PayloadTemplate, UrlPolicy).
require_once __DIR__ . '/../includes/webhook_form_lib.php';

/**
 * Reject webhook endpoint URLs that would let a saved webhook be used to make the
 * this server itself request internal/cloud-metadata targets (SSRF) when the
 * queued webhook is delivered server-side from cron/cron.php. Only http(s) URLs
 * whose host resolves exclusively to public IP addresses (or to the admin's allowed
 * internal networks, see rivetWebhookUrlPolicy()) are allowed - loopback, link-local
 * (incl. 169.254.169.254 cloud metadata) and any private range outside the allowed
 * networks are rejected, including via DNS resolution (not just literal IPs).
 */
function webhookUrlIsSafe(string $url): bool {
    return rivetWebhookUrlPolicy($GLOBALS['mysqli'] ?? null)->isSafe($url);
}

/** The Add / Edit pages save through fetch (wh_ajax=1) and expect JSON; a plain form post keeps its flash + redirect. */
function webhookWantsJson(): bool {
    return !empty($_POST['wh_ajax']);
}

/** Answer a wh_ajax request. */
function webhookJson(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** CSRF check. For wh_ajax it answers JSON (validateCSRFToken redirects), so the page can show the problem. */
function webhookCheckCsrf(): void {
    if (webhookWantsJson()) {
        $t = $_POST['csrf_token'] ?? null;
        if (!is_string($t) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $t)) {
            webhookJson(['ok' => false, 'errors' => ['Your session expired. Reload the page and try again.']], 403);
        }
        return;
    }
    validateCSRFToken($_POST['csrf_token'] ?? null);
}

/** A refused save: JSON for the wizard, a flash + back to the form otherwise. */
function webhookFail(array $errors, ?int $id): never {
    if (webhookWantsJson()) {
        webhookJson(['ok' => false, 'errors' => array_values($errors)]);
    }
    flash_alert('Webhook not saved:<br>' . implode('<br>', array_map('nullable_htmlentities', $errors)), 'error');
    $dest = urlencode((string) ($_POST['webhook_destination'] ?? ''));
    redirect($id ? "webhook_edit.php?id=$id" : ($dest !== '' ? "webhook_new.php?dest=$dest&step=review" : 'settings_webhooks.php'));
}

/** Does a validated row carry the plain-http opt-in? */
function webhookRowAllowsHttp(array $row): bool {
    return (json_decode((string) ($row['webhook_extra'] ?? ''), true)['allow_http'] ?? false) === true;
}

if (isset($_POST['add_webhook'])) {

    webhookCheckCsrf();

    $c = rivetWebhookCollect($mysqli, $_POST, null);
    if ($c['errors']) {
        webhookFail($c['errors'], null);
    }
    $r = $c['row'];
    $stmt = mysqli_prepare(
        $mysqli,
        "INSERT INTO webhooks
         SET webhook_name = ?, webhook_url = ?, webhook_secret = ?, webhook_events = ?, webhook_enabled = ?,
             webhook_destination = ?, webhook_format = ?, webhook_method = ?, webhook_template = ?, webhook_auth_mode = ?, webhook_auth_enc = ?, webhook_extra = ?"
    );
    mysqli_stmt_bind_param($stmt, "ssssisssssss", $r['webhook_name'], $r['webhook_url'], $r['webhook_secret'], $r['webhook_events'], $r['webhook_enabled'],
        $r['webhook_destination'], $r['webhook_format'], $r['webhook_method'], $r['webhook_template'], $r['webhook_auth_mode'], $r['webhook_auth_enc'], $r['webhook_extra']);
    mysqli_stmt_execute($stmt);
    $new_id = (int) mysqli_insert_id($mysqli);

    $webhook_name = $r['webhook_name'];
    logAction("Settings", "Webhook", "$session_name added webhook " . sanitizeInput($webhook_name) . ($r['webhook_destination'] !== '' ? " ({$r['webhook_destination']})" : '') . (webhookRowAllowsHttp($r) ? " with plain http:// allowed" : ""));

    if (webhookWantsJson()) {
        webhookJson(['ok' => true, 'id' => $new_id, 'name' => $webhook_name, 'destination' => $r['webhook_destination']]);
    }
    flash_alert("Webhook <strong>" . nullable_htmlentities($webhook_name) . "</strong> added");
    redirect('settings_webhooks.php');
}

if (isset($_POST['edit_webhook'])) {

    webhookCheckCsrf();

    $webhook_id = intval($_POST['webhook_id'] ?? 0);
    $stmt = mysqli_prepare($mysqli, "SELECT * FROM webhooks WHERE webhook_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $webhook_id);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$existing) {
        if (webhookWantsJson()) {
            webhookJson(['ok' => false, 'errors' => ['Webhook not found.']], 404);
        }
        flash_alert('Webhook not found.', 'error');
        redirect('settings_webhooks.php');
    }
    // A legacy edit post (no platform field) keeps the row's current platform.
    if (!isset($_POST['webhook_destination'])) {
        $_POST['webhook_destination'] = (string) $existing['webhook_destination'];
    }

    $c = rivetWebhookCollect($mysqli, $_POST, $existing);
    if ($c['errors']) {
        webhookFail($c['errors'], $webhook_id);
    }
    $r = $c['row'];
    $stmt = mysqli_prepare(
        $mysqli,
        "UPDATE webhooks
         SET webhook_name = ?, webhook_url = ?, webhook_secret = ?, webhook_events = ?, webhook_enabled = ?,
             webhook_destination = ?, webhook_format = ?, webhook_method = ?, webhook_template = ?, webhook_auth_mode = ?, webhook_auth_enc = ?, webhook_extra = ?
         WHERE webhook_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "ssssisssssssi", $r['webhook_name'], $r['webhook_url'], $r['webhook_secret'], $r['webhook_events'], $r['webhook_enabled'],
        $r['webhook_destination'], $r['webhook_format'], $r['webhook_method'], $r['webhook_template'], $r['webhook_auth_mode'], $r['webhook_auth_enc'], $r['webhook_extra'], $webhook_id);
    mysqli_stmt_execute($stmt);

    $webhook_name = $r['webhook_name'];
    $was_http_on = webhookRowAllowsHttp($existing);
    $now_http_on = webhookRowAllowsHttp($r);
    $http_note = $now_http_on !== $was_http_on ? ($now_http_on ? " (turned ON plain http:// for this destination)" : " (turned OFF plain http:// for this destination)") : "";
    logAction("Settings", "Webhook", "$session_name edited webhook " . sanitizeInput($webhook_name) . $http_note);

    if (webhookWantsJson()) {
        webhookJson(['ok' => true, 'id' => $webhook_id, 'name' => $webhook_name, 'destination' => $r['webhook_destination']]);
    }
    flash_alert("Webhook <strong>" . nullable_htmlentities($webhook_name) . "</strong> updated");
    redirect('settings_webhooks.php');
}

// The enabled switch on the list and the Edit page header: flips one column, answers JSON.
if (isset($_POST['toggle_webhook'])) {

    webhookCheckCsrf();

    $webhook_id = intval($_POST['webhook_id'] ?? 0);
    $enabled = !empty($_POST['enabled']) && $_POST['enabled'] !== '0' ? 1 : 0;
    $name = getFieldById('webhooks', $webhook_id, 'webhook_name');
    if ($name === null || $name === false) {
        webhookJson(['ok' => false, 'errors' => ['Webhook not found.']], 404);
    }
    $stmt = mysqli_prepare($mysqli, "UPDATE webhooks SET webhook_enabled = ? WHERE webhook_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $enabled, $webhook_id);
    mysqli_stmt_execute($stmt);
    logAction("Settings", "Webhook", "$session_name " . ($enabled ? 'enabled' : 'disabled') . " webhook " . sanitizeInput($name));
    webhookJson(['ok' => true, 'id' => $webhook_id, 'enabled' => $enabled]);
}

// A copy of a webhook (secrets copied as stored: same keys, still encrypted), created disabled so it never doubles deliveries by accident.
if (isset($_POST['duplicate_webhook'])) {

    validateCSRFToken($_POST['csrf_token'] ?? null);

    $webhook_id = intval($_POST['webhook_id'] ?? 0);
    $stmt = mysqli_prepare($mysqli, "SELECT * FROM webhooks WHERE webhook_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $webhook_id);
    mysqli_stmt_execute($stmt);
    $src = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$src) {
        flash_alert("Webhook not found.", 'error');
        redirect('settings_webhooks.php');
    }
    $name = mb_substr((string) $src['webhook_name'], 0, 190) . ' (copy)';
    $zero = 0;
    $stmt = mysqli_prepare($mysqli, "INSERT INTO webhooks
        SET webhook_name = ?, webhook_url = ?, webhook_secret = ?, webhook_events = ?, webhook_enabled = ?,
            webhook_destination = ?, webhook_format = ?, webhook_method = ?, webhook_template = ?, webhook_auth_mode = ?, webhook_auth_enc = ?, webhook_extra = ?");
    mysqli_stmt_bind_param($stmt, 'ssssisssssss', $name, $src['webhook_url'], $src['webhook_secret'], $src['webhook_events'], $zero,
        $src['webhook_destination'], $src['webhook_format'], $src['webhook_method'], $src['webhook_template'], $src['webhook_auth_mode'], $src['webhook_auth_enc'], $src['webhook_extra']);
    mysqli_stmt_execute($stmt);
    $new_id = (int) mysqli_insert_id($mysqli);
    logAction("Settings", "Webhook", "$session_name duplicated webhook " . sanitizeInput($src['webhook_name']) . " as " . sanitizeInput($name));
    flash_alert("Webhook duplicated as <strong>" . nullable_htmlentities($name) . "</strong> (disabled until you turn it on)");
    redirect('webhook_edit.php?id=' . $new_id);
}

// Sends one clearly labelled test event to a saved webhook through the real delivery path and reports the HTTP result (never the URL).
// The Add / Edit form has its own Send test (modals/webhook/webhook_action.php) that tries unsaved settings.
if (isset($_POST['test_webhook'])) {

    validateCSRFToken($_POST['csrf_token'] ?? null);

    $webhook_id = intval($_POST['webhook_id'] ?? 0);
    $stmt = mysqli_prepare($mysqli, "SELECT * FROM webhooks WHERE webhook_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $webhook_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$row) {
        flash_alert("Webhook not found.", 'error');
        redirect('settings_webhooks.php');
    }

    try {
        $r = rivetWebhookSendTest($mysqli, rivetWebhookSubsFromRow($row, $webhook_id));
    } catch (\Throwable $e) {
        $r = ['ok' => false, 'http_status' => null, 'duration_ms' => 0, 'error' => $e->getMessage(), 'response' => ''];
    }
    $name = nullable_htmlentities($row['webhook_name']);
    logAction("Settings", "Webhook", "$session_name sent a test message to webhook " . sanitizeInput($row['webhook_name']) . ($r['ok'] ? " (HTTP {$r['http_status']})" : " (failed)"));

    $snippet = $r['response'] !== null && $r['response'] !== '' ? ' Response: ' . nullable_htmlentities(mb_substr($r['response'], 0, 200)) : '';
    if ($r['ok']) {
        flash_alert("Test message sent to <strong>$name</strong>: HTTP " . intval($r['http_status']) . " in " . intval($r['duration_ms']) . " ms." . $snippet);
    } else {
        flash_alert("Test message to <strong>$name</strong> failed: " . nullable_htmlentities($r['error'] ?? 'unknown error') . $snippet, 'error');
    }
    redirect('settings_webhooks.php');
}

if (isset($_GET['delete_webhook'])) {

    validateCSRFToken($_GET['csrf_token']);

    $webhook_id   = intval($_GET['delete_webhook']);
    $webhook_name = sanitizeInput(getFieldById('webhooks', $webhook_id, 'webhook_name'));

    mysqli_query($mysqli, "DELETE FROM webhook_queue WHERE queue_webhook_id = $webhook_id");
    mysqli_query($mysqli, "DELETE FROM webhooks WHERE webhook_id = $webhook_id");

    logAction("Settings", "Webhook", "$session_name deleted webhook $webhook_name");

    flash_alert("Webhook <strong>$webhook_name</strong> deleted", 'error');
    redirect('settings_webhooks.php');
}

// Internal networks webhooks may reach. The list is validated by RivetCore's NetworkList (private ranges only, not too wide);
// loopback, link-local and cloud-metadata addresses can never be listed.
function webhookSaveNetworks(string $raw, string $audit_summary): bool {
    global $mysqli, $session_user_id;

    $parsed = \RivetCore\Webhooks\NetworkList::parse($raw);
    $stored = implode("\n", $parsed['networks']);
    $errors = $parsed['errors'];
    if (strlen($stored) > 500) {
        $errors[] = 'The list is too long to store (maximum 500 characters).';
    }
    if ($errors) {
        $_SESSION['webhook_networks_draft'] = $raw;
        flash_alert('Networks not saved:<br>' . implode('<br>', array_map('nullable_htmlentities', $errors)), 'error');
        return false;
    }

    $before = rivetWebhookAllowedNetworks($mysqli);
    $stmt = mysqli_prepare($mysqli, "UPDATE settings SET config_webhook_allowed_networks = ?");
    mysqli_stmt_bind_param($stmt, "s", $stored);
    mysqli_stmt_execute($stmt);
    unset($_SESSION['webhook_networks_draft']);

    if ($before !== $parsed['networks']) {
        logAction("Settings", "Webhook", "$GLOBALS[session_name] changed the webhook allowed networks");
        rivetAudit('webhooks.networks_changed', (int) $session_user_id, 'settings', 1, 'update', $audit_summary,
            ['before' => $before, 'after' => $parsed['networks']]);
    }
    flash_alert('Allowed internal networks saved' . ($parsed['networks'] ? ': <strong>' . nullable_htmlentities(implode(', ', $parsed['networks'])) . '</strong>' : ' (none: webhooks may only call public addresses)'));
    return true;
}

if (isset($_POST['save_webhook_networks'])) {

    validateCSRFToken($_POST['csrf_token']);

    webhookSaveNetworks((string) ($_POST['webhook_allowed_networks'] ?? ''), 'Webhook allowed networks changed');
    redirect();
}

// One-click add of a detected / suggested network to the saved list.
if (isset($_POST['add_webhook_network'])) {

    validateCSRFToken($_POST['csrf_token']);

    $current = rivetWebhookAllowedNetworks($mysqli);
    webhookSaveNetworks(implode("\n", $current) . "\n" . (string) ($_POST['network'] ?? ''), 'Webhook allowed network added');
    redirect();
}
