<?php

// JSON endpoint behind the Add / Edit webhook pages (js/webhook_wizard.js): the live address check, "Send test", "Preview payload", the live
// template check, step validation, and a test of an already saved webhook.
// Admin only (modal_header.php), POST only, CSRF-checked. Nothing is saved. Secrets are never returned: the preview masks auth headers
// and shows the address host only, and a test result carries the HTTP status, duration and a short cut of the response body.
// Every verdict comes from admin/includes/webhook_form_lib.php, the same code the save handler runs, so a test or a check can never
// disagree with what would be stored. Plain http:// is refused unless the form carries the audit-logged "Allow plain http://" opt-in.

require_once '../../../includes/modal_header.php';
require_once '../../includes/webhook_form_lib.php';

use RivetCore\Webhooks\Destinations;
use RivetCore\Webhooks\PayloadTemplate;

$out = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $out(['ok' => false, 'errors' => ['POST only.']], 405);
}
$csrf = $_POST['csrf_token'] ?? null;
if (!is_string($csrf) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
    $out(['ok' => false, 'errors' => ['Your session expired. Reload the page and try again.']], 403);
}
$action = (string) ($_POST['wh_action'] ?? '');

/** The saved row of ?webhook_id, or null. */
$loadRow = static function () use ($mysqli): ?array {
    $wid = intval($_POST['webhook_id'] ?? 0);
    if ($wid <= 0) {
        return null;
    }
    $stmt = mysqli_prepare($mysqli, "SELECT * FROM webhooks WHERE webhook_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $wid);
    mysqli_stmt_execute($stmt);

    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
};
$sampleEvent = static function (): string {
    $e = (string) ($_POST['sample_event'] ?? 'ticket.created');

    return in_array($e, rivetWebhookSampleEvents(), true) ? $e : 'ticket.created';
};

if ($action === 'validate_template') {
    $enc = (string) ($_POST['webhook_template_encoding'] ?? 'json');
    $tpl = str_replace("\r\n", "\n", (string) ($_POST['webhook_template'] ?? ''));
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

// Live address check: the same verdict as Save (platform pattern, placeholders, URL policy incl. the allowed internal networks), no request to the address.
if ($action === 'check_url') {
    $existing = $loadRow();
    $res = static fn (string $state, string $msg, string $host = '', ?string $link = null, ?string $suggest = null): array => [
        'state' => $state, 'ok' => in_array($state, ['ok', 'keep'], true), 'message' => $msg, 'host' => $host, 'link' => $link, 'suggest' => $suggest,
    ];
    $destId = trim((string) ($_POST['webhook_destination'] ?? ''));
    $dest = $destId !== '' ? Destinations::get($destId) : null;
    if ($dest === null) {
        $out($res('invalid', 'Choose a platform first.'));
    }
    $fields = rivetWebhookPostedFields($dest, $_POST);
    $built = rivetWebhookBuildUrl($dest, trim((string) ($_POST['webhook_url'] ?? '')), $fields);
    if ($built === '') {
        $same = $existing !== null && rivetWebhookEffectiveDestination($existing) === $dest->id && (string) $existing['webhook_url'] !== '';
        $out($same ? $res('keep', 'The saved address stays as it is. Type a new one only to replace it.') : $res('empty', 'Paste the ' . $dest->name . ' address here.'));
    }
    // The wizard's live check does not carry the "Allow plain http://" box (it posts only the address parts). So the address is judged as if the
    // opt-in were given (a private or blocked address is still explained), and a plain http:// address that passes is reported as a warning:
    // the opt-in itself is enforced by the step validation, by Save and by every test, never silently accepted here.
    $allowHttp = isset($_POST['webhook_allow_http']);
    $v = rivetWebhookUrlVerdict($mysqli, $dest, $built, true);
    if ($v['state'] === 'ok') {
        if (!$allowHttp && \RivetCore\Webhooks\UrlPolicy::isPlainHttp(str_replace('{txn}', 'x', $built))) {
            $out($res('incomplete', 'This address starts with http://. Webhooks are sent over https only unless you tick "Allow plain http://" under the address, which is audit-logged.', $v['host']));
        }
        $out($res('ok', $v['friendly'], $v['host']));
    }
    if ($v['state'] === 'placeholder') {
        $out($res('incomplete', $v['friendly'], $v['host']));
    }
    $out($res($v['state'], $v['friendly'], $v['host'], $v['link'], $v['suggest']));
}

// Send a test through the saved row (success screen, Edit page header): nothing from the browser but the id.
if ($action === 'test_saved') {
    $saved = $loadRow();
    if (!$saved) {
        $out(['ok' => false, 'errors' => ['Webhook not found.']], 404);
    }
    try {
        $sub = rivetWebhookSubsFromRow($saved, (int) $saved['webhook_id']);
        $r = rivetWebhookSendTest($mysqli, $sub, $sampleEvent());
    } catch (\Throwable $e) {
        $out(['ok' => false, 'errors' => ['The test could not be sent: ' . $e->getMessage()]]);
    }
    logAction('Settings', 'Webhook', "$session_name sent a test to " . sanitizeInput($saved['webhook_name']) . ($r['ok'] ? " (HTTP {$r['http_status']})" : ' (failed)'));
    $r['hint'] = rivetWebhookTestHint((bool) $r['ok'], $r['http_status'] !== null ? (int) $r['http_status'] : null, $r['error']);
    $out(['ok' => true, 'result' => $r]);
}

if (!in_array($action, ['test', 'preview', 'validate'], true)) {
    $out(['ok' => false, 'errors' => ['Unknown action.']], 400);
}

// The form is validated exactly like a save (same rules), against the saved row when editing so blank secrets mean "keep".
$existing = null;
if (intval($_POST['webhook_id'] ?? 0) > 0) {
    $existing = $loadRow();
    if (!$existing) {
        $out(['ok' => false, 'errors' => ['Webhook not found.']], 404);
    }
}
$in = $_POST;
if ($action === 'validate' && ($in['scope'] ?? '') === 'connect' && empty($in['webhook_events'])) {
    $in['webhook_events'] = ['*'];   // the Events step comes later: do not complain about it yet
}
if (trim((string) ($in['webhook_destination'] ?? '')) === '') {
    $out(['ok' => false, 'errors' => ['Choose a platform first.']]);
}
try {
    $c = rivetWebhookCollect($mysqli, $in, $existing);
} catch (\Throwable $e) {
    $out(['ok' => false, 'errors' => ['The form could not be checked: ' . $e->getMessage()]]);
}
if ($action === 'validate') {
    // Each problem is filed under the step that can fix it, so the page can send the person back to the right place.
    $steps = [];
    foreach ($c['errors'] as $e) {
        $step = preg_match('/^(Unknown event|Choose at least one event|Too many events)/', $e) ? 'events'
            : (preg_match('/^(Template|The template encoding|The signing secret|A signing secret|Authentication|.* only accepts (POST|PUT))/i', $e) ? 'advanced' : 'connect');
        $steps[] = ['step' => $step, 'message' => $e];
    }
    $out(['ok' => !$c['errors'], 'errors' => $c['errors'], 'steps' => $steps]);
}
if ($c['errors'] || !$c['subscription']) {
    $out(['ok' => false, 'errors' => $c['errors'] ?: ['The form is incomplete.']]);
}
$sub = $c['subscription'];
$event = $sampleEvent();

if ($action === 'preview') {
    try {
        $p = rivetWebhookPreview($sub, $c['auth_redacted'], $event);
    } catch (\Throwable $e) {
        $out(['ok' => false, 'errors' => ['The payload could not be built: ' . $e->getMessage()]]);
    }
    $out(['ok' => true, 'preview' => $p, 'event' => $event, 'events' => rivetWebhookSampleEvents()]);
}

$r = rivetWebhookSendTest($mysqli, $sub, $event);
$name = (string) ($c['row']['webhook_name'] ?? '');
$http_note = ($sub->options['allow_http'] ?? false) === true ? ' over plain http (opt-in)' : '';
logAction('Settings', 'Webhook', "$session_name sent a test to " . sanitizeInput($name) . $http_note . ($r['ok'] ? " (HTTP {$r['http_status']})" : ' (failed)'));
$r['hint'] = rivetWebhookTestHint((bool) $r['ok'], $r['http_status'] !== null ? (int) $r['http_status'] : null, $r['error']);
$out(['ok' => true, 'result' => $r]);
