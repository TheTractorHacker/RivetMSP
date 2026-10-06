<?php

declare(strict_types=1);

require_once __DIR__ . '/ConformanceSupport.php';

use RivetCore\ITSM\TicketProblemLinkInterface;

if (ConformanceSupport::kitAvailable()) {
    final class TicketProblemLinkConformanceTest extends \RivetCore\Testing\TicketProblemLinkConformanceTestCase
    {
        protected function links(): TicketProblemLinkInterface
        {
            return new \RivetMSP\Core\Adapter\Itsm\TicketsProblemLink(ConformanceSupport::db());
        }

        protected function createTicket(): int
        {
            return (int) ConformanceSupport::db()->execute(
                "INSERT INTO tickets (ticket_number, ticket_subject, ticket_details, ticket_status, ticket_created_by) VALUES (0, 'conformance', '', 1, 0)"
            )->insertId;
        }

        protected function linkedProblemId(int $ticketId): ?int
        {
            $r = ConformanceSupport::db()->fetchOne('SELECT ticket_problem_id FROM tickets WHERE ticket_id = ?', [$ticketId]);

            return $r === null || $r['ticket_problem_id'] === null ? null : (int) $r['ticket_problem_id'];
        }

        protected function deleteTicket(int $ticketId): void
        {
            ConformanceSupport::db()->execute('DELETE FROM tickets WHERE ticket_id = ?', [$ticketId]);
        }
    }
} else {
    final class TicketProblemLinkConformanceTest extends KitMissingTestCase
    {
    }
}
