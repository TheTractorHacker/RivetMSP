<?php

/**
 * Helpers for the redesigned Webhook guides hub (admin/webhook_guides.php). Everything here is presentation: the facts come from
 * the RivetCore catalog (Destinations / Destination) and the example payload is produced by RivetCore's own PayloadFormatter, so a
 * guide can never disagree with what is really sent. Every value that reaches the HTML goes through webhookH().
 */

require_once __DIR__ . '/webhook_guide.php';

use RivetCore\Webhooks\Destination;
use RivetCore\Webhooks\Destinations;
use RivetCore\Webhooks\PayloadFormatter;
use RivetCore\Webhooks\PayloadTemplate;

/** Language tabs for the signature snippets: key => [label, highlighter language]. */
const WG_SNIPPET_LANGS = [
    'node' => ['Node.js', 'javascript'],
    'python' => ['Python', 'python'],
    'php' => ['PHP', 'php'],
    'bash' => ['Bash', 'bash'],
    'n8n-code' => ['n8n Code node', 'javascript'],
];

/** Category => Font Awesome 5 solid icon. */
function wgCategoryIcon(string $cat): string
{
    return ['automation' => 'fa-cogs', 'chat' => 'fa-comments', 'notify' => 'fa-bell', 'home' => 'fa-home', 'generic' => 'fa-code'][$cat] ?? 'fa-satellite-dish';
}

/**
 * The badge for a platform: a brand glyph where the shipped Font Awesome 5.15 free set has one, a solid glyph for the generic presets,
 * otherwise a coloured letter avatar. @return array{kind:string, class:string, letter:string, color:string}
 */
function wgBadge(Destination $d): array
{
    static $brand = [
        'discord' => ['fab fa-discord', '#5865F2'], 'slack' => ['fab fa-slack', '#C2185B'], 'telegram' => ['fab fa-telegram-plane', '#229ED9'],
        'teams' => ['fab fa-microsoft', '#5059C9'], 'rocketchat' => ['fab fa-rocketchat', '#E5384F'],
        'generic-json' => ['fas fa-code', '#0F766E'], 'generic-form' => ['fas fa-file-alt', '#475569'], 'custom-template' => ['fas fa-edit', '#7C3AED'],
        'home-assistant' => ['fas fa-home', '#0284C7'], 'ntfy' => ['fas fa-mobile-alt', '#0D9488'],
    ];
    if (isset($brand[$d->id])) {
        return ['kind' => 'icon', 'class' => $brand[$d->id][0], 'letter' => '', 'color' => $brand[$d->id][1]];
    }
    $hue = abs(crc32($d->id)) % 360;
    $letter = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $d->name), 0, 1)) ?: '?';

    return ['kind' => 'letter', 'class' => '', 'letter' => $letter, 'color' => 'hsl(' . $hue . ',55%,42%)'];
}

/** Render the badge span (decorative; the name always sits next to it). */
function wgBadgeHtml(Destination $d, string $size = ''): string
{
    $b = wgBadge($d);
    $inner = $b['kind'] === 'icon' ? '<i class="' . webhookH($b['class']) . '" aria-hidden="true"></i>' : webhookH($b['letter']);

    return '<span class="wg-badge' . ($size !== '' ? ' wg-badge-' . webhookH($size) : '') . '" style="--wg-badge:' . webhookH($b['color']) . '" aria-hidden="true">' . $inner . '</span>';
}

/** "Needs: Webhook URL, Topic, ..." derived from the destination data. @return list<string> */
function wgNeeds(Destination $d): array
{
    $needs = [];
    foreach ($d->extraFields as $f) {
        if ($f->required) {
            $needs[] = $f->label;
        }
    }
    if (!$d->extraFields || !array_filter($d->extraFields, static fn ($f) => $f->target === 'url' && $f->required)) {
        array_unshift($needs, 'Webhook URL');
    }
    $needs[] = match ($d->defaultAuth) {
        'bearer' => 'Token',
        'basic' => 'User and password',
        'header' => 'Header name and value',
        default => null,
    };

    return array_values(array_unique(array_filter($needs, static fn ($n) => $n !== null && $n !== '')));
}

/** Auth mode id => human label. */
function wgAuthLabel(string $mode): string
{
    return ['none' => 'None', 'bearer' => 'Bearer token', 'basic' => 'Basic (user and password)', 'header' => 'Custom header', 'hmac' => 'Signature only (HMAC)'][$mode] ?? $mode;
}

/**
 * Escape a sentence and wrap the technical tokens in it (URLs, paths, $json.x expressions, ENV=values, <placeholders>) in <code>.
 * Escaping happens per piece, so nothing from the catalog reaches the page unescaped.
 */
