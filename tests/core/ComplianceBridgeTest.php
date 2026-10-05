<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivetMSP\Core\CoreBridge;

/** The compliance page records its own changes through CoreBridge::audit(), which is gated by the audit switch. Needs a scratch DB with RivetMSP's schema. */
final class ComplianceBridgeTest extends TestCase
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
        $this->m->query('DELETE FROM audit_events');
        $this->setAudit(0);
    }

    private function setAudit(int $on): void
    {
        $this->m->query("UPDATE settings SET config_core_audit_enabled = $on WHERE company_id = 1");
        $GLOBALS['mysqli'] = $this->m;
        CoreBridge::reset();
    }

    public function testNoAuditServiceWhileTheSwitchIsOff(): void
    {
        $this->assertNull(CoreBridge::audit());
    }

    public function testSwitchingItOnTakesEffectAfterResetAndRecords(): void
    {
        $this->assertNull(CoreBridge::audit());
        $this->setAudit(1);
        $svc = CoreBridge::audit();
        $this->assertNotNull($svc);
        $svc->log('compliance.settings_changed', 7, 'settings', 'compliance', 'update', 'Compliance settings changed', ['after' => ['profile' => 'soc2']]);
        $row = $this->m->query('SELECT * FROM audit_events')->fetch_assoc();
        $this->assertSame('compliance.settings_changed', $row['event_type']);
        $this->assertSame('{"after":{"profile":"soc2"}}', $row['metadata_json']);
        $this->assertMatchesRegularExpression('/^req_[0-9a-f]{16}$/', (string) $row['request_id']);
    }

    public function testTheSettingsColumnsExistWithSafeDefaults(): void
    {
        $r = $this->m->query("SELECT column_name, column_default FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'settings' AND column_name IN ('config_compliance_profile', 'config_audit_retention_days')")->fetch_all(MYSQLI_ASSOC);
        $defaults = array_column($r, 'column_default', 'column_name');
        $this->assertSame("'none'", $defaults['config_compliance_profile']);
        $this->assertSame('365', $defaults['config_audit_retention_days']);
    }

    public function testForcedAuditRecordsEvenWhenTheSwitchIsOff(): void
    {
        $this->assertNull(CoreBridge::audit());
        $svc = CoreBridge::audit(true);
        $this->assertNotNull($svc, 'the change that turns auditing off must still be recordable');
        $svc->log('compliance.settings_changed', 3, 'settings', 'compliance', 'update', 'turned the audit trail off', ['after' => ['audit_recording' => 0]]);
        $this->assertSame(1, (int) $this->m->query("SELECT COUNT(*) FROM audit_events WHERE summary = 'turned the audit trail off'")->fetch_row()[0]);
    }
}

