<?php

declare(strict_types=1);

namespace RivetMSP\Mcp;

use RivetCore\Mcp\NotFoundException;

/**
 * The catalogue of write actions an MCP client may PROPOSE (never perform). One place for each action's strict argument
 * schema, the human-readable "what will change" description, and the executor that runs only after a technician approved it.
 *
 * v1 is deliberately small and reversible: add a ticket reply or internal note, change a ticket's status / priority / assignee,
 * create a ticket. Nothing here touches credentials, invoices, billing, users, or deletes anything. Closing/resolving a ticket
 * is not offered (it triggers client e-mail and CSAT flows); reopening a closed ticket is not offered either.
 *
 * Every function that reads a ticket does so under the CALLING USER's own client scope + the AI opt-out (McpAccess), so the
 * same code path serves the requester at queue time and the approver at approval time, each with their own identity.
 */
final class McpWriteActions
{
    public const TOOL_REPLY = 'rivetmsp_propose_ticket_reply';
    public const TOOL_UPDATE = 'rivetmsp_propose_ticket_update';
    public const TOOL_CREATE = 'rivetmsp_propose_create_ticket';
    public const TOOL_STATUS = 'rivetmsp_get_proposal_status';

    public const PRIORITIES = ['Low', 'Medium', 'High'];
    public const MAX_TEXT = 10000;
    private const KEY_RE = '/^[A-Za-z0-9_.:-]{8,64}$/D';

    /** @return list<string> tool names that queue an action (as opposed to reading proposal status) */
    public static function proposeTools(): array
    {
        return [self::TOOL_REPLY, self::TOOL_UPDATE, self::TOOL_CREATE];
    }

    public static function isWriteTool(string $name): bool
    {
        return in_array($name, [...self::proposeTools(), self::TOOL_STATUS], true);
    }