function wgInline(string $text): string
{
    $parts = preg_split('~(https?://[^\s"\']*[^\s"\'.,;:)]|\$[A-Za-z_][\w.]*|\b[A-Z][A-Z0-9_]{3,}=[^\s,;]*[^\s.,;]|<[a-z_][\w-]*>|(?<![\w/])/[A-Za-z0-9_\-][A-Za-z0-9_.\-]*(?:/[A-Za-z0-9_.\-]*)*(?<!\.)(?=[.,;:)]*(?:\s|$)))~', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts as $i => $p) {
        $out .= $i % 2 === 1 ? '<code class="wg-inline">' . webhookH($p) . '</code>' : webhookH($p);
    }

    return $out;
}

/** Classify a pitfall as danger, warning or info (for the callout style). */
function wgNoteLevel(string $note): string
{
    if (preg_match('/\b(is the secret|only secret|is a secret|anyone who|never shown|leak)/i', $note)) {
        return 'danger';
    }
    if (preg_match('/\b(limit|limited|rate-limit|only|must|cannot|can\'t|not |blocked|rejected|refuse|private addresses|fail|drop|truncate|local_only|disabled)/i', $note)) {
        return 'warning';
    }

    return 'info';
}

/** @return array{danger:string,warning:string,info:string} callout level => icon */
function wgCalloutIcons(): array
{
    return ['danger' => 'fa-exclamation-circle', 'warning' => 'fa-exclamation-triangle', 'info' => 'fa-info-circle'];
}

/**
 * The real body RivetCore would send for a sample ticket.created event, formatted for this destination (the same call the
 * sample curl is built from). No secrets: only the catalog's sample data and the placeholder examples.
 *
 * @return array{content_type:string, headers:array<string,string>, body:string, lang:string}|null
 */
