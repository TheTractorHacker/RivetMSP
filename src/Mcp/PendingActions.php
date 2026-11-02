<?php

declare(strict_types=1);

namespace RivetMSP\Mcp;

use RivetCore\Audit\AuditService;
use RivetCore\Mcp\NotFoundException;
use RivetMSP\Core\Adapter\Database\MysqliDatabaseAdapter;
use RivetMSP\Core\Adapter\Http\ServerRequestContext;

/**
 * The approval queue behind the MCP write tools (table mcp_pending_actions). An MCP write tool only ever calls queue();
 * a human calls approve()/reject() from agent/mcp_actions.php with their own session; the cron job calls expireStale().
 *
 * Lifecycle: pending -> approved (claimed, atomic) -> executed | failed;  pending -> rejected | expired.
 * The claim is a single conditional UPDATE, so two approvers (or a double click) can never run an action twice.
 * Approval re-evaluates, AS THE APPROVER, the same module-permission (level >= 2), client-scope and AI-opt-out checks the
 * queueing path used, and additionally requires the requester to still hold them.
 */
final class PendingActions
{
    public function __construct(private \mysqli $db)
    {
    }

    /* ------------------------------------------------------------------ queueing (called by MCP tools only) */

    /**
     * @param array<string,mixed> $args validated + normalised
     * @return array{action:array, replayed:bool}
     * @throws McpActionRefused
     */
    public function queue(int $userId, string $oauthClientId, string $tool, array $args, int $targetClientId, ?int $ticketId, ?string $clientKey): array
    {
        $hash = McpWriteActions::argsHash($tool, $args);

        if ($clientKey !== null) {
            $key = hash('sha256', "$userId|$oauthClientId|$tool|$clientKey");
            $existing = $this->byKey($key);
            if ($existing !== null) {
                if (!hash_equals((string) $existing['args_hash'], $hash)) {
                    throw new McpActionRefused('That idempotency_key was already used with different arguments.');
                }

                return ['action' => $existing, 'replayed' => true];
            }
        } else {
            // No key: an identical proposal that is still waiting is returned instead of queueing a duplicate.
            $dup = McpAccess::rows($this->db, "SELECT * FROM mcp_pending_actions WHERE requested_by = ? AND tool = ? AND args_hash = ? AND status = 'pending' AND expires_at > NOW() LIMIT 1",
                'iss', [$userId, $tool, $hash]);
            if ($dup !== []) {
                return ['action' => $dup[0], 'replayed' => true];
            }
            $key = hash('sha256', "$userId|$oauthClientId|$tool|auto|" . bin2hex(random_bytes(16)));
        }

        $open = McpAccess::rows($this->db, "SELECT COUNT(*) AS n FROM mcp_pending_actions WHERE requested_by = ? AND status = 'pending' AND expires_at > NOW()", 'i', [$userId]);
        if ((int) $open[0]['n'] >= WriteConfig::MAX_PENDING_PER_USER) {
            throw new McpActionRefused('Too many proposals are already waiting for approval. Ask a technician to review them first.');
        }

        $hours = WriteConfig::expiryHours($this->db);
        $json = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare("INSERT INTO mcp_pending_actions
            (requested_by, oauth_client_id, tool, args_json, args_hash, target_client_id, target_ticket_id, status, idempotency_key, created_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW() + INTERVAL ? HOUR)");
        $stmt->bind_param('issssiisi', $userId, $oauthClientId, $tool, $json, $hash, $targetClientId, $ticketId, $key, $hours);
        try {
            $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() !== 1062) {
                throw $e;
            }
            // Lost a race with an identical replay: return the winner.
            $won = $this->byKey($key);
            if ($won === null) {
                throw $e;
            }

            return ['action' => $won, 'replayed' => true];
        }
        $id = (int) $stmt->insert_id;
        $stmt->close();
        if ($id === 0) {
            $won = $this->byKey($key);
            if ($won !== null) {
                return ['action' => $won, 'replayed' => true];
            }
            throw new \RuntimeException('Could not queue the proposal.');
        }
        $this->audit('mcp.action_queued', $userId, $id, "MCP $tool proposed (pending approval)", ['tool' => $tool, 'client_id' => $targetClientId, 'ticket_id' => $ticketId, 'oauth_client' => $oauthClientId]);

        return ['action' => $this->get($id) ?? throw new \RuntimeException('Queued row vanished'), 'replayed' => false];
    }

    private function byKey(string $key): ?array
    {
        return McpAccess::rows($this->db, 'SELECT * FROM mcp_pending_actions WHERE idempotency_key = ?', 's', [$key])[0] ?? null;
    }

