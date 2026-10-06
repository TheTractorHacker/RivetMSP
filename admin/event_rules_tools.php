<?php

/*
 * JSON helpers behind Administration > Event rules (admin only, POST + CSRF; same shape as webhook_tools.php):
 *   toggle / delete / duplicate   one rule (CSRF-protected, audited like the classic post handler)
 *   save                          validate + store the editor form; returns per-field errors instead of redirecting
 *   describe                      live sentence, per-field validation hints and a placeholder preview for an unsaved form
 *   recent                        recent real events a rule can be checked against
 *   test                          DRY RUN: evaluate the conditions against a sample / recent event and render what the action would
 *                                 do. No action handler is ever called: nothing is created, sent or queued.
 *   history                       the recorded runs of one rule (audit events "automation.rule_fired")
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$out = static function (array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
};

if (!isset($session_is_admin) || !$session_is_admin) {
    $out(['ok' => false, 'error' => 'Your role does not have admin access.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $out(['ok' => false, 'error' => 'POST required.'], 405);
}
if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals((string) $_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $out(['ok' => false, 'error' => 'CSRF token verification failed. Reload the page.'], 403);
}

require_once __DIR__ . '/../includes/event_rules_lib.php';

use RivetCore\Automation\AutomationRuleStore;
use RivetCore\Automation\EventContext;
use RivetCore\Webhooks\PayloadTemplate;
use RivetMSP\Automation\RuleDryRun;
use RivetMSP\Automation\RuleSummary;

if (!class_exists(AutomationRuleStore::class) || !rivetTableExists($mysqli, 'automation_rules')) {
    $out(['ok' => false, 'error' => 'Run the database update first.'], 409);
}
$store = new AutomationRuleStore(rivetCoreDb($mysqli));
$action = (string) ($_POST['action'] ?? '');
$ruleId = intval($_POST['rule_id'] ?? 0);

/** The rule a test/describe call is about: the posted (possibly unsaved) form, else the stored rule. */
$formRule = static function () use ($mysqli, $store, $ruleId, $out): array {
    if (isset($_POST['trigger_event']) || isset($_POST['action_type'])) {
        $v = eventRulesValidate($mysqli, $_POST, false);
        $c = $v['clean'];
        $missing = array_intersect_key($v['errors'], ['trigger_event' => 1, 'action_type' => 1]);
        if ($missing) {
            $out(['ok' => false, 'error' => implode(' ', $missing)], 422);
        }

        return ['name' => $c['name'], 'trigger_event' => $c['event'], 'conditions' => $c['conditions'], 'action_type' => $c['type'], 'config' => $c['config'], 'is_enabled' => $c['enabled'], 'errors' => $v['errors']];
    }
    $r = $store->find($ruleId);
    if (!$r) {
        $out(['ok' => false, 'error' => 'That rule no longer exists.'], 404);
    }

    return ['name' => $r['name'], 'trigger_event' => $r['trigger_event'], 'conditions' => RuleSummary::conditions($r), 'action_type' => $r['action_type'], 'config' => RuleSummary::config($r), 'is_enabled' => (bool) $r['is_enabled'], 'errors' => []];
};

