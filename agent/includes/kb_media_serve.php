<?php
/**
 * Shared byte-serving core for every authenticated Knowledge Base media route.
 *
 * GitHub issue #7 ("KB v2"): versions and review dates already shipped on RivetMSP.
 * This file is the missing piece - media attachments + inline images - ported from
 * RivetIT's agent/includes/kb_media_serve.php, with the signed-URL / mobile-API
 * capability-token branch (RivetIT's \ITFlow\KB\MediaToken machinery) deliberately
 * NOT carried over: RivetMSP has no Android/mobile KB client today, so there is no
 * caller for it, and porting unused attack surface is a liability with no benefit.
 * Every RivetMSP caller authenticates with its lane's own cookie session only (see
 * agent/kb_media.php and client/kb_media.php).
 *
 * WHY THIS FILE EXISTS: two endpoints (agent/kb_media.php, client/kb_media.php) hand
 * a caller the raw bytes of a file under /uploads/kb, and both must answer the "how
 * do I put these bytes on the wire without creating stored XSS at this origin"
 * question IDENTICALLY. That part is subtle - see kbMediaServe() below - and a
 * divergence between copies of it would be a vulnerability rather than a bug, so it
 * lives here exactly once.
 *
 * WHAT THIS FILE DOES NOT DO: authorization. Not one line of it. Every caller must
 * have finished deciding "is this principal allowed to read this article" BEFORE it
 * calls kbMediaResolve()/kbMediaServe(). These functions will cheerfully serve any
 * file under /uploads/kb to anybody, because by the time they run that question is
 * settled.
 */

defined('FROM_KB_MEDIA') || die("Direct file access is not allowed");

/**
 * Bail out with a status code and a plain-text body.
 *
 * Every failure path lands here so a bad id, a deleted row or a file that has gone
 * missing from disk produces a clean status code instead of a PHP fatal or a
 * half-written response - and, just as importantly, so none of them leak whether
 * the requested id exists.
 */
function kbMediaFail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: no-referrer');
    echo $message;
    exit;
}

/**
 * The one true shape of a stored KB media filename.
 *
 * Deliberately the PERMISSIVE form, matching functions.php::checkFileUpload(),
 * which names files md5(contents) . randomString(2) . '.' . ext, and randomString()
 * is base64url - so '-' and '_' genuinely occur in the stored name. The SERVE path
 * must be permissive or it would refuse to hand back files it already accepted.
 * There is no article-id component in this pattern; the article id is a separate,
 * already-validated integer that the caller joins onto the path itself.
 */
function kbMediaValidReferenceName(string $reference_name): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$/', $reference_name);
}

/**
 * Resolve a path under DOCUMENT_ROOT/uploads/kb and prove it stayed there.
 *
 * $relative_path must already be built out of validated components by the caller -
 * an intval'd article id, a kbMediaValidReferenceName()'d filename. This is the LAST
 * line of defence, not the first: it exists so that a hypothetically poisoned
 * database column, or a future caller that forgets a check, still cannot turn into
 * an arbitrary-file read.
 *
 * Ends the request on any failure rather than returning false, so a caller
 * physically cannot forget to check the return value.
 */
function kbMediaResolve(string $relative_path): string
{
    $uploads_root = realpath($_SERVER['DOCUMENT_ROOT'] . '/uploads/kb');
    if ($uploads_root === false) {
        kbMediaFail(404, 'Not found');
    }

    $file_path = realpath($uploads_root . '/' . $relative_path);

    // realpath() has resolved every symlink and every ".." by this point, so the
    // prefix test below is a statement about the real file, not about the string
    // that was used to name it.
    if ($file_path === false || !is_file($file_path) || !is_readable($file_path)) {
        kbMediaFail(404, 'Not found');
    }
    if (strncmp($file_path, $uploads_root . DIRECTORY_SEPARATOR, strlen($uploads_root) + 1) !== 0) {
        kbMediaFail(403, 'Access denied');
    }

    return $file_path;
}