    public function get(int $id): ?array
    {
        return McpAccess::rows($this->db, 'SELECT a.*, (a.expires_at > NOW()) AS is_live, ru.user_name AS requester_name, du.user_name AS decider_name, c.client_name
            FROM mcp_pending_actions a
            LEFT JOIN users ru ON ru.user_id = a.requested_by
            LEFT JOIN users du ON du.user_id = a.decided_by
            LEFT JOIN clients c ON c.client_id = a.target_client_id
            WHERE a.action_id = ?', 'i', [$id])[0] ?? null;
    }

    /** A proposal as its own requester sees it through MCP (never anyone else's). */
    public function getForRequester(int $id, int $userId): ?array
    {
        $row = $this->get($id);

        return $row !== null && (int) $row['requested_by'] === $userId ? $row : null;
    }

    /* ------------------------------------------------------------------ the approvals page */

    /**
     * Actions the viewer may see: only those whose client is inside the viewer's own client scope.
     *
     * @return list<array>
     */
    public function listVisible(int $viewerId, string $filter = 'pending', int $limit = 100): array
    {
        $where = match ($filter) {
            'pending' => "a.status = 'pending' AND a.expires_at > NOW()",
            'done' => "a.status IN ('executed','failed','rejected','expired') OR (a.status = 'pending' AND a.expires_at <= NOW())",
            default => '1=1',
        };

        return McpAccess::rows($this->db, "SELECT a.*, (a.expires_at > NOW()) AS is_live, ru.user_name AS requester_name, du.user_name AS decider_name, c.client_name
            FROM mcp_pending_actions a
            LEFT JOIN users ru ON ru.user_id = a.requested_by
            LEFT JOIN users du ON du.user_id = a.decided_by
            LEFT JOIN clients c ON c.client_id = a.target_client_id
            WHERE ($where) AND " . McpAccess::clientScope('a.target_client_id') . ' ORDER BY a.action_id DESC LIMIT ?',
            'iii', [$viewerId, $viewerId, max(1, min(200, $limit))]);
    }

    /** One action, but only if the viewer's client scope covers it (otherwise null: indistinguishable from not existing). */
    public function getVisible(int $id, int $viewerId): ?array
    {
        return McpAccess::rows($this->db, 'SELECT a.*, (a.expires_at > NOW()) AS is_live, ru.user_name AS requester_name, du.user_name AS decider_name, c.client_name
            FROM mcp_pending_actions a
            LEFT JOIN users ru ON ru.user_id = a.requested_by
            LEFT JOIN users du ON du.user_id = a.decided_by
            LEFT JOIN clients c ON c.client_id = a.target_client_id
            WHERE a.action_id = ? AND ' . McpAccess::clientScope('a.target_client_id'), 'iii', [$id, $viewerId, $viewerId])[0] ?? null;
    }

    public function pendingCount(int $viewerId): int
    {
        try {
            return (int) McpAccess::rows($this->db, "SELECT COUNT(*) AS n FROM mcp_pending_actions a WHERE a.status = 'pending' AND a.expires_at > NOW() AND "
                . McpAccess::clientScope('a.target_client_id'), 'ii', [$viewerId, $viewerId])[0]['n'];
        } catch (\Throwable) {
            return 0;
        }
    }

    /* ------------------------------------------------------------------ decisions */

    /**
     * Can this user decide this action at all? Returns an error string, or null when they may.
     * The same checks gate approve and reject, except self-approval (approve only) and the feature switch (approve only).
     */
    private function deniedReason(array $row, int $deciderId): ?string
    {
        if (!McpAccess::can($this->db, $deciderId, 'module_support', 2)) {
            return 'Your role does not allow changing tickets.';
        }
        if (!McpAccess::mayTouchClient($this->db, $deciderId, (int) $row['target_client_id'])) {
            return 'Not found.';   // out of scope reads as nonexistent
        }

        return null;
    }

    /** @return array{ok:bool, message:string} */
    public function approve(int $id, int $approverId): array
    {
        $fail = static fn (string $m): array => ['ok' => false, 'message' => $m];
        if (!WriteConfig::enabled($this->db)) {
            return $fail('MCP write actions are switched off.');
        }
        $row = $this->getVisible($id, $approverId);
        if ($row === null) {
            return $fail('Not found.');
        }
        if (($why = $this->deniedReason($row, $approverId)) !== null) {
            return $fail($why);
        }
        if ((int) $row['requested_by'] === $approverId && !WriteConfig::selfApprove($this->db)) {
            $this->audit('mcp.action_denied', $approverId, $id, 'Self-approval refused', ['requested_by' => (int) $row['requested_by']]);

            return $fail('You cannot approve an action you requested yourself. Ask another technician (an administrator can allow self-approval in Remote MCP settings).');
        }
        if ($row['status'] !== 'pending') {
            return $fail('This action was already ' . $row['status'] . '.');
        }
        // The requester must still be allowed what they asked for (a revoked or re-scoped agent cannot have actions pushed through).
        $requester = (int) $row['requested_by'];
        if (!McpAccess::can($this->db, $requester, 'module_support', 2) || !McpAccess::mayTouchClient($this->db, $requester, (int) $row['target_client_id'])) {
            $this->audit('mcp.action_denied', $approverId, $id, 'Requester no longer permitted', ['requested_by' => $requester]);

            return $fail('The agent who requested this no longer has access to do it. Reject it instead.');
        }

        // Atomic claim: exactly one caller can move pending -> approved, and only while it has not expired.
        $stmt = $this->db->prepare("UPDATE mcp_pending_actions SET status = 'approved', decided_by = ?, decided_at = NOW() WHERE action_id = ? AND status = 'pending' AND expires_at > NOW()");
        $stmt->bind_param('ii', $approverId, $id);
        $stmt->execute();
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$claimed) {
            $this->expireStale();

            return $fail('This action has expired or was already decided.');
        }
        $this->audit('mcp.action_approved', $approverId, $id, "Approved MCP action #$id ({$row['tool']})", ['tool' => $row['tool'], 'requested_by' => $requester, 'client_id' => (int) $row['target_client_id']]);

        $args = json_decode((string) $row['args_json'], true) ?: [];
        $after = [];
        try {
            $this->db->begin_transaction();
            $out = McpWriteActions::execute($this->db, (string) $row['tool'], $args, $approverId, $requester);
            $json = json_encode($out['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $msg = mb_substr((string) $out['result']['message'], 0, 500);
            $stmt = $this->db->prepare("UPDATE mcp_pending_actions SET status = 'executed', executed_at = NOW(), result_json = ?, result_message = ? WHERE action_id = ? AND status = 'approved'");
            $stmt->bind_param('ssi', $json, $msg, $id);
            $stmt->execute();
            $stmt->close();
            $this->db->commit();
            $after = $out['after'];
        } catch (\Throwable $e) {
            try {
                $this->db->rollback();
            } catch (\Throwable) {
            }
            $msg = $e instanceof McpActionRefused ? $e->getMessage() : ($e instanceof NotFoundException ? 'The target is no longer available to you.' : 'Execution failed; see the server error log.');
            if (!($e instanceof McpActionRefused) && !($e instanceof NotFoundException)) {
                error_log('MCP action #' . $id . ' failed: ' . $e::class . ': ' . $e->getMessage());
            }
            $stmt = $this->db->prepare("UPDATE mcp_pending_actions SET status = 'failed', executed_at = NOW(), result_message = ? WHERE action_id = ?");
            $stmt->bind_param('si', $msg, $id);
            $stmt->execute();
            $stmt->close();
            $this->audit('mcp.action_failed', $approverId, $id, "MCP action #$id failed: $msg", ['tool' => $row['tool']]);

            return $fail("Approved, but it could not be applied: $msg");
        }
        foreach ($after as $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                error_log('MCP action #' . $id . ' post-commit step failed: ' . $e::class);
            }
        }
        $this->audit('mcp.action_executed', $approverId, $id, "Executed MCP action #$id ({$row['tool']})", ['tool' => $row['tool'], 'result' => $out['result']]);

        return ['ok' => true, 'message' => $msg];
    }

    /** @return array{ok:bool, message:string} */
    public function reject(int $id, int $deciderId, string $note = ''): array
    {
        $row = $this->getVisible($id, $deciderId);
        if ($row === null || ($why = $this->deniedReason($row, $deciderId)) !== null) {
            return ['ok' => false, 'message' => $row === null ? 'Not found.' : ($why ?? 'Not found.')];
        }
        $note = mb_substr(trim($note), 0, 255);
        $stmt = $this->db->prepare("UPDATE mcp_pending_actions SET status = 'rejected', decided_by = ?, decided_at = NOW(), result_message = ? WHERE action_id = ? AND status = 'pending'");
        $stmt->bind_param('isi', $deciderId, $note, $id);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$changed) {
            return ['ok' => false, 'message' => 'This action was already decided.'];
        }
        $this->audit('mcp.action_rejected', $deciderId, $id, "Rejected MCP action #$id ({$row['tool']})", ['tool' => $row['tool']]);

        return ['ok' => true, 'message' => 'Rejected. Nothing was changed.'];
    }

    /** Marks every overdue pending action expired. Safe to call from anywhere, any number of times. */
    public function expireStale(): int
    {
        $this->db->query("UPDATE mcp_pending_actions SET status = 'expired' WHERE status = 'pending' AND expires_at <= NOW()");
        $n = max(0, (int) $this->db->affected_rows);
        // A claim that never finished (process died between claim and result) must not sit "approved" forever.
        $this->db->query("UPDATE mcp_pending_actions SET status = 'failed', executed_at = NOW(), result_message = 'Interrupted before it finished; check the ticket before re-proposing.'
            WHERE status = 'approved' AND decided_at < NOW() - INTERVAL 15 MINUTE");
        if ($n > 0) {
            $this->audit('mcp.action_expired', null, 0, "$n pending MCP action(s) expired", ['count' => $n]);
        }

        return $n;
    }

    /** @param array<string,mixed> $meta */
    private function audit(string $event, ?int $actor, int $id, string $summary, array $meta = []): void
    {
        try {
            (new AuditService(new MysqliDatabaseAdapter($this->db), new ServerRequestContext()))
                ->log($event, $actor, 'mcp_pending_action', (string) $id, str_replace('mcp.action_', '', $event), $summary, $meta);
        } catch (\Throwable $e) {
            error_log('MCP action audit failed: ' . $e::class);
        }
    }
}
