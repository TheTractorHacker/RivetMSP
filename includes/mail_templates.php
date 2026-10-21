<?php
/*
 * RivetMSP - Email Templates
 *
 * Ported from RivetIT's includes/mail_templates.php (same architecture, same function names) and adapted to RivetMSP's own
 * ticket/mail code. The seven client/technician-facing email events (ticket created, reply, resolved, closed, assigned; client
 * portal password reset and its confirmation) used to be ~17 hardcoded HTML strings spread over agent/post/ticket.php,
 * agent/ajax.php, agent/post/recurring_ticket.php, cron/cron.php, functions.php and client/login_reset.php, with copy/paste drift
 * between copies of the same email. This file is the single place their text now lives (mailTemplateDefaultBlocks(), compiled by
 * mailTemplateCompileBlocks()) and renderMailTemplate() is the single function every one of those call sites uses to turn an
 * event + its data into a subject/body, reading an administrator's edit from the mail_templates table (DB 2.6.107) when there is one.
 *
 * Placeholders are the automation-rule merge fields' syntax, {name}, and are filled by the very same engine rule actions use
 * (RivetCore\Automation\AutomationExecutor::interpolate(): one pass, an unknown placeholder renders as '', never as literal text).
 * Every value is HTML-escaped first, except the small fixed MAIL_TEMPLATE_RAW_KEYS set: HTML this app built itself before the
 * template ever sees it (a ticket's details/reply from the WYSIWYG editor, the CSAT rating links), inserted verbatim.
 *
 * The admin builder (admin/settings_mail_templates.php) stores each body as "<!--RIVETMSP_BLOCKS:{json}-->" + the compiled HTML.
 * The browser compiles with js/mail_template_blocks.js; tests/mail_template_compile.php proves both compilers byte-identical.
 */

require_once __DIR__ . '/theme_accent.php';

const MAIL_TEMPLATE_RAW_KEYS = ['ticket_details', 'ticket_reply', 'csat_rating_html'];

/**
 * Builder-state comment prefix. RIVETMSP_ (not RivetIT's RIVETIT_): it is this edition's own stored-data marker, never shared
 * with a RivetIT database, and matches RivetMSP's other RIVETMSP_* identifiers. js/mail_template_blocks.js uses the same string.
 */
const MAIL_TEMPLATE_BLOCKS_PREFIX = '<!--RIVETMSP_BLOCKS:';
const MAIL_TEMPLATE_BLOCKS_SUFFIX = '-->';

/** Joins compiled blocks. The old hardcoded emails used <br><br> between paragraphs, so the defaults compile back to the same text. */
const MAIL_TEMPLATE_BLOCK_JOIN = '<br><br>';

/** The exact marker the old hardcoded emails led with (minus their stray SQL-era backslashes); addReply()/QuotedTextStripper cut a reply at it. */
const MAIL_TEMPLATE_REPLY_MARKER_HTML = "<i style='color: #808080'>##- Please type your reply above this line -##</i>";

/** The strictest marker form the email parser accepts (functions.php addReply(): no whitespace inside the <i> tag). */
const MAIL_TEMPLATE_REPLY_MARKER_RE = '/<i[^>]*>##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##<\/i>/i';

/** Accent used when Admin > Theme has no known preset and no custom hex (same fallback as RivetIT). */
const MAIL_TEMPLATE_DEFAULT_ACCENT = '#206bc4';

/** Stored subject column width (varchar(500)) and a sanity cap on a stored body. */
const MAIL_TEMPLATE_SUBJECT_MAX = 500;
const MAIL_TEMPLATE_BODY_MAX = 1000000;

/** Event key => human label, for the admin page. Order here is the order they're listed in. */
function mailTemplateEvents(): array {
    return [
        'ticket_created'   => 'Ticket created',
        'ticket_reply'     => 'Ticket reply',
        'ticket_resolved'  => 'Ticket resolved',
        'ticket_closed'    => 'Ticket closed',
        'ticket_assigned'  => 'Ticket assigned (sent to the technician, not the client)',
        'password_reset'   => 'Client portal password reset',
        'password_changed' => 'Client portal password reset confirmation',
    ];
}

/** When each event is sent - shown under its name on the admin page. */
function mailTemplateEventDescriptions(): array {
    return [
        'ticket_created'   => 'To the contact when a technician creates a ticket, a ticket is raised from an inbound email or a recurring schedule, or a ticket is edited/re-assigned to a contact with "notify" ticked.',
        'ticket_reply'     => 'To the contact (and watchers) when a technician posts a public reply, including bulk replies.',
        'ticket_resolved'  => 'To the contact (and watchers) when a ticket is resolved - from the ticket, a resolving reply, bulk resolve or the kanban board.',
        'ticket_closed'    => 'To the contact (and watchers) when a ticket is closed without being resolved first. A "do not reply" notice.',
        'ticket_assigned'  => 'To the technician a ticket is assigned to.',
        'password_reset'   => 'To a client portal user who asked for a password reset link.',
        'password_changed' => 'To a client portal user right after their password was reset.',
    ];
}

