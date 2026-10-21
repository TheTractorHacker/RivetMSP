/*
 * Email Templates block compiler - the browser half of includes/mail_templates.php (mailTemplateCompileBlock(),
 * mailTemplateCompileBlocks(), mailTemplateNormalizeBlock(), mailTemplateSerializeBlocks(), mailTemplateBlocksFromBody()).
 * Both must produce byte-identical output for every block: tests/mail_template_compile.php loads this file in node and the PHP
 * functions in php on the same block data and fails on any difference. Change one, change the other.
 *
 * Loaded by admin/settings_mail_templates.php (window.MailTemplateBlocks) and by node (module.exports).
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.MailTemplateBlocks = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var PREFIX = '<!--RIVETMSP_BLOCKS:';
    var SUFFIX = '-->';
    var JOIN = '<br><br>';
    var REPLY_MARKER = "<i style='color: #808080'>##- Please type your reply above this line -##</i>";

    // A field that must be a string; anything else reads as '' (PHP: mailTemplateBlockString()).
    function str(v) { return typeof v === 'string' ? v : ''; }

    // PHP: mailTemplateSpacerSize() - numbers clamped before truncation, strings by their leading ASCII digits, else 16.
    function spacerSize(v) {
        if (typeof v === 'number' && isFinite(v)) {
            return v >= 200 ? 200 : (v <= 4 ? 4 : Math.trunc(v));
        }
        if (typeof v === 'string') {
            var m = /^[ \t\n\r]*([0-9]{1,4})/.exec(v);
            if (m) { return Math.max(4, Math.min(200, parseInt(m[1], 10))); }
        }
        return 16;
    }

    function isObj(b) { return b !== null && typeof b === 'object' && !Array.isArray(b); }

    function buttonHtml(link, label) {
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px 0;"><tr><td style="border-radius:6px;background-color:{mt_accent};">'
            + '<a href="' + link + '" style="display:inline-block;padding:12px 28px;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;border-radius:6px;font-family:inherit;">'
            + label + '</a></td></tr></table>';
    }

    function quoteOpen() {
        return '<div style="background-color:#f8f9fa;border-left:3px solid {mt_accent};border-radius:6px;padding:16px 18px;margin:18px 0;color:#374151;font-size:14px;line-height:1.6;">';
    }

    function compileOne(b) {
        b = isObj(b) ? b : {};
        switch (str(b.type)) {
            case 'reply_marker':
                return REPLY_MARKER;
            case 'button':
                return buttonHtml(str(b.link), str(b.label) !== '' ? str(b.label) : 'Click here');
            case 'divider':
                return '<hr style="border:none;border-top:1px solid #e5e7eb;margin:22px 0;">';
            case 'spacer':
                return '<div style="height:' + spacerSize(b.size) + 'px;line-height:1px;font-size:1px;">&nbsp;</div>';
            case 'quote':
                return quoteOpen() + str(b.html) + '</div>';
            default: // 'text', 'html', and anything unrecognized
                return str(b.html);
        }
    }

    function compileHtml(blocks) { return (Array.isArray(blocks) ? blocks : []).map(compileOne).join(JOIN); }

    function normalize(b) {
        b = isObj(b) ? b : {};
        var type = str(b.type);
        if (type === 'reply_marker' || type === 'divider') { return { type: type }; }
        if (type === 'button') { return { type: type, label: str(b.label), link: str(b.link) }; }
        if (type === 'spacer') { return { type: type, size: spacerSize(b.size) }; }
        return { type: type, html: str(b.html) };
    }

    // JSON.stringify plus PHP's JSON_HEX_TAG / JSON_HEX_AMP and its default U+2028/2029 escapes: the JSON can never hold "-->".
    var ESC = { '<': '\\u003C', '>': '\\u003E', '&': '\\u0026', '\u2028': '\\u2028', '\u2029': '\\u2029' };
    function blocksJson(blocks) {
        return JSON.stringify({ v: 1, blocks: blocks.map(normalize) }).replace(/[<>&\u2028\u2029]/g, function (c) { return ESC[c]; });
    }

    function serialize(blocks) {
        var normalized = (Array.isArray(blocks) ? blocks : []).map(normalize);
        return PREFIX + blocksJson(normalized) + SUFFIX + compileHtml(normalized);
    }

    // PHP: mailTemplateStripBlocksComment() - every builder comment, string search (no regex).
    function stripComment(body) {
        body = String(body || '');
        var start;
        while ((start = body.indexOf(PREFIX)) !== -1) {
            var end = body.indexOf(SUFFIX, start + PREFIX.length);
            body = end === -1 ? body.slice(0, start) : body.slice(0, start) + body.slice(end + SUFFIX.length);
        }
        return body;
    }

    // PHP: mailTemplateBlocksFromBody() - at least one block; an unreadable body becomes one 'html' block.
    function decompile(raw) {
        raw = String(raw || '');
        if (raw.indexOf(PREFIX) === 0) {
            var end = raw.indexOf(SUFFIX, PREFIX.length);
            if (end !== -1) {
                try {
                    var parsed = JSON.parse(raw.slice(PREFIX.length, end));
                    if (isObj(parsed) && Array.isArray(parsed.blocks)) { return parsed.blocks; }
                } catch (err) { /* fall through to the single html block */ }
            }
        }
        return [{ type: 'html', html: stripComment(raw) }];
    }

    // PHP: MAIL_TEMPLATE_REPLY_MARKER_RE / mailTemplateSaveWarnings() - the strict form functions.php addReply() cuts at.
    var MARKER_RE = /<i[^>]*>##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##<\/i>/i;
    var MARKER_LEAD_RE = /^\s*<i[^>]*>##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##<\/i>/i;
    function warnings(body, needsMarker, needsLink) {
        var html = stripComment(body);
        var out = [];
        if (needsMarker) {
            if (!MARKER_RE.test(html)) {
                out.push('the reply marker is missing, so a customer reply to this email will not be cut at the quoted original');
            } else if (!MARKER_LEAD_RE.test(html)) {
                out.push('the reply marker is not the first block, so everything above it will be copied into every parsed customer reply');
            }
        }
        if (needsLink && html.indexOf('{portal_link}') === -1) {
            out.push('there is no {portal_link}, so the recipient has no way back into the ticket or portal from this email');
        }
        return out;
    }

    return {
        PREFIX: PREFIX, SUFFIX: SUFFIX, JOIN: JOIN, REPLY_MARKER: REPLY_MARKER,
        compileOne: compileOne, compileHtml: compileHtml, normalize: normalize, blocksJson: blocksJson,
        serialize: serialize, stripComment: stripComment, decompile: decompile, warnings: warnings
    };
}));
