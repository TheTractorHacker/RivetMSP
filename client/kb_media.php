<?php
/**
 * Client Portal - authenticated serve endpoint for Knowledge Base media.
 *
 *   /client/kb_media.php?att=<kb_article_attachment_id>[&download=1]
 *   /client/kb_media.php?a=<kb_article_id>&f=<reference_name>[&download=1]
 *   /client/kb_media.php?f=<reference_name>[&download=1]
 *
 * The portal-session sibling of agent/kb_media.php (GitHub issue #7, "KB v2" -
 * ported from RivetIT). Same query shape, same byte-serving core
 * (agent/includes/kb_media_serve.php), a COMPLETELY different authorization
 * question: a department contact holds a portal session, not an agent one, so
 * every article/attachment here is re-tested against THIS SESSION'S OWN
 * $session_client_id - never against module_kb or user_client_permissions, which
 * do not exist on this side.
 *
 * THE RULE, AND WHY IT IS THE ARTICLE QUERY. There is no module_kb permission on
 * the portal side and no user_client_permissions row for a contact. What decides
 * whether a contact may read an article is one WHERE clause, and it must be the
 * SAME clause client/kb_article.php's own detail query uses:
 *
 *     kb_article_client_visible = 1 AND kb_article_client_id IN (0, $session_client_id)
 *     AND kb_article_archived_at IS NULL
 *
 * Every branch below re-runs that clause against the live database on every
 * request, so a forged ?a=/?att= naming another department's article, or an
 * article an agent switched to client_visible=0, gets nothing - the directory may
 * still exist on disk, but the ROW is the only thing that can make it reachable,
 * and the row is gated.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';

define('FROM_KB_MEDIA', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/includes/kb_media_serve.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/client/includes/check_login.php';

if (($config_module_enable_kb ?? 0) != 1) {
    kbMediaFail(404, 'Not found');
}

if (!isset($session_client_id)) {
    kbMediaFail(404, 'Not found');
}
$session_client_id = intval($session_client_id);

function kbMediaPortalParam(string $key): ?string
{
    return isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : null;
}

const KB_MEDIA_PORTAL_KIND_ATTACHMENT = 'att';
const KB_MEDIA_PORTAL_KIND_IMAGE = 'img';
const KB_MEDIA_PORTAL_KIND_POOL = 'pool';

$att_param = kbMediaPortalParam('att');
$a_param   = kbMediaPortalParam('a');
$f_param   = kbMediaPortalParam('f');

if ($att_param !== null) {
    if ($a_param !== null || $f_param !== null) {
        kbMediaFail(404, 'Not found');
    }
    $kind = KB_MEDIA_PORTAL_KIND_ATTACHMENT;
    $ref  = intval($att_param);
    $file = '';
    if ($ref < 1) {
        kbMediaFail(404, 'Not found');
    }
} elseif ($a_param !== null) {
    if ($f_param === null) {
        kbMediaFail(404, 'Not found');
    }
    $kind = KB_MEDIA_PORTAL_KIND_IMAGE;
    $ref  = intval($a_param);
    $file = $f_param;
    if ($ref < 1 || !kbMediaValidReferenceName($file)) {
        kbMediaFail(404, 'Not found');
    }
} elseif ($f_param !== null) {
    $kind = KB_MEDIA_PORTAL_KIND_POOL;
    $ref  = 0;
    $file = $f_param;
    if (!kbMediaValidReferenceName($file)) {
        kbMediaFail(404, 'Not found');
    }
} else {
    kbMediaFail(404, 'Not found');
}

$force_download = !empty($_GET['download']);

// The portal's article-visibility clause, written once and reused by all three
// branches. Must stay byte-for-byte in step with client/kb_article.php's detail
// query - if they ever disagreed, a contact would see either an article whose
// media 404s or media belonging to an article the portal refuses to show.
$portal_article_scope = "kb_article_client_visible = 1
     AND kb_article_client_id IN (0, $session_client_id)
     AND kb_article_archived_at IS NULL";

if ($kind === KB_MEDIA_PORTAL_KIND_ATTACHMENT) {

    $row = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT kb_article_attachments.kb_article_attachment_name,
                kb_article_attachments.kb_article_attachment_reference_name,
                kb_articles.kb_article_id
         FROM kb_article_attachments
         INNER JOIN kb_articles
                 ON kb_articles.kb_article_id = kb_article_attachments.kb_article_attachment_kb_article_id
         WHERE kb_article_attachments.kb_article_attachment_id = $ref
           AND $portal_article_scope
         LIMIT 1"
    ));

    if (!$row) {
        kbMediaFail(404, 'Not found');
    }

    $reference_name = (string) $row['kb_article_attachment_reference_name'];
    if (!kbMediaValidReferenceName($reference_name)) {
        kbMediaFail(404, 'Not found');
    }

    $file_path = kbMediaResolve(intval($row['kb_article_id']) . '/' . $reference_name);
    kbMediaServe($file_path, (string) $row['kb_article_attachment_name'], $force_download);

} elseif ($kind === KB_MEDIA_PORTAL_KIND_IMAGE) {

    $row = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT kb_article_id
         FROM kb_articles
         WHERE kb_article_id = $ref
           AND $portal_article_scope
         LIMIT 1"
    ));

    if (!$row) {
        kbMediaFail(404, 'Not found');
    }

    $file_path = kbMediaResolve($ref . '/' . $file);
    kbMediaServe($file_path, $file, $force_download);

} else {

    /* THE SHARED EDITOR POOL, IN THE PORTAL. No owning row exists for a flat
       pool file, so "any contact" cannot be the rule - that would hand one
       department's pasted screenshot to every other department. The scope is
       instead DERIVED FROM CONTENT THIS SESSION MAY ALREADY READ: served only if
       some article the portal would show THIS session's own content references
       the file by its canonical ?f= URL or by the legacy flat /uploads/kb/<name>
       path. addcslashes() BEFORE mysqli_real_escape_string(), in that order: '_'
       is both in the reference-name charset and LIKE's single-character
       wildcard, so escaping it first keeps the match exact rather than widened. */
    $like_canonical = mysqli_real_escape_string($mysqli, addcslashes('kb_media.php?f=' . $file, '%_'));
    $like_legacy     = mysqli_real_escape_string($mysqli, addcslashes('/uploads/kb/' . $file, '%_'));

    $row = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT 1 AS ok
         FROM kb_articles
         WHERE $portal_article_scope
           AND (kb_article_content LIKE '%$like_canonical%'
                OR kb_article_content LIKE '%$like_legacy%')
         LIMIT 1"
    ));

    if (!$row) {
        kbMediaFail(404, 'Not found');
    }

    $file_path = kbMediaResolve($file);
    kbMediaServe($file_path, $file, $force_download);
}

kbMediaFail(404, 'Not found');