/**
 * Stream an already-authorized, already-contained file to the client.
 *
 * $file_path      - output of kbMediaResolve(), never a request-derived string.
 * $download_name  - the human-facing filename. Hostile input by definition.
 * $force_download - true to refuse `inline` regardless of type.
 *
 * The security rule that must never be relaxed here: `inline` is granted from a
 * short ALLOW-list of types that cannot carry script at this origin (PDF and raster
 * images). Everything else - and text/html, application/xhtml+xml and
 * image/svg+xml explicitly and unconditionally - is served as an attachment.
 * Inlining any of those three would hand an uploader stored XSS on this origin.
 */
function kbMediaServe(string $file_path, string $download_name, bool $force_download): void
{
    $handle = fopen($file_path, 'rb');
    if ($handle === false) {
        kbMediaFail(404, 'Not found');
    }

    // From the file's contents, never its extension: the extension is attacker
    // chosen at upload time. A bounded 64 KB head is enough for libmagic and avoids
    // pulling a multi-hundred-MB attachment fully into memory just to classify it.
    $mime_type = false;
    if (class_exists('finfo')) {
        $file_head = fread($handle, 65536);
        rewind($handle);
        if ($file_head !== false && $file_head !== '') {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime_type = $finfo->buffer($file_head);
        }
        unset($file_head);
    }
    if (!is_string($mime_type) || $mime_type === '') {
        $mime_type = 'application/octet-stream';
    }
    $mime_type_bare = strtolower(trim(explode(';', $mime_type)[0]));

    // ALLOW-list, not a deny-list: an unknown type is an attachment by construction.
    $inline_safe_mime_types = [
        'application/pdf',
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/x-ms-bmp',
        'image/avif',
    ];

    // Belt and braces on top of the allow-list: THE MOST IMPORTANT CHECK IN THE
    // FILE. These types turn an attachment into stored XSS at this origin the
    // moment they are rendered here.
    $never_inline_mime_types = [
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
        'text/xml',
        'application/xml',
    ];

    $disposition = 'attachment';
    if (
        !$force_download
        && in_array($mime_type_bare, $inline_safe_mime_types, true)
        && !in_array($mime_type_bare, $never_inline_mime_types, true)
    ) {
        $disposition = 'inline';
    }

    // For those renderable-and-dangerous types, refuse to NAME them too - the
    // bytes are unchanged, so the download itself is unaffected.
    if (in_array($mime_type_bare, $never_inline_mime_types, true)) {
        $mime_type = 'application/octet-stream';
    }

    // The original name came from a user upload, so it is hostile input in a header
    // context. Two independent, header-injection-proof encodings (RFC 5987 + ASCII
    // fallback); neither can carry a newline into header().
    $utf8_name = preg_replace('/[\x00-\x1F\x7F]/', '', $download_name);
    $utf8_name = str_replace(['/', '\\'], '_', $utf8_name);
    $utf8_name = substr($utf8_name, 0, 200);

    $ascii_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $utf8_name);
    $ascii_name = trim($ascii_name, '.');
    $ascii_name = substr($ascii_name, 0, 200);
    if ($ascii_name === '' || $ascii_name === false) {
        $ascii_name = 'attachment';
    }

    $content_disposition = $disposition
        . '; filename="' . $ascii_name . '"'
        . "; filename*=UTF-8''" . rawurlencode($utf8_name);

    $file_size = filesize($file_path);

    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: ' . $content_disposition);
    header('Content-Length: ' . $file_size);
    header('X-Content-Type-Options: nosniff');

    // No CSP is inherited here (this response is not routed through header.php), so
    // set one directly - belt and braces behind the positive inline allow-list above.
    header("Content-Security-Policy: default-src 'none'; script-src 'none'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    fpassthru($handle);
    fclose($handle);
    exit;
}
