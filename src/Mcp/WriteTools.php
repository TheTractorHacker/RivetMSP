<?php

declare(strict_types=1);

namespace RivetMSP\Mcp;

use RivetCore\Mcp\NotFoundException;
use RivetCore\Mcp\ToolPipeline;
use RivetMSP\Mcp\OAuth\OAuthConfig;

/**
 * Write-capable MCP tools, propose-then-approve. NOTHING in this class mutates tickets or any business data: each tool
 * validates its arguments, checks the caller's permission (module_support >= 2) and client scope, and records a pending
 * action (PendingActions::queue) for a human technician to approve in RivetMSP. The only table it writes is
 * mcp_pending_actions.
 *
 * Gates, in order, all fail closed:
 *  1. the feature switch (WriteConfig::enabled): off means every write tool is "Unknown tool" (NOT_FOUND), exactly as if it did not exist
 *  2. the token's own scopes must include mcp:write (mcp:read alone is PERMISSION_DENIED, audited as denied)
 *  3. ToolPipeline: rate limit, role check (module_support level 2), audit row mcp.tool_call
 *  4. the tool body: strict argument validation, then the target is resolved under the caller's client scope + AI opt-out
 *     (an out-of-scope id is NOT_FOUND, same as a nonexistent one, so ids cannot be enumerated)
 * Bearer calls have no session CSRF token; replay safety is the idempotency key (see PendingActions::queue).
 */
final class WriteTools
{
    private ?ToolPipeline $pipeline = null;

    public function __construct(private \mysqli $db)
    {
    }

    /** Definitions to advertise: empty unless the feature is on. */
    public static function definitionsFor(\mysqli $db): array
    {
        return WriteConfig::enabled($db) ? McpWriteActions::definitions() : [];
    }

    /**
     * @param array<string,mixed> $args
     * @param list<string> $scopes the token's scopes
     */
    public function call(string $name, array $args, ?int $userId, string $oauthClientId, array $scopes): array
    {
        if (!McpWriteActions::isWriteTool($name) || !WriteConfig::enabled($this->db)) {
            return ToolPipeline::envelope('req_' . bin2hex(random_bytes(8)), null, 'NOT_FOUND', "Unknown tool \"$name\".");
        }
        $pipeline = $this->pipeline ??= McpPipeline::make($this->db);
        $hasScope = in_array(OAuthConfig::SCOPE_WRITE, $scopes, true);
        $allow = fn (int $uid): bool => $hasScope && McpAccess::can($this->db, $uid, 'module_support', 2);

        if ($name === McpWriteActions::TOOL_STATUS) {
            return $pipeline->run($userId, $name, $this->safeArgs($args), $allow, function (int $uid) use ($args) {
                $id = $args['action_id'] ?? null;
                $row = is_int($id) ? (new PendingActions($this->db))->getForRequester($id, $uid) : null;
                if ($row === null) {
                    throw new NotFoundException();
                }

                return self::present($row, false);
            }, 'RivetMSP role or token scope');
        }

        $invalid = null;
        $envelope = $pipeline->run($userId, $name, $this->safeArgs($args), $allow, function (int $uid) use ($name, $args, $oauthClientId, &$invalid) {
            try {
                $v = McpWriteActions::validate($name, $args);
                $target = McpWriteActions::resolveTarget($this->db, $uid, $name, $v['args']);
                $queued = (new PendingActions($this->db))->queue($uid, $oauthClientId, $name, $v['args'], $target['client_id'],
                    $target['ticket'] ? (int) $target['ticket']['ticket_id'] : null, $v['key']);
            } catch (McpActionRefused $e) {
                $invalid = $e->getMessage();

                return null;
            }

            return self::present($queued['action'], $queued['replayed']);
        }, 'RivetMSP role or token scope');

        if ($invalid !== null && $envelope['success']) {
            return ToolPipeline::envelope($envelope['request_id'], null, 'INVALID_ARGUMENTS', $invalid);
        }

        return $envelope;
    }

    /** Arguments as they go into the audit row: scalars only, so a nested value cannot bloat or confuse the log. */
    private function safeArgs(array $args): array
    {
        return array_filter($args, static fn ($v) => is_scalar($v) || $v === null);
    }

    /** @return array<string,mixed> */
    private static function present(array $row, bool $replayed): array
    {
        $label = ['pending' => 'pending_approval'][$row['status']] ?? $row['status'];
        $out = ['action_id' => (int) $row['action_id'], 'status' => $label, 'tool' => $row['tool'], 'expires_at' => $row['expires_at'], 'replayed' => $replayed];
        if ($row['status'] === 'pending') {
            $out['message'] = $replayed
                ? 'This proposal is already waiting for human approval. Nothing has been changed.'
                : 'Queued for human approval. Nothing has been changed yet.';
        } elseif (in_array($row['status'], ['executed', 'failed', 'rejected'], true)) {
            $out['message'] = (string) ($row['result_message'] ?? '');
        }

        return $out;
    }
}
