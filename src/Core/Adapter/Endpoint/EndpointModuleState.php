<?php

declare(strict_types=1);

namespace RivetMSP\Core\Adapter\Endpoint;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Contracts\RmmModuleStateInterface;

/**
 * RivetMSP's edition kill switch for the RMM module: settings.config_core_rmm_enabled, the same config_core_<module>_enabled convention as
 * the other Core modules (CoreBridge::enabled('core.rmm.enabled')). Default 0, so a Composer or database update never turns the RMM on.
 * It is read from the database (the module's state file mirrors it, see RmmModule::syncState(): call that whenever this flag is saved).
 *
 * The state file lives in the denied `backups/` area (default backups/rmm-state, overridable by RMM_STATE_DIR in config.php; an empty
 * string disables the zero-database fast path). The pre-bootstrap gate api/v1/rmm_gate.php reads the same default directory, or
 * RMM_GATE_STATE_DIR from the web server environment.
 */
final class EndpointModuleState implements RmmModuleStateInterface
{
    public const FLAG_COLUMN = 'config_core_rmm_enabled';

    public function __construct(private ?DatabaseInterface $database = null)
    {
    }

    public function editionAllows(): bool
    {
        if ($this->database === null) {
            return false;
        }
        try {
            $row = $this->database->fetchOne('SELECT ' . self::FLAG_COLUMN . ' AS f FROM settings WHERE company_id = 1 LIMIT 1');

            return $row !== null && (int) $row['f'] === 1;
        } catch (\Throwable) {
            return false;   // a column that is not there yet (code deployed before its migration), or any database error: off
        }
    }

    public function stateDirectory(): ?string
    {
        return self::directory();
    }

    public static function directory(): ?string
    {
        if (defined('RMM_STATE_DIR')) {
            $dir = (string) constant('RMM_STATE_DIR');

            return $dir === '' ? null : $dir;
        }

        return dirname(__DIR__, 4) . '/backups/rmm-state';
    }
}