/**
 * Events whose body must keep the reply-above-this-line marker. A customer reply to these is parsed into the ticket
 * (cron/ticket_email_parser.php -> QuotedTextStripper::stripReply() -> functions.php addReply(), which cuts the reply at the
 * marker). "Resolved" is included: RivetMSP closes a resolved ticket at once, and addReply() bounces a reply to a closed ticket,
 * but the email invites the customer to re-open it, after which their reply to this same email IS parsed. "Closed" is a
 * "do not reply" notice and "assigned" goes to a technician, so neither carries it.
 */
function mailTemplateRequiresReplyMarker(string $event): bool {
    return in_array($event, ['ticket_created', 'ticket_reply', 'ticket_resolved'], true);
}

/** Events whose body should keep a working {portal_link} (the recipient's only way back into the ticket / the reset flow). */
function mailTemplateRequiresPortalLink(string $event): bool {
    return in_array($event, ['ticket_created', 'ticket_reply', 'ticket_resolved', 'ticket_closed', 'password_reset'], true);
}

/** The placeholders every call site of this event fills, for the admin page's "click to insert" buttons. */
function mailTemplatePlaceholdersFor(string $event): array {
    $ticket = ['contact_name', 'ticket_number', 'subject', 'status', 'portal_link', 'client_portal_link', 'company_name', 'company_phone', 'from_email'];
    return match ($event) {
        'ticket_created' => array_merge($ticket, ['ticket_details']),
        'ticket_reply' => array_merge($ticket, ['ticket_reply']),
        'ticket_resolved' => array_merge($ticket, ['ticket_reply', 'csat_rating_html']),
        'ticket_closed' => ['contact_name', 'ticket_number', 'subject', 'portal_link', 'client_portal_link', 'company_name', 'company_phone', 'from_email', 'csat_rating_html'],
        'ticket_assigned' => ['technician_name', 'ticket_number', 'subject', 'client_name', 'agent_link', 'assigned_by_name', 'company_name', 'app_name'],
        'password_reset' => ['contact_name', 'portal_link', 'company_name', 'company_phone', 'from_email'],
        'password_changed' => ['contact_name', 'company_name', 'company_phone', 'from_email'],
        default => [],
    };
}

// ---------------------------------------------------------------------------------------------------------------------------------
// Blocks: the builder's state. Mirrored exactly, block type for block type, by js/mail_template_blocks.js - keep both in sync
// (tests/mail_template_compile.php runs both compilers on the same blocks and fails on any byte of difference).

/** A block field that must be a string; anything else (a number, an array, null) reads as '' in both compilers. */
function mailTemplateBlockString($v): string {
    return is_string($v) ? $v : '';
}

/**
 * Spacer height in px, clamped to 4..200 (16 when unreadable). Strings are read by their leading ASCII digits only and numbers are
 * clamped before truncation, so PHP's and JavaScript's number/string conversions can never disagree.
 */
function mailTemplateSpacerSize($v): int {
    if (is_int($v) || is_float($v)) {
        return $v >= 200 ? 200 : ($v <= 4 ? 4 : (int) $v);
    }
    if (is_string($v) && preg_match('/^[ \t\n\r]*([0-9]{1,4})/', $v, $m) === 1) {
        return max(4, min(200, (int) $m[1]));
    }
    return 16;
}

/**
 * A real, styled button - table-wrapped (not just a CSS background on the <a>) because Outlook desktop renders mail with Word's
 * engine, which honours a table cell's background far more reliably than one on an anchor. The background is the literal token
 * {mt_accent}, resolved to the company's theme colour at SEND/preview time (mailTemplateRenderWith()), so a later theme change
 * reaches every email, including already-saved ones, with no re-save. $link/$label are the administrator's own text, as in RivetIT.
 */
function mailTemplateButtonHtml(string $link, string $label): string {
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px 0;"><tr><td style="border-radius:6px;background-color:{mt_accent};">'
        . '<a href="' . $link . '" style="display:inline-block;padding:12px 28px;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;border-radius:6px;font-family:inherit;">'
        . $label . '</a></td></tr></table>';
}

/** Opening tag of the quote box, before ({mt_accent}) or after (a real hex) the accent is resolved. */
function mailTemplateQuoteOpen(string $accent = '{mt_accent}'): string {
    return '<div style="background-color:#f8f9fa;border-left:3px solid ' . $accent . ';border-radius:6px;padding:16px 18px;margin:18px 0;color:#374151;font-size:14px;line-height:1.6;">';
}

