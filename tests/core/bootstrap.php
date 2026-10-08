<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
// Bootstrap for the RivetCore adapter tests. PHPUnit is not a RivetIT runtime dependency, so point at a
// RivetCore checkout that has run `composer install` (it carries PHPUnit):
//   RIVETCORE_PHPUNIT_AUTOLOAD=/path/to/rivet-core/vendor/autoload.php
// and a scratch MySQL/MariaDB: RIVETCORE_TEST_DB_{NAME,USER,PASS,HOST}. Never point at production.
$phpunit = getenv('RIVETCORE_PHPUNIT_AUTOLOAD');
if ($phpunit && is_file($phpunit)) {
    require $phpunit;
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';

// A schema-only scratch database (db.sql) has no `settings` row; the feature-flag tests read and write it. Make it (scratch only).
if (getenv('RIVETCORE_TEST_DB_NAME') && extension_loaded('mysqli')) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = @new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', (string) getenv('RIVETCORE_TEST_DB_NAME'));
    if (!$m->connect_errno) {
        $r = $m->query('SELECT company_id FROM settings WHERE company_id = 1');
        if ($r !== false && $r->num_rows === 0) {
            $m->query("INSERT INTO settings SET company_id = 1, config_current_database_version = '0'");
        }
    }
}
