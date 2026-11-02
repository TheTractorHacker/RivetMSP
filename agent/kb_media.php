<?php
/**
 * Authenticated serve endpoint for Knowledge Base media (technician/agent session).
 *
 * GitHub issue #7 ("KB v2"): the missing piece after versions + review dates.
 * Ported from RivetIT's agent/kb_media.php, cookie-session branch only (RivetMSP
 * has no mobile/API KB client, so RivetIT's signed-URL capability-token branch was
 * not carried over - see agent/includes/kb_media_serve.php's header).
 *
 *   /agent/kb_media.php?att=<kb_article_attachment_id>[&download=1]
 *   /agent/kb_media.php?a=<kb_article_id>&f=<reference_name>[&download=1]
 *   /agent/kb_media.php?f=<reference_name>[&download=1]
 *
 * WHY THIS EXISTS. /uploads/ is served straight off nginx/apache with no
 * authentication whatsoever - anyone who guesses or has ever seen a path gets a
 * 200. kb_article_attachments rows are runbooks, network diagrams and vendor
 * docs, scoped per-department (kb_article_client_id), and until now they were
 * linked directly by their raw filesystem path (agent/kb_article.php's
 * "View"/"Download" dropdown items), bypassing every department check entirely.
 * The stored filename is NOT a secret (checkFileUpload() names files
 * md5(contents) + 2 random chars - guessable by anyone who already holds a copy
 * of the same document), so authentication + authorization is the only control.
 *
 * THE RULE RE-DERIVED ON EVERY REQUEST, never cached, never trusted from the URL
 * beyond "which object is being asked for": a live agent session
 * (includes/check_login.php), module_kb >= 1 (a READ), and the owning article's
 * department scope (kbMediaClientAccessOk(), the bool twin of
 * enforceClientAccess() - see agent/includes/kb_media_auth.php for why it is a
 * twin and not a call).
 */

// Binary endpoint: bootstrap $mysqli, the settings and the function library
// WITHOUT the page chrome. agent/includes/inc_all.php cannot be used here - it
// pulls in header.php / top_nav.php / side_nav.php and would emit a full HTML
// document ahead of the file bytes.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';

