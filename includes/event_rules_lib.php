<?php

/*
 * Shared server-side logic of Administration > Event rules: validation and saving (the page's JSON endpoint and the classic form post
 * use the same function, so they can never disagree), lookups that turn ids into names, per-rule run statistics read from the audit
 * trail, and the recent events a rule can be checked against. Nothing here runs a rule's action.
 */

require_once __DIR__ . '/event_bus.php';

use RivetCore\Automation\AutomationRuleStore;
use RivetCore\Automation\EventContext;
use RivetCore\Webhooks\EventCatalog;
use RivetCore\Webhooks\PayloadTemplate;

/** Action metadata for the UI. Keys are exactly AutomationRuleStore::ACTIONS; the engine here has these three handlers. */
function eventRulesActionMeta(): array
{
    $meta = [
        'create_ticket' => ['icon' => 'fa-ticket-alt', 'desc' => 'Open a new ticket with a subject, details and priority. Placeholders from the event are filled in.'],
        'send_webhook' => ['icon' => 'fa-paper-plane', 'desc' => 'POST the event as signed JSON to another service (chat, automation tool, your own endpoint).'],
        'notify_user' => ['icon' => 'fa-bell', 'desc' => 'Show a notification to every active technician.'],
    ];
    // This edition's notify handler reaches every active technician, so the label says so (the stored action id stays notify_user).
    $labels = ['notify_user' => 'Notify technicians'];
    $out = [];
    foreach (AutomationRuleStore::ACTIONS as $k => $label) {
        $label = $labels[$k] ?? $label;
        $out[$k] = ['label' => $label] + ($meta[$k] ?? ['icon' => 'fa-bolt', 'desc' => '']);
    }

    return $out;
}

/** Group key of an event id (tickets, security...), '' when the catalog does not list it. */
function eventRulesGroupOf(string $event): string
{
    $def = EventCatalog::get($event);

    return $def ? $def->group : '';
}

/** @return array{users:array<int,string>,clients:array<int,string>} */
function eventRulesNames($mysqli): array
{
    $users = [];
    $res = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $users[(int) $r['user_id']] = (string) $r['user_name'];
    }
    $clients = [];
    $res = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name LIMIT 500");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $clients[(int) $r['client_id']] = (string) $r['client_name'];
    }

    return ['users' => $users, 'clients' => $clients];
}

/** Values the condition editor offers as smart selects (free text stays possible). @return array<string,list<string>> */
function eventRulesValueLists($mysqli, array $names): array
{
    $statuses = [];
    $res = mysqli_query($mysqli, "SELECT ticket_status_name FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order, ticket_status_id");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $statuses[] = (string) $r['ticket_status_name'];
    }
    if (!$statuses) {
        $res = mysqli_query($mysqli, "SELECT ticket_status_name FROM ticket_statuses ORDER BY ticket_status_id");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $statuses[] = (string) $r['ticket_status_name'];
        }
    }
    $entity = [];
    $res = mysqli_query($mysqli, "SELECT DISTINCT entity_type FROM audit_events WHERE entity_type REGEXP '^[a-z0-9_.-]+$' ORDER BY entity_type LIMIT 100");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $entity[] = (string) $r['entity_type'];
    }

    return [
        'priority' => ['Low', 'Medium', 'High', 'Critical'],
        'status' => $statuses,
        'agents' => array_values($names['users']),
        'agent_ids' => array_map(static fn ($id, $n) => (string) $id . '|' . $n, array_keys($names['users']), array_values($names['users'])),
        'clients' => array_values($names['clients']),
        'client_ids' => array_map(static fn ($id, $n) => (string) $id . '|' . $n, array_keys($names['clients']), array_values($names['clients'])),
        'action' => ['create', 'update', 'delete', 'login', 'failed', 'ok', 'revealed', 'started', 'completed'],
        'entity_type' => $entity,
    ];
}

/**
 * Per-event payload field lists for the editor, deduplicated. {sets: [fields...], events: {eventId: setIndex}}
 * Every field: {p: path, t: type, d: description}.
 */
