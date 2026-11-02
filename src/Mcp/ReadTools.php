<?php

declare(strict_types=1);

namespace RivetMSP\Mcp;

use RivetCore\Mcp\NotFoundException;
use RivetCore\Mcp\ToolPipeline;

/** Raised inside a tool body when the requested record does not exist or is outside the caller's scope. */
final class McpNotFound extends NotFoundException
{
}

/**
 * Read-only MCP tools for RivetMSP. Every tool goes through the shared RivetCore\Mcp\ToolPipeline: bearer token ->
 * rate limit -> role check -> the tool's own client-scoped SQL -> audit row (audit_events, event_type
 * 'mcp.tool_call') -> standard envelope {success, request_id, data, errors}.
 *
 * RivetMSP is multi-tenant, so every row a tool can return is filtered twice, both inside the tool body's SQL,
 * never in PHP after the fact:
 *   1. the SAME client scope a signed-in technician's own pages use: no rows in `user_client_permissions` for
 *      this user means every client, otherwise only the listed ones (see self::clientScope()).
 *   2. `clients.client_ai_opt_out` - a client that opted out of AI access is excluded from every tool's
 *      results for every caller, admins included. This is NOT the same thing as #1 and is checked in addition
 *      to it.
 * No tool writes, and none ever selects a secret (contact_pin, credential vault rows, PINs are never touched).
 *
 * Write tools do NOT live here: see WriteTools (propose-then-approve; nothing in this class ever mutates). Both
 * share McpPipeline (the single choke point) and McpAccess (permission + client scope).
 */
final class ReadTools
{
    private const MAX_ROWS = 25;

    private ?ToolPipeline $pipeline = null;

    public function __construct(private \mysqli $db)
    {
    }

