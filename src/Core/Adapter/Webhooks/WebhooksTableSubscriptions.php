<?php

declare(strict_types=1);

namespace RivetMSP\Core\Adapter\Webhooks;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Webhooks\Authentication;
use RivetCore\Webhooks\Destinations;
use RivetCore\Webhooks\EventCatalog;
use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionLookupInterface;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

/**
 * RivetMSP's webhook endpoints: rows of the `webhooks` table (events stored as a comma list that may contain patterns such as
 * "ticket.*" or "*"; secrets, the URL of platform endpoints and the outgoing authentication are encrypted with encryptSetting).
 * RivetCore never touches the table.
 *
 * A row made from a destination preset (webhook_destination / webhook_format set) is delivered with its format, method and
 * extra headers; a legacy row ('' destination and format) is delivered the old way (plain JSON envelope).
 */
final class WebhooksTableSubscriptions implements WebhookSubscriptionsInterface, WebhookSubscriptionLookupInterface
{
    private const COLUMNS = 'webhook_id, webhook_url, webhook_secret, webhook_events, webhook_destination, webhook_format, webhook_method, webhook_template, webhook_auth_mode, webhook_auth_enc, webhook_extra';

    public function __construct(private DatabaseInterface $database)
    {
    }

    public function find(int $webhookId): ?WebhookSubscription
    {
        $r = $this->database->fetchOne('SELECT ' . self::COLUMNS . ' FROM webhooks WHERE webhook_id = ? AND webhook_enabled = 1', [$webhookId]);

        return $r === null ? null : self::subscriptionFromRow($r);
    }

    public function forEvent(string $eventType): array
    {
        $rows = $this->database->fetchAll('SELECT ' . self::COLUMNS . ' FROM webhooks WHERE webhook_enabled = 1');
        $out = [];
        foreach ($rows as $r) {
            if (self::matches((string) $r['webhook_events'], $eventType)) {
                $out[] = self::subscriptionFromRow($r);
            }
        }

        return $out;
    }

    /**
     * Does a stored subscription ("ticket.created, invoice.*") include this event? Plain ids match exactly; a pattern ("*",
     * "ticket.*", "auth.login_*") matches through EventCatalog::matchPattern(), and, for events the catalog does not list (the
     * "other events seen on this server"), through the same wildcard rule so a new "ticket.something" is still covered by "ticket.*".
     */
    public static function matches(string $stored, string $event): bool
    {
        foreach (explode(',', $stored) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if ($token === $event) {
                return true;
            }
            if (!EventCatalog::isPattern($token) || !preg_match('/^[a-z0-9_.*-]{1,150}$/', $token)) {
                continue;
            }
            if (in_array($event, EventCatalog::matchPattern($token), true)) {
                return true;
            }
            if (!EventCatalog::has($event) && preg_match('/^' . str_replace('\*', '.*', preg_quote($token, '/')) . '$/', $event) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $r */
    public static function subscriptionFromRow(array $r): WebhookSubscription
    {
        return new WebhookSubscription(
            (int) $r['webhook_id'],
            decryptSetting((string) $r['webhook_url']),
            decryptSetting((string) ($r['webhook_secret'] ?? '')),
            self::optionsFromRow($r)
        );
    }

    /**
     * Delivery options (see WebhookDispatcher::deliverTo()) for a row: format, method, template, format_options and extraHeaders
     * (outgoing authentication + the preset's static headers). Legacy rows return [].
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function optionsFromRow(array $r): array
    {
        $destId = (string) ($r['webhook_destination'] ?? '');
        $format = (string) ($r['webhook_format'] ?? '');
        if ($destId === '' && $format === '') {
            return [];
        }
        $dest = $destId !== '' ? Destinations::get($destId) : null;
        $format = $format !== '' ? $format : ($dest->format ?? 'json');
        $extra = json_decode((string) ($r['webhook_extra'] ?? ''), true);
        $extra = is_array($extra) ? $extra : [];
        $fields = is_array($extra['fields'] ?? null) ? $extra['fields'] : [];

        $formatOptions = $dest ? $dest->formatOptions : [];
        unset($formatOptions['template'], $formatOptions['template_encoding']);
        if ($dest) {
            foreach ($dest->extraFields as $f) {
                $v = isset($fields[$f->name]) && is_scalar($fields[$f->name]) ? trim((string) $fields[$f->name]) : '';
                if ($f->target === 'option' && $f->option !== '' && $v !== '') {
                    $formatOptions[$f->option] = $f->type === 'number' && ctype_digit($v) ? (int) $v : $v;
                }
            }
        }
        $formatOptions['app_name'] = defined('APP_NAME') ? APP_NAME : 'RivetMSP';

        $opts = ['format' => $format, 'method' => strtoupper((string) ($r['webhook_method'] ?? '')) ?: ($dest->method ?? 'POST'), 'format_options' => $formatOptions];

        if ($format === 'template') {
            $template = (string) ($r['webhook_template'] ?? '');
            $encoding = (string) ($extra['template_encoding'] ?? '');
            if ($template === '' && $dest) {
                $template = (string) ($dest->formatOptions['template'] ?? '');
                $encoding = $encoding !== '' ? $encoding : (string) ($dest->formatOptions['template_encoding'] ?? '');
            }
            $opts['template'] = $template;
            $opts['template_encoding'] = $encoding !== '' ? $encoding : 'json';
        }

        $opts['extraHeaders'] = self::headersFromRow($r, $dest);

        return $opts;
    }

    /**
     * Outgoing headers: the authentication the receiver expects plus the preset's static headers. A stored configuration that no longer
     * validates must not be sent without its authentication, so it yields a header the dispatcher refuses ("invalid extra header").
     *
     * @param array<string,mixed> $r
     * @return array<string,string>
     */
    private static function headersFromRow(array $r, ?\RivetCore\Webhooks\Destination $dest): array
    {
        $headers = $dest ? $dest->headers : [];
        $mode = (string) ($r['webhook_auth_mode'] ?? 'none');
        if ($mode !== '' && $mode !== 'none' && $mode !== 'hmac') {
            $cfg = json_decode(decryptSetting((string) ($r['webhook_auth_enc'] ?? '')), true);
            $cfg = is_array($cfg) ? $cfg : [];
            $cfg['mode'] = $mode;
            try {
                $headers = array_merge($headers, Authentication::headers($cfg));
            } catch (\Throwable) {
                $headers['Invalid Authentication'] = 'stored authentication is not usable';
            }
        }

        return $headers;
    }
}