function eventRulesFieldCatalog(): array
{
    $sets = [];
    $index = [];
    $events = [];
    foreach (EventCatalog::all() as $e) {
        $fields = array_map(static fn ($f) => ['p' => $f['path'], 't' => $f['type'], 'd' => $f['description']], $e->payloadFields);
        $key = md5(json_encode($fields));
        if (!isset($index[$key])) {
            $index[$key] = count($sets);
            $sets[] = $fields;
        }
        $events[$e->id] = $index[$key];
    }

    return ['sets' => $sets, 'events' => $events];
}

/**
 * Validate a posted rule (the classic form's field names) and describe every problem per field. $checkUrl resolves the webhook
 * address against the URL policy (a DNS lookup), which live hints skip until asked.
 *
 * @return array{clean:array<string,mixed>, errors:array<string,string>}
 */
function eventRulesValidate($mysqli, array $in, bool $checkUrl = true): array
{
    $errors = [];
    $name = trim((string) ($in['rule_name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 200) {
        $errors['rule_name'] = 'Give the rule a name (up to 200 characters).';
    }
    $event = trim((string) ($in['trigger_event'] ?? ''));
    if (!preg_match('/^[a-z0-9_.]{1,150}$/', $event)) {
        $errors['trigger_event'] = 'Choose the event that starts this rule.';
    }
    $type = (string) ($in['action_type'] ?? '');
    if (!isset(AutomationRuleStore::ACTIONS[$type])) {
        $errors['action_type'] = 'Choose what the rule does.';
    }

    $conditions = [];
    $fields = is_array($in['cond_field'] ?? null) ? $in['cond_field'] : [];
    $values = is_array($in['cond_value'] ?? null) ? $in['cond_value'] : [];
    foreach ($fields as $i => $f) {
        $f = trim(is_scalar($f) ? (string) $f : '');
        if ($f === '') {
            continue;
        }
        $v = is_scalar($values[$i] ?? '') ? (string) ($values[$i] ?? '') : '';
        if (!preg_match('/^[A-Za-z0-9_.]{1,100}$/', $f)) {
            $errors['conditions'] = 'A condition field may only contain letters, digits, dots and underscores (up to 100 characters).';
        } elseif (mb_strlen($v) > 200) {
            $errors['conditions'] = 'A condition value is longer than 200 characters.';
        } elseif (array_key_exists($f, $conditions)) {
            $errors['conditions'] = 'The field "' . $f . '" is used by two conditions. A field can only be compared once per rule.';
        } else {
            $conditions[$f] = $v;
        }
    }

    $text = static fn (string $k, int $max): string => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
    $config = [];
    if ($type === 'create_ticket') {
        $config = ['subject' => $text('cfg_subject', 500), 'details' => $text('cfg_details', 5000), 'priority' => (string) ($in['cfg_priority'] ?? 'Low'), 'client_id' => max(0, (int) ($in['cfg_client_id'] ?? 0))];
        if ($config['subject'] === '') {
            $errors['cfg_subject'] = 'Enter the subject for the ticket that will be created.';
        }
        if (!in_array($config['priority'], ['Low', 'Medium', 'High'], true)) {
            $config['priority'] = 'Low';
        }
    } elseif ($type === 'send_webhook') {
        $config = ['url' => $text('cfg_url', 500), 'secret' => $text('cfg_secret', 200)];
        $parts = parse_url($config['url']);
        if (!$parts || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true) || empty($parts['host'])) {
            $errors['cfg_url'] = 'Enter a valid http(s) URL for the webhook.';
        } elseif ($checkUrl && !rivetWebhookUrlIsSafe($config['url'])) {
            $errors['cfg_url'] = 'This address is not allowed (' . rivetWebhookRuleText($mysqli) . ').';
        }
    } elseif ($type === 'notify_user') {
        $config = ['message' => $text('cfg_message', 1000), 'user_id' => 0];
        if ($config['message'] === '') {
            $errors['cfg_message'] = 'Enter the notification message.';
        }
    }

    return ['clean' => ['name' => $name, 'event' => $event, 'conditions' => $conditions, 'type' => $type, 'config' => $config, 'enabled' => !empty($in['is_enabled'])], 'errors' => $errors];
}

/**
 * Validate and store a rule, with the audit trail and the event record. @return array{ok:bool,id:?int,errors:array<string,string>}
 */
function eventRulesSave($mysqli, AutomationRuleStore $store, array $in, ?int $id, string $actorName, int $actorId): array
{
    $v = eventRulesValidate($mysqli, $in);
    if ($v['errors']) {
        return ['ok' => false, 'id' => null, 'errors' => $v['errors']];
    }
    $c = $v['clean'];
    try {
        $saved = $store->save($id ?: null, $c['name'], $c['event'], $c['conditions'], $c['type'], $c['config'], $c['enabled']);
    } catch (\InvalidArgumentException $e) {
        return ['ok' => false, 'id' => null, 'errors' => ['form' => $e->getMessage()]];
    }
    logAction('Automation', $id ? 'Edit' : 'Create', "$actorName " . ($id ? 'edited' : 'created') . " event rule $saved");
    rivetAudit($id ? 'automation.rule_updated' : 'automation.rule_created', $actorId, 'automation_rule', $saved, $id ? 'update' : 'create', 'Event rule ' . ($id ? 'updated' : 'created'));

    return ['ok' => true, 'id' => $saved, 'errors' => []];
}

/**
 * What each rule has done, from the audit trail ('automation.rule_fired' is written once per run, ok or failed).
 * Only runs are recorded: events that did not match a rule's conditions leave no trace.
 *
 * @return array<int,array{runs:int,last_at:string,ago:int,last_ok:bool,day:int,day_failed:int}>
 */
function eventRulesStats($mysqli): array
{
    $out = [];
    $res = @mysqli_query($mysqli, "SELECT entity_id, COUNT(*) AS runs, MAX(created_at) AS last_at, TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS ago,
        SUBSTRING_INDEX(GROUP_CONCAT(action ORDER BY created_at DESC, audit_id DESC), ',', 1) AS last_action,
        SUM(created_at >= NOW() - INTERVAL 24 HOUR) AS day, SUM(created_at >= NOW() - INTERVAL 24 HOUR AND action = 'failed') AS day_failed
        FROM audit_events WHERE event_type = 'automation.rule_fired' AND entity_type = 'automation_rule' GROUP BY entity_id");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $out[(int) $r['entity_id']] = ['runs' => (int) $r['runs'], 'last_at' => (string) $r['last_at'], 'ago' => max(0, (int) $r['ago']), 'last_ok' => $r['last_action'] === 'ok', 'day' => (int) $r['day'], 'day_failed' => (int) $r['day_failed']];
    }

    return $out;
}

/** @return list<array{audit_id:int,at:string,ago:int,ok:bool,event:string,message:string}> */
function eventRulesHistory($mysqli, int $ruleId, int $limit = 50): array
{
    $stmt = mysqli_prepare($mysqli, "SELECT audit_id, created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS ago, action, summary, metadata_json FROM audit_events
        WHERE event_type = 'automation.rule_fired' AND entity_type = 'automation_rule' AND entity_id = ? ORDER BY created_at DESC, audit_id DESC LIMIT $limit");
    $id = (string) $ruleId;
    mysqli_stmt_bind_param($stmt, 's', $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $runs = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $meta = json_decode((string) $r['metadata_json'], true);
        // The summary is "Rule 'name' on <event>: <result message>"; show only the result part when it parses.
        $message = (string) $r['summary'];
        if (preg_match("/^Rule '.*?' on [a-z0-9_.]+: (.*)$/su", $message, $m)) {
            $message = $m[1];
        }
        $runs[] = ['audit_id' => (int) $r['audit_id'], 'at' => (string) $r['created_at'], 'ago' => max(0, (int) $r['ago']), 'ok' => $r['action'] === 'ok', 'event' => (string) ($meta['event'] ?? ''), 'message' => $message];
    }

    return $runs;
}

/** Is this event's payload a ticket (ticket.* / sla.*) rather than an audit record? */
function eventRulesIsTicketShaped(string $event): bool
{
    $def = EventCatalog::get($event);
    if ($def) {
        return in_array('ticket_id', array_column($def->payloadFields, 'path'), true);
    }

    return in_array(explode('.', $event)[0], ['ticket', 'sla'], true);
}

/**
 * Recent real events a rule could be checked against, newest first. Ticket events come from the tickets table (the event stream
 * for them is not stored), every other event from the audit trail. Read-only. @return list<array{id:string,label:string,ago:int}>
 */
function eventRulesRecentEvents($mysqli, string $event, int $limit = 8): array
{
    $out = [];
    if (eventRulesIsTicketShaped($event)) {
        $res = mysqli_query($mysqli, "SELECT ticket_id, CONCAT(ticket_prefix, ticket_number) AS num, ticket_subject, TIMESTAMPDIFF(SECOND, ticket_created_at, NOW()) AS ago FROM tickets WHERE ticket_archived_at IS NULL ORDER BY ticket_id DESC LIMIT $limit");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $out[] = ['id' => 't' . (int) $r['ticket_id'], 'label' => $r['num'] . ' ' . mb_substr((string) $r['ticket_subject'], 0, 80), 'ago' => max(0, (int) $r['ago'])];
        }

        return $out;
    }
    $stmt = mysqli_prepare($mysqli, "SELECT audit_id, summary, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS ago FROM audit_events WHERE event_type = ? ORDER BY audit_id DESC LIMIT $limit");
    mysqli_stmt_bind_param($stmt, 's', $event);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $out[] = ['id' => 'a' . (int) $r['audit_id'], 'label' => mb_substr((string) ($r['summary'] ?: $event), 0, 100), 'ago' => max(0, (int) $r['ago'])];
    }

    return $out;
}