    /**
     * Tool definitions for the MCP `tools/list` response. Kept separate from the dispatch table in call() so the
     * JSON schema advertised to clients and the actual argument handling cannot silently drift apart - every
     * entry here must have a matching case in call().
     *
     * @return list<array{name:string,description:string,inputSchema:array<string,mixed>}>
     */
    public static function definitions(): array
    {
        $str = static fn (string $description, int $maxLength = 200): array => ['type' => 'string', 'description' => $description, 'maxLength' => $maxLength];
        $int = static fn (string $description): array => ['type' => 'integer', 'description' => $description];

        return [
            ['name' => 'rivetmsp_my_profile', 'description' => 'Read the signed-in RivetMSP agent profile.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false]],
            ['name' => 'rivetmsp_list_clients', 'description' => 'List active clients, optionally filtered by name. Returns up to 25, limited to the clients the caller may access.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'query' => $str('Optional name filter'), 'limit' => $int('Max rows, default 25'),
                ], 'additionalProperties' => false]],
            ['name' => 'rivetmsp_search_contacts', 'description' => 'Search people (contacts) by name, email, or title. Optional client_id filter. Returns up to 25.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'query' => $str('Name, email or title filter'), 'client_id' => $int('Optional client to restrict to'), 'limit' => $int('Max rows, default 10'),
                ], 'additionalProperties' => false]],
            ['name' => 'rivetmsp_recent_tickets', 'description' => 'Read up to 25 recent open tickets within the signed-in agent\'s client access.',
                'inputSchema' => ['type' => 'object', 'properties' => ['limit' => $int('Max rows, default 10')], 'additionalProperties' => false]],
            ['name' => 'rivetmsp_search_tickets', 'description' => 'Search tickets by subject, number, or details. open_only defaults to true. Returns up to 25.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'query' => $str('Subject, number or details filter'), 'open_only' => ['type' => 'boolean', 'description' => 'Default true'], 'limit' => $int('Max rows, default 10'),
                ], 'additionalProperties' => false]],
            ['name' => 'rivetmsp_get_ticket', 'description' => 'Read one ticket with its description and the 10 most recent replies (public and internal notes).',
                'inputSchema' => ['type' => 'object', 'properties' => ['ticket_id' => $int('Ticket id')], 'required' => ['ticket_id'], 'additionalProperties' => false]],
            ['name' => 'rivetmsp_search_assets', 'description' => 'Search assets by name, tag, serial, make, model, or OS. Optional client_id filter. Returns up to 25.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'query' => $str('Name, tag, serial, make, model or OS filter'), 'client_id' => $int('Optional client to restrict to'), 'limit' => $int('Max rows, default 10'),
                ], 'additionalProperties' => false]],
            ['name' => 'rivetmsp_get_asset', 'description' => 'Read one asset: hardware identity, OS, status, dates, location, assigned contact, and notes. Never returns credentials.',
                'inputSchema' => ['type' => 'object', 'properties' => ['asset_id' => $int('Asset id')], 'required' => ['asset_id'], 'additionalProperties' => false]],
            ['name' => 'rivetmsp_search_kb', 'description' => 'Search knowledge base articles by title or content. Returns up to 25 with a short excerpt.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'query' => $str('Title or content filter', 100), 'limit' => $int('Max rows, default 10'),
                ], 'required' => ['query'], 'additionalProperties' => false]],
            ['name' => 'rivetmsp_get_kb_article', 'description' => 'Read one knowledge base article as plain text (truncated to 20,000 characters).',
                'inputSchema' => ['type' => 'object', 'properties' => ['article_id' => $int('KB article id')], 'required' => ['article_id'], 'additionalProperties' => false]],
        ];
    }

    /**
     * Dispatch one `tools/call`. $userId is the identity the bearer token resolved to (never trust anything else
     * for identity). Always returns the ToolPipeline envelope, even for an unknown tool name (NOT_FOUND).
     *
     * @param array<string,mixed> $args
     */
    public function call(string $name, array $args, ?int $userId): array
    {
        return match ($name) {
            'rivetmsp_my_profile' => $this->myProfile($userId),
            'rivetmsp_list_clients' => $this->listClients($userId, (string) ($args['query'] ?? ''), (int) ($args['limit'] ?? 25)),
            'rivetmsp_search_contacts' => $this->searchContacts($userId, (string) ($args['query'] ?? ''), (int) ($args['client_id'] ?? 0), (int) ($args['limit'] ?? 10)),
            'rivetmsp_recent_tickets' => $this->searchTickets($userId, '', true, (int) ($args['limit'] ?? 10), 'rivetmsp_recent_tickets'),
            'rivetmsp_search_tickets' => $this->searchTickets($userId, (string) ($args['query'] ?? ''), (bool) ($args['open_only'] ?? true), (int) ($args['limit'] ?? 10), 'rivetmsp_search_tickets'),
            'rivetmsp_get_ticket' => $this->getTicket($userId, (int) ($args['ticket_id'] ?? 0)),
            'rivetmsp_search_assets' => $this->searchAssets($userId, (string) ($args['query'] ?? ''), (int) ($args['client_id'] ?? 0), (int) ($args['limit'] ?? 10)),
            'rivetmsp_get_asset' => $this->getAsset($userId, (int) ($args['asset_id'] ?? 0)),
            'rivetmsp_search_kb' => $this->searchKb($userId, (string) ($args['query'] ?? ''), (int) ($args['limit'] ?? 10)),
            'rivetmsp_get_kb_article' => $this->getKbArticle($userId, (int) ($args['article_id'] ?? 0)),
            default => ToolPipeline::envelope('req_' . bin2hex(random_bytes(8)), null, 'NOT_FOUND', "Unknown tool \"$name\"."),
        };
    }

    /* ---------------------------------------------------------------- pipeline */

    /** The shared pipeline (rate limit -> permission -> scoped read -> audit -> envelope) lives in RivetCore. */
    private function pipeline(): ToolPipeline
    {
        return $this->pipeline ??= McpPipeline::make($this->db);
    }

    /** @param callable(int):bool $allow @param callable(int):mixed $body */
    private function run(string $tool, array $args, ?int $userId, callable $allow, callable $body): array
    {
        return $this->pipeline()->run($userId, $tool, $args, $allow, $body, 'RivetMSP role');
    }

    /* ---------------------------------------------------------------- permissions and scope */

    private function modulePermission(int $userId, string $module): int
    {
        return McpAccess::modulePermission($this->db, $userId, $module);
    }

    private function can(int $userId, string $module, int $level = 1): bool
    {
        return McpAccess::can($this->db, $userId, $module, $level);
    }

    /** Same client restriction the signed-in agent's own pages apply (see McpAccess). */
    private static function clientScope(string $clientColumn): string
    {
        return McpAccess::clientScope($clientColumn);
    }

    /** A client that opted out of AI access (clients.client_ai_opt_out) is excluded from every tool's results, for every caller. */
    private static function aiOptOutExcluded(string $clientAlias = 'c'): string
    {
        return McpAccess::aiOptOutExcluded($clientAlias);
    }

    private static function like(string $q): string
    {
        return '%' . addcslashes(mb_substr(trim($q), 0, 100), '%_\\') . '%';
    }

    private static function limit(int $n): int
    {
        return max(1, min(self::MAX_ROWS, $n));
    }

    private static function text(?string $html, int $max, bool $keepLines = false): string
    {
        $t = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = trim($keepLines ? preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $t))) : preg_replace('/\s+/', ' ', $t));

        return mb_strlen($t) > $max ? mb_substr($t, 0, $max) . '…' : $t;
    }

    /** Prepared SELECT; $types/$params bind in order. */
    private function rows(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('MCP query failed to prepare');
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function one(string $sql, string $types, array $params): array
    {
        return $this->rows($sql, $types, $params)[0] ?? throw new McpNotFound();
    }

    /* ---------------------------------------------------------------- profile */

    private function myProfile(?int $userId): array
    {
        return $this->run('rivetmsp_my_profile', [], $userId, fn () => true, function (int $uid) {
            $row = $this->one('SELECT user_id, user_name, user_email FROM users
                WHERE user_id = ? AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL', 'i', [$uid]);

            return ['id' => (int) $row['user_id'], 'name' => $row['user_name'], 'email' => $row['user_email']];
        });
    }

    /* ---------------------------------------------------------------- clients and contacts */

    private function listClients(?int $userId, string $query, int $limit): array
    {
        $args = ['query' => $query, 'limit' => $limit];

        return $this->run('rivetmsp_list_clients', $args, $userId, fn ($uid) => $this->can($uid, 'module_client'), function (int $uid) use ($query, $limit) {
            $where = ['c.client_archived_at IS NULL', self::clientScope('c.client_id'), self::aiOptOutExcluded()];
            $types = 'ii';
            $params = [$uid, $uid];
            if (trim($query) !== '') {
                $where[] = 'c.client_name LIKE ?';
                $types .= 's';
                $params[] = self::like($query);
            }
            $types .= 'i';
            $params[] = self::limit($limit);

            return $this->rows('SELECT c.client_id, c.client_name, c.client_type, c.client_website
                FROM clients c WHERE ' . implode(' AND ', $where) . ' ORDER BY c.client_name LIMIT ?', $types, $params);
        });
    }

    private function searchContacts(?int $userId, string $query, int $clientId, int $limit): array
    {
        $args = ['query' => $query, 'client_id' => $clientId, 'limit' => $limit];

        return $this->run('rivetmsp_search_contacts', $args, $userId, fn ($uid) => $this->can($uid, 'module_client'), function (int $uid) use ($query, $clientId, $limit) {
            $where = ['ct.contact_archived_at IS NULL', self::clientScope('ct.contact_client_id'), self::aiOptOutExcluded()];
            $types = 'ii';
            $params = [$uid, $uid];
            if ($clientId > 0) {
                $where[] = 'ct.contact_client_id = ?';
                $types .= 'i';
                $params[] = $clientId;
            }
            if (trim($query) !== '') {
                $where[] = '(ct.contact_name LIKE ? OR ct.contact_email LIKE ? OR ct.contact_title LIKE ?)';
                $like = self::like($query);
                $types .= 'sss';
                array_push($params, $like, $like, $like);
            }
            $types .= 'i';
            $params[] = self::limit($limit);

            return $this->rows('SELECT ct.contact_id, ct.contact_name, ct.contact_title, ct.contact_email, ct.contact_phone,
                    ct.contact_mobile, ct.contact_department, ct.contact_client_id, c.client_name
                FROM contacts ct JOIN clients c ON c.client_id = ct.contact_client_id
                WHERE ' . implode(' AND ', $where) . ' ORDER BY ct.contact_name LIMIT ?', $types, $params);
        });
    }

    /* ---------------------------------------------------------------- tickets */

    private function searchTickets(?int $userId, string $query, bool $openOnly, int $limit, string $tool): array
    {
        $args = ['query' => $query, 'open_only' => $openOnly, 'limit' => $limit];

        return $this->run($tool, $args, $userId, fn ($uid) => $this->can($uid, 'module_support'), function (int $uid) use ($query, $openOnly, $limit) {
            $where = ['t.ticket_archived_at IS NULL', self::clientScope('t.ticket_client_id'), self::aiOptOutExcluded()];
            $types = 'ii';
            $params = [$uid, $uid];
            if ($openOnly) {
                $where[] = 't.ticket_resolved_at IS NULL';
            }
            if (trim($query) !== '') {
                $where[] = "(t.ticket_subject LIKE ? OR t.ticket_details LIKE ? OR CONCAT(IFNULL(t.ticket_prefix,''), t.ticket_number) LIKE ?)";
                $like = self::like($query);
                $types .= 'sss';
                array_push($params, $like, $like, $like);
            }
            $n = self::limit($limit);
            $types .= 'i';
            $params[] = $n;

            return $this->rows("SELECT t.ticket_id, CONCAT(IFNULL(t.ticket_prefix,''), t.ticket_number) AS ticket_number,
                    t.ticket_subject, t.ticket_priority, s.ticket_status_name AS status, t.ticket_client_id, c.client_name,
                    t.ticket_assigned_to, t.ticket_created_at, t.ticket_updated_at
                FROM tickets t JOIN clients c ON c.client_id = t.ticket_client_id
                LEFT JOIN ticket_statuses s ON s.ticket_status_id = t.ticket_status
                WHERE " . implode(' AND ', $where) . ' ORDER BY t.ticket_created_at DESC LIMIT ?', $types, $params);
        });
    }

    private function getTicket(?int $userId, int $ticketId): array
    {
        return $this->run('rivetmsp_get_ticket', ['ticket_id' => $ticketId], $userId, fn ($uid) => $this->can($uid, 'module_support'), function (int $uid) use ($ticketId) {
            $t = $this->one('SELECT t.ticket_id, CONCAT(IFNULL(t.ticket_prefix,\'\'), t.ticket_number) AS ticket_number,
                    t.ticket_subject, t.ticket_details, t.ticket_priority, s.ticket_status_name AS status,
                    t.ticket_client_id, c.client_name, t.ticket_asset_id, t.ticket_assigned_to,
                    t.ticket_created_at, t.ticket_updated_at, t.ticket_resolved_at, t.ticket_due_at
                FROM tickets t JOIN clients c ON c.client_id = t.ticket_client_id
                LEFT JOIN ticket_statuses s ON s.ticket_status_id = t.ticket_status
                WHERE t.ticket_id = ? AND t.ticket_archived_at IS NULL AND ' . self::clientScope('t.ticket_client_id') . ' AND ' . self::aiOptOutExcluded(),
                'iii', [$ticketId, $uid, $uid]);
            $t['ticket_details'] = self::text($t['ticket_details'], 6000);
            $replies = $this->rows('SELECT ticket_reply_id, ticket_reply_type, ticket_reply, ticket_reply_by, ticket_reply_created_at
                FROM ticket_replies WHERE ticket_reply_ticket_id = ? AND ticket_reply_archived_at IS NULL
                ORDER BY ticket_reply_id DESC LIMIT 10', 'i', [$ticketId]);
            foreach ($replies as &$r) {
                $r['ticket_reply'] = self::text($r['ticket_reply'], 3000);
            }
            unset($r);
            $t['recent_replies'] = array_reverse($replies);

            return $t;
        });
    }

    /* ---------------------------------------------------------------- assets */

    private function searchAssets(?int $userId, string $query, int $clientId, int $limit): array
    {
        $args = ['query' => $query, 'client_id' => $clientId, 'limit' => $limit];

        return $this->run('rivetmsp_search_assets', $args, $userId, fn ($uid) => $this->can($uid, 'module_support'), function (int $uid) use ($query, $clientId, $limit) {
            $where = ['a.asset_archived_at IS NULL', self::clientScope('a.asset_client_id'), self::aiOptOutExcluded()];
            $types = 'ii';
            $params = [$uid, $uid];
            if ($clientId > 0) {
                $where[] = 'a.asset_client_id = ?';
                $types .= 'i';
                $params[] = $clientId;
            }
            if (trim($query) !== '') {
                $where[] = '(a.asset_name LIKE ? OR a.asset_tag LIKE ? OR a.asset_serial LIKE ? OR a.asset_make LIKE ? OR a.asset_model LIKE ? OR a.asset_os LIKE ?)';
                $like = self::like($query);
                $types .= 'ssssss';
                array_push($params, $like, $like, $like, $like, $like, $like);
            }
            $types .= 'i';
            $params[] = self::limit($limit);

            return $this->rows('SELECT a.asset_id, a.asset_name, a.asset_type, a.asset_make, a.asset_model, a.asset_serial,
                    a.asset_tag, a.asset_os, a.asset_status, a.asset_client_id, c.client_name
                FROM assets a JOIN clients c ON c.client_id = a.asset_client_id
                WHERE ' . implode(' AND ', $where) . ' ORDER BY a.asset_name LIMIT ?', $types, $params);
        });
    }

    private function getAsset(?int $userId, int $assetId): array
    {
        return $this->run('rivetmsp_get_asset', ['asset_id' => $assetId], $userId, fn ($uid) => $this->can($uid, 'module_support'), function (int $uid) use ($assetId) {
            $a = $this->one('SELECT a.asset_id, a.asset_name, a.asset_type, a.asset_description, a.asset_make, a.asset_model,
                    a.asset_serial, a.asset_tag, a.asset_os, a.asset_status, a.asset_purchase_date, a.asset_warranty_expire,
                    a.asset_install_date, a.asset_physical_location, a.asset_notes, a.asset_created_at, a.asset_updated_at,
                    a.asset_client_id, c.client_name, a.asset_contact_id, ct.contact_name AS assigned_contact
                FROM assets a JOIN clients c ON c.client_id = a.asset_client_id
                LEFT JOIN contacts ct ON ct.contact_id = a.asset_contact_id
                WHERE a.asset_id = ? AND a.asset_archived_at IS NULL AND ' . self::clientScope('a.asset_client_id') . ' AND ' . self::aiOptOutExcluded(),
                'iii', [$assetId, $uid, $uid]);
            $a['asset_notes'] = self::text($a['asset_notes'], 4000);

            return $a;
        });
    }

    /* ---------------------------------------------------------------- knowledge base */

    private function searchKb(?int $userId, string $query, int $limit): array
    {
        return $this->run('rivetmsp_search_kb', ['query' => $query, 'limit' => $limit], $userId, fn ($uid) => $this->can($uid, 'module_kb'), function (int $uid) use ($query, $limit) {
            $like = self::like($query);
            $rows = $this->rows('SELECT a.kb_article_id, a.kb_article_title, a.kb_article_client_id, a.kb_article_updated_at,
                    a.kb_article_content_raw
                FROM kb_articles a LEFT JOIN clients c ON c.client_id = a.kb_article_client_id
                WHERE a.kb_article_archived_at IS NULL
                  AND (a.kb_article_client_id = 0 OR (' . self::clientScope('a.kb_article_client_id') . ' AND ' . self::aiOptOutExcluded() . '))
                  AND (a.kb_article_title LIKE ? OR a.kb_article_content_raw LIKE ?)
                ORDER BY (a.kb_article_title LIKE ?) DESC, a.kb_article_updated_at DESC LIMIT ?',
                'iisssi', [$uid, $uid, $like, $like, $like, self::limit($limit)]);
            foreach ($rows as &$r) {
                $r['excerpt'] = self::text($r['kb_article_content_raw'], 240);
                unset($r['kb_article_content_raw']);
            }
            unset($r);

            return $rows;
        });
    }

    private function getKbArticle(?int $userId, int $articleId): array
    {
        return $this->run('rivetmsp_get_kb_article', ['article_id' => $articleId], $userId, fn ($uid) => $this->can($uid, 'module_kb'), function (int $uid) use ($articleId) {
            $a = $this->one('SELECT a.kb_article_id, a.kb_article_title, a.kb_article_content_raw, a.kb_article_client_id,
                    a.kb_article_created_at, a.kb_article_updated_at
                FROM kb_articles a LEFT JOIN clients c ON c.client_id = a.kb_article_client_id
                WHERE a.kb_article_id = ? AND a.kb_article_archived_at IS NULL
                  AND (a.kb_article_client_id = 0 OR (' . self::clientScope('a.kb_article_client_id') . ' AND ' . self::aiOptOutExcluded() . '))',
                'iii', [$articleId, $uid, $uid]);
            $a['content'] = self::text($a['kb_article_content_raw'], 20000, true);
            unset($a['kb_article_content_raw']);

            return $a;
        });
    }
}