function wgSamplePayload(Destination $d): ?array
{
    try {
        $options = $d->formatOptions;
        foreach ($d->extraFields as $f) {
            if ($f->target === 'option' && $f->option !== '' && $f->example !== '' && $f->required) {
                $options[$f->option] = $f->example;
            }
        }
        $sample = PayloadTemplate::sampleContext('ticket.created');
        $p = PayloadFormatter::format($d->format, ['event' => 'ticket.created', 'timestamp' => $sample['timestamp'], 'data' => $sample['data']], $options + ['app_name' => defined('APP_NAME') ? APP_NAME : 'RivetMSP', 'link_url' => 'https://helpdesk.example.com/ticket/1042']);
    } catch (\Throwable $e) {
        return null;
    }
    $body = $p->body;
    $lang = 'text';
    if (stripos($p->contentType, 'json') !== false) {
        $decoded = json_decode($body, true);
        if ($decoded !== null) {
            $body = (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $lang = 'json';
    }

    return ['content_type' => $p->contentType, 'headers' => $d->headers + $p->headers, 'body' => $body, 'lang' => $lang];
}

/**
 * Troubleshooting rows: generic ones first, then the platform's own pitfalls.
 *
 * @return list<array{0:string,1:string,2:string,3:bool}> symptom, likely cause, fix, platform-specific
 */
function wgTroubleshooting(Destination $d): array
{
    $rows = [
        ['401 or 403 from the receiver', 'The receiver wants credentials and ours are missing, wrong or expired.', 'Edit the webhook and check Authentication: the header name and value (or token) must match the receiver exactly. Re-copy the secret, avoid trailing spaces.', false],
        ['404 Not Found', 'The address is wrong, was deleted, or points at a test URL or an inactive workflow.', 'Copy the production URL again and paste it into the webhook. For n8n use the /webhook/ URL and make sure the workflow is Active.', false],
        ['429 Too Many Requests', 'The receiver is rate limiting. A burst of events arrived faster than it accepts.', 'Narrow the events to what you need, or let the retries catch up. Failed deliveries are retried automatically with a growing delay.', false],
        ['Timeouts or connection errors', 'The receiver is slow, down, or unreachable from this server (DNS, firewall, TLS).', 'Answer with a 2xx straight away and do the work afterwards. Check the address resolves from this server and that its certificate is valid.', false],
        ['"endpoint URL not allowed" in the delivery log', 'The address is private or internal and its network is not on the allow-list. Loopback, link-local and cloud-metadata addresses are never allowed.', 'Add the receiver\'s network under Internal network access (Administration > Webhooks), then send a test.', false],
        ['Signature mismatch on the receiver', 'The signature was computed over parsed or re-serialised JSON instead of the raw bytes, the secret differs, or a proxy rewrote the body.', 'Verify against the raw request body, use the same signing secret as the webhook, and compare with a constant-time function.', false],
        ['Signature rejected as too old', 'The receiver\'s clock differs from ours by more than the tolerance window (5 minutes).', 'Sync both servers with NTP. Keep replay protection on; only widen the window if you must.', false],
        ['Nothing arrives and no error is shown', 'The webhook is disabled, or none of its events happened yet.', 'Check the webhook is enabled and its events match, then use Send test and read the delivery log.', false],
    ];
    foreach ($d->notes as $note) {
        $first = trim((string) preg_split('/(?<=[.:;])\s/', $note, 2)[0]);
        if (mb_strlen($first) > 90) {
            $first = rtrim(mb_substr($first, 0, 87)) . '...';
        }
        $rows[] = [$first, $note, '', true];
    }

    return $rows;
}

/** The checklist of what to have ready before starting. @return list<string> */
function wgChecklist(Destination $d): array
{
    $items = ['Access to ' . $d->name . ' (an account or your own instance) with permission to add an endpoint or integration'];
    foreach ($d->extraFields as $f) {
        $items[] = $f->label . ($f->required ? '' : ' (optional)') . ($f->help !== '' ? ': ' . $f->help : '');
    }
    if (!$d->extraFields || !array_filter($d->extraFields, static fn ($f) => $f->target === 'url' && $f->required)) {
        $items[] = 'The address to post to, in the shape ' . $d->urlHint;
    }
    if ($d->defaultAuth !== 'none') {
        $items[] = 'Credentials for the ' . wgAuthLabel($d->defaultAuth) . ' option, if you turn authentication on';
    }
    if (in_array('hmac', $d->authModes, true)) {
        $items[] = 'Optional: a signing secret (the form can generate one) to verify our requests';
    }
    $items[] = 'If it runs on your own network: its range added under Internal network access';

    return $items;
}

/** Flat ordered list of destinations (category order). @return list<Destination> */
function wgOrdered(): array
{
    $out = [];
    foreach (Destinations::byCategory() as $items) {
        foreach ($items as $d) {
            $out[] = $d;
        }
    }

    return $out;
}

/** A code block with header (language label), copy button and wrap toggle. The text is highlighted client-side. */
function wgCode(string $id, string $code, string $lang, string $label, string $copyLabel = 'Copy'): void
{
    ?>
    <div class="wg-code" data-wg-code>
        <div class="wg-code-head">
            <span class="wg-code-lang"><?= webhookH($label) ?></span>
            <span class="wg-code-actions">
                <button type="button" class="wg-btn-sm" data-wg-wrap aria-pressed="false" title="Toggle line wrapping"><i class="fas fa-fw fa-align-left" aria-hidden="true"></i><span>Wrap</span></button>
                <button type="button" class="wg-btn-sm" data-wg-copy="#<?= webhookH($id) ?>" aria-label="<?= webhookH($copyLabel) ?>"><i class="fas fa-fw fa-copy" aria-hidden="true"></i><span>Copy</span></button>
            </span>
        </div>
        <pre class="wg-pre" tabindex="0"><code id="<?= webhookH($id) ?>" data-lang="<?= webhookH($lang) ?>"><?= webhookH($code) ?></code></pre>
    </div>
    <?php
}

/** Language tabs (arrow keys, roving tabindex) around WG_SNIPPET_LANGS snippets. */
function wgSnippetTabs(array $snippets, string $prefix, string $aria = 'Signature verification language'): void
{
    $keys = array_values(array_filter(array_keys(WG_SNIPPET_LANGS), static fn ($k) => isset($snippets[$k])));
    if (!$keys) {
        return;
    }
    ?>
    <div class="wg-tabs" data-wg-tabs>
        <div class="wg-tablist" role="tablist" aria-label="<?= webhookH($aria) ?>">
            <?php foreach ($keys as $i => $k) { $tid = $prefix . '-tab-' . $k; ?>
                <button type="button" role="tab" class="wg-tab<?= $i === 0 ? ' is-active' : '' ?>" id="<?= webhookH($tid) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" aria-controls="<?= webhookH($tid) ?>-panel" tabindex="<?= $i === 0 ? '0' : '-1' ?>" data-wg-lang="<?= webhookH($k) ?>"><?= webhookH(WG_SNIPPET_LANGS[$k][0]) ?></button>
            <?php } ?>
        </div>
        <?php foreach ($keys as $i => $k) { $tid = $prefix . '-tab-' . $k; ?>
            <div role="tabpanel" class="wg-tabpanel" id="<?= webhookH($tid) ?>-panel" aria-labelledby="<?= webhookH($tid) ?>" tabindex="0" <?= $i === 0 ? '' : 'hidden' ?>>
                <?php wgCode($prefix . '-code-' . $k, $snippets[$k], WG_SNIPPET_LANGS[$k][1], WG_SNIPPET_LANGS[$k][0], 'Copy the ' . WG_SNIPPET_LANGS[$k][0] . ' verification snippet'); ?>
            </div>
        <?php } ?>
    </div>
    <?php
}
