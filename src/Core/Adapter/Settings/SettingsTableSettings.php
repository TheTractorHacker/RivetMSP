<?php

declare(strict_types=1);

namespace RivetMSP\Core\Adapter\Settings;

use RivetCore\Contracts\SettingsInterface;
use RivetCore\Database\DatabaseInterface;

/**
 * Reads RivetCore feature flags from the one-row `settings` table.
 * Key "core.audit.enabled" maps to column `config_core_audit_enabled`. Unknown keys, a missing column
 * (code deployed before its migration) or any database error all read as the default, so a flag can
 * never take the site down.
 */
final class SettingsTableSettings implements SettingsInterface
{
    /** @var array<string,mixed>|null */
    private ?array $row = null;

    public function __construct(private DatabaseInterface $database)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!preg_match('/^core\.[a-z_]+\.enabled$/', $key)) {
            return $default;
        }
        $column = 'config_' . str_replace('.', '_', $key);
        $row = $this->row();

        return array_key_exists($column, $row) ? $row[$column] : $default;
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        if ($this->row === null) {
            try {
                $this->row = $this->database->fetchOne('SELECT * FROM settings WHERE company_id = 1 LIMIT 1') ?? [];
            } catch (\Throwable) {
                $this->row = [];
            }
        }

        return $this->row;
    }
}
