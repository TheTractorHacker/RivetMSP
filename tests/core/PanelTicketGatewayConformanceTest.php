<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)

// functions.php first: it has its own, real encryptSetting()/decryptSetting(), and ConformanceSupport.php's
// stand-ins are function_exists()-guarded, so loading the real ones first (not after) avoids a redeclare fatal.
require_once dirname(__DIR__, 2) . '/functions.php';
require_once __DIR__ . '/ConformanceSupport.php';
require_once dirname(__DIR__, 2) . '/src/Core/Adapter/Endpoint/EndpointPanelGateway.php';

use RivetCore\Panel\Contracts\PanelTicketGatewayInterface;

if (ConformanceSupport::kitAvailable() && class_exists(\RivetCore\Testing\PanelTicketGatewayConformanceTestCase::class)) {
    final class PanelTicketGatewayConformanceTest extends \RivetCore\Testing\PanelTicketGatewayConformanceTestCase
    {
        private static ?\mysqli $raw = null;

        /** The gateway needs a raw \mysqli (it reuses the app's own prepared-statement/global-helper style), not the DatabaseInterface wrapper the rest of the kit uses. */
        private function rawMysqli(): \mysqli
        {
            if (self::$raw instanceof \mysqli) {
                return self::$raw;
            }
            $name = getenv('RIVETCORE_TEST_DB_NAME');
            if (!$name) {
                $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
            }
            mysqli_report(MYSQLI_REPORT_OFF);
            $m = new \mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $name);
            $m->set_charset('utf8mb4');
            // Without this, a strict-mode NOT NULL violation on an unrelated column (clients.client_currency_code
            // has no default) makes an INSERT silently fail under mysqli_report(OFF) - insert_id then keeps its
            // PREVIOUS value instead of erroring, so two "different" test clients/contacts end up with the same id
            // and every cross-client isolation assertion in this kit passes for the wrong reason. The real app
            // bootstrap (includes/db.php) sets this same session mode for exactly this reason.
            $m->query("SET SESSION sql_mode=''");
            $GLOBALS['mysqli'] = $m;
            $GLOBALS['session_user_id'] = 0;
            $GLOBALS['session_name'] = 'conformance';
            $GLOBALS['session_ip'] = '127.0.0.1';
            $GLOBALS['session_user_agent'] = 'conformance';
            $GLOBALS['config_ticket_default_technician_id'] = null;
            $GLOBALS['config_ticket_default_category_id'] = 0;
            $GLOBALS['config_ticket_default_status_id'] = 0;
            // The gateway's ticket-number allocator reads settings.company_id = 1 directly; a schema-only scratch
            // DB has no row, so this kit (like ConformanceSupport::ensureSettingsRow() for the Settings kit) seeds
            // the minimum one.
            if ($m->query('SELECT 1 FROM settings WHERE company_id = 1')->num_rows === 0) {
                $m->query("INSERT INTO settings SET company_id = 1, config_current_database_version = '0', config_ticket_next_number = 1000, config_ticket_prefix = 'T'");
            }

            return self::$raw = $m;
        }

        protected function gateway(): PanelTicketGatewayInterface
        {
            return new \RivetMSP\Core\Adapter\Endpoint\EndpointPanelGateway($this->rawMysqli());
        }

        protected function createClient(string $name): int
        {
            $db = $this->rawMysqli();
            $esc = $db->real_escape_string($name);
            $db->query("INSERT INTO clients SET client_name = '$esc'");

            return (int) $db->insert_id;
        }

        protected function createContact(int $clientId, string $name): int
        {
            $db = $this->rawMysqli();
            $esc = $db->real_escape_string($name);
            $db->query("INSERT INTO contacts SET contact_name = '$esc', contact_client_id = $clientId");

            return (int) $db->insert_id;
        }

        protected function archiveContact(int $contactId): void
        {
            $this->rawMysqli()->query("UPDATE contacts SET contact_archived_at = NOW() WHERE contact_id = $contactId");
        }

        protected function createCategory(int $clientId, string $name): int
        {
            // Categories are global in RivetMSP (not per-client - confirmed by reading agent/certificates.php's
            // categories table and this gateway's own categories() method), so $clientId is unused here, matching
            // the gateway's own behaviour.
            $db = $this->rawMysqli();
            $esc = $db->real_escape_string($name . '-' . bin2hex(random_bytes(3)));
            $db->query("INSERT INTO categories SET category_name = '$esc', category_type = 'Ticket'");

            return (int) $db->insert_id;
        }

        protected function addStaffReply(int $ticketId, string $body): void
        {
            $db = $this->rawMysqli();
            $esc = $db->real_escape_string($body);
            $db->query("INSERT INTO ticket_replies SET ticket_reply = '$esc', ticket_reply_type = 'Public', ticket_reply_by = 0, ticket_reply_ticket_id = $ticketId");
        }

        protected function addInternalNote(int $ticketId, string $body): void
        {
            $db = $this->rawMysqli();
            $esc = $db->real_escape_string($body);
            $db->query("INSERT INTO ticket_replies SET ticket_reply = '$esc', ticket_reply_type = 'Internal', ticket_reply_by = 0, ticket_reply_ticket_id = $ticketId");
        }
    }
} else {
    final class PanelTicketGatewayConformanceTest extends KitMissingTestCase
    {
    }
}
