<?php

/*
 * Live address check for the webhook form (admin only, POST + CSRF, JSON). It runs the SAME verdict the save runs
 * (rivetWebhookUrlVerdict(): platform URL pattern, placeholders, and the shared URL policy including the admin's allowed internal
 * networks) and makes NO outbound request (a DNS lookup of the host name is the only network use, exactly like the save).
 * Fields: webhook_destination, webhook_url, webhook_field[name] (platform fields that go into the address), webhook_id (edit: a blank
 * address means "keep the saved one").
 * Answer: {ok, state, message, host, link, suggest}. state is one of ok, empty, keep, invalid, placeholder, pattern, private, blocked, unresolved.
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
    $out(['ok' => false, 'state' => 'denied', 'message' => 'Your role does not have admin access.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $out(['ok' => false, 'state' => 'error', 'message' => 'POST required.'], 405);
}
if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals((string) $_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $out(['ok' => false, 'state' => 'error', 'message' => 'CSRF token verification failed. Reload the page.'], 403);
}

require_once __DIR__ . '/includes/webhook_form_lib.php';

use RivetCore\Webhooks\Destinations;

$destId = trim((string) ($_POST['webhook_destination'] ?? ''));
$dest = $destId !== '' ? Destinations::get($destId) : null;
if ($destId !== '' && $dest === null) {
    $out(['ok' => false, 'state' => 'invalid', 'message' => 'Unknown platform.', 'host' => '', 'link' => null, 'suggest' => null]);
}

$url = trim((string) ($_POST['webhook_url'] ?? ''));
if ($url === '') {
    $wid = intval($_POST['webhook_id'] ?? 0);
    if ($wid > 0 && mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM webhooks WHERE webhook_id = $wid")) > 0) {
        $out(['ok' => true, 'state' => 'keep', 'message' => 'The saved address is kept. Type a new one only to replace it.', 'host' => '', 'link' => null, 'suggest' => null]);
    }
    $out(['ok' => false, 'state' => 'empty', 'message' => 'Paste the address the receiver gave you.', 'host' => '', 'link' => null, 'suggest' => null]);
}
if ($dest !== null) {
    $fields = rivetWebhookPostedFields($dest, $_POST);
    foreach ($dest->extraFields as $f) {
        if ($f->target === 'url' && $fields[$f->name] !== '') {
            $url = str_replace('{' . $f->name . '}', str_replace('%3A', ':', rawurlencode($fields[$f->name])), $url);
        }
    }
}

$v = rivetWebhookUrlVerdict($mysqli, $dest, $url);
$out(['ok' => $v['state'] === 'ok', 'state' => $v['state'], 'message' => $v['friendly'], 'host' => $v['host'], 'link' => $v['link'], 'suggest' => $v['suggest']]);
