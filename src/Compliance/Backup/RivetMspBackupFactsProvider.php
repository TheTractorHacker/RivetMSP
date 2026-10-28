<?php

declare(strict_types=1);

namespace RivetMSP\Compliance\Backup;

use RivetCore\Compliance\Backup\BackupFacts;
use RivetCore\Compliance\Backup\BackupFactsProviderInterface;
use RivetCore\Compliance\Backup\BackupStore;
use RivetCore\Rmm\Store\RmmDataStoreKind;

/**
 * What RivetMSP tells RivetCore's compliance checks about its backup, read from this installation's own run log
 * (table `backup_runs`, DB 2.6.81, written by both backup paths - see src/Recovery/BackupStatus.php): the newest
 * SUCCESSFUL run, never the configuration (a scheduled-backup switch being on proves nothing about whether the
 * last run actually finished).
 *
 * Two backup paths write to that log, with different honesty about encryption:
 *  - `run_kind = 'script'`: deploy/backup.sh, run as root. It REFUSES to run without a passphrase file and always
 *    produces an openssl-encrypted `.enc` archive (0600, root:root - not even readable by this www-data process,
 *    which is why this provider asserts `encrypted = true` from its own record rather than relying on
 *    {@see \RivetCore\Compliance\Backup\ArtifactCheckedBackupFactsProvider}'s file inspection to confirm it: an
 *    unreadable file inspects as "unknown" and the decorator never upgrades or downgrades on "unknown").
 *  - `run_kind = 'app_auto' | 'app_manual'`: the in-app builder (admin/post/backup.php). Its zip is readable by
 *    www-data (0640) but its db.sql/uploads.zip entries are plain zip content - only the manifest inside
 *    (`backup-manifest.json.enc`, carrying the settings key) is passphrase-encrypted. This provider reports that
 *    honestly as `encrypted = false`; ArtifactCheckedBackupFactsProvider's own header check of the readable zip
 *    confirms it (zip is a recognised plaintext container) rather than correcting it.
 *
 * Never throws (the conformance rule): a missing table, a bad row or a database error all fall back to "unavailable".
 *
 * @see \RivetMSP\Recovery\BackupStatus
 */
final class RivetMspBackupFactsProvider implements BackupFactsProviderInterface
{
    public function __construct(private \mysqli $db, private string $rmmDataStoreKind = RmmDataStoreKind::MARIADB)
    {
    }

    public function backupFacts(): BackupFacts
    {
        try {
            $t = @mysqli_query($this->db, "SHOW TABLES LIKE 'backup_runs'");
            if (!$t || mysqli_num_rows($t) === 0) {
                return BackupFacts::unavailable('The backup_runs table does not exist yet (run the database update).');
            }
            $row = $this->newestGoodRun();
            if ($row === null) {
                // Table exists, nothing has ever finished successfully: claim nothing but "available" (per BackupFacts' own honesty rule).
                return new BackupFacts(true, source: 'backup_runs');
            }
            $finishedAt = (new \DateTimeImmutable('@' . (int) $row['finished_epoch']))->setTimezone(new \DateTimeZone('UTC'));
            $isScript = (string) $row['run_kind'] === 'script';
            $offsiteOk = is_string($row['run_offsite_result']) && str_starts_with($row['run_offsite_result'], 'ok');
            $destinations = $offsiteOk ? [BackupFacts::DEST_LOCAL, BackupFacts::DEST_OFFSITE] : [BackupFacts::DEST_LOCAL];
            $offsiteAt = $offsiteOk ? $finishedAt : null;

            return new BackupFacts(
                available: true,
                lastSuccessAt: $finishedAt,
                includedStores: BackupStore::required($this->rmmDataStoreKind),
                destinations: $destinations,
                encrypted: $isScript ? true : false,
                retentionDays: null, // both paths prune by count/days configured at run time, not recorded per-run; honestly unknown here.
                lastOffsiteSuccessAt: $offsiteAt,
                encryptionMethod: $isScript ? 'openssl enc' : null,
                source: 'backup_runs',
            );
        } catch (\Throwable) {
            return BackupFacts::unavailable('Could not read the backup run records.');
        }
    }

    /** @return array{run_kind:string, run_offsite_result:?string, finished_epoch:string}|null */
    private function newestGoodRun(): ?array
    {
        $res = @mysqli_query(
            $this->db,
            "SELECT run_kind, run_offsite_result, UNIX_TIMESTAMP(run_finished_at) AS finished_epoch
             FROM backup_runs WHERE run_ok = 1 AND run_finished_at IS NOT NULL
             ORDER BY run_finished_at DESC LIMIT 1"
        );

        return $res ? (mysqli_fetch_assoc($res) ?: null) : null;
    }
}
