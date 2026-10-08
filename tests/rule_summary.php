<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Unit tests (plain php, no database): RuleSummary sentences, RuleDryRun matching/rendering and the recipe catalogue.
 *   php tests/rule_summary.php
 */
require __DIR__ . '/../vendor/autoload.php';

use RivetCore\Automation\AutomationRuleStore;
use RivetCore\Webhooks\EventCatalog;
use RivetMSP\Automation\RuleDryRun;
use RivetMSP\Automation\RuleRecipes;
use RivetMSP\Automation\RuleSummary;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name" . ($ok || $detail === '' ? '' : "  [$detail]") . "\n";
}
function row(string $event, array $cond, string $type, array $cfg, array $extra = []): array
{
    return ['name' => 'T', 'trigger_event' => $event, 'condition_json' => $cond ? json_encode($cond) : null, 'action_type' => $type, 'action_config_json' => json_encode($cfg), 'is_enabled' => 1] + $extra;
}

$s = RuleSummary::describe(row('ticket.created', ['ticket_priority' => 'Critical'], 'notify_user', ['message' => 'Critical ticket {ticket_number}']));
check('notify sentence names the event, condition and message', $s === 'When "Ticket created" happens and ticket priority is "Critical", notify technicians: "Critical ticket {ticket_number}".', $s);
$s = RuleSummary::describe(row('auth.login_failed', [], 'create_ticket', ['subject' => 'Failed login', 'priority' => 'High']));
check('no conditions reads as every time', str_contains($s, '(every time)') && str_contains($s, 'create a High-priority ticket "Failed login"'), $s);
$s = RuleSummary::describe(row('ticket.created', ['ticket_priority' => 'High', 'client_name' => 'Acme', 'action' => 'create'], 'send_webhook', ['url' => 'https://user:pw@hooks.example.com:8443/secret/path?token=abc', 'secret' => 'sekret']));
check('several conditions are joined with commas and "and"', str_contains($s, 'ticket priority is "High", client name is "Acme" and action is "create"'), $s);
check('a webhook shows host and port only, never userinfo, path, query or secret', str_contains($s, 'https://hooks.example.com:8443') && !preg_match('/pw|secret|token|sekret/', $s), $s);
$s = RuleSummary::describe(row('ticket.assigned', ['assigned_to_user_id' => '4', 'client_id' => '7'], 'create_ticket', ['subject' => 'x', 'client_id' => 7], ['names' => ['users' => [4 => 'Sam Tech'], 'clients' => [7 => 'Acme Corp']]]));
check('user and client ids resolve to names where known', str_contains($s, 'assigned to user id is "Sam Tech"') && str_contains($s, 'client id is "Acme Corp"') && str_contains($s, 'for Acme Corp'), $s);
$s = RuleSummary::describe(row('ticket.assigned', ['assigned_to_user_id' => '99'], 'notify_user', ['message' => 'm']));
check('unknown ids stay as typed', str_contains($s, '"99"'), $s);
check('an unknown event falls back to its id', str_contains(RuleSummary::describe(row('custom.thing', [], 'notify_user', ['message' => 'm'])), 'When "custom.thing" happens'));
check('an incomplete rule still produces a sentence', RuleSummary::describe(['trigger_event' => '', 'action_type' => '']) === 'When an event happens (every time), do nothing yet.');
$long = RuleSummary::describe(row('ticket.created', [], 'notify_user', ['message' => str_repeat('word ', 60)]));
check('long text is clipped', mb_strlen($long) < 220 && str_contains($long, '…'), (string) mb_strlen($long));
$hostile = RuleSummary::describe(row('ticket.created', ['x' => '<script>alert(1)</script>'], 'notify_user', ['message' => '<b>hi</b>']));
check('describe returns raw text (callers escape); it never adds markup of its own', str_contains($hostile, '<script>alert(1)</script>') && !str_contains(str_replace(['<script>alert(1)</script>', '<b>hi</b>'], '', $hostile), '<'));
check('decoded conditions/config are accepted too', RuleSummary::describe(['trigger_event' => 'ticket.created', 'conditions' => ['a' => 'b'], 'action_type' => 'notify_user', 'config' => ['message' => 'hey']]) === 'When "Ticket created" happens and a is "b", notify technicians: "hey".');
check('malformed condition JSON is treated as none', str_contains(RuleSummary::describe(['trigger_event' => 'ticket.created', 'condition_json' => '{nope', 'action_type' => 'notify_user', 'action_config_json' => '[]']), '(every time)'));

