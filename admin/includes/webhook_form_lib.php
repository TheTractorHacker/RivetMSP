<?php

/**
 * Shared server-side logic of Administration > Webhooks: turns what the guided form posts into a validated row (add, edit, "Send
 * test" and "Preview payload" all use the same function, so a test can never differ from what would be saved), builds the subscription
 * the dispatcher delivers, and renders the previews. Validation is RivetCore's: Destination::urlMatches, Authentication::validate,
 * PayloadTemplate::validate, and the one shared URL policy (rivetWebhookUrlPolicy()).
 */

require_once __DIR__ . '/../../includes/event_bus.php';
require_once __DIR__ . '/webhook_events.php';

use RivetCore\Webhooks\Authentication;
use RivetCore\Webhooks\Destination;
use RivetCore\Webhooks\Destinations;
use RivetCore\Webhooks\EventCatalog;
use RivetCore\Webhooks\PayloadFormatter;
use RivetCore\Webhooks\PayloadTemplate;
use RivetCore\Webhooks\WebhookDispatcher;
use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionLookupInterface;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

/** One in-memory endpoint: lets "Send test" run an unsaved form through the real dispatcher. */
final class RivetInlineWebhookSubscription implements WebhookSubscriptionsInterface, WebhookSubscriptionLookupInterface
{
    public function __construct(private WebhookSubscription $subscription)
    {
    }

    public function forEvent(string $eventType): array
    {
        return [];
    }

    public function find(int $webhookId): ?WebhookSubscription
    {
        return $webhookId === $this->subscription->webhookId ? $this->subscription : null;
    }
}

function rivetWebhookSubsClass(): string
{
    return rivetCoreAdapterNs() . '\\Webhooks\\WebhooksTableSubscriptions';
}

/** Icon for a destination card (Font Awesome free). */
function rivetWebhookIcon(string $destinationId, string $category = 'generic'): string
{
    static $map = [
        'n8n' => 'fa-project-diagram', 'node-red' => 'fa-sitemap', 'activepieces' => 'fa-puzzle-piece', 'windmill' => 'fa-wind',
        'huginn' => 'fa-user-secret', 'home-assistant' => 'fa-home', 'apprise' => 'fa-bell', 'ntfy' => 'fa-mobile-alt',
        'gotify' => 'fa-bullhorn', 'discord' => 'fa-comments', 'mattermost' => 'fa-comment-dots', 'rocketchat' => 'fa-rocket',
        'slack' => 'fa-hashtag', 'teams' => 'fa-users', 'matrix-hookshot' => 'fa-border-all', 'matrix-client' => 'fa-border-all',
        'telegram' => 'fa-paper-plane', 'zapier' => 'fa-bolt', 'make' => 'fa-cogs', 'pipedream' => 'fa-stream', 'ifttt' => 'fa-random',
        'generic-json' => 'fa-code', 'generic-form' => 'fa-file-alt', 'custom-template' => 'fa-edit',
    ];

    return $map[$destinationId] ?? ['automation' => 'fa-cogs', 'chat' => 'fa-comments', 'notify' => 'fa-bell', 'home' => 'fa-home'][$category] ?? 'fa-satellite-dish';
}

/** Does a stored/posted event token have a valid shape and refer to something this install knows? */
function rivetWebhookEventTokenOk(string $token, array $known): bool
{
    if (!preg_match('/^[a-z0-9_.*-]{1,150}$/', $token)) {
        return false;
    }
    if (in_array($token, $known, true) || EventCatalog::has($token)) {
        return true;
    }

    return EventCatalog::isPattern($token) && EventCatalog::matchPattern($token) !== [];
}

/**
 * Validate the posted events (ids and patterns). @return array{0:list<string>,1:list<string>} [tokens, errors]
 */
function rivetWebhookCleanEvents($raw): array
{
    $known = all_webhook_event_types();
    $tokens = [];
    $errors = [];
    foreach ((array) $raw as $t) {
        $t = is_string($t) ? trim($t) : '';
        if ($t === '') {
            continue;
        }
        if (!rivetWebhookEventTokenOk($t, $known)) {
            $errors[] = 'Unknown event or pattern: ' . $t;
            continue;
        }
        $tokens[$t] = true;
    }
    $tokens = array_keys($tokens);
    if (in_array('*', $tokens, true)) {
        $tokens = ['*'];
    }
    if (!$tokens && !$errors) {
        $errors[] = 'Choose at least one event.';
    }
    if (strlen(implode(',', $tokens)) > 2000) {
        $errors[] = 'Too many events selected to store; select whole groups (they are saved as patterns such as ticket.*).';
    }

    return [$tokens, $errors];
}

