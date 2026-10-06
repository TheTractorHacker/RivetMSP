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

/** Keep what was typed (never a secret) so a refused save does not lose the form. */
function webhookRememberDraft(array $post): void {
    $keep = ['webhook_id', 'webhook_destination', 'webhook_name', 'webhook_url', 'webhook_method', 'webhook_template', 'webhook_template_encoding',
        'webhook_auth_mode', 'auth_username', 'auth_header_name', 'webhook_enabled'];
    $draft = array_intersect_key($post, array_flip($keep));
    $draft['webhook_events'] = array_values(array_filter((array) ($post['webhook_events'] ?? []), 'is_string'));
    $draft['webhook_field'] = array_map('strval', array_filter((array) ($post['webhook_field'] ?? []), 'is_string'));
    // A URL can carry the secret (Telegram token, Zapier hook id): only keep it when it is the platform's own template.
    unset($draft['webhook_url']);
    $_SESSION['webhook_form_draft'] = $draft;
}

function webhookFormErrorsRedirect(array $errors, array $post, ?int $id): void {
    webhookRememberDraft($post);
    flash_alert('Webhook not saved:<br>' . implode('<br>', array_map('nullable_htmlentities', $errors)), 'error');
    $dest = urlencode((string) ($post['webhook_destination'] ?? ''));
    redirect($id ? "webhook_form.php?id=$id" : ($dest !== '' ? "webhook_form.php?dest=$dest&step=review" : 'settings_webhooks.php'));
}

if (isset($_POST['add_webhook'])) {

    validateCSRFToken($_POST['csrf_token']);

    $c = rivetWebhookCollect($mysqli, $_POST, null);
    if ($c['errors']) {
        webhookFormErrorsRedirect($c['errors'], $_POST, null);
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
    unset($_SESSION['webhook_form_draft']);

    $webhook_name = $r['webhook_name'];
    logAction("Settings", "Webhook", "$session_name added webhook $webhook_name" . ($r['webhook_destination'] !== '' ? " ({$r['webhook_destination']})" : ''));

    flash_alert("Webhook <strong>" . nullable_htmlentities($webhook_name) . "</strong> added");
    // The guided page shows a success screen with the next actions ("Create and send test" also fires a test there).
    redirect($new_id > 0 && isset($_POST['wizard']) ? "webhook_form.php?id=$new_id&created=1" . (($_POST['after'] ?? '') === 'test' ? '&test=1' : '') : 'settings_webhooks.php');
}

if (isset($_POST['edit_webhook'])) {

    validateCSRFToken($_POST['csrf_token']);

    $webhook_id = intval($_POST['webhook_id'] ?? 0);
    $stmt = mysqli_prepare($mysqli, "SELECT * FROM webhooks WHERE webhook_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $webhook_id);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$existing) {
        flash_alert('Webhook not found.', 'error');
        redirect('settings_webhooks.php');
    }
    // A legacy edit post (no platform field) keeps the row's current platform.
    if (!isset($_POST['webhook_destination'])) {
        $_POST['webhook_destination'] = (string) $existing['webhook_destination'];
    }

    $c = rivetWebhookCollect($mysqli, $_POST, $existing);
    if ($c['errors']) {
        webhookFormErrorsRedirect($c['errors'], $_POST, $webhook_id);
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
    unset($_SESSION['webhook_form_draft']);

    $webhook_name = $r['webhook_name'];
    logAction("Settings", "Webhook", "$session_name edited webhook $webhook_name");

    flash_alert("Webhook <strong>" . nullable_htmlentities($webhook_name) . "</strong> updated");
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
