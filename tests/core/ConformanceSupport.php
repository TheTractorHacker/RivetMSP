<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)

use RivetMSP\Core\Adapter\Database\MysqliDatabaseAdapter;

/**
 * Shared helpers for the *ConformanceTest classes (RivetCore adapter conformance kit, docs/conformance.md in rivet-core).
 *
 * The kit ships in RivetCore from v1.0.0-rc.1 on. Until the version pinned in composer.json carries it, each *ConformanceTest
 * class is a KitMissingTestCase: one skipped test that gives the reason.
 */
// Stand-ins for functions.php (the adapters call them; the real ones need the whole app). Same ENC: envelope shape.
if (!function_exists('encryptSetting')) {
    function encryptSetting(string $p): string { return $p === '' ? $p : 'ENC:' . $p; }
}
if (!function_exists('decryptSetting')) {
    function decryptSetting(string $c): string { return str_starts_with($c, 'ENC:') ? substr($c, 4) : $c; }
}

/** Stands in for a conformance class while the installed RivetCore has no kit: one skipped test that says why. */
abstract class KitMissingTestCase extends \PHPUnit\Framework\TestCase
{
    public function testConformanceKitIsInstalled(): void
    {
        $this->markTestSkipped('The RivetCore conformance kit (RivetCore\\Testing\\*ConformanceTestCase) is not in the installed rivet/rivet-core; '
            . 'it ships from v1.0.0-rc.1. Until composer.json pins that, point RIVETCORE_PHPUNIT_AUTOLOAD at a rivet-core checkout that has it.');
    }
}

final class ConformanceSupport
{
    private static ?MysqliDatabaseAdapter $db = null;

    public static function kitAvailable(): bool
    {
        return class_exists(\RivetCore\Testing\WebhookSubscriptionsConformanceTestCase::class)
            && class_exists(\RivetCore\Testing\RequestContextConformanceTestCase::class)
            && class_exists(\RivetCore\Testing\SettingsConformanceTestCase::class)
            && class_exists(\RivetCore\Testing\RedisClientProviderConformanceTestCase::class)
            && class_exists(\RivetCore\Testing\TicketProblemLinkConformanceTestCase::class);
    }

    /** The scratch database (never production). Skips the calling test when RIVETCORE_TEST_DB_NAME is not set. */
    public static function db(): MysqliDatabaseAdapter
    {
        if (self::$db !== null) {
            return self::$db;
        }
        $name = getenv('RIVETCORE_TEST_DB_NAME');
        if (!$name) {
            \PHPUnit\Framework\Assert::markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $m = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $name);
        $m->set_charset('utf8mb4');
        return self::$db = new MysqliDatabaseAdapter($m);
    }

    /** The `settings` row the Settings adapter reads. A schema-only scratch database has none, so make one (scratch only). */
    public static function ensureSettingsRow(): void
    {
        $db = self::db();
        if ($db->fetchOne('SELECT company_id FROM settings WHERE company_id = 1') === null) {
            $db->execute("INSERT INTO settings SET company_id = 1, config_current_database_version = '0'");
        }
    }
}