    /** @return list<array{name:string,description:string,inputSchema:array<string,mixed>}> */
    public static function definitions(): array
    {
        $key = ['type' => 'string', 'description' => 'Optional. A unique id for this proposal (8-64 chars: letters, digits, _ . : -). Re-sending the same key returns the original proposal instead of queueing a second one.', 'minLength' => 8, 'maxLength' => 64];
        $note = ' This only QUEUES a proposal. Nothing is changed until a human technician approves it in RivetMSP; the call returns status "pending_approval".';

        return [
            ['name' => self::TOOL_REPLY, 'description' => 'Propose a reply or internal note on an open ticket.' . $note,
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'ticket_id' => ['type' => 'integer', 'minimum' => 1],
                    'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_TEXT, 'description' => 'Plain text (no HTML).'],
                    'visibility' => ['type' => 'string', 'enum' => ['internal', 'public'], 'description' => 'internal (default) is a private note; public is visible to the client in the portal.'],
                    'idempotency_key' => $key,
                ], 'required' => ['ticket_id', 'body'], 'additionalProperties' => false]],
            ['name' => self::TOOL_UPDATE, 'description' => 'Propose changing an open ticket\'s status, priority and/or assignee. Closing or resolving is not available.' . $note,
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'ticket_id' => ['type' => 'integer', 'minimum' => 1],
                    'status' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Name of an active, non-closing ticket status, for example "Open" or "On Hold".'],
                    'priority' => ['type' => 'string', 'enum' => self::PRIORITIES],
                    'assigned_to' => ['type' => 'integer', 'minimum' => 0, 'description' => 'User id of an active agent, or 0 to unassign.'],
                    'idempotency_key' => $key,
                ], 'required' => ['ticket_id'], 'additionalProperties' => false]],
            ['name' => self::TOOL_CREATE, 'description' => 'Propose creating a new ticket for a client.' . $note,
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'client_id' => ['type' => 'integer', 'minimum' => 1],
                    'subject' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'details' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_TEXT, 'description' => 'Plain text (no HTML).'],
                    'priority' => ['type' => 'string', 'enum' => self::PRIORITIES, 'description' => 'Default Low.'],
                    'contact_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional contact of the same client.'],
                    'asset_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional asset of the same client.'],
                    'idempotency_key' => $key,
                ], 'required' => ['client_id', 'subject', 'details'], 'additionalProperties' => false]],
            ['name' => self::TOOL_STATUS, 'description' => 'Check the status of a proposal you queued (pending_approval, approved, rejected, expired, executed, failed).',
                'inputSchema' => ['type' => 'object', 'properties' => ['action_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['action_id'], 'additionalProperties' => false]],
        ];
    }

    /* ------------------------------------------------------------------ strict validation (pure: no database) */

    /**
     * @param array<string,mixed> $args raw tool arguments
     * @return array{args:array<string,mixed>, key:?string} normalised arguments (what is stored and later executed) and the optional client idempotency key
     * @throws McpActionRefused
     */
    public static function validate(string $tool, array $args): array
    {
        $key = null;
        if (array_key_exists('idempotency_key', $args)) {
            if (!is_string($args['idempotency_key']) || !preg_match(self::KEY_RE, $args['idempotency_key'])) {
                throw new McpActionRefused('idempotency_key must be 8-64 characters: letters, digits, _ . : -');
            }
            $key = $args['idempotency_key'];
            unset($args['idempotency_key']);
        }
        $out = match ($tool) {
            self::TOOL_REPLY => self::validateReply($args),
            self::TOOL_UPDATE => self::validateUpdate($args),
            self::TOOL_CREATE => self::validateCreate($args),
            default => throw new McpActionRefused('Unknown action.'),
        };

        return ['args' => $out, 'key' => $key];
    }

    private static function only(array $args, array $allowed): void
    {
        $extra = array_diff(array_keys($args), $allowed);
        if ($extra !== []) {
            throw new McpActionRefused('Unexpected argument: ' . mb_substr((string) reset($extra), 0, 40));
        }
    }

    private static function posInt(array $args, string $name, bool $required, int $min = 1): ?int
    {
        if (!array_key_exists($name, $args)) {
            if ($required) {
                throw new McpActionRefused("$name is required.");
            }

            return null;
        }
        $v = $args[$name];
        if (!is_int($v) || $v < $min || $v > 2147483647) {
            throw new McpActionRefused("$name must be an integer" . ($min > 0 ? ' >= ' . $min : '') . '.');
        }

        return $v;
    }

    private static function text(array $args, string $name, int $max, bool $required = true): ?string
    {
        if (!array_key_exists($name, $args)) {
            if ($required) {
                throw new McpActionRefused("$name is required.");
            }

            return null;
        }
        $v = $args[$name];
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8') || str_contains($v, "\0")) {
            throw new McpActionRefused("$name must be plain text.");
        }
        $v = trim(str_replace("\r\n", "\n", $v));
        if ($v === '' || mb_strlen($v) > $max) {
            throw new McpActionRefused("$name must be 1-$max characters.");
        }

        return $v;
    }

    private static function validateReply(array $a): array
    {
        self::only($a, ['ticket_id', 'body', 'visibility']);
        $vis = $a['visibility'] ?? 'internal';
        if (!in_array($vis, ['internal', 'public'], true)) {
            throw new McpActionRefused('visibility must be "internal" or "public".');
        }

        return ['ticket_id' => self::posInt($a, 'ticket_id', true), 'body' => self::text($a, 'body', self::MAX_TEXT), 'visibility' => $vis];
    }

    private static function validateUpdate(array $a): array
    {
        self::only($a, ['ticket_id', 'status', 'priority', 'assigned_to']);
        $out = ['ticket_id' => self::posInt($a, 'ticket_id', true)];
        if (array_key_exists('status', $a)) {
            $out['status'] = self::text($a, 'status', 100);
        }
        if (array_key_exists('priority', $a)) {
            $p = is_string($a['priority']) ? ucfirst(strtolower($a['priority'])) : null;
            if (!in_array($p, self::PRIORITIES, true)) {
                throw new McpActionRefused('priority must be Low, Medium or High.');
            }
            $out['priority'] = $p;
        }
        if (array_key_exists('assigned_to', $a)) {
            $out['assigned_to'] = self::posInt($a, 'assigned_to', true, 0);
        }
        if (count($out) < 2) {
            throw new McpActionRefused('Provide at least one of status, priority, assigned_to.');
        }

        return $out;
    }

    private static function validateCreate(array $a): array
    {
        self::only($a, ['client_id', 'subject', 'details', 'priority', 'contact_id', 'asset_id']);
        $subject = trim(strip_tags((string) self::text($a, 'subject', 200)));
        if ($subject === '') {
            throw new McpActionRefused('subject must be plain text.');
        }
        $p = 'Low';
        if (array_key_exists('priority', $a)) {
            $p = is_string($a['priority']) ? ucfirst(strtolower($a['priority'])) : '';
            if (!in_array($p, self::PRIORITIES, true)) {
                throw new McpActionRefused('priority must be Low, Medium or High.');
            }
        }
        $out = ['client_id' => self::posInt($a, 'client_id', true), 'subject' => $subject,
            'details' => self::text($a, 'details', self::MAX_TEXT), 'priority' => $p];
        foreach (['contact_id', 'asset_id'] as $opt) {
            if (($v = self::posInt($a, $opt, false)) !== null) {
                $out[$opt] = $v;
            }
        }

        return $out;
    }

    /** Canonical hash of the validated arguments (key order independent). */
    public static function argsHash(string $tool, array $args): string
    {
        ksort($args);

        return hash('sha256', $tool . "\n" . json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /* ------------------------------------------------------------------ state-dependent resolution (as a given user) */

    /**
     * The client a proposal targets, resolved UNDER $userId's client scope and the AI opt-out. A ticket or client that does not
     * exist and one the user may not touch are indistinguishable (NotFoundException), so IDs cannot be enumerated.
     * Also re-checks the state the action needs (ticket still open, referenced rows valid). Used at queue time (requester) and
     * again at approval time (approver).
     *
     * @return array{client_id:int, ticket:?array}
     * @throws NotFoundException|McpActionRefused
     */
    public static function resolveTarget(\mysqli $db, int $userId, string $tool, array $args): array
    {
        if ($tool === self::TOOL_CREATE) {
            $clientId = (int) $args['client_id'];
            if (!McpAccess::mayTouchClient($db, $userId, $clientId)) {
                throw new NotFoundException();
            }
            foreach (['contact_id' => ['contacts', 'contact_id', 'contact_client_id', 'contact_archived_at'], 'asset_id' => ['assets', 'asset_id', 'asset_client_id', 'asset_archived_at']] as $arg => [$tbl, $idc, $cc, $arc]) {
                if (isset($args[$arg]) && McpAccess::rows($db, "SELECT $idc FROM $tbl WHERE $idc = ? AND $cc = ? AND $arc IS NULL", 'ii', [(int) $args[$arg], $clientId]) === []) {
                    throw new McpActionRefused("$arg does not belong to this client.");
                }
            }

            return ['client_id' => $clientId, 'ticket' => null];
        }

        $rows = McpAccess::rows($db, 'SELECT t.ticket_id, t.ticket_client_id, t.ticket_status, t.ticket_priority, t.ticket_assigned_to, t.ticket_closed_at,
                CONCAT(IFNULL(t.ticket_prefix, \'\'), t.ticket_number) AS ticket_number, t.ticket_subject, s.ticket_status_name,
                c.client_name, au.user_name AS assigned_name
            FROM tickets t JOIN clients c ON c.client_id = t.ticket_client_id
            LEFT JOIN ticket_statuses s ON s.ticket_status_id = t.ticket_status
            LEFT JOIN users au ON au.user_id = t.ticket_assigned_to
            WHERE t.ticket_id = ? AND t.ticket_archived_at IS NULL AND ' . McpAccess::clientScope('t.ticket_client_id') . ' AND ' . McpAccess::aiOptOutExcluded(),
            'iii', [(int) $args['ticket_id'], $userId, $userId]);
        if ($rows === []) {
            throw new NotFoundException();
        }
        $t = $rows[0];
        if ($t['ticket_closed_at'] !== null) {
            throw new McpActionRefused('This ticket is closed; closed tickets cannot be changed through MCP.');
        }
        if ($tool === self::TOOL_UPDATE) {
            if (isset($args['status'])) {
                self::resolveStatus($db, (string) $args['status']);
            }
            if (isset($args['assigned_to']) && (int) $args['assigned_to'] > 0 && self::activeAgentName($db, (int) $args['assigned_to']) === null) {
                throw new McpActionRefused('assigned_to is not an active agent.');
            }
        }

        return ['client_id' => (int) $t['ticket_client_id'], 'ticket' => $t];
    }

    /** @return array{id:int,name:string} an active status that is not a closing one */
    private static function resolveStatus(\mysqli $db, string $name): array
    {
        $r = McpAccess::rows($db, 'SELECT ticket_status_id, ticket_status_name FROM ticket_statuses WHERE ticket_status_name = ? AND ticket_status_active = 1 LIMIT 1', 's', [$name]);
        if ($r === []) {
            throw new McpActionRefused('Unknown or inactive ticket status.');
        }
        $id = (int) $r[0]['ticket_status_id'];
        if (in_array($id, [4, 5], true) || in_array(strtolower((string) $r[0]['ticket_status_name']), ['closed', 'resolved'], true)) {
            throw new McpActionRefused('Closing or resolving a ticket is not available through MCP.');
        }

        return ['id' => $id, 'name' => (string) $r[0]['ticket_status_name']];
    }

    private static function activeAgentName(\mysqli $db, int $userId): ?string
    {
        $r = McpAccess::rows($db, 'SELECT user_name FROM users WHERE user_id = ? AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL', 'i', [$userId]);

        return $r === [] ? null : (string) $r[0]['user_name'];
    }

    /* ------------------------------------------------------------------ description for the approver */

    /**
     * What approving would do, from the CURRENT database state, as plain text for the approver to read before deciding.
     *
     * @return array{summary:string, lines:list<array{0:string,1:string}>}
     */
    public static function describe(\mysqli $db, int $viewerId, string $tool, array $args): array
    {
        try {
            $target = self::resolveTarget($db, $viewerId, $tool, $args);
        } catch (\Throwable $e) {
            return ['summary' => 'This action can no longer be applied: ' . ($e instanceof McpActionRefused ? $e->getMessage() : 'the target is not available to you.'), 'lines' => []];
        }
        $t = $target['ticket'];
        $tk = $t ? $t['ticket_number'] . ' - ' . $t['ticket_subject'] . ' (' . $t['client_name'] . ')' : '';
        switch ($tool) {
            case self::TOOL_REPLY:
                return ['summary' => 'Add ' . ($args['visibility'] === 'public' ? 'a PUBLIC reply (visible to the client)' : 'an internal note') . ' to ticket ' . $tk,
                    'lines' => [['Ticket', $tk], ['Visibility', $args['visibility'] === 'public' ? 'Public - visible to the client in the portal (no e-mail is sent)' : 'Internal note'], ['Text', (string) $args['body']]]];
            case self::TOOL_UPDATE:
                $lines = [['Ticket', $tk]];
                if (isset($args['status'])) {
                    $lines[] = ['Status', ($t['ticket_status_name'] ?? '?') . ' -> ' . $args['status']];
                }
                if (isset($args['priority'])) {
                    $lines[] = ['Priority', ($t['ticket_priority'] ?? '?') . ' -> ' . $args['priority']];
                }
                if (isset($args['assigned_to'])) {
                    $to = (int) $args['assigned_to'] === 0 ? 'Unassigned' : (self::activeAgentName($db, (int) $args['assigned_to']) ?? '?');
                    $lines[] = ['Assignee', ($t['assigned_name'] ?? 'Unassigned') . ' -> ' . $to];
                }
                return ['summary' => 'Change ticket ' . $tk, 'lines' => $lines];
            case self::TOOL_CREATE:
                $c = McpAccess::rows($db, 'SELECT client_name FROM clients WHERE client_id = ?', 'i', [(int) $args['client_id']]);
                $lines = [['Client', (string) ($c[0]['client_name'] ?? '?')], ['Subject', (string) $args['subject']], ['Priority', (string) $args['priority']], ['Details', (string) $args['details']]];
                foreach (['contact_id' => 'Contact id', 'asset_id' => 'Asset id'] as $k => $label) {
                    if (isset($args[$k])) {
                        $lines[] = [$label, (string) $args[$k]];
                    }
                }
                return ['summary' => 'Create a new ticket', 'lines' => $lines];
        }

        return ['summary' => 'Unknown action', 'lines' => []];
    }

    /* ------------------------------------------------------------------ execution (after approval only) */

    private static function html(string $plain): string
    {
        return '<p>' . nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
    }

    private static function exec(\mysqli $db, string $sql, string $types, array $params): int
    {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('MCP write failed to prepare');
        }
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            throw new \RuntimeException('MCP write failed: ' . $stmt->error);
        }
        $id = $stmt->insert_id;
        $stmt->close();

        return (int) $id;
    }

    private static function loadHelpers(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['includes/sla_functions.php', 'includes/ticket_category_rules.php', 'includes/ticket_automation_dispatch.php', 'includes/redis_functions.php'] as $f) {
            require_once "$root/$f";
        }
    }

    /**
     * Apply an approved action AS $approverId. The caller (PendingActions) has already re-checked permission and scope and
     * wraps this in a transaction; everything that must not be rolled back (Redis pushes, webhooks, notifications) is returned as
     * closures to run after commit.
     *
     * @return array{result:array<string,mixed>, after:list<callable>}
     * @throws NotFoundException|McpActionRefused
     */
    public static function execute(\mysqli $db, string $tool, array $args, int $approverId, int $requesterId): array
    {
        self::loadHelpers();
        $target = self::resolveTarget($db, $approverId, $tool, $args);
        $approver = self::activeAgentName($db, $approverId) ?? 'an approver';
        $requester = self::activeAgentName($db, $requesterId) ?? 'unknown';
        $via = mb_substr("via MCP, requested by $requester, approved by $approver", 0, 150);
        $after = [];

        if ($tool === self::TOOL_CREATE) {
            return self::executeCreate($db, $args, $approverId, $target['client_id'], $via);
        }

        $t = $target['ticket'];
        $ticketId = (int) $t['ticket_id'];
        $clientId = (int) $t['ticket_client_id'];
        $number = (string) $t['ticket_number'];
        $statusName = (string) ($t['ticket_status_name'] ?? '');

        if ($tool === self::TOOL_REPLY) {
            $type = $args['visibility'] === 'public' ? 'Public' : 'Internal';
            $replyId = self::exec($db, "INSERT INTO ticket_replies SET ticket_reply = ?, ticket_reply_type = ?, ticket_reply_time_worked = '00:00:00', ticket_reply_by = ?, ticket_reply_ticket_id = ?, ticket_reply_emailed = 0",
                'ssii', [self::html((string) $args['body']), $type, $approverId, $ticketId]);
            self::exec($db, 'UPDATE tickets SET ticket_updated_at = NOW() WHERE ticket_id = ?', 'i', [$ticketId]);
            self::exec($db, 'INSERT INTO ticket_history SET ticket_history_status = ?, ticket_history_description = ?, ticket_history_ticket_id = ?',
                'ssi', [$statusName, mb_substr("$type reply added ($via)", 0, 255), $ticketId]);
            $log = "$approver added a $type reply to $number ($via)";
            $result = ['ticket_id' => $ticketId, 'reply_id' => $replyId, 'message' => "$type reply added to $number."];
        } else {
            $changes = [];
            $curStatus = (int) $t['ticket_status'];   // tracked across the steps below: a later step must not overwrite an earlier one
            if (isset($args['status']) && ($new = self::resolveStatus($db, (string) $args['status'])) && $new['id'] !== $curStatus) {
                self::exec($db, 'UPDATE tickets SET ticket_status = ? WHERE ticket_id = ?', 'ii', [$new['id'], $ticketId]);
                slaAccruePause($db, $ticketId, $curStatus, $new['id']);
                $curStatus = $new['id'];
                self::exec($db, 'INSERT INTO ticket_history SET ticket_history_status = ?, ticket_history_description = ?, ticket_history_ticket_id = ?',
                    'ssi', [$new['name'], mb_substr("$approver changed status from $statusName to {$new['name']} ($via)", 0, 255), $ticketId]);
                $changes[] = "status $statusName -> {$new['name']}";
                $after[] = static function () use ($ticketId, $new, $approver): void {
                    publishTicketEvent($ticketId, 'status', ['status_id' => $new['id'], 'status_name' => $new['name'], 'by' => $approver]);
                    queueWebhookEvent('ticket.status_changed', getWebhookTicketPayload($ticketId));
                };
            }
            if (isset($args['priority']) && $args['priority'] !== (string) $t['ticket_priority']) {
                self::exec($db, 'UPDATE tickets SET ticket_priority = ? WHERE ticket_id = ?', 'si', [$args['priority'], $ticketId]);
                recalculateTicketSla($db, $ticketId);
                self::exec($db, 'INSERT INTO ticket_history SET ticket_history_status = ?, ticket_history_description = ?, ticket_history_ticket_id = ?',
                    'ssi', [$statusName, mb_substr("$approver changed priority from {$t['ticket_priority']} to {$args['priority']} ($via)", 0, 255), $ticketId]);
                $changes[] = "priority {$t['ticket_priority']} -> {$args['priority']}";
            }
            if (isset($args['assigned_to']) && (int) $args['assigned_to'] !== (int) $t['ticket_assigned_to']) {
                $to = (int) $args['assigned_to'];
                $name = $to === 0 ? 'No One' : (string) self::activeAgentName($db, $to);
                // Same as the UI's quick-assign: a New ticket that gets an owner becomes Open.
                $newStatus = ($curStatus === 1 && $to !== 0) ? 2 : $curStatus;
                self::exec($db, 'UPDATE tickets SET ticket_assigned_to = ?, ticket_status = ? WHERE ticket_id = ?', 'iii', [$to, $newStatus, $ticketId]);
                self::exec($db, "INSERT INTO ticket_replies SET ticket_reply = ?, ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply_by = ?, ticket_reply_ticket_id = ?",
                    'sii', [$to === 0 ? 'Ticket unassigned.' : 'Ticket re-assigned to ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '.', $approverId, $ticketId]);
                $changes[] = 'assignee -> ' . $name;
                if ($to !== 0 && $to !== $approverId) {
                    $subject = (string) $t['ticket_subject'];
                    $after[] = static function () use ($to, $number, $subject, $approver, $ticketId, $clientId): void {
                        notifyUser($to, 'Ticket', "Ticket $number - $subject has been assigned to you by $approver (via MCP)", "/agent/ticket.php?ticket_id=$ticketId&client_id=$clientId", $clientId, $ticketId);
                        queueWebhookEvent('ticket.assigned', getWebhookTicketPayload($ticketId));
                    };
                }
            }
            $log = "$approver changed ticket $number: " . ($changes ? implode(', ', $changes) : 'no change') . " ($via)";
            $result = ['ticket_id' => $ticketId, 'changes' => $changes, 'message' => $changes ? "Ticket $number updated: " . implode(', ', $changes) . '.' : "Ticket $number already matched; nothing to change."];
        }
        if (function_exists('logAction')) {
            logAction('Ticket', 'Edit', mb_substr($log, 0, 1000), $clientId, $ticketId);
        }

        return ['result' => $result, 'after' => $after];
    }

    private static function executeCreate(\mysqli $db, array $args, int $approverId, int $clientId, string $via): array
    {
        $s = McpAccess::rows($db, 'SELECT config_ticket_prefix FROM settings WHERE company_id = 1', '', [])[0] ?? [];
        $assigned = resolveTicketAssignee(0);
        $status = resolveTicketCreationStatus($assigned);
        $category = resolveTicketCategory(0);

        // Same atomic counter bump the UI uses.
        $db->query('UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number), config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1');
        $number = (int) $db->insert_id;
        $prefix = (string) ($s['config_ticket_prefix'] ?? '');
        $urlKey = randomString(32);
        $contact = (int) ($args['contact_id'] ?? 0);
        $asset = (int) ($args['asset_id'] ?? 0);

        $ticketId = self::exec($db, "INSERT INTO tickets SET ticket_prefix = ?, ticket_number = ?, ticket_source = 'MCP', ticket_category = ?, ticket_subject = ?, ticket_details = ?,
                ticket_priority = ?, ticket_billable = 0, ticket_status = ?, ticket_created_by = ?, ticket_assigned_to = ?, ticket_contact_id = ?, ticket_url_key = ?,
                ticket_client_id = ?, ticket_asset_id = ?, ticket_invoice_id = 0, ticket_project_id = 0",
            'sissssiiiisii', [$prefix, $number, (string) $category, $args['subject'], self::html((string) $args['details']), $args['priority'], $status, $approverId, $assigned, $contact, $urlKey, $clientId, $asset]);
        ticketCategoryApplyOnCreate($db, $ticketId, $category);
        recalculateTicketSla($db, $ticketId);
        self::exec($db, 'INSERT INTO ticket_history SET ticket_history_status = ?, ticket_history_description = ?, ticket_history_ticket_id = ?',
            'ssi', ['New', mb_substr("Ticket created ($via)", 0, 255), $ticketId]);
        $label = $prefix . $number;
        if (function_exists('logAction')) {
            logAction('Ticket', 'Create', mb_substr("Ticket $label created ($via)", 0, 1000), $clientId, $ticketId);
        }
        $after = [static function () use ($ticketId, $assigned, $approverId, $label, $args, $clientId): void {
            runTicketCreatedAutomation($GLOBALS['mysqli'], $ticketId);
            if ($assigned !== 0 && $assigned !== $approverId) {
                notifyUser($assigned, 'Ticket', "New ticket $label - {$args['subject']} has been assigned to you (via MCP)", "/agent/ticket.php?ticket_id=$ticketId&client_id=$clientId", $clientId, $ticketId);
            }
        }];

        return ['result' => ['ticket_id' => $ticketId, 'ticket_number' => $label, 'message' => "Ticket $label created."], 'after' => $after];
    }
}
