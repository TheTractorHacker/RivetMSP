<?php

declare(strict_types=1);

namespace RivetMSP\Core\Adapter\Endpoint;

use RivetCore\Panel\Contracts\PanelTicketGatewayInterface;

/**
 * RivetMSP's ticket storage behind the RivetPanel API (GitHub roadmap "Prompt 2"). Core decides WHO may do WHAT
 * (device, client, contact, ownership, limits, text hygiene); this class only stores and fetches, over the
 * existing tickets/ticket_replies/ticket_attachments/categories/contacts tables - the same ones the agent UI, the
 * client portal and the email parser already use, including their shared status/category/assignee resolution
 * helpers (functions.php's resolveTicketCreationStatus()/resolveTicketCategory()/resolveTicketAssignee()), so a
 * panel-created ticket is indistinguishable in triage/reporting from one made any other way.
 *
 * Status is resolved by NAME (resolveTicketCreationStatus() reads ticket_statuses.ticket_status_name = 'New'/'Open'/
 * the default), never a literal per-install id - the hardcoded-status-id lesson from earlier this session.
 *
 * Takes a raw \mysqli (not RivetCore's DatabaseInterface) to reuse the same prepared-statement style and the
 * existing global ticket-creation helpers directly, matching RivetIT's adapter for the same interface.
 *
 * @internal
 */
final class EndpointPanelGateway implements PanelTicketGatewayInterface
{
    /** Reply types visible to a requester (client-level or contact) - never 'Internal', 'Labor' or 'System'. */
    private const VISIBLE_REPLY_TYPES = ['Public', 'Client'];

    /** Cached by mysqlUtcOffsetSeconds() - see that method's doc comment. */
    private ?int $mysqlUtcOffsetSeconds = null;

    public function __construct(private \mysqli $mysqli)
    {
    }