/** Values of a posted/stored extra-field map, limited to the destination's fields. @return array<string,string> */
function rivetWebhookPostedFields(Destination $dest, array $in): array
{
    $posted = is_array($in['webhook_field'] ?? null) ? $in['webhook_field'] : [];
    $out = [];
    foreach ($dest->extraFields as $f) {
        $v = $posted[$f->name] ?? '';
        $out[$f->name] = is_string($v) ? trim($v) : '';
    }

    return $out;
}

/**
 * Validate everything the form posts. $existing is the stored row when editing (blank secrets then keep their stored value).
 *
 * @param array<string,mixed> $in the posted fields
 * @param array<string,mixed>|null $existing
 * @return array{errors:list<string>, row:array<string,mixed>, destination:?Destination, subscription:?WebhookSubscription, auth_redacted:array<string,mixed>, events:list<string>}
 */
function rivetWebhookCollect($mysqli, array $in, ?array $existing = null): array
{
    $errors = [];
    $destId = trim((string) ($in['webhook_destination'] ?? ''));
    $dest = $destId !== '' ? Destinations::get($destId) : null;
    if ($destId !== '' && $dest === null) {
        $errors[] = 'Unknown platform.';
    }
    $blank = ['errors' => [], 'row' => [], 'destination' => $dest, 'subscription' => null, 'auth_redacted' => [], 'events' => []];

    $name = trim((string) ($in['webhook_name'] ?? ''));
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
    if ($name === '' || mb_strlen($name) > 200) {
        $errors[] = 'A name (up to 200 characters) is required.';
    }
    $enabled = isset($in['webhook_enabled']) ? 1 : 0;
    [$events, $eventErrors] = rivetWebhookCleanEvents($in['webhook_events'] ?? []);
    $errors = array_merge($errors, $eventErrors);

    $existingSecret = $existing ? decryptSetting((string) $existing['webhook_secret']) : '';
    $secret = (string) ($in['webhook_secret'] ?? '');
    if (preg_match('/[\x00-\x1F\x7F]/', $secret)) {
        $errors[] = 'The signing secret must not contain line breaks or control characters.';
    }
    $secretFinal = $secret !== '' ? $secret : $existingSecret;
    $blank['events'] = $events;

    // ---------- legacy post (no platform): the old behaviour, a plain JSON-envelope endpoint
    if ($destId === '') {
        $url = filter_var(trim((string) ($in['webhook_url'] ?? '')), FILTER_SANITIZE_URL);
        if ($url === '' && $existing) {
            $url = decryptSetting((string) $existing['webhook_url']);
        }
        if ($url === '') {
            $errors[] = 'An endpoint URL is required.';
        } elseif (!rivetWebhookUrlPolicy($mysqli)->isSafe($url)) {
            $errors[] = 'Endpoint URL rejected (' . rivetWebhookRuleText($mysqli) . '). Loopback, link-local and cloud-metadata addresses are never allowed.';
        }
        $row = [
            'webhook_name' => $name, 'webhook_url' => $url, 'webhook_secret' => rivetWebhookSeal($secretFinal, $existing, 'webhook_secret'), 'webhook_events' => implode(',', $events),
            'webhook_enabled' => $enabled, 'webhook_destination' => '', 'webhook_format' => '', 'webhook_method' => 'POST',
            'webhook_template' => null, 'webhook_auth_mode' => 'none', 'webhook_auth_enc' => null, 'webhook_extra' => null,
        ];

        return ['errors' => $errors, 'row' => $row, 'destination' => null, 'subscription' => $errors ? null : rivetWebhookSubsFromRow($row, (int) ($existing['webhook_id'] ?? 0)), 'auth_redacted' => ['mode' => 'none'], 'events' => $events];
    }
    if ($dest === null) {
        return ['errors' => $errors] + $blank;
    }

    // ---------- platform fields
    $fields = rivetWebhookPostedFields($dest, $in);
    $fieldErrors = [];
    foreach ($dest->extraFields as $f) {
        $v = $fields[$f->name];
        if (preg_match('/[\x00-\x1F\x7F]/', $v) || mb_strlen($v) > 300) {
            $fieldErrors[] = $f->label . ' must be a single line of up to 300 characters.';
        } elseif ($f->type === 'number' && $v !== '' && !preg_match('/^[1-5]$/', $v)) {
            $fieldErrors[] = $f->label . ' must be a number from 1 to 5.';
        } elseif ($f->target === 'option' && $f->required && $v === '') {
            $fieldErrors[] = $f->label . ' is required.';
        }
    }
    $errors = array_merge($errors, $fieldErrors);

    // ---------- URL (blank on edit keeps the stored one; secrets are never echoed back into the form)
    $submittedUrl = trim((string) ($in['webhook_url'] ?? ''));
    $storedUrl = $existing ? decryptSetting((string) $existing['webhook_url']) : '';
    $urlChanged = $submittedUrl !== '';
    if (!$urlChanged && $existing) {
        $url = $storedUrl;
    } else {
        $url = $submittedUrl;
        foreach ($dest->extraFields as $f) {
            if ($f->target === 'url' && $fields[$f->name] !== '') {
                $url = str_replace('{' . $f->name . '}', str_replace('%3A', ':', rawurlencode($fields[$f->name])), $url);
            }
        }
        if ($url === '') {
            $errors[] = 'The endpoint URL is required.';
        } elseif (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F"<>\\\\]/', $url)) {
            $errors[] = 'The endpoint URL must be a single address of up to 2048 characters without spaces or control characters.';
        } else {
            $unfilled = [];
            if (preg_match_all('/\{([a-z_]+)\}/', $url, $m)) {
                foreach ($m[1] as $ph) {
                    if ($ph !== 'txn') {
                        $unfilled[$ph] = true;
                    }
                }
            }
            if ($unfilled) {
                $labels = [];
                foreach ($dest->extraFields as $f) {
                    if (isset($unfilled[$f->name])) {
                        $labels[] = $f->label;
                        unset($unfilled[$f->name]);
                    }
                }
                $errors[] = 'Fill in ' . implode(', ', array_merge($labels, array_keys($unfilled))) . ' (the URL still contains a {placeholder}).';
            } elseif (!$dest->urlMatches($url)) {
                $errors[] = 'The URL does not look like a ' . $dest->name . ' address. Expected something like ' . $dest->urlHint . '.';
            } elseif (!rivetWebhookUrlPolicy($mysqli)->isSafe(str_replace('{txn}', 'x', $url))) {
                $errors[] = 'Endpoint URL rejected (' . rivetWebhookRuleText($mysqli) . '). Loopback, link-local and cloud-metadata addresses are never allowed.';
            }
        }
    }

    // ---------- method
    $method = strtoupper(trim((string) ($in['webhook_method'] ?? '')));
    $allowsChoice = $dest->id === 'custom-template';
    if ($dest->method === 'PUT') {
        $method = 'PUT';
    } elseif ($method === '' || $method === $dest->method) {
        $method = $dest->method;
    } elseif ($allowsChoice && $method === 'PUT') {
        $method = 'PUT';
    } else {
        $errors[] = $dest->name . ' only accepts ' . $dest->method . ' requests.';
        $method = $dest->method;
    }

    // ---------- payload format / template
    $format = $dest->format;
    $template = null;
    $templateEnc = null;
    if ($dest->id === 'custom-template') {
        $template = (string) ($in['webhook_template'] ?? '');
        $templateEnc = (string) ($in['webhook_template_encoding'] ?? 'json');
        if (!in_array($templateEnc, PayloadTemplate::ENCODINGS, true)) {
            $errors[] = 'Choose a body encoding: json, text or form.';
        } elseif (strlen($template) > 8192) {
            $errors[] = 'The template is longer than 8 KB.';
        } else {
            foreach (PayloadTemplate::validate($template, $templateEnc) as $e) {
                $errors[] = 'Template: ' . $e;
            }
        }
    }

    // ---------- authentication towards the receiver
    $mode = (string) ($in['webhook_auth_mode'] ?? '');
    if ($mode === '') {
        $mode = $existing && $existing['webhook_auth_mode'] !== '' ? (string) $existing['webhook_auth_mode'] : $dest->defaultAuth;
    }
    $oldAuth = $existing ? (json_decode(decryptSetting((string) ($existing['webhook_auth_enc'] ?? '')), true) ?: []) : [];
    $oldMode = $existing ? (string) $existing['webhook_auth_mode'] : '';
    $authCfg = ['mode' => $mode];
    if (!in_array($mode, Authentication::MODES, true) || !in_array($mode, $dest->authModes, true)) {
        $errors[] = 'Authentication mode "' . $mode . '" is not available for ' . $dest->name . ' (allowed: ' . implode(', ', $dest->authModes) . ').';
        $authCfg = ['mode' => 'none'];
        $mode = 'none';
    } else {
        $keep = static fn (string $field): string => ($oldMode === $mode && isset($oldAuth[$field]) && is_string($oldAuth[$field])) ? $oldAuth[$field] : '';
        foreach (['token' => 'auth_token', 'username' => 'auth_username', 'password' => 'auth_password', 'header_name' => 'auth_header_name', 'header_value' => 'auth_header_value'] as $cfgKey => $postKey) {
            $v = isset($in[$postKey]) && is_string($in[$postKey]) ? $in[$postKey] : '';
            if (in_array($cfgKey, ['username', 'header_name'], true)) {
                $v = trim($v);
            }
            $authCfg[$cfgKey] = $v !== '' ? $v : $keep($cfgKey);
        }
        if ($mode === 'header' && $authCfg['header_name'] === '' && $dest->defaultAuthHeader) {
            $authCfg['header_name'] = $dest->defaultAuthHeader;
        }
        // Only the fields the chosen mode uses are kept.
        $use = ['bearer' => ['token'], 'basic' => ['username', 'password'], 'header' => ['header_name', 'header_value']][$mode] ?? [];
        foreach (['token', 'username', 'password', 'header_name', 'header_value'] as $k) {
            if (!in_array($k, $use, true)) {
                unset($authCfg[$k]);
            }
        }
        foreach (Authentication::validate($authCfg) as $e) {
            $errors[] = 'Authentication: ' . $e;
        }
    }
    if ($mode === 'hmac' && $secretFinal === '') {
        $errors[] = 'A signing secret is required when the receiver verifies our signature (use Generate).';
    }

    $extra = ['fields' => [], 'template_encoding' => $templateEnc];
    foreach ($dest->extraFields as $f) {
        if ($f->target === 'option' && $fields[$f->name] !== '') {
            $extra['fields'][$f->name] = $fields[$f->name];
        }
    }
    $extra = array_filter($extra, static fn ($v) => $v !== null && $v !== []);

    $authPlain = array_diff_key($authCfg, ['mode' => 1]);
    $row = [
        'webhook_name' => $name,
        'webhook_url' => rivetWebhookSeal($url, $existing, 'webhook_url'),
        'webhook_secret' => rivetWebhookSeal($secretFinal, $existing, 'webhook_secret'),
        'webhook_events' => implode(',', $events),
        'webhook_enabled' => $enabled,
        'webhook_destination' => $dest->id,
        'webhook_format' => $format,
        'webhook_method' => $method,
        'webhook_template' => $template,
        'webhook_auth_mode' => $mode,
        'webhook_auth_enc' => $authPlain ? rivetWebhookSeal((string) json_encode($authPlain, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $existing, 'webhook_auth_enc') : null,
        'webhook_extra' => $extra ? (string) json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
    ];

    return [
        'errors' => $errors,
        'row' => $row,
        'destination' => $dest,
        'subscription' => $errors ? null : rivetWebhookSubsFromRow($row, (int) ($existing['webhook_id'] ?? 0)),
        'auth_redacted' => Authentication::redact($authCfg),
        'events' => $events,
    ];
}

/** Encrypt a value for storage, but keep the stored ciphertext when the value did not change (no pointless re-encryption on edit). */
function rivetWebhookSeal(string $plain, ?array $existing, string $column): string
{
    if ($existing && (string) $existing[$column] !== '' && decryptSetting((string) $existing[$column]) === $plain) {
        return (string) $existing[$column];
    }

    return encryptSetting($plain);
}

/** @param array<string,mixed> $row */
function rivetWebhookSubsFromRow(array $row, int $id): WebhookSubscription
{
    $class = rivetWebhookSubsClass();

    return $class::subscriptionFromRow($row + ['webhook_id' => $id]);
}

/** A readable, secret-free description of an endpoint address: scheme://host[:port]/... */
function rivetWebhookUrlMasked(string $url): string
{
    $p = parse_url(str_replace(['{', '}'], ['%7B', '%7D'], $url));
    if (!is_array($p) || empty($p['host'])) {
        return '(address saved)';
    }
    $path = ($p['path'] ?? '') !== '' && $p['path'] !== '/' ? '/…' : '';

    return strtolower((string) ($p['scheme'] ?? 'https')) . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $path;
}

/** The sample event used by "Send test" and "Preview payload". @return array<string,mixed> */
function rivetWebhookSampleData(): array
{
    return PayloadTemplate::sampleContext('ticket.created')['data'] + ['test' => true];
}

/**
 * Send a sample event through the REAL delivery path (the shared dispatcher: format, auth headers, signing, URL policy, delivery log).
 *
 * @return array{ok:bool, http_status:?int, duration_ms:int, error:?string, response:string, event:string}
 */
function rivetWebhookSendTest($mysqli, WebhookSubscription $sub): array
{
    $dispatcher = rivetWebhookDispatcher($mysqli, new RivetInlineWebhookSubscription($sub));
    $opts = $sub->options;
    if ($opts !== []) {
        $opts['format_options'] = array_merge((array) ($opts['format_options'] ?? []), ['test' => true]);
    }
    $r = $dispatcher->deliverTo($sub->webhookId, 'test', rivetWebhookSampleData(), 1, null, time(), $opts ?: null);

    $response = '';
    $res = mysqli_query($mysqli, "SELECT response_body_snippet FROM webhook_deliveries WHERE webhook_id = " . (int) $sub->webhookId . " AND event_type = 'test' ORDER BY delivery_id DESC LIMIT 1");
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        $response = (string) $row['response_body_snippet'];
    }

    return [
        'ok' => (bool) $r['ok'], 'http_status' => $r['http_status'] ?? null, 'duration_ms' => (int) $r['duration_ms'],
        'error' => $r['error'] ?? null, 'response' => mb_substr($response, 0, 600), 'event' => 'test',
    ];
}

