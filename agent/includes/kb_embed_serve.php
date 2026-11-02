<?php
/**
 * Shared serve core for the Knowledge Base "interactive embed" escape hatch.
 *
 * GitHub issue #7 ("KB v2") - the embeds half of the missing piece, ported from
 * RivetIT's agent/includes/kb_embed_serve.php.
 *
 * ===========================================================================
 * THIS FILE ECHOES HTML THAT NO FILTER HAS EVER TOUCHED. READ THIS HEADER.
 * ===========================================================================
 *
 * kb_article_embeds.kb_article_embed_untrusted_html is the only column in this
 * database holding author-supplied HTML that is never purified. That is not an
 * oversight - purifying it would delete the thing it exists to carry (a small
 * self-contained tool: a subnet calculator, a sizing sheet). The ONLY reason it
 * is not stored XSS on this application's own origin is the `sandbox` directive
 * in the response Content-Security-Policy that kbEmbedServe() sends.
 *
 * FIVE RULES, EVERY ONE LOAD-BEARING (identical to RivetIT's own; do not relax
 * any of them when editing this file):
 *
 * 1. NEVER ADD `allow-same-origin` to the sandbox - combined with
 *    `script-src 'unsafe-inline'` below it turns this endpoint into an XSS
 *    primitive at this origin. `allow-scripts` WITHOUT `allow-same-origin` puts
 *    the document in an OPAQUE origin: no document.cookie, no localStorage, no
 *    parent.document, and 'self' matches nothing for it.
 * 2. THE `sandbox` DIRECTIVE MUST STAY IN THE RESPONSE HEADER, not only on an
 *    iframe attribute - this endpoint is also reachable as a top-level
 *    navigation (a no-JS fallback link), where an iframe attribute does not
 *    exist.
 * 3. THE HEADER SANDBOX AND ANY IFRAME `sandbox` ATTRIBUTE MUST INTERSECT
 *    EXACTLY on `allow-scripts`. A token present in one and absent from the
 *    other is silently lost.
 * 4. NEVER include inc_all.php/header.php from a caller of this file -
 *    includes/header.php sends `X-Frame-Options: DENY`, which makes the frame
 *    unloadable with no console message explaining why. `frame-ancestors
 *    'self'` in the CSP below is what replaces XFO here.
 * 5. WHAT THIS FILE DOES NOT DO: authorization. Not one line. Every caller must
 *    have already decided "may this principal read the ARTICLE that owns this
 *    embed" before calling kbEmbedServe(). kb_article_embed_kb_article_id is the
 *    whole authorization model - the row is bound to one article, and each lane
 *    re-derives access to that article with its own rule.
 */

defined('FROM_KB_EMBED') || die("Direct file access is not allowed");

// The declared-height bounds. 120 is about the smallest frame that can show a
// line of text and a button; 4000 is past any screen and is the point where
// "tall" becomes a denial of service on the article page.
const KB_EMBED_MIN_HEIGHT = 120;
const KB_EMBED_MAX_HEIGHT = 4000;

// Refuse to serve anything larger than this - the serve-side restatement of the
// authoring-side cap, so a row that got large by some other route (a direct
// database edit) cannot be turned into an even-larger response by anyone who can
// guess an id.
const KB_EMBED_MAX_BYTES = 52428800;

/**
 * Bail out with a status code and a plain-text body.
 *
 * ALWAYS 404 from the callers, never 403: the difference between "no such
 * embed" and "not yours" is itself information. The failure response carries a
 * minimal policy of its own (not the embed policy) - nothing here needs to run.
 */
function kbEmbedFail(int $status, string $message): void
{
    if (headers_sent($sent_file, $sent_line)) {
        error_log("kb_embed_serve: kbEmbedFail($status) refused - output already started at $sent_file:$sent_line");
        exit;
    }
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header("Content-Security-Policy: sandbox; default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: private, no-store');
    echo $message;
    exit;
}

/**
 * Clamp a declared height into the range the render layer will honour. Pins to
 * the nearer edge rather than substituting a default: this is reporting about a
 * row that already exists, and a direct database edit is not a trust boundary.
 */
function kbEmbedClampHeight(int $height): int
{
    if ($height < KB_EMBED_MIN_HEIGHT) {
        return KB_EMBED_MIN_HEIGHT;
    }
    if ($height > KB_EMBED_MAX_HEIGHT) {
        return KB_EMBED_MAX_HEIGHT;
    }
    return $height;
}

/**
 * Fetch one embed row, bound to one article.
 *
 * BOTH ids are in the WHERE clause: the embed id from the request, the article
 * id from whatever the caller has ALREADY proved the principal may read. A
 * request naming an embed that belongs to a different article gets no row, so
 * an id that leaked from an internal-only runbook is not a key to it.
 */
function kbEmbedFetch(mysqli $mysqli, int $embed_id, int $article_id): ?array
{
    if ($embed_id < 1 || $article_id < 1) {
        return null;
    }

    try {
        $row = mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT kb_article_embed_id,
                    kb_article_embed_kb_article_id,
                    kb_article_embed_name,
                    kb_article_embed_untrusted_html,
                    kb_article_embed_height
             FROM kb_article_embeds
             WHERE kb_article_embed_id = $embed_id
               AND kb_article_embed_kb_article_id = $article_id
             LIMIT 1"
        ));
    } catch (\Throwable $e) {
        return null;
    }

    return $row ?: null;
}

