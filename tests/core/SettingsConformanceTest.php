<?php

declare(strict_types=1);

require_once __DIR__ . '/ConformanceSupport.php';

use RivetCore\Contracts\SettingsInterface;

if (ConformanceSupport::kitAvailable()) {
    final class SettingsConformanceTest extends \RivetCore\Testing\SettingsConformanceTestCase
    {
        protected function settings(): SettingsInterface
        {
            ConformanceSupport::ensureSettingsRow();

            // A fresh adapter per call: it caches the settings row, and seededSettings() changes it first.
            return new \RivetMSP\Core\Adapter\Settings\SettingsTableSettings(ConformanceSupport::db());
        }

        /** Seeds a falsy and a truthy stored flag so "stored value, not the default" is exercised. */
        protected function seededSettings(): array
        {
            ConformanceSupport::ensureSettingsRow();
            ConformanceSupport::db()->execute('UPDATE settings SET config_core_audit_enabled = 0, config_core_jobs_enabled = 1 WHERE company_id = 1');

            return ['core.audit.enabled' => '0', 'core.jobs.enabled' => '1'];
        }
    }
} else {
    final class SettingsConformanceTest extends KitMissingTestCase
    {
    }
}
