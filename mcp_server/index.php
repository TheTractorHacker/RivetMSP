<?php

/**
 * Remote MCP resource server (read-only). Answers the two routes settings_mcp.php already advertises and that
 * the OAuth administration side (src/Mcp/OAuth/*, admin/settings_mcp.php) was built for: the protected-resource
 * metadata document and the MCP endpoint itself.
 *
 * v1 scope / what is intentionally NOT here yet. Write tools exist (GitHub issue #28) but only as PROPOSALS a technician must approve
 * (src/Mcp/WriteTools.php), off by default, and only for tokens granted mcp:write.
 *  - Only the built-in authorization server (RivetMSP as its own OAuth 2.1 AS, "Built-in sign-in" on
 *    settings_mcp.php) is accepted. An external identity provider's JWTs (the Issuer URL / Audience fields on
 *    the same page) are NOT validated by this file - that needs a JWKS-fetching JWT validator and discovery
 *    cache (RivetIT uses the mcp/sdk package's JwtTokenValidator + OidcDiscovery + JwksProvider for this, which
 *    RivetMSP does not vendor). Turning on Remote MCP with an external provider configured and built-in
 *    sign-in off currently means nothing answers at /mcp (same "configures but nothing answers yet" state the
 *    DB 2.6.130 migration note already described, now narrowed to "external provider only").
 *  - No SSE / resumable streaming: every response is a single JSON object (valid per the MCP Streamable HTTP
 *    transport spec, which allows a server to answer a POST with either `application/json` or
 *    `text/event-stream` - simple request/response tool calls do not need the stream). No session id is
 *    required between calls: every call re-authenticates from its own bearer token and every tool query is
 *    self-contained, so there is no server-side session state to lose.
 *  - JSON-RPC batch requests (an array of request objects) are not supported; one request per HTTP call.
 *
 * Multi-tenant isolation: EVERY row any tool can return is filtered inside RivetMSP\Mcp\ReadTools's own SQL by
 * (a) the caller's user_client_permissions, exactly like their own signed-in pages, and (b) clients.client_ai_opt_out,
 * which excludes an opted-out client from every tool's results for every caller. See ReadTools.php's class
 * doc. Every call (allowed, denied, not-found, or erroring) is written to `audit_events`
 * (event_type 'mcp.tool_call') by the shared RivetCore\Mcp\ToolPipeline before the response leaves this file.
 */

declare(strict_types=1);

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Protocol-Version');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$DOCUMENT_ROOT = realpath(__DIR__ . '/..');
$_SERVER['DOCUMENT_ROOT'] = $DOCUMENT_ROOT;

require_once $DOCUMENT_ROOT . '/config.php';
require_once $DOCUMENT_ROOT . '/includes/db.php';
require_once $DOCUMENT_ROOT . '/functions.php';
require_once $DOCUMENT_ROOT . '/includes/load_company_settings.php';
require_once $DOCUMENT_ROOT . '/includes/load_global_settings.php';
require_once $DOCUMENT_ROOT . '/includes/redis_functions.php';
require_once $DOCUMENT_ROOT . '/includes/app_version.php';
require_once $DOCUMENT_ROOT . '/vendor/autoload.php';

use RivetMSP\Mcp\BuiltinTokenAuth;
use RivetMSP\Mcp\McpConfig;
use RivetMSP\Mcp\McpWriteActions;
use RivetMSP\Mcp\OAuth\OAuthConfig;
use RivetMSP\Mcp\ReadTools;
use RivetMSP\Mcp\WriteConfig;
use RivetMSP\Mcp\WriteTools;

// Fail closed: the module switch (Administration > Settings > Remote MCP) must be on and
// RIVETMSP_MCP_ENABLED must not be 0.
$mcp_config = McpConfig::load($mysqli);
if (!$mcp_config['enabled']) {
    http_response_code(404);
    exit;
}

// v1 only answers for the built-in authorization server (see the file doc above). Without it, Remote MCP is
// "configured but nothing answers at /mcp" - the same state the DB 2.6.130 migration note already described.
$oauth_state = OAuthConfig::load($mysqli);
if (!$oauth_state['active']) {
    http_response_code(404);
    exit;
}

$resource = OAuthConfig::resource($config_base_url);
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