/**
 * The flat event context for a chosen source: 'sample' (RivetCore's sample payload), 't<id>' (a real ticket) or 'a<id>' (a recorded audit
 * event). Built exactly like rivetEmitEvent() builds the context a live rule sees. @return array{label:string,context:array<string,string>}|null
 */
function eventRulesContext($mysqli, string $event, string $source): ?array
{
    if ($source === 'sample' || $source === '') {
        $s = PayloadTemplate::sampleContext($event);

        return ['label' => 'Sample event', 'context' => EventContext::flatten($s['data'] + ['event' => $event])];
    }
    if (preg_match('/^t(\d+)$/', $source, $m) && eventRulesIsTicketShaped($event)) {
        $data = getWebhookTicketPayload((int) $m[1]);

        return count($data) > 1 ? ['label' => 'Ticket ' . ($data['ticket_number'] ?? $m[1]), 'context' => EventContext::flatten($data + ['event' => $event])] : null;
    }
    if (preg_match('/^a(\d+)$/', $source, $m) && !eventRulesIsTicketShaped($event)) {
        $stmt = mysqli_prepare($mysqli, "SELECT actor_user_id, entity_type, entity_id, action, summary, metadata_json FROM audit_events WHERE audit_id = ? AND event_type = ?");
        $aid = (int) $m[1];
        mysqli_stmt_bind_param($stmt, 'is', $aid, $event);
        mysqli_stmt_execute($stmt);
        $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$r) {
            return null;
        }
        $data = ['actor_user_id' => $r['actor_user_id'] === null ? null : (int) $r['actor_user_id'], 'entity_type' => $r['entity_type'], 'entity_id' => $r['entity_id'], 'action' => $r['action'], 'summary' => $r['summary'], 'metadata' => json_decode((string) $r['metadata_json'], true) ?: []];

        return ['label' => 'Recorded event #' . $aid, 'context' => EventContext::flatten($data + ['event' => $event])];
    }

    return null;
}

/** "5 minutes ago"-style text for the server-rendered list. */
function eventRulesAgo(int $seconds): string
{
    if ($seconds < 60) {
        return 'just now';
    }
    foreach ([[86400 * 30, 'month', 86400 * 30], [86400, 'day', 86400], [3600, 'hour', 3600], [60, 'minute', 60]] as [$min, $unit, $div]) {
        if ($seconds >= $min) {
            $n = intdiv($seconds, $div);

            return "$n $unit" . ($n === 1 ? '' : 's') . ' ago';
        }
    }

    return 'just now';
}
