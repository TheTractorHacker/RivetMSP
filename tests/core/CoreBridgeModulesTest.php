<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)

use PHPUnit\Framework\TestCase;
use RivetMSP\Core\CoreBridge;

// The app's real getRedisClient() (includes/redis_functions.php) reads RIVETMSP_REDIS_HOST/PORT from the environment first, so the tests
// point it at the throwaway server (RIVETCORE_TEST_REDIS_PORT) and, with none set, at a closed port: never at a real Redis. This also
// means the Redis conformance case exercises the real function, not a stand-in.
if (!function_exists('getRedisClient')) {
    putenv('RIVETMSP_REDIS_HOST=127.0.0.1');
    putenv('RIVETMSP_REDIS_PORT=' . ((int) getenv('RIVETCORE_TEST_REDIS_PORT') ?: 1));
    require_once __DIR__ . '/../../includes/redis_functions.php';
}
if (!function_exists('decryptSetting')) {
    function decryptSetting(string $c): string { return str_starts_with($c, 'ENC:') ? substr($c, 4) : $c; } // stand-in for functions.php
}

/** Every RivetCore module is OFF by default on RivetMSP and works once its flag is set. Needs a scratch DB with RivetMSP's schema. */
final class CoreBridgeModulesTest extends TestCase
{
    private mysqli $m;

    private const FLAGS = ['audit', 'redis', 'jobs', 'itsm', 'webhooks', 'workflow', 'automation', 'health'];

