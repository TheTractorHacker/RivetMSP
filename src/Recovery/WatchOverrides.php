<?php

namespace RivetMSP\Recovery;

/**
 * Per-integration overrides for the sync watcher (table recovery_watch_overrides, DB 2.6.92): mute an integration, or declare how often
 * it is really expected to run (an external script that syncs once a day is "expected every 24 h", not every 15 minutes). Every read is
 * defensive: before the migration there are simply no overrides.
 */
final class WatchOverrides
{
    public const SOURCES = ['rmm', 'unifi'];
    public const MIN_INTERVAL_S = 60;
    public const MAX_INTERVAL_S = 2592000;   // 30 days

    public static function ready(\mysqli $db): bool
    {
        static $cache = [];
        $key = spl_object_id($db);
        if (!isset($cache[$key])) {
            $r = @mysqli_query($db, "SHOW TABLES LIKE 'recovery_watch_overrides'");
            $cache[$key] = $r && mysqli_num_rows($r) > 0;
        }

        return $cache[$key];
    }

    /** @return array<string,array{muted:bool,interval_s:?int,note:string}> keyed "source:id" */
    public static function all(\mysqli $db): array
    {
        $out = [];
        if (!self::ready($db)) {
            return $out;
        }
        $q = @mysqli_query($db, 'SELECT override_source, override_integration_id, override_muted, override_expected_interval_s, override_note FROM recovery_watch_overrides');
        while ($q && ($r = mysqli_fetch_assoc($q))) {
            $out[$r['override_source'] . ':' . (int) $r['override_integration_id']] = [
                'muted' => (int) $r['override_muted'] === 1,
                'interval_s' => $r['override_expected_interval_s'] === null ? null : (int) $r['override_expected_interval_s'],
                'note' => (string) $r['override_note'],
            ];
        }

        return $out;
    }

    /** Pure: clamp a requested expected interval (seconds) to the allowed range; null/0/negative means "no override". */
    public static function cleanInterval(?int $seconds): ?int
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        return max(self::MIN_INTERVAL_S, min(self::MAX_INTERVAL_S, $seconds));
    }

    /** Creates or replaces the override. A row with no mute, no interval and no note is removed instead. */
    public static function set(\mysqli $db, string $source, int $integrationId, bool $muted, ?int $intervalS, string $note, int $userId = 0): bool
    {
        if (!self::ready($db) || !in_array($source, self::SOURCES, true) || $integrationId <= 0) {
            return false;
        }
        $intervalS = self::cleanInterval($intervalS);
        $note = trim(substr($note, 0, 255));
        if (!$muted && $intervalS === null && $note === '') {
            return self::remove($db, $source, $integrationId);
        }
        $stmt = mysqli_prepare($db, 'INSERT INTO recovery_watch_overrides (override_source, override_integration_id, override_muted, override_expected_interval_s, override_note, override_updated_by)
            VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE override_muted = VALUES(override_muted), override_expected_interval_s = VALUES(override_expected_interval_s),
            override_note = VALUES(override_note), override_updated_by = VALUES(override_updated_by)');
        if (!$stmt) {
            return false;
        }
        $m = $muted ? 1 : 0;
        mysqli_stmt_bind_param($stmt, 'siiisi', $source, $integrationId, $m, $intervalS, $note, $userId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return (bool) $ok;
    }

    public static function remove(\mysqli $db, string $source, int $integrationId): bool
    {
        if (!self::ready($db)) {
            return false;
        }
        $stmt = mysqli_prepare($db, 'DELETE FROM recovery_watch_overrides WHERE override_source = ? AND override_integration_id = ?');
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'si', $source, $integrationId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return (bool) $ok;
    }

    /** Mute one integration, keeping any interval and note already set. */
    public static function mute(\mysqli $db, string $source, int $integrationId, int $userId = 0): bool
    {
        $cur = self::all($db)["$source:$integrationId"] ?? ['muted' => false, 'interval_s' => null, 'note' => ''];

        return self::set($db, $source, $integrationId, true, $cur['interval_s'], $cur['note'], $userId);
    }

    /**
     * "Forget history": removes the integration's old sync-log rows and its alert state, so a dormant integration drops off the watch
     * list until it runs again. Returns the number of log rows removed (0 for an unknown source).
     */
    public static function forgetHistory(\mysqli $db, string $source, int $integrationId): int
    {
        $table = ['rmm' => 'rmm_sync_log', 'unifi' => 'unifi_sync_log'][$source] ?? null;
        if ($table === null || $integrationId <= 0) {
            return 0;
        }
        @mysqli_query($db, "DELETE FROM `$table` WHERE integration_id = " . $integrationId);
        $n = max(0, mysqli_affected_rows($db));
        foreach (['failing', 'stale', 'dormant'] as $kind) {
            RecoveryAlerts::clear($db, "sync.$source.$integrationId.$kind");
        }

        return $n;
    }
}