/** One block's compiled HTML. A block type the builder could not have produced degrades to its 'html' text rather than vanishing. */
function mailTemplateCompileBlock($b): string {
    $b = is_array($b) ? $b : [];
    switch (mailTemplateBlockString($b['type'] ?? null)) {
        case 'reply_marker':
            return MAIL_TEMPLATE_REPLY_MARKER_HTML;
        case 'button':
            $label = mailTemplateBlockString($b['label'] ?? null);
            return mailTemplateButtonHtml(mailTemplateBlockString($b['link'] ?? null), $label !== '' ? $label : 'Click here');
        case 'divider':
            return '<hr style="border:none;border-top:1px solid #e5e7eb;margin:22px 0;">';
        case 'spacer':
            return '<div style="height:' . mailTemplateSpacerSize($b['size'] ?? null) . 'px;line-height:1px;font-size:1px;">&nbsp;</div>';
        case 'quote':
            // A shaded "quoted content" box (a ticket's details, a reply) instead of text fenced in ASCII dashes; the accent edge
            // is {mt_accent}, resolved at render time like a button's colour.
            return mailTemplateQuoteOpen() . mailTemplateBlockString($b['html'] ?? null) . '</div>';
        default: // 'text', 'html', and anything unrecognized
            return mailTemplateBlockString($b['html'] ?? null);
    }
}

/** @param array<int,mixed> $blocks */
function mailTemplateCompileBlocks(array $blocks): string {
    return implode(MAIL_TEMPLATE_BLOCK_JOIN, array_map('mailTemplateCompileBlock', array_values($blocks)));
}

/** Only the fields a block type uses, as the builder would save them (junk keys dropped, spacer size as a clamped number). */
function mailTemplateNormalizeBlock($b): array {
    $b = is_array($b) ? $b : [];
    $type = mailTemplateBlockString($b['type'] ?? null);
    return match ($type) {
        'reply_marker', 'divider' => ['type' => $type],
        'button' => ['type' => $type, 'label' => mailTemplateBlockString($b['label'] ?? null), 'link' => mailTemplateBlockString($b['link'] ?? null)],
        'spacer' => ['type' => $type, 'size' => mailTemplateSpacerSize($b['size'] ?? null)],
        default => ['type' => $type, 'html' => mailTemplateBlockString($b['html'] ?? null)],
    };
}

/**
 * JSON for the builder comment. <, >, & (and U+2028/2029) are \u-escaped so the JSON can never contain "-->" and end the HTML
 * comment early (an "Advanced HTML" block holding an HTML comment would otherwise leak the rest of the JSON into the email);
 * the flags make the output byte-identical to the JS serializer's JSON.stringify + the same escapes.
 */
