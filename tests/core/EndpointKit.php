<?php

declare(strict_types=1);

use RivetMSP\Core\Adapter\Database\MysqliDatabaseAdapter;

/**
 * Plumbing for the conformance tests of the RMM module adapters (src/Core/Adapter/Endpoint): a scratch-database handle in the connection's DEFAULT
 * sql_mode (strict, as the app runs), and a logAction() stand-in that writes the same `logs` row the real one does (functions.php needs a whole
 * app bootstrap). Never point RIVETCORE_TEST_DB_NAME at a real database.
 */
if (!function_exists('logAction')) {
    function logAction($type, $action, $description, $client_id = 0, $entity_id = 0): void
    {
        EndpointKit::db()->execute('INSERT INTO logs SET log_type = ?, log_action = ?, log_description = ?, log_ip = ?, log_user_agent = ?, log_client_id = ?, log_user_id = 0, log_entity_id = ?',
            [substr((string) $type, 0, 200), substr((string) $action, 0, 255), substr((string) $description, 0, 1000), 'conformance', 'conformance', (int) $client_id, (int) $entity_id]);
    }
}

final class EndpointKit
{
    private static ?MysqliDatabaseAdapter $db = null;

    public static function db(): MysqliDatabaseAdapter
    {
        if (self::$db === null) {
            $name = getenv('RIVETCORE_TEST_DB_NAME');
            if (!$name || !preg_match('/scratch|test/i', (string) $name)) {
                throw new \PHPUnit\Framework\SkippedWithMessageException('RIVETCORE_TEST_DB_NAME not set to a scratch/test database.');
            }
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $m = new \mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $name);
            $m->set_charset('utf8mb4');
            $GLOBALS['mysqli'] = $m;
            self::$db = new MysqliDatabaseAdapter($m);
        }

        return self::$db;
    }

    public static function mysqli(): \mysqli
    {
        self::db();

        return $GLOBALS['mysqli'];
    }

    public static function client(string $name = 'conformance'): int
    {
        return (int) self::db()->execute("INSERT INTO clients SET client_name = ?, client_currency_code = 'USD', client_net_terms = 30, client_created_at = NOW()", [$name])->insertId;
    }

    /** An asset in the default (strict) sql_mode: asset_make has no default in RivetMSP. */
    public static function asset(string $name, int $clientId = 0): int
    {
        return (int) self::db()->execute("INSERT INTO assets SET asset_type = 'Laptop', asset_name = ?, asset_make = '', asset_status = 'Active', asset_client_id = ?, asset_created_at = NOW()", [$name, $clientId])->insertId;
    }
}