/**
 * Fetch the article id an embed belongs to, WITHOUT authorising anything.
 *
 * Returns 0 for "no such embed" (including when the table does not exist yet -
 * an install where this code is deployed but the migration has not run). Every
 * caller must run its own check on the answer before serving a byte.
 */
function kbEmbedOwningArticleId(mysqli $mysqli, int $embed_id): int
{
    if ($embed_id < 1) {
        return 0;
    }

    try {
        $row = mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT kb_article_embed_kb_article_id
             FROM kb_article_embeds
             WHERE kb_article_embed_id = $embed_id
             LIMIT 1"
        ));
    } catch (\Throwable $e) {
        return 0;
    }

    return $row ? intval($row['kb_article_embed_kb_article_id']) : 0;
}

/**
 * Send the already-authorised embed document, with its own policy.
 *
 * $html is the raw stored bytes, echoed VERBATIM on purpose - see the file
 * header. Nothing below escapes, filters or rewrites it; the containment is
 * entirely in the headers. REFUSING BEATS DEGRADING: if headers are already
 * sent, nothing is echoed at all rather than serving the bytes without the
 * sandbox CSP that is the only thing making them safe.
 */
function kbEmbedServe(string $html, int $height): void
{
    if (strlen($html) > KB_EMBED_MAX_BYTES) {
        kbEmbedFail(413, 'Embed too large');
    }

    $etag = '"' . hash('sha256', $html) . '"';
    $if_none_match = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($if_none_match !== '' && $if_none_match === $etag) {
        if (headers_sent($sent_file, $sent_line)) {
            error_log("kb_embed_serve: refused 304 - output already started at $sent_file:$sent_line");
            exit;
        }
        http_response_code(304);
        header('ETag: ' . $etag);
        header('Cache-Control: private, no-cache');
        header('Pragma: no-cache');
        exit;
    }

    if (headers_sent($sent_file, $sent_line)) {
        error_log("kb_embed_serve: refused to serve - output already started at $sent_file:$sent_line, cannot guarantee the sandbox CSP");
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('X-Ikb-Embed-Height: ' . kbEmbedClampHeight($height));

    /* THE POLICY. sandbox allow-scripts (opaque origin, no allow-same-origin -
       ever); default-src 'none'; script/style 'unsafe-inline' only (NOT 'self' -
       meaningless under an opaque origin, and would read as a permission it does
       not grant); img/font/media-src data: only (a remote URL would be a beacon);
       connect-src 'none' (no fetch/XHR/WebSocket/sendBeacon); form-action 'none';
       base-uri 'none'; frame-ancestors 'self' (replaces X-Frame-Options, which
       must NOT be sent here). */
    header(
        "Content-Security-Policy: "
        . "sandbox allow-scripts; "
        . "default-src 'none'; "
        . "script-src 'unsafe-inline'; "
        . "style-src 'unsafe-inline'; "
        . "img-src data:; "
        . "font-src data:; "
        . "media-src data:; "
        . "connect-src 'none'; "
        . "form-action 'none'; "
        . "base-uri 'none'; "
        . "frame-ancestors 'self'"
    );

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: private, no-cache');
    header('Pragma: no-cache');
    header('ETag: ' . $etag);

    echo $html;

    /* Height reporter, appended AFTER the stored bytes so an unterminated
       document still gets it. postMessage target is '*' because the frame is in
       an opaque origin and cannot read the parent's origin to target it more
       narrowly - all that leaves the frame is one integer. The parent-side
       listener must verify identity with ev.source === iframe.contentWindow
       (ev.origin is the literal string "null" for an opaque-origin document and
       proves nothing), and must clamp the reported value to the same
       MIN/MAX_HEIGHT before writing it to the frame's CSSOM height. */
    echo "\n<script>(function(){"
        . "var last=0;"
        . "function h(){try{var v=Math.max("
        . "document.documentElement.scrollHeight,document.body?document.body.scrollHeight:0);"
        . "if(v>0&&v!==last){last=v;parent.postMessage({ikbEmbedHeight:v},'*');}}catch(e){}}"
        . "addEventListener('load',h);addEventListener('resize',h);"
        . "if(window.ResizeObserver){try{new ResizeObserver(h).observe(document.documentElement);}catch(e){}}"
        . "var n=0,t=setInterval(function(){h();if(++n>=20){clearInterval(t);}},500);"
        . "h();"
        . "})();</script>\n";

    exit;
}