/**
 * Exactly what would be sent for a sample event: method, address (path hidden), headers and body. Secrets are redacted.
 *
 * @return array{method:string, url:string, headers:list<string>, body:string, content_type:string}
 */
function rivetWebhookPreview(WebhookSubscription $sub, array $authRedacted): array
{
    $opts = $sub->options;
    $event = ['event' => 'test', 'timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'data' => rivetWebhookSampleData()];
    if ($opts === []) {
        $format = 'json';
        $fo = [];
    } else {
        $format = (string) ($opts['format'] ?? 'json');
        $fo = array_merge((array) ($opts['format_options'] ?? []), ['test' => true]);
        foreach (['template', 'template_encoding'] as $k) {
            if (isset($opts[$k])) {
                $fo[$k] = $opts[$k];
            }
        }
    }
    $f = PayloadFormatter::format($format, $event, $fo);

    $auth = [];
    $mode = (string) ($authRedacted['mode'] ?? 'none');
    $configured = (array) ($opts['extraHeaders'] ?? []);
    foreach ($configured as $name => $value) {
        $isAuth = strcasecmp((string) $name, 'Authorization') === 0 || ($mode === 'header' && strcasecmp((string) $name, (string) ($authRedacted['header_name'] ?? '')) === 0);
        $auth[$name] = $isAuth ? (str_starts_with((string) $value, 'Bearer ') ? 'Bearer ********' : (str_starts_with((string) $value, 'Basic ') ? 'Basic ********' : '********')) : $value;
    }
    $headers = ['Content-Type: ' . $f->contentType, 'X-Rivet-Timestamp: <unix time when this attempt is sent>', 'X-Rivet-Signature-V2: t=<unix time>,v1=<HMAC-SHA256 of "<time>.<body>" with your signing secret>'];
    foreach (rivetWebhookHeaderPrefixes() as $prefix) {
        $headers[] = $prefix . '-Signature: sha256=<HMAC-SHA256 of the body with your signing secret>';
        $headers[] = $prefix . '-Event: test';
    }
    foreach ($f->headers as $k => $v) {
        if (!isset($auth[$k])) {
            $headers[] = $k . ': ' . $v;
        }
    }
    foreach ($auth as $k => $v) {
        $headers[] = $k . ': ' . $v;
    }

    return ['method' => strtoupper((string) ($opts['method'] ?? 'POST')), 'url' => rivetWebhookUrlMasked($sub->url), 'headers' => $headers, 'body' => $f->body, 'content_type' => $f->contentType];
}

/** Mask secret-looking keys of a stored request body for display (the delivery log keeps the exact bytes sent). */
function rivetWebhookRedactBody(string $body): string
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return mb_substr($body, 0, 20000);
    }
    $walk = static function ($v) use (&$walk) {
        if (!is_array($v)) {
            return $v;
        }
        $out = [];
        foreach ($v as $k => $x) {
            $out[$k] = is_string($k) && preg_match('/pass(word|wd)?|secret|token|api[_-]?key|authorization|cookie|credential_value|private|signature|bearer|otp|totp|session/i', $k) ? '[redacted]' : $walk($x);
        }

        return $out;
    };

    return (string) json_encode($walk($decoded), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
