<?php

declare(strict_types=1);

namespace RivetMSP\Automation;

use RivetCore\Webhooks\EventCatalog;

/**
 * One plain-English sentence for an event rule, shared by the rule list, the editor's live summary panel and the test drawer so
 * they can never disagree:
 *
 *   When "Ticket created" happens and ticket priority is "Critical", notify technicians: "Critical ticket {ticket_number}".
 *
 * Input is a row of automation_rules (name, trigger_event, condition_json, action_type, action_config_json, is_enabled) or the same
 * rule with already decoded 'conditions' (field => value) and 'config' arrays (the editor sends the unsaved form that way).
 * An optional 'names' => ['users' => [id => name], 'clients' => [id => name]] resolves ids to names. Nothing here touches the
 * database, and a secret or a URL path/query (which may carry a token) is never printed: webhooks show scheme and host only.
 */
final class RuleSummary
{
    private const PRIORITIES = ['Low', 'Medium', 'High'];

    /** Plain-English rule sentence. */
    public static function describe(array $rule): string
    {
        $names = is_array($rule['names'] ?? null) ? $rule['names'] : [];

        return self::trigger((string) ($rule['trigger_event'] ?? '')) . self::conditionsText(self::conditions($rule), $names) . ', ' . self::actionText($rule, $names) . '.';
    }

    /** Decoded conditions of a rule row (field => expected value). @return array<string,string> */
    public static function conditions(array $rule): array
    {
        $c = $rule['conditions'] ?? null;
        if (!is_array($c)) {
            $c = json_decode((string) ($rule['condition_json'] ?? ''), true);
        }
        $out = [];
        foreach (is_array($c) ? $c : [] as $field => $value) {
            if (is_scalar($value) && (string) $field !== '') {
                $out[(string) $field] = (string) $value;
            }
        }

        return $out;
    }

    /** Decoded action configuration of a rule row. @return array<string,mixed> */
    public static function config(array $rule): array
    {
        $c = $rule['config'] ?? null;
        if (!is_array($c)) {
            $c = json_decode((string) ($rule['action_config_json'] ?? ''), true);
        }

        return is_array($c) ? $c : [];
    }

    /** The event's display name from the catalog, or the raw id for an event the catalog does not list. */
    public static function eventLabel(string $event): string
    {
        $def = $event !== '' ? EventCatalog::get($event) : null;

        return $def ? $def->label : $event;
    }

    /** "ticket_priority" -> "ticket priority"; dotted paths keep their dots so nested fields stay recognisable. */
    public static function fieldLabel(string $field): string
    {
        return str_replace('_', ' ', $field);
    }

    public static function clip(string $s, int $max): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));

        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }

    /** scheme://host[:port] of a URL, or '' when it is not an http(s) URL. Userinfo, path and query are dropped on purpose. */
    public static function hostOf(string $url): string
    {
        $p = parse_url(trim($url));
        if (!$p || empty($p['host']) || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)) {
            return '';
        }

        return strtolower((string) $p['scheme']) . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    private static function trigger(string $event): string
    {
        return $event === '' ? 'When an event happens' : 'When "' . self::eventLabel($event) . '" happens';
    }

    /** @param array<string,string> $conditions */
    private static function conditionsText(array $conditions, array $names): string
    {
        if (!$conditions) {
            return ' (every time)';
        }
        $parts = [];
        foreach ($conditions as $field => $value) {
            $parts[] = self::fieldLabel($field) . ' is "' . self::clip(self::resolve($field, $value, $names), 60) . '"';
        }

        return ' and ' . self::joinAnd($parts);
    }

    private static function resolve(string $field, string $value, array $names): string
    {
        if (preg_match('/(?:^|[._])user_id$/', $field) && ctype_digit($value) && isset($names['users'][(int) $value])) {
            return (string) $names['users'][(int) $value];
        }
        if (preg_match('/(?:^|[._])client_id$/', $field) && ctype_digit($value) && isset($names['clients'][(int) $value])) {
            return (string) $names['clients'][(int) $value];
        }

        return $value;
    }

    /** @param list<string> $parts */
    private static function joinAnd(array $parts): string
    {
        return count($parts) <= 1 ? (string) ($parts[0] ?? '') : implode(', ', array_slice($parts, 0, -1)) . ' and ' . end($parts);
    }

    private static function actionText(array $rule, array $names): string
    {
        $type = (string) ($rule['action_type'] ?? '');
        $cfg = self::config($rule);
        switch ($type) {
            case 'create_ticket':
                $priority = in_array($cfg['priority'] ?? '', self::PRIORITIES, true) ? (string) $cfg['priority'] : 'Low';
                $subject = self::clip((string) ($cfg['subject'] ?? ''), 60);
                $client = (int) ($cfg['client_id'] ?? 0);
                $for = $client > 0 ? ' for ' . ($names['clients'][$client] ?? 'client #' . $client) : '';

                return 'create a ' . $priority . '-priority ticket' . ($subject !== '' ? ' "' . $subject . '"' : '') . $for;
            case 'send_webhook':
                $host = self::hostOf((string) ($cfg['url'] ?? ''));

                return 'send the event to a webhook' . ($host !== '' ? ' at ' . $host : '');
            case 'notify_user':
                $message = self::clip((string) ($cfg['message'] ?? ''), 80);

                return 'notify technicians' . ($message !== '' ? ': "' . $message . '"' : '');
        }

        return $type === '' ? 'do nothing yet' : 'run "' . $type . '"';
    }
}
