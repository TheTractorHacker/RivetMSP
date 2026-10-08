<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)

use PHPUnit\Framework\TestCase;
use RivetMSP\Core\CoreBridge;

/**
 * Needs a scratch DB holding RivetMSP's schema (import db.sql, run migration 2.6.55). Proves the default-OFF
 * flag, the on-path, and that a broken audit table can never surface as an exception to sign-in.
 */
final class CoreBridgeTest extends TestCase
{
    private mysqli $m;

    protected function setUp(): void
    {
        $name = getenv('RIVETCORE_TEST_DB_NAME');
        if (!$name) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->m = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $name);
        $GLOBALS['mysqli'] = $this->m;
        $this->m->query('DELETE FROM audit_events');
        $this->m->query("UPDATE settings SET config_core_audit_enabled = 0 WHERE company_id = 1");
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $ref = new ReflectionClass(CoreBridge::class);
        foreach (['settings', 'database'] as $p) { // fresh static state per test
            $prop = $ref->getProperty($p);
            $prop->setValue(null, null);
        }
    }

    private function auditCount(): int
    {
        return (int) $this->m->query('SELECT COUNT(*) c FROM audit_events')->fetch_assoc()['c'];
    }

    public function testDisabledByDefaultWritesNothing(): void
    {
        $this->assertFalse(CoreBridge::enabled('core.audit.enabled'));
        CoreBridge::recordLogin('Login', 'Failed', 'Failed login attempt using x@y', 0);
        $this->assertSame(0, $this->auditCount());
    }

    public function testEnabledWritesMappedEvent(): void
    {
        $this->m->query("UPDATE settings SET config_core_audit_enabled = 1 WHERE company_id = 1");
        CoreBridge::recordLogin('Login', 'Success', 'U logged in', 12);
        CoreBridge::recordLogin('Login', 'MFA Failed', 'U failed MFA', 12);
        CoreBridge::recordLogin('Login', 'Blocked', '1.2.3.4 blocked', 0);
        CoreBridge::recordLogin('Ticket', 'Create', 'not a login event', 12);
        $rows = $this->m->query('SELECT event_type, actor_user_id, ip_address FROM audit_events ORDER BY audit_id')->fetch_all(MYSQLI_ASSOC);
        $this->assertSame(['auth.login_success', 'auth.mfa_failed', 'auth.login_blocked'], array_column($rows, 'event_type'));
        $this->assertEquals(12, $rows[0]['actor_user_id']);
        $this->assertNull($rows[2]['actor_user_id']);
        $this->assertSame('198.51.100.7', $rows[0]['ip_address']);
    }

    public function testFailureNeverThrows(): void
    {
        $this->m->query("UPDATE settings SET config_core_audit_enabled = 1 WHERE company_id = 1");
        $this->m->query('ALTER TABLE audit_events RENAME audit_events_gone');
        try {
            CoreBridge::recordLogin('Login', 'Failed', 'x', 0);
            $this->addToAssertionCount(1);
        } finally {
            $this->m->query('ALTER TABLE audit_events_gone RENAME audit_events');
        }
    }

    public function testMissingColumnReadsAsDisabled(): void
    {
        $this->m->query('ALTER TABLE settings DROP COLUMN config_core_audit_enabled');
        try {
            $this->assertFalse(CoreBridge::enabled('core.audit.enabled'));
        } finally {
            $this->m->query('ALTER TABLE settings ADD COLUMN config_core_audit_enabled tinyint(1) NOT NULL DEFAULT 0');
        }
    }
}
