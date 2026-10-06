<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Ticket events are the original set (queued/delivered async via
// queueWebhookEvent()/cron.php). Platform events are AuditService event_type
// strings (see src/Audit/AuditService.php callers) - subscribing a webhook to
// one of these only records the subscription; nothing dispatches on them yet
// (that's WebhookDispatcher's job, once a real trigger point wires it in).
require_once __DIR__ . '/../includes/webhook_events.php';
require_once __DIR__ . '/../../includes/event_bus.php';
$ALL_EVENTS = all_webhook_event_types();

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

if (isset($_POST['add_webhook'])) {

    validateCSRFToken($_POST['csrf_token']);

    // These values are bound as prepared-statement parameters below, so they must
    // NOT be pre-escaped: cleanInput() is sanitizeInput() minus the SQL escape.
    // Running mysqli_real_escape_string() on a bound value would store the literal
    // backslashes in the row (and, for the secret, encrypt the wrong plaintext).
    $webhook_name    = cleanInput($_POST['webhook_name']);
    // FILTER_SANITIZE_URL is a URL-charset filter, NOT an escaper - both ' and "
    // survive it - so this value is only ever safe as a bound parameter. It is
    // also deliberately left unescaped so webhookUrlIsSafe() below parse_url()s
    // the real URL rather than a backslash-mangled copy of it.
    $webhook_url     = filter_var(trim($_POST['webhook_url']), FILTER_SANITIZE_URL);
    $webhook_secret  = encryptSetting(cleanInput($_POST['webhook_secret'] ?? ''));
    $webhook_enabled = isset($_POST['webhook_enabled']) ? 1 : 0;
    $raw_events      = $_POST['webhook_events'] ?? [];
    $valid_events    = array_intersect($raw_events, $ALL_EVENTS);
    $webhook_events  = cleanInput(implode(',', $valid_events));

    if (empty($webhook_name) || empty($webhook_url) || empty($valid_events)) {
        flash_alert("Name, URL, and at least one event are required.", 'error');
        redirect();
    }

    if (!webhookUrlIsSafe($webhook_url)) {
        flash_alert("Endpoint URL rejected (" . nullable_htmlentities(rivetWebhookRuleText($mysqli)) . "). Loopback, link-local and cloud-metadata addresses are never allowed.", 'error');
        redirect();
    }

    $stmt = mysqli_prepare(
        $mysqli,
        "INSERT INTO webhooks
         SET webhook_name = ?, webhook_url = ?, webhook_secret = ?, webhook_events = ?, webhook_enabled = ?"
    );

    mysqli_stmt_bind_param($stmt, "ssssi", $webhook_name, $webhook_url, $webhook_secret, $webhook_events, $webhook_enabled);

    mysqli_stmt_execute($stmt);

    logAction("Settings", "Webhook", "$session_name added webhook $webhook_name");

    flash_alert("Webhook <strong>$webhook_name</strong> added");
    redirect();
}

if (isset($_POST['edit_webhook'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Same rule as the add branch: everything below is bound, so nothing is pre-escaped.
    $webhook_id      = intval($_POST['webhook_id']);
    $webhook_name    = cleanInput($_POST['webhook_name']);
    $webhook_url     = filter_var(trim($_POST['webhook_url']), FILTER_SANITIZE_URL);
    $webhook_enabled = isset($_POST['webhook_enabled']) ? 1 : 0;
    $raw_events      = $_POST['webhook_events'] ?? [];
    $valid_events    = array_intersect($raw_events, $ALL_EVENTS);
    $webhook_events  = cleanInput(implode(',', $valid_events));

    if (empty($webhook_name) || empty($webhook_url) || empty($valid_events)) {
        flash_alert("Name, URL, and at least one event are required.", 'error');
        redirect();
    }

    if (!webhookUrlIsSafe($webhook_url)) {
        flash_alert("Endpoint URL rejected (" . nullable_htmlentities(rivetWebhookRuleText($mysqli)) . "). Loopback, link-local and cloud-metadata addresses are never allowed.", 'error');
        redirect();
    }

    // Rotate secret only if a new one was provided
    $raw_secret = trim($_POST['webhook_secret'] ?? '');
    if (!empty($raw_secret)) {
        $webhook_secret = encryptSetting(cleanInput($raw_secret));
        $stmt = mysqli_prepare(
            $mysqli,
            "UPDATE webhooks
             SET webhook_name = ?, webhook_url = ?, webhook_secret = ?, webhook_events = ?, webhook_enabled = ?
             WHERE webhook_id = ?"
        );
        mysqli_stmt_bind_param($stmt, "ssssii", $webhook_name, $webhook_url, $webhook_secret, $webhook_events, $webhook_enabled, $webhook_id);
    } else {
        $stmt = mysqli_prepare(
            $mysqli,
            "UPDATE webhooks
             SET webhook_name = ?, webhook_url = ?, webhook_events = ?, webhook_enabled = ?
             WHERE webhook_id = ?"
        );
        mysqli_stmt_bind_param($stmt, "sssii", $webhook_name, $webhook_url, $webhook_events, $webhook_enabled, $webhook_id);
    }

    mysqli_stmt_execute($stmt);

    logAction("Settings", "Webhook", "$session_name edited webhook $webhook_name");

    flash_alert("Webhook <strong>$webhook_name</strong> updated");
    redirect();
}

if (isset($_GET['delete_webhook'])) {

    validateCSRFToken($_GET['csrf_token']);

    $webhook_id   = intval($_GET['delete_webhook']);
    $webhook_name = sanitizeInput(getFieldById('webhooks', $webhook_id, 'webhook_name'));

    mysqli_query($mysqli, "DELETE FROM webhook_queue WHERE queue_webhook_id = $webhook_id");
    mysqli_query($mysqli, "DELETE FROM webhooks WHERE webhook_id = $webhook_id");

    logAction("Settings", "Webhook", "$session_name deleted webhook $webhook_name");

    flash_alert("Webhook <strong>$webhook_name</strong> deleted", 'error');
    redirect();
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