define('FROM_KB_MEDIA', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/includes/kb_media_serve.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/includes/kb_media_auth.php';

// check_login.php does the whole agent-session chain (live, enabled, non-archived,
// user_type 1) and redirects to /login.php if any of it fails. For an <img>
// subresource that 302 simply means a broken image, which is the correct visible
// outcome for "you are not logged in".
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

if (($config_module_enable_kb ?? 0) != 1) {
    kbMediaFail(404, 'Not found');
}

/**
 * Fetch a query parameter that MUST be a scalar string.
 *
 * PHP happily makes $_GET['f'] an array when the request says ?f[]=x, and every
 * downstream preg_match()/intval() then behaves in a way the validation below was
 * not written for. Returning null for anything that is not a string puts the
 * array case on the same deny path as a missing parameter.
 */
function kbMediaParam(string $key): ?string
{
    return isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : null;
}

const KB_MEDIA_KIND_ATTACHMENT = 'att';
const KB_MEDIA_KIND_IMAGE = 'img';
const KB_MEDIA_KIND_POOL = 'pool';

// ---------------------------------------------------------------------------
// 1. What is being asked for. Format validation ONLY - no database, no
//    filesystem, and nothing here depends on who is asking.
// ---------------------------------------------------------------------------

$att_param = kbMediaParam('att');
$a_param   = kbMediaParam('a');
$f_param   = kbMediaParam('f');

if ($att_param !== null) {
    // An attachment is addressed by id ALONE. A request that also carries a
    // filename is refused rather than having the filename ignored.
    if ($a_param !== null || $f_param !== null) {
        kbMediaFail(404, 'Not found');
    }
    $kind = KB_MEDIA_KIND_ATTACHMENT;
    $ref  = intval($att_param);
    $file = '';
    if ($ref < 1) {
        kbMediaFail(404, 'Not found');
    }
} elseif ($a_param !== null) {
    // Article-scoped inline image: /uploads/kb/<article_id>/<name>.
    if ($f_param === null) {
        kbMediaFail(404, 'Not found');
    }
    $kind = KB_MEDIA_KIND_IMAGE;
    $ref  = intval($a_param);
    $file = $f_param;
    if ($ref < 1 || !kbMediaValidReferenceName($file)) {
        kbMediaFail(404, 'Not found');
    }
} elseif ($f_param !== null) {
    // The shared TinyMCE editor pool: /uploads/kb/<name>, flat, no article id, no
    // owner. See the authorization note at the pool branch below.
    $kind = KB_MEDIA_KIND_POOL;
    $ref  = 0;
    $file = $f_param;
    if (!kbMediaValidReferenceName($file)) {
        kbMediaFail(404, 'Not found');
    }
} else {
    kbMediaFail(404, 'Not found');
}

$force_download = !empty($_GET['download']);

// ---------------------------------------------------------------------------
// 2. Who is asking, and are they allowed to read module_kb at all. Viewing is a
//    READ - level 1 deliberately, so a read-only KB role can see the images in
//    the articles it is allowed to read. The pool kind skips this: see below.
// ---------------------------------------------------------------------------

$auth_user_id = intval($session_user_id);

if ($kind !== KB_MEDIA_KIND_POOL) {
    $kb_permission_level = lookupUserPermission('module_kb');
    if (!$kb_permission_level || $kb_permission_level < 1) {
        kbMediaFail(403, 'Access denied');
    }
}

// ---------------------------------------------------------------------------
// 3. Which article/attachment is this, and may this session read it. NO ROW IS
//    A 404 in every branch: the /uploads/kb/<id>/ directory outlives the article
//    row (nothing unlinks it), so the row is the ONLY thing that can make a
//    deleted article's files unreachable.
// ---------------------------------------------------------------------------

if ($kind === KB_MEDIA_KIND_ATTACHMENT) {

    $row = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT kb_article_attachments.kb_article_attachment_name,
                kb_article_attachments.kb_article_attachment_reference_name,
                kb_articles.kb_article_id,
                kb_articles.kb_article_client_id
         FROM kb_article_attachments
         INNER JOIN kb_articles
                 ON kb_articles.kb_article_id = kb_article_attachments.kb_article_attachment_kb_article_id
         WHERE kb_article_attachments.kb_article_attachment_id = $ref
         LIMIT 1"
    ));

    if (!$row) {
        kbMediaFail(404, 'Not found');
    }

    if (!kbMediaClientAccessOk($auth_user_id, intval($row['kb_article_client_id']))) {
        kbMediaFail(403, 'Access denied');
    }

    $reference_name = (string) $row['kb_article_attachment_reference_name'];
    if (!kbMediaValidReferenceName($reference_name)) {
        kbMediaFail(404, 'Not found');
    }

    $file_path = kbMediaResolve(intval($row['kb_article_id']) . '/' . $reference_name);
    kbMediaServe($file_path, (string) $row['kb_article_attachment_name'], $force_download);

} elseif ($kind === KB_MEDIA_KIND_IMAGE) {

    $row = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT kb_article_client_id
         FROM kb_articles
         WHERE kb_article_id = $ref
         LIMIT 1"
    ));

    if (!$row) {
        kbMediaFail(404, 'Not found');
    }

    if (!kbMediaClientAccessOk($auth_user_id, intval($row['kb_article_client_id']))) {
        kbMediaFail(403, 'Access denied');
    }

    // The capability granted by a forged ?a= is bounded by the filesystem: the
    // article id picks the DIRECTORY, so claiming an article you can read does not
    // let you reach a file that lives under one you cannot.
    $file_path = kbMediaResolve($ref . '/' . $file);
    kbMediaServe($file_path, $file, $force_download);

} else {

    /* THE SHARED EDITOR POOL. agent/kb_article_upload.php writes TinyMCE's
       uploaded images FLAT into /uploads/kb/<name> - no article id in the path,
       no row in any table, no owner recorded anywhere. So the rule here is "any
       live agent session", with NO module check: requiring module_kb would hide
       inline images in ticket replies/IT documents for agents whose role lacks KB
       access, which is a functional break with no security benefit (they can
       already see those images today via the raw /uploads/ path, and the file has
       no KB affiliation to enforce). This endpoint is strictly narrower than that
       status quo, not equal to it. */
    $file_path = kbMediaResolve($file);
    kbMediaServe($file_path, $file, $force_download);
}

kbMediaFail(404, 'Not found');