if ($path === '/.well-known/oauth-protected-resource') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode([
        'resource' => $resource,
        'authorization_servers' => [OAuthConfig::issuer($config_base_url)],
        'scopes_supported' => WriteConfig::enabled($mysqli) ? [OAuthConfig::SCOPE, OAuthConfig::SCOPE_WRITE] : [OAuthConfig::SCOPE],
        'bearer_methods_supported' => ['header'],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($path !== '/mcp') {
    http_response_code(404);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// Server-generated correlation id for this request; used by the tool envelope and the audit row. Never taken
// from a client header - a client-supplied X-Request-ID/Mcp-Request-Id is not a trust boundary we accept input on.
$request_id = 'req_' . bin2hex(random_bytes(8));
$_SERVER['RIVET_REQUEST_ID'] = $request_id;
header('X-Request-ID: ' . $request_id);
header('Content-Type: application/json');

/** Bearer -> resolved identity {user_id, scopes, client_id}, or null (the caller is told once, then every tool denies with the same envelope). */
function mcp_authenticated(\mysqli $mysqli, string $resource): ?array
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!is_string($auth) || !preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
        return null;
    }
    return (new BuiltinTokenAuth($mysqli, $resource))->validate(trim($m[1]));
}

function mcp_json_error(mixed $id, int $code, string $message): never
{
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input', length: 1_048_577);
if ($raw === false || strlen($raw) > 1_048_576) {
    http_response_code(413);
    exit;
}
try {
    $req = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
} catch (\Throwable) {
    mcp_json_error(null, -32700, 'Parse error');
}
if (!is_array($req) || array_is_list($req)) {
    // Batch requests are not supported in v1 (see file doc).
    mcp_json_error(null, -32600, 'Invalid Request');
}
$id = $req['id'] ?? null;
$method = is_string($req['method'] ?? null) ? $req['method'] : '';
$params = is_array($req['params'] ?? null) ? $req['params'] : [];
$isNotification = !array_key_exists('id', $req);

switch ($method) {
    case 'initialize':
        if ($isNotification) {
            http_response_code(202);
            exit;
        }
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'protocolVersion' => is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '2025-06-18',
            'capabilities' => ['tools' => (object) []],
            'serverInfo' => ['name' => defined('APP_NAME') ? APP_NAME : 'RivetMSP', 'version' => defined('APP_VERSION') ? APP_VERSION : '0.0.0'],
        ]], JSON_UNESCAPED_SLASHES);
        exit;

    case 'notifications/initialized':
    case 'notifications/cancelled':
        http_response_code(202);
        exit;

    case 'ping':
        if ($isNotification) {
            http_response_code(202);
            exit;
        }
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) []], JSON_UNESCAPED_SLASHES);
        exit;

    case 'tools/list':
        if ($isNotification) {
            http_response_code(202);
            exit;
        }
        // Write tools are advertised only while the feature is on AND this bearer was granted mcp:write; a read-only token
        // (or no token) sees the read tools alone.
        $listed = ReadTools::definitions();
        $who = mcp_authenticated($mysqli, $resource);
        if ($who !== null && in_array(OAuthConfig::SCOPE_WRITE, $who['scopes'], true)) {
            $listed = array_merge($listed, WriteTools::definitionsFor($mysqli));
        }
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $listed]], JSON_UNESCAPED_SLASHES);
        exit;

    case 'tools/call':
        if ($isNotification) {
            http_response_code(202);
            exit;
        }
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if ($name === '') {
            mcp_json_error($id, -32602, 'Invalid params: "name" is required');
        }
        // Authorization happens here, not earlier: every JSON-RPC method above (initialize, tools/list, ping)
        // is answerable without a resolved identity, but a tool call always goes through ToolPipeline with the
        // resolved user id (or null, which ToolPipeline turns into PERMISSION_DENIED) - never skipped.
        $who = mcp_authenticated($mysqli, $resource);
        $userId = $who['user_id'] ?? null;
        // Write tools (propose-then-approve) are a separate class with their own scope + feature gate; a read tool name never
        // reaches it and a write tool name never reaches ReadTools.
        $envelope = McpWriteActions::isWriteTool($name)
            ? (new WriteTools($mysqli))->call($name, $args, $userId, (string) ($who['client_id'] ?? ''), $who['scopes'] ?? [])
            : (new ReadTools($mysqli))->call($name, $args, $userId);
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'content' => [['type' => 'text', 'text' => json_encode($envelope['data'] ?? $envelope['errors'], JSON_UNESCAPED_SLASHES)]],
            'structuredContent' => $envelope,
            'isError' => !$envelope['success'],
        ]], JSON_UNESCAPED_SLASHES);
        exit;

    default:
        if ($isNotification) {
            http_response_code(202);
            exit;
        }
        mcp_json_error($id, -32601, "Method not found: $method");
}