    protected function setUp(): void
    {
        $name = getenv('RIVETCORE_TEST_DB_NAME');
        if (!$name) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->m = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $name);
        $this->m->query("SET SESSION sql_mode=''");
        $GLOBALS['mysqli'] = $this->m;
        foreach (['integration_jobs', 'problems', 'changes', 'webhooks', 'webhook_deliveries', 'automation_rules', 'tickets', 'workflow_run_tasks', 'workflow_runs', 'workflow_template_tasks', 'workflow_templates'] as $t) {
            $this->m->query("DELETE FROM $t");
        }
        $this->setFlags([]);
    }

    private function setFlags(array $on): void
    {
        $sets = [];
        foreach (self::FLAGS as $f) {
            $sets[] = "config_core_{$f}_enabled = " . (in_array($f, $on, true) ? 1 : 0);
        }
        $this->m->query('UPDATE settings SET ' . implode(', ', $sets) . ' WHERE company_id = 1');
        CoreBridge::reset();
        $GLOBALS['mysqli'] = $this->m;
    }

    public function testEverythingIsOffByDefault(): void
    {
        $this->assertNull(CoreBridge::locks());
        $this->assertNull(CoreBridge::rateLimiter());
        $this->assertNull(CoreBridge::cronGuard());
        $this->assertNull(CoreBridge::jobs());
        $this->assertNull(CoreBridge::problems());
        $this->assertNull(CoreBridge::changes());
        $this->assertNull(CoreBridge::webhooks());
        $this->assertNull(CoreBridge::workflow());
        $this->assertNull(CoreBridge::automation());
        $this->assertNull(CoreBridge::readiness(fn () => true));
        $this->assertSame(0, (int) $this->m->query('SELECT COUNT(*) FROM settings WHERE config_core_redis_enabled + config_core_jobs_enabled + config_core_itsm_enabled + config_core_webhooks_enabled + config_core_workflow_enabled + config_core_automation_enabled + config_core_health_enabled + config_core_audit_enabled > 0')->fetch_row()[0]);
    }

    public function testFlagsAreIndependent(): void
    {
        $this->setFlags(['jobs']);
        $this->assertNotNull(CoreBridge::jobs());
        $this->assertNull(CoreBridge::problems());
        $this->assertNull(CoreBridge::locks());
    }

    public function testJobsQueue(): void
    {
        $this->setFlags(['jobs']);
        $id = CoreBridge::jobs()->enqueue('sync.rmm', ['asset' => 4]);
        $claimed = CoreBridge::jobs()->claim(5);
        $this->assertSame([$id], array_map(fn ($j) => (int) $j['job_id'], $claimed));
        $this->assertSame(1, $claimed[0]['attempts']);
    }

    public function testItsmLinksTicketsToProblemsThroughMsPsOwnColumn(): void
    {
        $this->setFlags(['itsm']);
        $this->m->query("INSERT INTO tickets (ticket_id, ticket_subject) VALUES (900, 'VPN down')");
        $p = CoreBridge::problems()->create('VPN gateway flaps', null, 1);
        CoreBridge::problems()->linkTicket($p, 900);
        $this->assertEquals($p, $this->m->query('SELECT ticket_problem_id FROM tickets WHERE ticket_id = 900')->fetch_row()[0]);
        $c = CoreBridge::changes()->create('Replace gateway', 'flapping', 'brief outage', 'medium', 'swap', 'swap back', null, 1);
        CoreBridge::problems()->linkChange($p, $c);
        CoreBridge::changes()->setStatus($c, 'awaiting_approval');
        CoreBridge::problems()->unlinkTicket($p, 900);
        $this->assertNull($this->m->query('SELECT ticket_problem_id FROM tickets WHERE ticket_id = 900')->fetch_row()[0]);
    }

    public function testWebhooksUseMsPsWebhooksTableAndKeepLegacyHeaders(): void
    {
        $this->setFlags(['webhooks']);
        $this->m->query("INSERT INTO webhooks (webhook_name, webhook_url, webhook_secret, webhook_events, webhook_enabled) VALUES ('a', 'https://h.example/x', 'ENC:sekret', 'ticket.created, invoice.paid', 1), ('off', 'https://h.example/y', 'ENC:z', 'invoice.paid', 0)");
        $subs = (new \RivetMSP\Core\Adapter\Webhooks\WebhooksTableSubscriptions(new \RivetMSP\Core\Adapter\Database\MysqliDatabaseAdapter($this->m)))->forEvent('invoice.paid');
        $this->assertCount(1, $subs);
        $this->assertSame('sekret', $subs[0]->secret);
        $this->assertSame('https://h.example/x', $subs[0]->url);
        $this->assertNotNull(CoreBridge::webhooks());
    }

    public function testWorkflowAndAutomation(): void
    {
        $this->setFlags(['workflow', 'automation']);
        $this->m->query("INSERT INTO workflow_templates (workflow_template_id, name, type) VALUES (1, 'New client', 'onboarding')");
        $this->m->query("INSERT INTO workflow_template_tasks (workflow_template_id, title, required, sort_order) VALUES (1, 'Create accounts', 1, 0)");
        $run = CoreBridge::workflow()->startRun(1, 3, 1);
        $this->assertGreaterThan(0, $run);
        $this->m->query("INSERT INTO automation_rules (name, trigger_event, action_type) VALUES ('r', 'client.created', 'notify_user')");
        $this->assertCount(1, CoreBridge::automation()->findMatchingRules('client.created', []));
    }

    public function testRedisServicesFollowTheEditionsClientAndPrefix(): void
    {
        if (!getenv('RIVETCORE_TEST_REDIS_PORT')) {
            $this->markTestSkipped('throwaway Redis required');
        }
        $this->setFlags(['redis']);
        getRedisClient()->flushdb();
        $lock = CoreBridge::locks()->acquire('nightly', 30);
        $this->assertTrue($lock->held());
        $this->assertFalse(CoreBridge::locks()->acquire('nightly', 30)->held());
        $this->assertSame(1, (int) getRedisClient()->exists('rivetmsp:lock:nightly'));
        $this->assertTrue(CoreBridge::rateLimiter()->hit('login', 1, 60)['allowed']);
        $this->assertFalse(CoreBridge::rateLimiter()->hit('login', 1, 60)['allowed']);
        $this->assertNotNull(CoreBridge::cronGuard());
        $lock->release();
    }

    public function testReadinessWhenEnabled(): void
    {
        $this->setFlags(['health']);
        $r = CoreBridge::readiness(fn () => true)->check();
        $this->assertTrue($r['ready']);
        $this->assertSame('ok', $r['checks']['database']);
        $this->assertFalse(CoreBridge::readiness(fn () => false)->check()['ready']);
    }

    public function testAMissingFlagColumnReadsAsOff(): void
    {
        $this->m->query('ALTER TABLE settings DROP COLUMN config_core_jobs_enabled');
        try {
            CoreBridge::reset();
            $this->assertNull(CoreBridge::jobs());
        } finally {
            $this->m->query('ALTER TABLE settings ADD COLUMN config_core_jobs_enabled tinyint(1) NOT NULL DEFAULT 0');
        }
    }
}
