<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Rmm\Contracts\RmmModuleStateInterface;
use RivetCore\Testing\RmmModuleStateConformanceTestCase;

/** EndpointModuleState: the edition kill switch is settings.config_core_rmm_enabled (default 0); the state directory is backups/rmm-state or RMM_STATE_DIR. */
final class EndpointModuleStateConformanceTest extends RmmModuleStateConformanceTestCase
{
    protected function state(): RmmModuleStateInterface
    {
        $dir = sys_get_temp_dir() . '/rmm_state_conf_' . getmypid();
        @mkdir($dir, 0750, true);
        if (!defined('RMM_STATE_DIR')) {
            define('RMM_STATE_DIR', $dir);
        }

        return new \RivetMSP\Core\Adapter\Endpoint\EndpointModuleState(EndpointKit::db());
    }

    public function testTheEditionSwitchFollowsTheSettingsFlagAndDefaultsToOff(): void
    {
        $db = EndpointKit::db();
        if ($db->fetchOne('SELECT company_id FROM settings WHERE company_id = 1') === null) {
            $db->execute("INSERT INTO settings SET company_id = 1, config_current_database_version = '0'");
        }
        $db->execute('UPDATE settings SET config_core_rmm_enabled = 0 WHERE company_id = 1');
        $state = new \RivetMSP\Core\Adapter\Endpoint\EndpointModuleState($db);
        $this->assertFalse($state->editionAllows());
        $db->execute('UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1');
        $this->assertTrue($state->editionAllows());
        $db->execute('UPDATE settings SET config_core_rmm_enabled = 0 WHERE company_id = 1');
        $this->assertFalse($state->editionAllows());
        $this->assertFalse((new \RivetMSP\Core\Adapter\Endpoint\EndpointModuleState(null))->editionAllows(), 'no database: off');
    }

    public function testAColumnThatIsNotThereYetReadsAsOff(): void
    {
        $broken = new \RivetMSP\Core\Adapter\Endpoint\EndpointModuleState(new class(EndpointKit::db()) implements \RivetCore\Database\DatabaseInterface {
            public function __construct(private \RivetCore\Database\DatabaseInterface $inner) {}
            public function fetchOne(string $sql, array $params = []): ?array { return $this->inner->fetchOne('SELECT config_core_no_such_flag AS f FROM settings WHERE company_id = 1'); }
            public function fetchAll(string $sql, array $params = []): array { return []; }
            public function execute(string $sql, array $params = []): \RivetCore\Database\ExecutionResult { return $this->inner->execute($sql, $params); }
            public function transaction(callable $callback): mixed { return $callback($this); }
        });
        $this->assertFalse($broken->editionAllows());
    }
}
