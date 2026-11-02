<?php
/**
 * Interactive KB escape hatch - serve endpoint, AGENT session.
 *
 *   /agent/kb_embed.php?id=<kb_article_embed_id>
 *
 * GitHub issue #7 ("KB v2"), ported from RivetIT's agent/kb_embed.php. Framed by
 * an <iframe sandbox="allow-scripts" referrerpolicy="no-referrer" loading="lazy"
 * src="kb_embed.php?id=17">, and also reachable top-level via a plain link -
 * both cases are caged by agent/includes/kb_embed_serve.php; read that file's
 * header before changing a line of this one.
 *
 * The portal-session sibling is client/kb_embed.php. Same query shape, same
 * serve core, a completely different authorization question - exactly the split
 * agent/kb_media.php and client/kb_media.php already make.
 *
 * BOOTSTRAP: config + functions + check_login, and NOTHING ELSE. Not
 * inc_all.php/header.php - they emit a whole HTML document ahead of this one,
 * and includes/header.php sends `X-Frame-Options: DENY`, which would make the
 * frame unloadable with no explanation.
 *
 * THE RULE: the row is bound to ONE article (kb_article_embed_kb_article_id),
 * and this endpoint re-derives access to THAT article on every request with the
 * agent lane's own rule: a live agent session, module_kb >= 1, and the
 * article's department scope. Nothing is trusted from the URL beyond "which
 * embed is being asked for". EVERY FAILURE IS 404 - the difference between "no
 * such embed" and "not yours" is itself information.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';

define('FROM_KB_EMBED', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/includes/kb_embed_serve.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/includes/kb_media_auth.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

if (($config_module_enable_kb ?? 0) != 1) {
    kbEmbedFail(404, 'Not found');
}

$id_param = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : null;
$embed_id = $id_param !== null ? intval($id_param) : 0;
if ($embed_id < 1) {
    kbEmbedFail(404, 'Not found');
}

$kb_permission_level = lookupUserPermission('module_kb');
if (!$kb_permission_level || $kb_permission_level < 1) {
    kbEmbedFail(404, 'Not found');
}

$article_id = kbEmbedOwningArticleId($mysqli, $embed_id);
if ($article_id < 1) {
    kbEmbedFail(404, 'Not found');
}

try {
    $article = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT kb_article_id, kb_article_client_id
         FROM kb_articles
         WHERE kb_article_id = $article_id
           AND kb_article_archived_at IS NULL
         LIMIT 1"
    ));
} catch (\Throwable $e) {
    kbEmbedFail(404, 'Not found');
}

if (!$article) {
    kbEmbedFail(404, 'Not found');
}

$article_client_id = intval($article['kb_article_client_id']);
if (!kbMediaClientAccessOk(intval($session_user_id), $article_client_id)) {
    kbEmbedFail(404, 'Not found');
}

// Re-fetch with BOTH ids bound, so the row that is served is provably the row
// whose article was just authorised.
$embed = kbEmbedFetch($mysqli, $embed_id, $article_id);
if (!$embed) {
    kbEmbedFail(404, 'Not found');
}

kbEmbedServe(
    (string) ($embed['kb_article_embed_untrusted_html'] ?? ''),
    intval($embed['kb_article_embed_height'] ?? 0)
);
