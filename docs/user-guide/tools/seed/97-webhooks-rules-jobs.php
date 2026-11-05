<?php
/*
 * Demo data for the webhooks / event rules / job queue guide page (30b-webhooks-event-rules-and-jobs.md), Lakeshore Managed IT.
 * EXAMPLE DATA, NOT A LIVE CONNECTION: the sandbox cannot call outside services and webhooks only deliver to public https addresses,
 * so the three webhooks point at made-up ".example" hosts and the delivery log, the queued jobs and the event-rule history are rows the
 * delivery worker would have written. Nothing is sent anywhere.
 *
 *   RIVETIT_APP_DIR=/tmp/rivetmsp-user-guide-demo php docs/user-guide/tools/seed/97-webhooks-rules-jobs.php
 *
 * Adds: 3 webhooks (n8n, Slack, Generic JSON) with encrypted addresses/secrets, a few delivery-log rows, four jobs in the job queue
 * (one waiting for a retry, one that failed for good, two finished), and three event rules built from the stock recipes.
 * Idempotent: webhooks are matched by name, rules by name, jobs by a marker in the payload.
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "97-webhooks: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';
mysqli_report(MYSQLI_REPORT_OFF);
require_once $app . '/includes/inc_set_timezone.php';
require_once $app . '/includes/event_bus.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "97-webhooks: " . mysqli_error($mysqli) . "\n  $sql\n"); exit(1); } }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function e($v) { global $mysqli; return mysqli_real_escape_string($mysqli, (string) $v); }

// ---- webhooks --------------------------------------------------------------------------------------
$hooks = [
    // name, destination, format, url, secret, events, enabled
    ['Dispatch board (n8n)', 'n8n', 'json', 'https://automation.lakeshoremsp.example/webhook/rivet-tickets', 'demo-signing-secret-1', 'ticket.created,ticket.resolved,ticket.status_changed', 1],
    ['Ops chat: critical alerts', 'slack', 'slack', 'https://chat.lakeshoremsp.example/hooks/T0DEMO/B0DEMO/not-a-real-token', '', 'ticket.created,ticket.assigned', 1],
    ['Audit feed (generic JSON)', 'generic-json', 'json', 'https://logs.lakeshoremsp.example/ingest/rivetmsp', 'demo-signing-secret-3', 'auth.login_failed,vault.credential_revealed', 1],
];
$ids = [];
foreach ($hooks as [$name, $dest, $fmt, $url, $secret, $events, $on]) {
    $id = (int) one("SELECT webhook_id FROM webhooks WHERE webhook_name = '" . e($name) . "' LIMIT 1");
    if (!$id) {
        q("INSERT INTO webhooks SET webhook_name = '" . e($name) . "', webhook_url = '" . e(encryptSetting($url)) . "',
            webhook_secret = '" . e($secret === '' ? '' : encryptSetting($secret)) . "', webhook_destination = '" . e($dest) . "',
            webhook_format = '" . e($fmt) . "', webhook_method = 'POST', webhook_auth_mode = 'none', webhook_events = '" . e($events) . "',
            webhook_enabled = $on");
        $id = (int) mysqli_insert_id($mysqli);
    }
    $ids[$name] = $id;
}

// ---- delivery log (one row per attempt) ------------------------------------------------------------
$n8n = $ids['Dispatch board (n8n)'];
$chat = $ids['Ops chat: critical alerts'];
$deliveries = [
    [$n8n, 'ticket.created', 200, 184, 1, '{"event":"ticket.created","data":{"ticket_number":"TCK-48"}}', '{"status":"ok"}', 35],
    [$n8n, 'ticket.status_changed', 200, 143, 1, '{"event":"ticket.status_changed","data":{"ticket_number":"TCK-49","status":"Open"}}', '{"status":"ok"}', 190],
    [$n8n, 'ticket.resolved', 200, 201, 1, '{"event":"ticket.resolved","data":{"ticket_number":"TCK-31"}}', '{"status":"ok"}', 640],
    [$chat, 'ticket.created', 200, 96, 1, '{"text":"New ticket TCK-52: Front desk printer offline again"}', 'ok', 75],
    [$chat, 'ticket.assigned', null, 10012, 1, '{"text":"TCK-49 assigned to Devon Park"}', 'Connection timed out after 10 seconds', 120],
];
usort($deliveries, static fn ($a, $b) => $b[7] <=> $a[7]);   // oldest first, so the highest id is the newest attempt (the list's "last delivery" badge reads the highest id)
foreach ($deliveries as [$wid, $ev, $http, $ms, $att, $body, $resp, $mins]) {
    if (one("SELECT delivery_id FROM webhook_deliveries WHERE webhook_id = $wid AND event_type = '" . e($ev) . "' AND request_payload_json = '" . e($body) . "' LIMIT 1")) { continue; }
    q("INSERT INTO webhook_deliveries SET webhook_id = $wid, event_type = '" . e($ev) . "', http_status = " . ($http === null ? 'NULL' : $http) . ",
        duration_ms = $ms, attempt_number = $att, request_payload_json = '" . e($body) . "', response_body_snippet = '" . e($resp) . "',
        created_at = NOW() - INTERVAL $mins MINUTE");
}

// ---- job queue ---------------------------------------------------------------------------------------
$jobs = [
    // marker, type, status, attempts, max, available (SQL), completed (SQL), error, payload
    ['demo-job-1', 'webhook.deliver', 'pending', 2, 5, 'NOW() + INTERVAL 30 MINUTE', 'NULL', 'Connection timed out after 10 seconds', ['webhook_id' => $chat, 'event' => 'ticket.assigned']],
    ['demo-job-2', 'webhook.deliver', 'dead_letter', 5, 5, 'NOW() - INTERVAL 2 HOUR', 'NULL', 'HTTP 410 Gone from the endpoint', ['webhook_id' => $ids['Audit feed (generic JSON)'], 'event' => 'auth.login_failed']],
    ['demo-job-3', 'webhook.deliver', 'completed', 1, 5, 'NOW() - INTERVAL 35 MINUTE', 'NOW() - INTERVAL 35 MINUTE', null, ['webhook_id' => $n8n, 'event' => 'ticket.created']],
    ['demo-job-4', 'automation.action', 'completed', 1, 3, 'NOW() - INTERVAL 70 MINUTE', 'NOW() - INTERVAL 70 MINUTE', null, ['rule_id' => 0, 'event' => 'ticket.created']],
];
foreach ($jobs as [$marker, $type, $status, $att, $max, $avail, $done, $err, $payload]) {
    $payload['demo'] = $marker;
    $pj = json_encode($payload);
    if (one("SELECT job_id FROM integration_jobs WHERE payload LIKE '%" . e($marker) . "%' LIMIT 1")) { continue; }
    q("INSERT INTO integration_jobs SET job_type = '$type', resource_type = '" . ($type === 'webhook.deliver' ? 'webhook' : 'automation') . "', status = '$status',
        attempts = $att, max_attempts = $max, available_at = $avail, completed_at = $done, payload = '" . e($pj) . "',
        error = " . ($err === null ? 'NULL' : "'" . e($err) . "'") . ", created_at = NOW() - INTERVAL 3 HOUR");
}

// ---- event rules (built from the stock recipes; saved through the same store the editor uses) -------
if (class_exists(\RivetCore\Automation\AutomationRuleStore::class) && rivetTableExists($mysqli, 'automation_rules')) {
    $store = new \RivetCore\Automation\AutomationRuleStore(rivetCoreDb($mysqli));
    $rules = [
        ['Notify on Critical tickets', 'ticket.created', ['ticket_priority' => 'Critical'], 'notify_user', ['message' => 'Critical ticket {ticket_number}: {ticket_subject} ({client_name})'], true],
        ['Alert when a credential is revealed', 'vault.credential_revealed', [], 'notify_user', ['message' => '{summary}'], true],
        ['Ticket when offboarding starts', 'workflow.offboarding_started', [], 'create_ticket', ['subject' => 'Collect equipment and revoke access', 'details' => '{summary}', 'priority' => 'High', 'client_id' => 0], false],
    ];
    foreach ($rules as [$name, $event, $cond, $type, $cfg, $on]) {
        if (one("SELECT rule_id FROM automation_rules WHERE name = '" . e($name) . "' LIMIT 1")) { continue; }
        try { $store->save(null, $name, $event, $cond, $type, $cfg, $on); } catch (Throwable $t) { fwrite(STDERR, "97-webhooks: rule '$name': " . $t->getMessage() . "\n"); }
    }
}
echo "97-webhooks: done\n";