    public function categories(int $clientId): array
    {
        if (!$this->clientExists($clientId)) {
            return [];
        }
        $stmt = $this->mysqli->prepare(
            "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL ORDER BY category_order ASC, category_id ASC"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $out = [];
        while ($row = $result->fetch_assoc()) {
            $out[] = ['category_id' => (int) $row['category_id'], 'name' => (string) $row['category_name']];
        }
        $stmt->close();

        return $out;
    }

    public function contact(int $contactId, int $clientId): ?array
    {
        $stmt = $this->mysqli->prepare(
            'SELECT contact_id, contact_name FROM contacts WHERE contact_id = ? AND contact_client_id = ? AND contact_archived_at IS NULL LIMIT 1'
        );
        $stmt->bind_param('ii', $contactId, $clientId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? ['contact_id' => (int) $row['contact_id'], 'name' => (string) $row['contact_name']] : null;
    }

    public function createTicket(int $clientId, ?int $contactId, string $subject, string $body, ?int $categoryId, string $requesterHint, array $attachments): int
    {
        $this->mysqli->begin_transaction();
        try {
            $assignedTo = \resolveTicketAssignee(0);
            $status = \resolveTicketCreationStatus($assignedTo);
            $category = \resolveTicketCategory($categoryId ?? 0);

            // Same atomic "reserve the next number" pattern cron.php's recurring tickets and every other creation
            // path use - LAST_INSERT_ID() on an UPDATE makes the read-then-increment a single atomic statement.
            $prefixStmt = $this->mysqli->prepare('SELECT config_ticket_prefix FROM settings WHERE company_id = 1 LIMIT 1');
            $prefixStmt->execute();
            $prefix = (string) ($prefixStmt->get_result()->fetch_assoc()['config_ticket_prefix'] ?? 'T');
            $prefixStmt->close();
            $this->mysqli->query(
                'UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number), config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1'
            );
            $number = (int) $this->mysqli->insert_id;

            $detailsHtml = $this->toStoredHtml($body);
            $stmt = $this->mysqli->prepare(
                'INSERT INTO tickets SET ticket_prefix = ?, ticket_number = ?, ticket_source = ?, ticket_subject = ?, ticket_details = ?,
                    ticket_priority = ?, ticket_status = ?, ticket_created_by = 0, ticket_assigned_to = ?, ticket_contact_id = ?,
                    ticket_client_id = ?, ticket_category = ?, ticket_send_email = 1'
            );
            $source = 'RivetPanel';
            $priority = 'Medium';
            $contactIdForRow = $contactId ?? 0;
            $categoryStr = $category > 0 ? (string) $category : '';
            $stmt->bind_param(
                'sisssssiiis',
                $prefix,
                $number,
                $source,
                $subject,
                $detailsHtml,
                $priority,
                $status,
                $assignedTo,
                $contactIdForRow,
                $clientId,
                $categoryStr
            );
            $stmt->execute();
            $ticketId = (int) $this->mysqli->insert_id;
            $stmt->close();

            $replyId = $this->insertReply($ticketId, $clientId, $contactId, $body, $requesterHint, $attachments, true);

            $initialStmt = $this->mysqli->prepare('UPDATE tickets SET ticket_initial_issue_reply_id = ? WHERE ticket_id = ?');
            $initialStmt->bind_param('ii', $replyId, $ticketId);
            $initialStmt->execute();
            $initialStmt->close();

            \logAction('Support', 'Create', "RivetPanel created ticket #$number for client $clientId", $clientId, $ticketId);

            $this->mysqli->commit();

            return $ticketId;
        } catch (\Throwable $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }

    public function listTickets(int $clientId, ?int $contactId, array $ownedTicketIds, int $limit, int $offset): array
    {
        $ownedIds = array_values(array_unique(array_map('intval', $ownedTicketIds)));
        $ownedList = $ownedIds === [] ? '-1' : implode(',', $ownedIds);
        $scope = $contactId !== null
            ? '(ticket_contact_id = ' . (int) $contactId . ' OR ticket_id IN (' . $ownedList . '))'
            : 'ticket_id IN (' . $ownedList . ')';
        $sql = "SELECT ticket_id FROM tickets WHERE ticket_client_id = ? AND ticket_archived_at IS NULL AND ticket_merged_into_id IS NULL AND $scope ORDER BY ticket_id DESC LIMIT ? OFFSET ?";
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('iii', $clientId, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['ticket_id'];
        }
        $stmt->close();

        $out = [];
        foreach ($ids as $id) {
            $row = $this->ticket($id);
            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    public function ticket(int $ticketId): ?array
    {
        $stmt = $this->mysqli->prepare(
            'SELECT tickets.ticket_id, tickets.ticket_client_id, tickets.ticket_contact_id, tickets.ticket_subject,
                tickets.ticket_closed_at, tickets.ticket_category, tickets.ticket_created_at, tickets.ticket_updated_at,
                ticket_statuses.ticket_status_name
             FROM tickets
             LEFT JOIN ticket_statuses ON ticket_statuses.ticket_status_id = tickets.ticket_status
             WHERE tickets.ticket_id = ? AND tickets.ticket_archived_at IS NULL
             LIMIT 1'
        );
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        $contactId = (int) $row['ticket_contact_id'];
        $categoryId = (int) $row['ticket_category'];

        return [
            'ticket_id' => (int) $row['ticket_id'],
            'client_id' => (int) $row['ticket_client_id'],
            'contact_id' => $contactId > 0 ? $contactId : null,
            'subject' => (string) $row['ticket_subject'],
            'status' => (string) ($row['ticket_status_name'] ?? ''),
            'closed' => $row['ticket_closed_at'] !== null,
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'created_at' => $this->toUtc((string) $row['ticket_created_at']),
            'updated_at' => $this->toUtc((string) ($row['ticket_updated_at'] ?? $row['ticket_created_at'])),
        ];
    }

    public function messages(int $ticketId, int $afterMessageId, int $limit): array
    {
        $types = "'" . implode("','", self::VISIBLE_REPLY_TYPES) . "'";
        $stmt = $this->mysqli->prepare(
            "SELECT ticket_reply_id, ticket_reply, ticket_reply_type, ticket_reply_by, ticket_reply_created_at
             FROM ticket_replies
             WHERE ticket_reply_ticket_id = ? AND ticket_reply_archived_at IS NULL AND ticket_reply_type IN ($types) AND ticket_reply_id > ?
             ORDER BY ticket_reply_id ASC
             LIMIT ?"
        );
        $stmt->bind_param('iii', $ticketId, $afterMessageId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $out = [];
        while ($row = $result->fetch_assoc()) {
            $replyId = (int) $row['ticket_reply_id'];
            $isClient = $row['ticket_reply_type'] === 'Client';
            $out[] = [
                'message_id' => $replyId,
                'author' => $isClient ? 'requester' : 'staff',
                'author_name' => $this->replyAuthorName($isClient, (int) $row['ticket_reply_by']),
                'body' => $this->fromStoredHtml((string) $row['ticket_reply']),
                'internal' => false,
                'created_at' => $this->toUtc((string) $row['ticket_reply_created_at']),
                'attachments' => $this->attachmentsOf($replyId),
            ];
        }
        $stmt->close();

        return $out;
    }

    public function postMessage(int $ticketId, int $clientId, ?int $contactId, string $body, string $requesterHint, array $attachments): int
    {
        $this->mysqli->begin_transaction();
        try {
            $replyId = $this->insertReply($ticketId, $clientId, $contactId, $body, $requesterHint, $attachments, false);
            \logAction('Support', 'Edit', "RivetPanel posted a reply to ticket #$ticketId", $clientId, $ticketId);
            $this->mysqli->commit();

            return $replyId;
        } catch (\Throwable $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }

    // ---- internals ----

    private function clientExists(int $clientId): bool
    {
        $stmt = $this->mysqli->prepare('SELECT 1 FROM clients WHERE client_id = ? AND client_archived_at IS NULL LIMIT 1');
        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();

        return $found;
    }

    /**
     * @param list<array{filename:string,content_type:string,bytes:string}> $attachments
     */
    private function insertReply(int $ticketId, int $clientId, ?int $contactId, string $body, string $requesterHint, array $attachments, bool $isFirst): int
    {
        // A client-level device (no contact) has no identity of its own; the OS user name it reported is an
        // UNVERIFIED hint, never a selected contact - shown, never trusted (PANEL_API.md "Who may do what").
        $storedBody = $contactId === null && $requesterHint !== ''
            ? '[reported as: ' . $this->toStoredHtml($requesterHint) . "]\n\n" . $this->toStoredHtml($body)
            : $this->toStoredHtml($body);
        $replyBy = $contactId ?? 0;
        $stmt = $this->mysqli->prepare(
            "INSERT INTO ticket_replies SET ticket_reply = ?, ticket_reply_type = 'Client', ticket_reply_by = ?, ticket_reply_ticket_id = ?"
        );
        $stmt->bind_param('sii', $storedBody, $replyBy, $ticketId);
        $stmt->execute();
        $replyId = (int) $this->mysqli->insert_id;
        $stmt->close();

        foreach ($attachments as $a) {
            $name = $this->safeAttachmentName($a['filename']);
            $dir = $this->uploadsDir() . "/tickets/$ticketId";
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            $stored = bin2hex(random_bytes(16)) . $this->safeExtension($a['filename']);
            file_put_contents("$dir/$stored", $a['bytes']);
            $attStmt = $this->mysqli->prepare(
                'INSERT INTO ticket_attachments SET ticket_attachment_name = ?, ticket_attachment_reference_name = ?, ticket_attachment_ticket_id = ?, ticket_attachment_reply_id = ?'
            );
            $attStmt->bind_param('ssii', $name, $stored, $ticketId, $replyId);
            $attStmt->execute();
            $attStmt->close();
        }

        return $replyId;
    }

    /** @return list<array{filename:string,content_type:string,size:int}> */
    private function attachmentsOf(int $replyId): array
    {
        $stmt = $this->mysqli->prepare('SELECT ticket_attachment_name, ticket_attachment_reference_name, ticket_attachment_ticket_id FROM ticket_attachments WHERE ticket_attachment_reply_id = ?');
        $stmt->bind_param('i', $replyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $out = [];
        while ($row = $result->fetch_assoc()) {
            $path = $this->uploadsDir() . '/tickets/' . (int) $row['ticket_attachment_ticket_id'] . '/' . $row['ticket_attachment_reference_name'];
            $out[] = [
                'filename' => (string) $row['ticket_attachment_name'],
                'content_type' => $this->contentTypeFromName((string) $row['ticket_attachment_name']),
                'size' => is_file($path) ? (int) filesize($path) : 0,
            ];
        }
        $stmt->close();

        return $out;
    }

    private function replyAuthorName(bool $isClient, int $replyBy): string
    {
        if ($replyBy === 0) {
            return $isClient ? 'Client' : 'Staff';
        }
        $table = $isClient ? 'contacts' : 'users';
        $col = $isClient ? 'contact_name' : 'user_name';
        $idCol = $isClient ? 'contact_id' : 'user_id';
        $stmt = $this->mysqli->prepare("SELECT $col FROM $table WHERE $idCol = ? LIMIT 1");
        $stmt->bind_param('i', $replyBy);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (string) $row[$col] : ($isClient ? 'Client' : 'Staff');
    }

    /** Plain text in (per the interface contract) stored as this codebase's tickets already store replies: escaped HTML with <br> for newlines. */
    private function toStoredHtml(string $plain): string
    {
        return nl2br(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8'));
    }

    /** The reverse of toStoredHtml(), close enough for round-tripping panel-authored plain text back out. */
    private function fromStoredHtml(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
        return html_entity_decode(strip_tags((string) $text), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Seconds to ADD to a raw `NOW()`-stamped MySQL datetime string to get UTC, cached for the
     * life of this gateway instance (one request). Asked of the database itself
     * (TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP())) rather than trusted from PHP's own
     * `date.timezone` ini setting: the panel API's unauthenticated device-token routes never run
     * includes/inc_set_timezone.php (that only happens on a logged-in user's session), so
     * `ini_get('date.timezone')` here is whatever php.ini's bare default is - which is NOT
     * guaranteed to match the timezone MySQL's own NOW() wall clock actually runs on (this
     * install's PHP and MySQL happen to agree today; a differently configured server, or PHP and
     * MySQL on separate hosts, would not). RivetCore 1.0.0-rc.38 conformance
     * (testTimestampsAreUtcAndCurrent()) is what caught this - fixed by asking MySQL for its own
     * real offset instead of assuming PHP's ini state describes it.
     */
    private function mysqlUtcOffsetSeconds(): int
    {
        if ($this->mysqlUtcOffsetSeconds === null) {
            $res = mysqli_query($this->mysqli, 'SELECT TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP())');
            $row = $res ? mysqli_fetch_row($res) : null;
            $this->mysqlUtcOffsetSeconds = $row ? (int) $row[0] : 0;
        }

        return $this->mysqlUtcOffsetSeconds;
    }

    private function toUtc(string $datetime): string
    {
        // DateTime::createFromFormat(..., new DateTimeZone('UTC')) takes the string's wall-clock
        // digits AS WRITTEN, tagged with a fixed, explicit zone object - unlike strtotime()/`new
        // DateTime($datetime)`, which (with no offset in the string) apply PHP's AMBIENT default
        // timezone during parsing. Using strtotime() here was tried and is exactly the double-
        // offset bug this method exists to avoid: it would already convert the naive string via
        // PHP's ini timezone (right by coincidence when that happens to equal MySQL's own, as it
        // does on this install today) and THEN get mysqlUtcOffsetSeconds() added on top.
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $datetime, new \DateTimeZone('UTC'));
        if ($dt === false) {
            return $datetime;
        }
        $dt->modify($this->mysqlUtcOffsetSeconds() . ' seconds');

        return $dt->format('Y-m-d H:i:s');
    }

    private function safeAttachmentName(string $name): string
    {
        $base = preg_replace('/[^A-Za-z0-9. _()\[\]-]/', '_', basename($name));

        return substr((string) $base, 0, 100) ?: 'attachment';
    }

    private function safeExtension(string $name): string
    {
        $ext = strtolower((string) preg_replace('/[^a-z0-9]/i', '', pathinfo($name, PATHINFO_EXTENSION)));

        return $ext !== '' ? '.' . substr($ext, 0, 10) : '.bin';
    }

    private function contentTypeFromName(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'txt', 'log' => 'text/plain',
            'csv' => 'text/csv',
            default => 'application/octet-stream',
        };
    }

    private function uploadsDir(): string
    {
        $override = getenv('RIVETMSP_PANEL_TEST_UPLOADS_DIR');

        return $override !== false && $override !== '' ? $override : dirname(__DIR__, 4) . '/uploads';
    }
}
