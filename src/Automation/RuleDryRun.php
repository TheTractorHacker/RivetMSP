<?php

declare(strict_types=1);

namespace RivetMSP\Automation;

use RivetCore\Automation\AutomationExecutor;

/**
 * The read-only half of "test this rule": evaluate a rule's conditions against an event context and render what its action would
 * do. It never calls an action handler, so nothing is created, sent or queued. Matching mirrors AutomationRuleEvaluator::conditionsMatch
 * exactly (all conditions must hold, a field missing from the event never matches, values compare as strings) and the text fields are
 * filled with AutomationExecutor::interpolate, the very function a real run uses.
 */
final class RuleDryRun
{
    /**
     * @param array<string,string> $conditions field => expected
     * @param array<string,string> $context flat event context (EventContext::flatten)
     * @return array{matched:bool, rows:list<array{field:string,expected:string,actual:?string,ok:bool}>}
     */
    public static function evaluate(array $conditions, array $context): array
    {
        $rows = [];
        $matched = true;
        foreach ($conditions as $field => $expected) {
            $field = (string) $field;
            $has = array_key_exists($field, $context);
            $ok = $has && (string) $context[$field] === (string) $expected;
            $matched = $matched && $ok;
            $rows[] = ['field' => $field, 'expected' => (string) $expected, 'actual' => $has ? (string) $context[$field] : null, 'ok' => $ok];
        }

        return ['matched' => $matched, 'rows' => $rows];
    }

    /**
     * What the action would do, with the {placeholders} of its text fields filled in from the context.
     *
     * @param array<string,mixed> $config
     * @param array<string,string> $context
     * @return array{text:string, fields:array<string,string>}
     */
    public static function render(string $type, array $config, array $context, array $names = []): array
    {
        $cfg = AutomationExecutor::interpolate($config, $context);
        $fields = [];
        switch ($type) {
            case 'create_ticket':
                $fields = ['Subject' => (string) ($cfg['subject'] ?? ''), 'Details' => (string) ($cfg['details'] ?? ''), 'Priority' => (string) ($cfg['priority'] ?? 'Low')];
                $text = 'Would create a ' . ($fields['Priority'] ?: 'Low') . '-priority ticket "' . RuleSummary::clip($fields['Subject'], 120) . '"';
                $client = (int) ($cfg['client_id'] ?? 0);
                if ($client > 0) {
                    $text .= ' for ' . ($names['clients'][$client] ?? 'client #' . $client);
                }
                break;
            case 'send_webhook':
                $host = RuleSummary::hostOf((string) ($cfg['url'] ?? ''));
                $text = 'Would send the event as a signed JSON POST to ' . ($host !== '' ? $host : 'the configured URL');
                $fields = ['Endpoint' => $host];
                break;
            case 'notify_user':
                $fields = ['Message' => (string) ($cfg['message'] ?? '')];
                $text = 'Would notify every technician: "' . RuleSummary::clip($fields['Message'], 200) . '"';
                break;
            default:
                $text = 'Would run "' . $type . '"';
        }

        return ['text' => $text, 'fields' => array_filter($fields, static fn (string $v): bool => $v !== '')];
    }
}