// ---- dry run
$ctx = ['event' => 'ticket.created', 'ticket_priority' => 'High', 'ticket_number' => 'TCK-9', 'client_name' => 'Acme'];
$r = RuleDryRun::evaluate(['ticket_priority' => 'High', 'client_name' => 'Acme'], $ctx);
check('all matching conditions match', $r['matched'] && count($r['rows']) === 2 && $r['rows'][0]['ok']);
$r = RuleDryRun::evaluate(['ticket_priority' => 'Critical', 'client_name' => 'Acme'], $ctx);
check('a failing condition is reported with expected and actual', !$r['matched'] && !$r['rows'][0]['ok'] && $r['rows'][0]['actual'] === 'High' && $r['rows'][1]['ok']);
$r = RuleDryRun::evaluate(['nope' => ''], $ctx);
check('a field missing from the event never matches, even against an empty value', !$r['matched'] && $r['rows'][0]['actual'] === null);
check('no conditions always match', RuleDryRun::evaluate([], $ctx)['matched']);
$d = RuleDryRun::render('notify_user', ['message' => 'Ticket {ticket_number} for {client_name} {missing}'], $ctx);
check('placeholders are filled from the event, unknown ones become empty', $d['fields']['Message'] === 'Ticket TCK-9 for Acme ' && str_starts_with($d['text'], 'Would notify every technician'), $d['text']);
$d = RuleDryRun::render('send_webhook', ['url' => 'https://hooks.example.com/a?token=zzz', 'secret' => 'shh'], $ctx);
check('a webhook preview shows the host only', $d['fields'] === ['Endpoint' => 'https://hooks.example.com'] && !str_contains($d['text'], 'zzz') && !str_contains($d['text'], 'shh'), $d['text']);
$d = RuleDryRun::render('create_ticket', ['subject' => '{ticket_subject}', 'details' => 'd', 'priority' => '{ticket_priority}', 'client_id' => 5], $ctx + ['ticket_subject' => 'S'], ['clients' => [5 => 'Acme']]);
check('a ticket preview never lets event data choose the priority or client', str_contains($d['text'], '-priority ticket "S" for Acme') && $d['fields']['Priority'] === '{ticket_priority}', $d['text']);

// ---- recipes
$store = new ReflectionClass(AutomationRuleStore::class);
$recipes = RuleRecipes::all();
check('there are 8 to 10 recipes', count($recipes) >= 8 && count($recipes) <= 10, (string) count($recipes));
$bad = [];
foreach ($recipes as $key => $rc) {
    if (!RuleRecipes::eventIsLive($rc['trigger_event'])) {
        $bad[] = "$key: event {$rc['trigger_event']} is not emitted here";
    }
    if (!isset(AutomationRuleStore::ACTIONS[$rc['action_type']])) {
        $bad[] = "$key: unknown action";
    }
    if ($rc['name'] === '' || mb_strlen($rc['name']) > 200) {
        $bad[] = "$key: bad name";
    }
    $sample = RuleDryRun::render($rc['action_type'], $rc['config'], ['event' => $rc['trigger_event']]);
    if (RuleSummary::describe($rc + ['action_config_json' => null]) === '' || $sample['text'] === '') {
        $bad[] = "$key: no sentence";
    }
    if ($rc['action_type'] === 'create_ticket' && trim((string) $rc['config']['subject']) === '') {
        $bad[] = "$key: empty subject";
    }
    if ($rc['action_type'] === 'notify_user' && trim((string) $rc['config']['message']) === '') {
        $bad[] = "$key: empty message";
    }
    // Placeholders must exist in the event's payload fields (or be the generic {event}).
    $def = EventCatalog::get($rc['trigger_event']);
    $paths = array_column($def ? $def->payloadFields : [], 'path');
    foreach ($rc['config'] as $v) {
        if (is_string($v) && preg_match_all('/\{([A-Za-z0-9_.]+)\}/', $v, $m)) {
            foreach ($m[1] as $ph) {
                if ($ph !== 'event' && !in_array($ph, $paths, true)) {
                    $bad[] = "$key: placeholder {$ph} is not a field of {$rc['trigger_event']}";
                }
            }
        }
    }
    foreach (array_keys($rc['conditions']) as $f) {
        if (!in_array($f, $paths, true)) {
            $bad[] = "$key: condition field $f is not a payload field";
        }
    }
}
check('every recipe uses a live event, a real action, valid placeholders and fields', !$bad, implode('; ', $bad));
check('no recipe promises SLA or backup events this edition does not emit', !array_filter($recipes, static fn ($r) => str_starts_with($r['trigger_event'], 'sla.') || str_starts_with($r['trigger_event'], 'backup.')));
check('recipe keys are unique slugs', count(array_filter(array_keys($recipes), static fn ($k) => preg_match('/^[a-z0-9-]+$/', $k))) === count($recipes));

echo "SUMMARY $pass/" . ($pass + $fail) . " passed\n";
exit($fail ? 1 : 0);