switch ($action) {
    case 'toggle':
        $rule = $store->find($ruleId);
        if (!$rule) {
            $out(['ok' => false, 'error' => 'That rule no longer exists.'], 404);
        }
        $now = !$rule['is_enabled'];
        $store->setEnabled($ruleId, $now);
        logAction('Automation', 'Edit', "$session_name turned event rule $ruleId " . ($now ? 'on' : 'off'));
        rivetAudit('automation.rule_toggled', (int) $session_user_id, 'automation_rule', $ruleId, 'update', 'Event rule turned ' . ($now ? 'on' : 'off'));
        $out(['ok' => true, 'enabled' => $now]);

    case 'delete':
        if (!$store->find($ruleId)) {
            $out(['ok' => false, 'error' => 'That rule no longer exists.'], 404);
        }
        $store->delete($ruleId);
        logAction('Automation', 'Delete', "$session_name deleted event rule $ruleId");
        rivetAudit('automation.rule_deleted', (int) $session_user_id, 'automation_rule', $ruleId, 'delete', 'Event rule deleted');
        $out(['ok' => true]);

    case 'duplicate':
        $rule = $store->find($ruleId);
        if (!$rule) {
            $out(['ok' => false, 'error' => 'That rule no longer exists.'], 404);
        }
        $name = mb_substr($rule['name'], 0, 193) . ' (copy)';
        $id = $store->save(null, $name, (string) $rule['trigger_event'], RuleSummary::conditions($rule), (string) $rule['action_type'], RuleSummary::config($rule), false);
        logAction('Automation', 'Create', "$session_name duplicated event rule $ruleId as $id");
        rivetAudit('automation.rule_created', (int) $session_user_id, 'automation_rule', $id, 'create', 'Event rule duplicated from #' . $ruleId . ' (off)');
        $out(['ok' => true, 'id' => $id, 'name' => $name]);

    case 'save':
        $id = isset($_POST['rule_id']) && intval($_POST['rule_id']) > 0 ? intval($_POST['rule_id']) : null;
        $res = eventRulesSave($mysqli, $store, $_POST, $id, (string) $session_name, (int) $session_user_id);
        if (!$res['ok']) {
            $out(['ok' => false, 'errors' => $res['errors']], 422);
        }
        $out(['ok' => true, 'id' => $res['id']]);

    case 'describe':
        $names = eventRulesNames($mysqli);
        $v = eventRulesValidate($mysqli, $_POST, !empty($_POST['check_url']));
        $c = $v['clean'];
        $rule = ['trigger_event' => $c['event'], 'conditions' => $c['conditions'], 'action_type' => $c['type'], 'config' => $c['config'], 'names' => $names];
        $ctx = eventRulesContext($mysqli, $c['event'], 'sample');
        $preview = $ctx && isset(AutomationRuleStore::ACTIONS[$c['type']]) ? RuleDryRun::render($c['type'], $c['config'], $ctx['context'], $names)['fields'] : [];
        $out(['ok' => true, 'summary' => RuleSummary::describe($rule), 'errors' => $v['errors'], 'preview' => $preview]);

    case 'recent':
        $event = (string) ($_POST['event'] ?? '');
        if (!preg_match('/^[a-z0-9_.]{1,150}$/', $event)) {
            $out(['ok' => false, 'error' => 'Choose an event first.'], 422);
        }
        $out(['ok' => true, 'events' => eventRulesRecentEvents($mysqli, $event), 'ticket_shaped' => eventRulesIsTicketShaped($event)]);

    case 'test':
        $rule = $formRule();
        $event = (string) $rule['trigger_event'];
        $ctx = eventRulesContext($mysqli, $event, (string) ($_POST['source'] ?? 'sample'));
        if (!$ctx) {
            $out(['ok' => false, 'error' => 'That event is no longer available. Pick another one.'], 404);
        }
        $names = eventRulesNames($mysqli);
        $eval = RuleDryRun::evaluate($rule['conditions'], $ctx['context']);
        $would = RuleDryRun::render((string) $rule['action_type'], (array) $rule['config'], $ctx['context'], $names);
        $extra = [];
        if ($rule['action_type'] === 'send_webhook' && ($url = (string) ($rule['config']['url'] ?? '')) !== '') {
            $extra['url_allowed'] = rivetWebhookUrlIsSafe($url);
            $extra['url_rule'] = rivetWebhookRuleText($mysqli);
        }
        $shown = [];
        foreach ($ctx['context'] as $k => $val) {
            if (count($shown) < 40) {
                $shown[$k] = mb_substr((string) $val, 0, 160);
            }
        }
        $out(['ok' => true, 'source' => $ctx['label'], 'matched' => $eval['matched'], 'conditions' => $eval['rows'], 'would' => $would['text'], 'fields' => $would['fields'], 'context' => $shown,
            'summary' => RuleSummary::describe(['trigger_event' => $event, 'conditions' => $rule['conditions'], 'action_type' => $rule['action_type'], 'config' => $rule['config'], 'names' => $names]),
            'rule_off' => !$rule['is_enabled'], 'errors' => $rule['errors'], 'dry_run' => true] + $extra);

    case 'history':
        $rule = $store->find($ruleId);
        $runs = eventRulesHistory($mysqli, $ruleId);
        $stats = eventRulesStats($mysqli)[$ruleId] ?? null;
        $out(['ok' => true, 'rule' => $rule ? $rule['name'] : null, 'runs' => $runs, 'total' => $stats['runs'] ?? 0, 'shown' => count($runs)]);
}

$out(['ok' => false, 'error' => 'Unknown action.'], 400);
