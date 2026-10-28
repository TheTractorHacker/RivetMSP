<?php

namespace RivetMSP\Compliance;

use RivetMSP\Compliance\Backup\RivetMspBackupFactsProvider;
use RivetMSP\Core\Adapter\Database\MysqliDatabaseAdapter;
use RivetCore\Compliance\Assessment;
use RivetCore\Compliance\AttestationStore;
use RivetCore\Compliance\Backup\ArtifactCheckedBackupFactsProvider;
use RivetCore\Compliance\Backup\BackupFactsProviderInterface;
use RivetCore\Compliance\ComplianceAssessor;
use RivetCore\Compliance\SharedReport;
use RivetCore\Compliance\SnapshotStore;
use RivetCore\Compliance\SubjectCompliance;
use RivetCore\Rmm\Store\RmmDataStoreKind;
use RivetCore\Support\SystemClock;

/** Wires the Core compliance engine to this installation. Pages and cron use this and nothing else. */
final class ComplianceService
{
    public static function ready(\mysqli $db): bool
    {
        if (!class_exists(ComplianceAssessor::class)) {
            return false;
        }
        $res = @mysqli_query($db, "SHOW TABLES LIKE 'compliance_snapshots'");

        return (bool) ($res && mysqli_num_rows($res) > 0);
    }

    public static function attestations(\mysqli $db): AttestationStore
    {
        return new AttestationStore(new MysqliDatabaseAdapter($db));
    }

    public static function snapshots(\mysqli $db): SnapshotStore
    {
        return new SnapshotStore(new MysqliDatabaseAdapter($db));
    }

    public static function sharedReady(\mysqli $db): bool
    {
        if (!class_exists(SharedReport::class)) {
            return false;
        }
        $res = @mysqli_query($db, "SHOW TABLES LIKE 'compliance_shared_report'");

        return (bool) ($res && mysqli_num_rows($res) > 0);
    }

    public static function shared(\mysqli $db): SharedReport
    {
        return new SharedReport(new MysqliDatabaseAdapter($db));
    }

    /** Per-customer compliance (subject id = client id). Needs the migration that adds compliance_subjects. */
    public static function subjectsReady(\mysqli $db): bool
    {
        if (!class_exists(SubjectCompliance::class)) {
            return false;
        }
        $res = @mysqli_query($db, "SHOW TABLES LIKE 'compliance_subjects'");

        return (bool) ($res && mysqli_num_rows($res) > 0);
    }

    public static function subjects(\mysqli $db): SubjectCompliance
    {
        return new SubjectCompliance(new MysqliDatabaseAdapter($db), new SystemClock());
    }

    public static function catalog(\mysqli $db): ComplianceCatalog
    {
        $res = mysqli_query($db, 'SELECT * FROM settings WHERE company_id = 1');
        $settings = ($res ? mysqli_fetch_assoc($res) : null) ?: [];

        return new ComplianceCatalog($db, dirname(__DIR__, 2), $settings, self::backupFacts($db, dirname(__DIR__, 2)));
    }

    /**
     * RivetCore's "real backup artifact" provider (rc.53's ArtifactCheckedBackupFactsProvider): RivetMspBackupFactsProvider
     * reads this installation's own run log (backup_runs), then the artifact itself is checked by its content (not its
     * name) for every file of the newest successful run that is actually readable by this process - never the files
     * deploy/backup.sh writes (0600, root:root; that path already asserts encrypted=true from its own honest record,
     * see RivetMspBackupFactsProvider's class doc).
     */
    public static function backupFacts(\mysqli $db, string $appRoot): BackupFactsProviderInterface
    {
        $kind = RmmDataStoreKind::MARIADB;
        try {
            if (function_exists('rivetRmmModule')) {
                $kind = rivetRmmModule($db)->settings()->dataStore();
            }
        } catch (\Throwable) {
            // Falls back to MariaDB (control_db only): a backup check must never fail because the RMM module could not load.
        }
        $inner = new RivetMspBackupFactsProvider($db, $kind);

        return new ArtifactCheckedBackupFactsProvider($inner, static function () use ($db, $appRoot): array {
            $res = @mysqli_query($db, "SELECT run_file FROM backup_runs WHERE run_ok = 1 AND run_file IS NOT NULL ORDER BY run_finished_at DESC LIMIT 1");
            $row = $res ? mysqli_fetch_assoc($res) : null;
            $file = $row['run_file'] ?? null;

            return is_string($file) && $file !== '' ? [$appRoot . '/backups/' . basename($file)] : [];
        });
    }

    public static function assess(\mysqli $db): Assessment
    {
        $catalog = self::catalog($db);

        return (new ComplianceAssessor($catalog->checks(), $catalog->manualItems(), self::attestations($db), new SystemClock()))->assess();
    }
}