function mailTemplateBlocksJson(array $blocks): string {
    return (string) json_encode(['v' => 1, 'blocks' => array_map('mailTemplateNormalizeBlock', array_values($blocks))],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** The block list as a leading HTML comment ahead of its own compiled HTML, so re-opening the builder shows the same blocks. */
function mailTemplateSerializeBlocks(array $blocks): string {
    $normalized = array_map('mailTemplateNormalizeBlock', array_values($blocks));
    return MAIL_TEMPLATE_BLOCKS_PREFIX . mailTemplateBlocksJson($normalized) . MAIL_TEMPLATE_BLOCKS_SUFFIX . mailTemplateCompileBlocks($normalized);
}

/**
 * Removes every builder comment from $body. Run before any placeholder substitution (the JSON's own {"..."} braces would otherwise
 * be read as placeholders) and on everything renderMailTemplate() sends, so no real email ever carries it. String search, not a
 * regex: a 1 MB body can never hit a PCRE limit and fall through un-stripped. An unterminated comment swallows the rest of the
 * body (it is builder state, not content).
 */
function mailTemplateStripBlocksComment(string $body): string {
    while (($start = strpos($body, MAIL_TEMPLATE_BLOCKS_PREFIX)) !== false) {
        $end = strpos($body, MAIL_TEMPLATE_BLOCKS_SUFFIX, $start + strlen(MAIL_TEMPLATE_BLOCKS_PREFIX));
        $body = $end === false ? substr($body, 0, $start) : substr($body, 0, $start) . substr($body, $end + strlen(MAIL_TEMPLATE_BLOCKS_SUFFIX));
    }
    return $body;
}

/**
 * The block list in a body's leading builder comment, or null when there is no readable one. Shape is checked on an object decode
 * ({"blocks": [...]} with a real JSON array), exactly as the JS decompiler's JSON.parse + Array.isArray sees it.
 *
 * @return list<mixed>|null
 */
function mailTemplateDecodeBlocksComment(string $body): ?array {
    if (!str_starts_with($body, MAIL_TEMPLATE_BLOCKS_PREFIX)) {
        return null;
    }
    $end = strpos($body, MAIL_TEMPLATE_BLOCKS_SUFFIX, strlen(MAIL_TEMPLATE_BLOCKS_PREFIX));
    if ($end === false) {
        return null;
    }
    $json = substr($body, strlen(MAIL_TEMPLATE_BLOCKS_PREFIX), $end - strlen(MAIL_TEMPLATE_BLOCKS_PREFIX));
    $shape = json_decode($json);
    if (!($shape instanceof \stdClass) || !isset($shape->blocks) || !is_array($shape->blocks)) {
        return null;
    }
    $decoded = json_decode($json, true);

    return array_values($decoded['blocks']);
}

/**
 * The block list behind a stored (or default) body, for the builder. A body without a readable leading comment (a row saved some
 * other way, or a broken comment) becomes one 'html' block holding the body, so nothing is lost or hidden.
 *
 * @return list<mixed>
 */
function mailTemplateBlocksFromBody(string $body): array {
    return mailTemplateDecodeBlocksComment($body) ?? [['type' => 'html', 'html' => mailTemplateStripBlocksComment($body)]];
}

/**
 * A body as it will be stored: one with a readable builder comment is re-serialized from its blocks by THIS compiler, so the stored
 * HTML always matches the stored blocks whatever the browser sent; anything else is kept exactly as given.
 */
function mailTemplateNormalizeBody(string $body): string {
    $blocks = mailTemplateDecodeBlocksComment($body);

    return $blocks === null ? $body : mailTemplateSerializeBlocks($blocks);
}

/**
 * The default block list per event - the single source of truth mailTemplateDefaults() compiles from. Rebuilt from the text
 * RivetMSP's call sites sent before this feature, character for character, from the call site whose copy most others agreed on
 * (tests/mail_templates.php holds those original strings and proves the rendered default reproduces each one). The only deliberate
 * changes: the stray backslashes the old double-quoted PHP strings leaked into every email (style=\'...\', didn\'t) are gone, and the
 * "--------------------------------" fence around a ticket's details/reply is a Quote box. Every link keeps its old wording/place.
 */
function mailTemplateDefaultBlocks(): array {
    $signature = ['type' => 'text', 'html' => '--<br>{company_name} - Support<br>{from_email}<br>{company_phone}'];
    $ticketFacts = ['type' => 'text', 'html' => "Ticket: {ticket_number}<br>Subject: {subject}<br>Status: {status}<br>Portal: <a href='{portal_link}'>View ticket</a>"];

    return [
        // agent/post/ticket.php edit_ticket / edit_ticket_contact (add_ticket's copy differed only in its subject's missing " -")
        'ticket_created' => [
            ['type' => 'reply_marker'],
            ['type' => 'text', 'html' => 'Hello {contact_name},<br><br>A ticket regarding "{subject}" has been created for you.'],
            ['type' => 'quote', 'html' => '{ticket_details}'],
            $ticketFacts,
            $signature,
        ],
        // agent/post/ticket.php add_ticket_reply and bulk_ticket_reply (identical text)
        'ticket_reply' => [
            ['type' => 'reply_marker'],
            ['type' => 'text', 'html' => 'Hello {contact_name},<br><br>Your ticket regarding {subject} has been updated.'],
            ['type' => 'quote', 'html' => '{ticket_reply}'],
            $ticketFacts,
            $signature,
        ],
        // agent/post/ticket.php resolve_ticket and agent/ajax.php kanban (identical), plus add_ticket_reply's resolving-reply copy,
        // which is the same text with the reply quoted (bulk resolve sends it that way): an empty Quote box is dropped at render time,
        // so one template is both. (add_ticket_reply re-reads the status after closing the ticket, so in practice its resolving reply
        // goes out as "Ticket reply" - unchanged from before this feature.)
        'ticket_resolved' => [
            ['type' => 'reply_marker'],
            ['type' => 'text', 'html' => 'Hello {contact_name},<br><br>Your ticket regarding {subject} has been resolved and closed.'],
            ['type' => 'quote', 'html' => '{ticket_reply}'],
            ['type' => 'text', 'html' => "We hope this resolved things to your satisfaction - how would you rate it?<br>{csat_rating_html}<br>If you need further assistance, please reply or <a href='{portal_link}'>re-open</a> to let us know! "],
            $ticketFacts,
            $signature,
        ],
        // agent/post/ticket.php close_ticket. Deliberately NO reply marker: "(do not reply)" is the point of this one.
        'ticket_closed' => [
            ['type' => 'text', 'html' => "Hello {contact_name},<br><br>Your ticket regarding \"{subject}\" has been closed. <br><br> We hope the request/issue was resolved to your satisfaction - how would you rate it?<br>{csat_rating_html}<a href='{portal_link}'>Or click here to leave feedback</a>. <br>If you need further assistance, please raise a new ticket using the below details. Please do not reply to this email. "],
            ['type' => 'text', 'html' => 'Ticket: {ticket_number}<br>Subject: {subject}<br>Portal: {client_portal_link}'],
            $signature,
        ],
        // agent/post/ticket.php assign_ticket. Sent to the technician, so no reply marker and no portal link (an agent link instead).
        'ticket_assigned' => [
            ['type' => 'text', 'html' => 'Hi {technician_name}, <br><br>A ticket has been assigned to you!'],
            ['type' => 'text', 'html' => 'Client: {client_name}<br>Ticket Number: {ticket_number}<br> Subject: {subject}'],
            ['type' => 'text', 'html' => '{agent_link} '],
            ['type' => 'text', 'html' => 'Thanks, <br>{assigned_by_name}<br>{company_name}'],
        ],
        // client/login_reset.php
        'password_reset' => [
            ['type' => 'text', 'html' => "Hello {contact_name},<br><br>Someone (probably you) has requested a new password for your account on {company_name}'s Client Portal."],
            ['type' => 'text', 'html' => "<b>Please <a href='{portal_link}'>click here</a> to reset your password.</b> "],
            ['type' => 'text', 'html' => 'Alternatively, copy and paste this URL into your browser:<br> {portal_link}'],
            ['type' => 'text', 'html' => "<i>If you didn't request this change, you can safely ignore this email.</i>"],
            $signature,
        ],
        'password_changed' => [
            ['type' => 'text', 'html' => "Hello {contact_name},<br><br>Your password for your account on {company_name}'s Client Portal was successfully reset. You should be all set! "],
            ['type' => 'text', 'html' => "<b>If you didn't reset your password, please get in touch ASAP.</b>"],
            $signature,
        ],
    ];
}

/** What every event ships with, and what "Reset to default" restores - compiled from mailTemplateDefaultBlocks(), one source of truth. */
function mailTemplateDefaults(): array {
    $subjects = [
        'ticket_created' => 'Ticket Created - [{ticket_number}] - {subject}',
        'ticket_reply' => 'Ticket update - [{ticket_number}] - {subject}',
        'ticket_resolved' => 'Ticket resolved - [{ticket_number}] - {subject}',
        'ticket_closed' => 'Ticket closed - [{ticket_number}] - {subject} | (do not reply)',
        'ticket_assigned' => '{app_name} - Ticket {ticket_number} assigned to you - {subject}',
        'password_reset' => 'Password reset for {company_name} Client Portal',
        'password_changed' => 'Password reset confirmation for {company_name} Client Portal',
    ];
    $out = [];
    foreach (mailTemplateDefaultBlocks() as $event => $blocks) {
        $out[$event] = ['subject' => $subjects[$event], 'body' => mailTemplateSerializeBlocks($blocks)];
    }

    return $out;
}

/** Sample data for the admin live preview / test send, filling every placeholder mailTemplatePlaceholdersFor() lists. */
function mailTemplateSampleVars(string $event, string $companyName = ''): array {
    $company = $companyName !== '' ? $companyName : 'Acme IT';
    $csat = function_exists('csatEmailRatingLinksHtml') ? csatEmailRatingLinksHtml('support.example.com', 1042, 'sample') : '<a href="#">Great</a> | <a href="#">OK</a> | <a href="#">Poor</a>';
    $ticket = [
        'contact_name' => 'Jamie Example', 'ticket_number' => 'TCK-1042', 'subject' => 'Printer on 3rd floor is offline', 'status' => 'Open',
        'portal_link' => 'https://support.example.com/guest/guest_view_ticket.php?ticket_id=1042&url_key=sample',
        'client_portal_link' => 'https://support.example.com/client/ticket.php?id=1042',
        'company_name' => $company, 'company_phone' => '(555) 555-0100', 'from_email' => 'support@example.com',
        'ticket_details' => 'The printer on the 3rd floor shows a paper jam error, but there is no visible paper stuck.',
        'ticket_reply' => 'I cleared a scrap of paper from the rear tray and ran a test page - it printed fine. Let us know if it happens again!',
        'csat_rating_html' => $csat,
    ];
    return match ($event) {
        'ticket_assigned' => ['technician_name' => 'Sam Technician', 'ticket_number' => 'TCK-1042', 'subject' => $ticket['subject'], 'client_name' => 'Acme Corp',
            'agent_link' => 'https://support.example.com/agent/ticket.php?ticket_id=1042&client_id=7', 'assigned_by_name' => 'Alex Admin', 'company_name' => $company,
            'app_name' => defined('APP_NAME') ? APP_NAME : 'RivetMSP'],
        'password_reset', 'password_changed' => ['contact_name' => $ticket['contact_name'], 'company_name' => $company, 'company_phone' => $ticket['company_phone'],
            'from_email' => $ticket['from_email'], 'portal_link' => 'https://support.example.com/client/login_reset.php?email=jamie%40example.com&token=sample&client=1'],
        default => $ticket,
    };
}

// ---------------------------------------------------------------------------------------------------------------------------------
// Database. Every read degrades to "no customization" instead of failing: the code can be deployed before the DB update has run
// (no mail_templates table / header-footer columns yet), and a ticket must still be created and its email still sent.

/** The stored row for one event, or null when there is none (the caller falls back to mailTemplateDefaults()). */
function mailTemplateRow($mysqli, string $event): ?array {
    try {
        $res = mysqli_query($mysqli, "SELECT * FROM mail_templates WHERE mail_template_event = '" . mysqli_real_escape_string($mysqli, $event) . "' LIMIT 1");
        $row = $res ? mysqli_fetch_assoc($res) : null;
    } catch (\Throwable $e) {
        $row = null;
    }

    return $row ?: null;
}

/** @return array{header:string,footer:string} the optional shared header and footer, the same for every template. */
function mailTemplateHeaderFooter($mysqli): array {
    try {
        $res = mysqli_query($mysqli, "SELECT config_mail_template_header_html, config_mail_template_footer_html FROM settings WHERE company_id = 1");
        $row = $res ? mysqli_fetch_assoc($res) : null;
    } catch (\Throwable $e) {
        $row = null;
    }

    return ['header' => (string) ($row['config_mail_template_header_html'] ?? ''), 'footer' => (string) ($row['config_mail_template_footer_html'] ?? '')];
}

/**
 * The company's own branding, read straight from the database rather than from globals: mail is sent from entry points (cron,
 * the email parser, the public client/login_reset.php) that do not all load includes/load_global_settings.php.
 *
 * @return array{accent:string,logo_url:string,company_name:string} accent '#RRGGBB' (the theme preset / custom hex, else
 *   MAIL_TEMPLATE_DEFAULT_ACCENT); logo_url the uploaded company logo as an ABSOLUTE https URL (a mail client cannot resolve a
 *   relative one) or ''; company_name as stored (not SQL-escaped).
 */
function mailTemplateBranding($mysqli): array {
    try {
        $res = mysqli_query($mysqli, "SELECT settings.config_theme, settings.config_theme_accent_custom, companies.company_logo, companies.company_name
            FROM settings LEFT JOIN companies ON companies.company_id = settings.company_id WHERE settings.company_id = 1 LIMIT 1");
        $row = $res ? mysqli_fetch_assoc($res) : null;
    } catch (\Throwable $e) {
        $row = null;
    }
    $accent = itflow_theme_accent_hex($row['config_theme'] ?? '', $row['config_theme_accent_custom'] ?? null);
    $logo = basename((string) ($row['company_logo'] ?? ''));
    $base = trim((string) ($GLOBALS['config_base_url'] ?? ''), '/');

    return [
        'accent' => $accent !== '' ? $accent : MAIL_TEMPLATE_DEFAULT_ACCENT,
        'logo_url' => ($logo !== '' && $base !== '') ? 'https://' . $base . '/uploads/settings/' . rawurlencode($logo) : '',
        'company_name' => (string) ($row['company_name'] ?? ''),
    ];
}

// ---------------------------------------------------------------------------------------------------------------------------------
// Rendering.

/**
 * Strips the builder comment, then substitutes {placeholder}s with the automation engine. 'html': MAIL_TEMPLATE_RAW_KEYS verbatim,
 * every other value HTML-escaped. 'text' (a subject, i.e. a mail header): values and result with control characters flattened to
 * spaces; the RAW keys (HTML) are not available. Substitution is ONE pass, so a value containing "{x}" (a technician's reply, a
 * customer's name) is never expanded again.
 *
 * @param array<string,mixed> $vars
 */
function mailTemplateApplyPlaceholders(string $template, array $vars, string $mode = 'html'): string {
    $template = mailTemplateStripBlocksComment($template);
    $context = [];
    foreach ($vars as $key => $value) {
        $key = (string) $key;
        $value = is_scalar($value) ? (string) $value : '';
        if ($mode === 'html') {
            $context[$key] = in_array($key, MAIL_TEMPLATE_RAW_KEYS, true) ? $value : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } elseif (!in_array($key, MAIL_TEMPLATE_RAW_KEYS, true)) {
            $context[$key] = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
        }
    }
    $out = (string) (\RivetCore\Automation\AutomationExecutor::interpolate(['body' => $template], $context)['body'] ?? '');

    return $mode === 'html' ? $out : (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $out);
}

/** Drops a Quote box that rendered empty (e.g. "resolved" with no reply text) together with one of the separators around it. */
function mailTemplateCollapseEmptyQuotes(string $html, string $accent): string {
    $re = '/(' . preg_quote(MAIL_TEMPLATE_BLOCK_JOIN, '/') . ')?' . preg_quote(mailTemplateQuoteOpen($accent), '/') . '\s*<\/div>(' . preg_quote(MAIL_TEMPLATE_BLOCK_JOIN, '/') . ')?/';

    return (string) preg_replace_callback($re, static fn (array $m): string => (($m[1] ?? '') !== '' && ($m[2] ?? '') !== '') ? MAIL_TEMPLATE_BLOCK_JOIN : '', $html);
}

/**
 * Wraps a rendered body in a plain, widely compatible transactional-email shell: light outer background, a white card with a thin
 * stripe in the company's accent, a system font and generous padding - table-based (not flexbox / body CSS) so it renders the same
 * across real inboxes, Outlook desktop included. Header: the administrator's shared header HTML, else the company logo (absolute
 * URL), else the company name in bold. Unconditional: every email looks deliberate with no admin action.
 *
 * A body that LEADS with the reply marker keeps it as the very first thing in the email, above the shell: the parser cuts a reply at
 * the marker and drops everything after it, so nothing of the shell (header, company name) can ever end up in a parsed reply.
 */
function mailTemplateWrapShell(string $bodyHtml, string $companyName, string $customHeaderHtml, string $customFooterHtml, string $accentHex, string $logoUrl): string {
    $lead = '';
    if (str_starts_with($bodyHtml, MAIL_TEMPLATE_REPLY_MARKER_HTML)) {
        $lead = MAIL_TEMPLATE_REPLY_MARKER_HTML;
        $bodyHtml = substr($bodyHtml, strlen(MAIL_TEMPLATE_REPLY_MARKER_HTML));
        if (str_starts_with($bodyHtml, MAIL_TEMPLATE_BLOCK_JOIN)) {
            $bodyHtml = substr($bodyHtml, strlen(MAIL_TEMPLATE_BLOCK_JOIN));
        }
    }
    $header = $customHeaderHtml !== '' ? $customHeaderHtml
        : ($logoUrl !== '' ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '" style="max-height:44px;max-width:220px;display:block;margin-bottom:20px;border:0;">'
        : ($companyName !== '' ? '<div style="font-size:19px;font-weight:700;color:#1f2937;margin-bottom:20px;">' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</div>' : ''));
    $accentHex = preg_match('/^#[0-9A-Fa-f]{6}$/D', $accentHex) === 1 ? $accentHex : MAIL_TEMPLATE_DEFAULT_ACCENT;

    return $lead
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f5f7;padding:32px 16px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" style="max-width:580px;background-color:#ffffff;border-radius:10px;" cellpadding="0" cellspacing="0">'
        . '<tr><td style="height:4px;line-height:4px;font-size:0;background-color:' . $accentHex . ';border-radius:10px 10px 0 0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:32px 36px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1f2937;font-size:15px;line-height:1.65;">'
        . $header . $bodyHtml . $customFooterHtml
        . '</td></tr></table>'
        . '</td></tr></table>';
}

/**
 * Subject + body (before the shell) for one subject/body template pair: the accent token resolved, placeholders filled, empty
 * Quote boxes dropped. Shared by renderMailTemplate() and the admin preview/test send.
 *
 * @return array{subject:string,body:string}
 */
function mailTemplateRenderParts(string $subjectTpl, string $bodyTpl, array $vars, string $accent): array {
    $vars['mt_accent'] = $accent;
    $body = mailTemplateCollapseEmptyQuotes(mailTemplateApplyPlaceholders($bodyTpl, $vars, 'html'), $accent);

    return ['subject' => mailTemplateApplyPlaceholders($subjectTpl, $vars, 'text'), 'body' => $body];
}

/**
 * The full email for a subject/body template pair: parts + branding + shell. $appendHtml (trusted, caller-built - the watcher
 * notice) goes inside the card right after the body. renderMailTemplate() and the admin preview/test send both end here, so the
 * preview can never drift from what is really sent.
 *
 * @return array{subject:string,body:string}
 */
function mailTemplateRenderWith($mysqli, string $subjectTpl, string $bodyTpl, array $vars, string $appendHtml = ''): array {
    $branding = mailTemplateBranding($mysqli);
    $parts = mailTemplateRenderParts($subjectTpl, $bodyTpl, $vars, $branding['accent']);
    $hf = mailTemplateHeaderFooter($mysqli);
    $parts['body'] = mailTemplateWrapShell($parts['body'] . $appendHtml, $branding['company_name'], $hf['header'], $hf['footer'], $branding['accent'], $branding['logo_url']);

    return $parts;
}

/**
 * Render one mail event. $vars are plain strings keyed by placeholder name (mailTemplatePlaceholdersFor()); a key the template does
 * not use is ignored and a placeholder with no value renders as ''. $appendHtml: see mailTemplateRenderWith().
 *
 * @return array{subject:string,body:string}|null null when the event is disabled (or unknown) - the caller then queues nothing for it.
 */
function renderMailTemplate($mysqli, string $event, array $vars, string $appendHtml = ''): ?array {
    $defaults = mailTemplateDefaults();
    if (!isset($defaults[$event])) {
        return null;
    }
    $row = mailTemplateRow($mysqli, $event);
    if ($row !== null && (int) $row['mail_template_enabled'] === 0) {
        return null;
    }
    $subjectTpl = (string) ($row['mail_template_subject'] ?? '');
    $bodyTpl = (string) ($row['mail_template_body'] ?? '');

    return mailTemplateRenderWith($mysqli, $subjectTpl !== '' ? $subjectTpl : $defaults[$event]['subject'], $bodyTpl !== '' ? $bodyTpl : $defaults[$event]['body'], $vars, $appendHtml);
}

/**
 * Restore one event's row to mailTemplateDefaults() (enabled), creating it if missing. The row goes back to "never customized"
 * (mail_template_updated_by = 0), so a later default-text update re-syncs it like any untouched row; who reset it is in the log.
 */
function mailTemplateResetToDefault($mysqli, string $event): bool {
    $defaults = mailTemplateDefaults();
    if (!isset($defaults[$event])) {
        return false;
    }
    $e = mysqli_real_escape_string($mysqli, $event);
    $s = mysqli_real_escape_string($mysqli, $defaults[$event]['subject']);
    $b = mysqli_real_escape_string($mysqli, $defaults[$event]['body']);

    return (bool) mysqli_query($mysqli, "INSERT INTO mail_templates (mail_template_event, mail_template_subject, mail_template_body, mail_template_enabled, mail_template_updated_at, mail_template_updated_by)
        VALUES ('$e', '$s', '$b', 1, NOW(), 0)
        ON DUPLICATE KEY UPDATE mail_template_subject = '$s', mail_template_body = '$b', mail_template_enabled = 1, mail_template_updated_at = NOW(), mail_template_updated_by = 0");
}

/**
 * Seeds a row for every event that has none, and brings every row nobody has customized (mail_template_updated_by = 0) up to the
 * current default text. A row an administrator saved is never touched. Called by the DB update that introduced the table (2.6.107);
 * any later change to mailTemplateDefaultBlocks() ships with a DB step that calls it again. Returns the number of rows written.
 */
function mailTemplateSyncDefaults($mysqli): int {
    $written = 0;
    foreach (mailTemplateDefaults() as $event => $tpl) {
        $e = mysqli_real_escape_string($mysqli, $event);
        $s = mysqli_real_escape_string($mysqli, $tpl['subject']);
        $b = mysqli_real_escape_string($mysqli, $tpl['body']);
        mysqli_query($mysqli, "INSERT IGNORE INTO mail_templates (mail_template_event, mail_template_subject, mail_template_body, mail_template_enabled, mail_template_updated_at, mail_template_updated_by)
            VALUES ('$e', '$s', '$b', 1, NOW(), 0)");
        $written += max(0, mysqli_affected_rows($mysqli));
        mysqli_query($mysqli, "UPDATE mail_templates SET mail_template_subject = '$s', mail_template_body = '$b', mail_template_updated_at = NOW()
            WHERE mail_template_event = '$e' AND mail_template_updated_by = 0
            AND (CAST(mail_template_subject AS BINARY) <> CAST('$s' AS BINARY) OR CAST(mail_template_body AS BINARY) <> CAST('$b' AS BINARY))");
        $written += max(0, mysqli_affected_rows($mysqli));
    }

    return $written;
}

// ---------------------------------------------------------------------------------------------------------------------------------
// Save-time checks (the admin page warns; nothing is blocked).

/** Whether $body still carries the reply marker in the exact tag-wrapped form functions.php addReply() cuts at. */
function mailTemplateHasReplyMarker(string $body): bool {
    return preg_match(MAIL_TEMPLATE_REPLY_MARKER_RE, mailTemplateStripBlocksComment($body)) === 1;
}

/** Whether the marker is the first thing in the body. Anything above it would end up inside every parsed customer reply. */
function mailTemplateReplyMarkerLeads(string $body): bool {
    return preg_match('/^\s*' . substr(MAIL_TEMPLATE_REPLY_MARKER_RE, 1, -2) . '/i', mailTemplateStripBlocksComment($body)) === 1;
}

/** Whether $body still contains a {portal_link} placeholder anywhere. */
function mailTemplateHasPortalLink(string $body): bool {
    return str_contains(mailTemplateStripBlocksComment($body), '{portal_link}');
}

/** Human-readable warnings for a body about to be saved for $event ([] when it is fine). */
function mailTemplateSaveWarnings(string $event, string $body): array {
    $w = [];
    if (mailTemplateRequiresReplyMarker($event)) {
        if (!mailTemplateHasReplyMarker($body)) {
            $w[] = 'the reply marker is missing, so a customer reply to this email will not be cut at the quoted original';
        } elseif (!mailTemplateReplyMarkerLeads($body)) {
            $w[] = 'the reply marker is not the first block, so everything above it will be copied into every parsed customer reply';
        }
    }
    if (mailTemplateRequiresPortalLink($event) && !mailTemplateHasPortalLink($body)) {
        $w[] = 'there is no {portal_link}, so the recipient has no way back into the ticket or portal from this email';
    }

    return $w;
}
