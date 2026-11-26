<?php

/*
 * Inline PDF viewer for stored attachments (ticket attachments and client files).
 *
 * Everything under /uploads/ is sent by the web server with "Content-Disposition: attachment" (so an uploaded .svg/.html can never run script at the app's
 * origin), which makes the browser DOWNLOAD a PDF instead of opening its viewer. This route is the authorised way to open one: it checks the login and the
 * module/client permissions, resolves the file from the database (never from a path in the request), serves it ONLY if it really is a PDF (%PDF- magic),
 * and sends it as application/pdf + nosniff, so the browser's own PDF viewer opens it. Anything that is not a PDF is refused (use the Download link).
 *
 * GET pdf_view.php?type=ticket&id=<ticket_attachment_id>   |   ?type=file&id=<file_id>
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';

enforceUserPermission('module_support');

$type = $_GET['type'] ?? '';
$id   = intval($_GET['id'] ?? 0);

$path = '';
$name = 'document.pdf';

if ($type === 'ticket' && $id > 0) {
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT ta.ticket_attachment_name, ta.ticket_attachment_reference_name, ta.ticket_attachment_ticket_id, t.ticket_client_id
         FROM ticket_attachments ta JOIN tickets t ON t.ticket_id = ta.ticket_attachment_ticket_id
         WHERE ta.ticket_attachment_id = $id LIMIT 1"));
    if ($row) {
        // the same client scope as agent/ticket.php (0 = a ticket with no client)
        $cid = intval($row['ticket_client_id']);
        if ($client_access_string && empty($session_is_admin) && $cid !== 0 && !in_array($cid, array_map('intval', explode(',', $client_access_string)), true)) {
            $row = null;
        }
    }
    if ($row) {
        $path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/tickets/' . intval($row['ticket_attachment_ticket_id']) . '/' . basename((string) $row['ticket_attachment_reference_name']);
        $name = (string) $row['ticket_attachment_name'];
    }
} elseif ($type === 'file' && $id > 0) {
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT file_name, file_reference_name, file_client_id FROM files WHERE file_id = $id LIMIT 1"));
    if ($row) {
        $cid = intval($row['file_client_id']);
        if ($client_access_string && empty($session_is_admin) && $cid !== 0 && !in_array($cid, array_map('intval', explode(',', $client_access_string)), true)) {
            $row = null;
        }
    }
    if ($row) {
        $path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/clients/' . intval($row['file_client_id']) . '/' . basename((string) $row['file_reference_name']);
        $name = (string) $row['file_name'];
    }
}

if ($path === '' || !is_file($path)) {
    http_response_code(404);
    exit('File not found');
}

$fh = fopen($path, 'rb');
$magic = $fh ? fread($fh, 5) : '';
if ($magic !== '%PDF-') {
    if ($fh) { fclose($fh); }
    http_response_code(415);
    exit('This file is not a PDF; use Download.');
}
fclose($fh);

$safe = preg_replace('/[^\w.\- ]/', '_', $name);
if (!str_ends_with(strtolower($safe), '.pdf')) { $safe .= '.pdf'; }

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $safe . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=0');
readfile($path);
exit;
