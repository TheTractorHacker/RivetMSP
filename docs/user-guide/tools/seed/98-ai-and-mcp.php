<?php
/*
 * Demo data for the AI and Remote MCP guide page (30c-ai-and-remote-mcp.md), Lakeshore Managed IT.
 * EXAMPLE DATA, NOT A LIVE CONNECTION: there is no AI service and no MCP client in the sandbox. The AI provider points at a made-up
 * ".example" address with a placeholder key; the MCP connection, its audit rows and the waiting proposal are rows a real connection would write.
 *
 *   RIVETIT_APP_DIR=/tmp/rivetmsp-user-guide-demo php docs/user-guide/tools/seed/98-ai-and-mcp.php
 *
 * (The MCP tables store UTC; the settings page converts to local time, so those columns use UTC_TIMESTAMP().)
 * Adds: one AI provider with two models (General, Reply Draft); the Remote MCP switches (built-in sign-in on, app self-registration off,
 * write proposals on), one registered app, one consent (grant) for Devon Park, a few audit rows for MCP tool calls and one proposed ticket
 * note waiting for approval (Service Desk > AI actions). Idempotent. Depends on seed 31 for the ticket; skips the proposal if it is missing.
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "98-ai-and-mcp: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';
mysqli_report(MYSQLI_REPORT_OFF);
require_once $app . '/includes/inc_set_timezone.php';
require_once $app . '/includes/event_bus.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "98-ai-and-mcp: " . mysqli_error($mysqli) . "\n  $sql\n"); exit(1); } }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function e($v) { global $mysqli; return mysqli_real_escape_string($mysqli, (string) $v); }

// ---- AI ------------------------------------------------------------------------------------------
q("UPDATE settings SET config_ai_enable = 1, config_ai_max_input_chars = 12000, config_ai_timeout_seconds = 25 WHERE company_id = 1");
$pname = 'Lakeshore AI gateway (example)';
$pid = (int) one("SELECT ai_provider_id FROM ai_providers WHERE ai_provider_name = '" . e($pname) . "'");
if (!$pid) {
    q("INSERT INTO ai_providers SET ai_provider_name = '" . e($pname) . "', ai_provider_api_url = 'https://ai-gateway.lakeshoremsp.example/v1',
        ai_provider_api_key = '" . e(encryptSetting('example-key-not-real')) . "'");
    $pid = (int) mysqli_insert_id($mysqli);
}
$models = [
    ['example-chat-model', 'General', 'You are a helpful assistant for an IT service desk. Keep answers short and factual.'],
    ['example-chat-model', 'Reply Draft', 'Draft a polite, plain-language reply to the client. Do not promise a fix time.'],
];
foreach ($models as [$m, $use, $prompt]) {
    if (!one("SELECT ai_model_id FROM ai_models WHERE ai_model_ai_provider_id = $pid AND ai_model_use_case = '" . e($use) . "'")) {
        q("INSERT INTO ai_models SET ai_model_name = '" . e($m) . "', ai_model_use_case = '" . e($use) . "', ai_model_prompt = '" . e($prompt) . "', ai_model_ai_provider_id = $pid");
    }
}

// ---- Remote MCP ----------------------------------------------------------------------------------
$oc = [['builtin_enabled', '1'], ['registration_enabled', '0'], ['write_enabled', '1'], ['write_self_approve', '0'], ['write_expiry_hours', '24']];
foreach ($oc as [$k, $v]) {
    q("INSERT INTO mcp_oauth_config (setting_key, setting_value) VALUES ('" . e($k) . "', '" . e($v) . "') ON DUPLICATE KEY UPDATE setting_value = setting_value");
}
$devon = (int) one("SELECT user_id FROM users WHERE user_email = 'devon.park@lakeshoremsp.example'");
$morgan = (int) one("SELECT user_id FROM users WHERE user_email = 'morgan.reyes@lakeshoremsp.example'");
$cid = 'demo-claude-connector';
if (!one("SELECT client_id FROM mcp_oauth_clients WHERE client_id = '$cid'")) {
    q("INSERT INTO mcp_oauth_clients SET client_id = '$cid', client_name = 'Claude (example connector)', redirect_uris = '" . e(json_encode(['https://claude.example/api/mcp/auth_callback'])) . "',
        registration_type = 'manual', created_by_user_id = $morgan, created_at = UTC_TIMESTAMP() - INTERVAL 1 DAY, last_used_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR");
}
if ($devon && !one("SELECT grant_id FROM mcp_oauth_grants WHERE client_id = '$cid' AND user_id = $devon")) {
    q("INSERT INTO mcp_oauth_grants SET client_id = '$cid', user_id = $devon, scope = 'mcp:read mcp:write', resource = 'https://rivet.lakeshoremsp.example/mcp',
        origin_code_hash = UNHEX(SHA2('lakeshore-demo-grant-1', 256)), created_at = UTC_TIMESTAMP() - INTERVAL 1 DAY, last_used_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR, expires_at = UTC_TIMESTAMP() + INTERVAL 150 DAY");
}
// audit rows (written through the app's hash-chained audit service so the chain stays valid)
if ($devon && !one("SELECT audit_id FROM audit_events WHERE event_type = 'mcp.tool_call' LIMIT 1")) {
    rivetAudit('mcp.tool_call', $devon, 'mcp_tool', 'rivetmsp_search_tickets', 'call', 'rivetmsp_search_tickets: allowed (3 results)');
    rivetAudit('mcp.tool_call', $devon, 'mcp_tool', 'rivetmsp_get_ticket', 'call', 'rivetmsp_get_ticket: allowed');
    rivetAudit('mcp.tool_call', $devon, 'mcp_tool', 'rivetmsp_get_asset', 'call', 'rivetmsp_get_asset: not found');
    rivetAudit('mcp.tool_call', $devon, 'mcp_tool', 'rivetmsp_propose_ticket_reply', 'call', 'rivetmsp_propose_ticket_reply: queued for approval');
}
// one proposed internal note waiting for a second technician
$tid = (int) one("SELECT ticket_id FROM tickets WHERE ticket_subject = 'Backup job failed overnight' ORDER BY ticket_id LIMIT 1");
$tclient = $tid ? (int) one("SELECT ticket_client_id FROM tickets WHERE ticket_id = $tid") : 0;
if ($devon && $tid && !one("SELECT action_id FROM mcp_pending_actions WHERE target_ticket_id = $tid AND oauth_client_id = '$cid' LIMIT 1")) {
    $args = ['ticket_id' => $tid, 'body' => 'Checked the job history: the last three runs failed on the same disk-full error. Suggest clearing the staging folder before the next scheduled run.', 'visibility' => 'internal'];
    try {
        (new \RivetMSP\Mcp\PendingActions($mysqli))->queue($devon, $cid, \RivetMSP\Mcp\McpWriteActions::TOOL_REPLY, $args, $tclient, $tid, 'demo-proposal-1');
    } catch (Throwable $t) {
        fwrite(STDERR, "98-ai-and-mcp: proposal not queued: " . $t->getMessage() . "\n");
    }
}
echo "98-ai-and-mcp: done\n";
