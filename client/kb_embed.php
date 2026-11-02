<?php
/**
 * Client Portal - interactive KB escape hatch serve endpoint.
 *
 *   /client/kb_embed.php?id=<kb_article_embed_id>
 *
 * The portal-session sibling of agent/kb_embed.php (GitHub issue #7, "KB v2" -
 * ported from RivetIT). Same query shape, same serve core
 * (agent/includes/kb_embed_serve.php), the portal's own authorization question:
 * the embed's owning article must be one THIS SESSION's $session_client_id may
 * read under client/kb_article.php's own visibility rule - never module_kb or
 * user_client_permissions, neither of which exist on this side.
 *
 * BOOTSTRAP: config + functions + load_global_settings + the portal's own
 * check_login, and NOTHING ELSE - not client/includes/inc_all.php, which pulls
 * in client/includes/header.php and would both emit a full HTML document ahead
 * of this one and send `X-Frame-Options: DENY` (unloadable frame, no
 * explanation). `frame-ancestors 'self'` in kbEmbedServe()'s CSP is what
 * replaces XFO here.
 *
 * EVERY FAILURE IS 404 - the difference between "no such embed" and "not yours"
 * is itself information.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';

define('FROM_KB_EMBED', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/includes/kb_embed_serve.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/client/includes/check_login.php';

if (($config_module_enable_kb ?? 0) != 1) {
    kbEmbedFail(404, 'Not found');
}

if (!isset($session_client_id)) {
    kbEmbedFail(404, 'Not found');
}
$session_client_id = intval($session_client_id);

$id_param = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : null;
$embed_id = $id_param !== null ? intval($id_param) : 0;
if ($embed_id < 1) {
    kbEmbedFail(404, 'Not found');
}

$article_id = kbEmbedOwningArticleId($mysqli, $embed_id);
if ($article_id < 1) {
    kbEmbedFail(404, 'Not found');
}

// Must stay byte-for-byte in step with client/kb_article.php's own detail-query
// visibility rule and client/kb_media.php's $portal_article_scope.
try {
    $article = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT kb_article_id
         FROM kb_articles
         WHERE kb_article_id = $article_id
           AND kb_article_client_visible = 1
           AND kb_article_client_id IN (0, $session_client_id)
           AND kb_article_archived_at IS NULL
         LIMIT 1"
    ));
} catch (\Throwable $e) {
    kbEmbedFail(404, 'Not found');
}

if (!$article) {
    kbEmbedFail(404, 'Not found');
}

$embed = kbEmbedFetch($mysqli, $embed_id, $article_id);
if (!$embed) {
    kbEmbedFail(404, 'Not found');
}

kbEmbedServe(
    (string) ($embed['kb_article_embed_untrusted_html'] ?? ''),
    intval($embed['kb_article_embed_height'] ?? 0)
);
