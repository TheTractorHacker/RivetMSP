<?php

namespace RivetMSP\Recovery;

/**
 * Alerts for integration syncs that stopped running or keep failing: RMM (rmm_sync_log: Tactical, Level, Action1 and Sophos
 * Central integrations) and UniFi (unifi_sync_log). Per integration the watcher state is one of:
 *
 *   healthy   it ran recently and is not failing
 *   failing   its last N runs (recovery setting sync_error_threshold, default 3) all ended in a REAL error (an errored status with a
 *             message that is not the "Endpoint agent devices are managed from the device page" sentinel an external loop writes)
 *   stale     no run in 3x the expected interval (the global setting, or the interval an admin declared for that integration)
 *   dormant   its newest run is older than sync_dormant_days (default 14): nothing is wrong that anyone can act on by email, so no
 *             alert; at most one "no longer running" notice (per 30 days), and only when it was healthy before and has only just gone
 *             quiet. An explicit expected interval (override) opts out of dormancy: a script declared "every 24 h" that stops is stale.
 *   muted     an admin muted it
 *
 * Integration types without a vendor API (rivetit_agent) are never watched. A failing/stale alert repeats at most every
 * alert_repeat_hours (default 24) and its recovery is announced once (RecoveryAlerts).
 *
 * assess(), evaluate() and inferInterval() are pure so the rules are tested without a database.
 */
final class SyncWatch
{
    public const STALE_FACTOR = 3;
    public const DORMANT_NOTICE_HOURS = 720;          // one "no longer running" notice per 30 days
    public const DORMANT_NOTICE_WINDOW_DAYS = 7;      // ... and only while it has been dormant for less than this long
    public const SENTINEL = 'Endpoint agent devices are managed from the device page';
    /** Integration types that have no vendor API and are never watched (they are managed from the device page). */
    public const UNWATCHED_RMM_TYPES = ['rivetit_agent'];
    private const STUCK_RUNNING_SECONDS = 3600;

    /** Pure. The sentinel an external "sync every enabled integration" loop writes against the wrong integration's row. */
    public static function isSentinelError(?string $errors): bool
    {
        return $errors !== null && stripos($errors, self::SENTINEL) !== false;
    }

    /** Pure. A run is an error when it failed with a real message, or has been "running" for over an hour with no finish. */
    public static function runErrored(array $run, int $nowTs): bool
    {
        $status = strtolower((string) ($run['status'] ?? ''));
        if (in_array($status, ['failed', 'error', 'errored'], true)) {
            if (!array_key_exists('errors', $run)) {
                return true;   // a caller that does not carry the message keeps the plain status rule
            }
            $e = trim((string) $run['errors']);

            return $e !== '' && !self::isSentinelError($e);
        }

        return $status === 'running' && empty($run['finished']) && ($nowTs - (int) ($run['started'] ?? $nowTs)) > self::STUCK_RUNNING_SECONDS;
    }

    /**
     * Pure. Median gap in minutes between scheduled runs, from run start times (newest first), or null when there are too few
     * to tell. Floored at 5 minutes.
     *
     * @param list<int> $startsDesc
     */
    public static function inferInterval(array $startsDesc): ?int
    {
        $startsDesc = array_values($startsDesc);
        if (count($startsDesc) < 3) {
            return null;
        }
        $gaps = [];
        for ($i = 0; $i + 1 < count($startsDesc); $i++) {
            $gaps[] = max(0, $startsDesc[$i] - $startsDesc[$i + 1]);
        }
        sort($gaps);
        $median = $gaps[intdiv(count($gaps), 2)];

        return max(5, (int) round($median / 60));
    }

    /**
     * Pure. The failing / stale rules only.
     *
     * @param list<array{status:string,started:int,finished:?int}> $runs newest first
     * @return array{state:string,detail:string} state: ok | stale | failing
     */
    public static function assess(array $runs, ?int $createdTs, int $nowTs, ?int $intervalMin, int $errorThreshold): array
    {
        $errorThreshold = max(1, $errorThreshold);
        if (count($runs) >= $errorThreshold) {
            $allBad = true;
            for ($i = 0; $i < $errorThreshold; $i++) {
                if (!self::runErrored($runs[$i], $nowTs)) {
                    $allBad = false;
                    break;
                }
            }
            if ($allBad) {
                return ['state' => 'failing', 'detail' => "the last $errorThreshold runs all ended in an error"];
            }
        }
        if ($intervalMin === null || $intervalMin <= 0) {
            return ['state' => 'ok', 'detail' => 'staleness watch off'];
        }
        $limit = self::STALE_FACTOR * $intervalMin * 60;
        if ($runs === []) {
            if ($createdTs !== null && ($nowTs - $createdTs) > $limit) {
                return ['state' => 'stale', 'detail' => 'it has never run since it was set up'];
            }

            return ['state' => 'ok', 'detail' => 'no runs yet'];
        }
        $lastStart = (int) $runs[0]['started'];
        if (($nowTs - $lastStart) > $limit) {
            return ['state' => 'stale', 'detail' => 'no run for ' . self::human($nowTs - $lastStart) . ' (expected every ' . self::humanInterval($intervalMin * 60) . ')'];
        }

        return ['state' => 'ok', 'detail' => 'last run ' . self::human($nowTs - $lastStart) . ' ago'];
    }

    /**
     * Pure. The full per-integration verdict.
     *
     * @param list<array{status:string,started:int,finished:?int,errors?:?string}> $runs newest first, sentinel rows already dropped
     * @param ?int   $globalIntervalMin the global expected interval in minutes (setting or inferred); null/0 = staleness watch off
     * @param ?array{muted:bool,interval_s:?int,note:string} $override
     * @return array{state:string,detail:string,last_ts:?int,since_ts:?int,interval_s:?int,notice:bool}
     *         state: healthy | failing | stale | dormant | muted; notice: a one-off "no longer running" notice is due (dormant only)
     */
    public static function evaluate(array $runs, ?int $createdTs, int $nowTs, ?int $globalIntervalMin, int $errorThreshold, int $dormantDays, ?array $override = null): array
    {
        $lastTs = $runs === [] ? null : (int) $runs[0]['started'];
        $declared = $override !== null && !empty($override['interval_s']) ? (int) $override['interval_s'] : null;
        $intervalS = $declared ?? ($globalIntervalMin !== null && $globalIntervalMin > 0 ? $globalIntervalMin * 60 : null);
        $base = ['last_ts' => $lastTs, 'since_ts' => null, 'interval_s' => $intervalS, 'notice' => false];

        if ($override !== null && !empty($override['muted'])) {
            return ['state' => 'muted', 'detail' => 'muted by an administrator'] + $base;
        }

        if ($declared === null && $dormantDays > 0) {
            $limit = $dormantDays * 86400;
            if ($runs !== [] && ($nowTs - $lastTs) > $limit) {
                $age = $nowTs - $lastTs;

                return ['state' => 'dormant', 'detail' => 'dormant since ' . gmdate('Y-m-d', $lastTs) . ' (no run for ' . self::human($age) . ')',
                    'since_ts' => $lastTs + $limit,
                    'notice' => !self::runErrored($runs[0], $nowTs) && $age <= $limit + self::DORMANT_NOTICE_WINDOW_DAYS * 86400] + $base;
            }
            if ($runs === [] && $createdTs !== null && ($nowTs - $createdTs) > $limit) {
                return ['state' => 'dormant', 'detail' => 'dormant since ' . gmdate('Y-m-d', $createdTs) . ' (it has never run)', 'since_ts' => $createdTs + $limit] + $base;
            }
        }

        $intervalMin = $intervalS === null ? null : max(1, (int) round($intervalS / 60));
        $a = self::assess($runs, $createdTs, $nowTs, $intervalMin, $errorThreshold);
        $state = $a['state'] === 'ok' ? 'healthy' : $a['state'];

        return ['state' => $state, 'detail' => $a['detail']] + $base;
    }

    public static function humanInterval(int $seconds): string
    {
        if ($seconds % 86400 === 0) {
            return ($seconds / 86400) . ' day' . ($seconds === 86400 ? '' : 's');
        }
        if ($seconds % 3600 === 0) {
            return ($seconds / 3600) . ' h';
        }

        return max(1, (int) round($seconds / 60)) . ' min';
    }

    public static function human(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 5400) {
            return max(1, (int) round($seconds / 60)) . ' min';
        }

        return $seconds < 172800 ? round($seconds / 3600, 1) . ' h' : round($seconds / 86400, 1) . ' days';
    }

    /**
     * Reads every watched integration and evaluates it (no alerts raised). This is what the panel shows.
     *
     * @return list<array{source:string,label:string,id:int,name:string,state:string,detail:string,last_ts:?int,since_ts:?int,interval_s:?int,notice:bool,override:?array,runs:int}>
     */
    public static function snapshot(\mysqli $db, bool $rmmModuleOn = true): array
    {
        // Sync-log times are written by cron and external scripts in the server's own time zone, while a signed-in page has its session set to the
        // user's offset (includes/inc_set_timezone.php). UNIX_TIMESTAMP(datetime) reads in the session zone, so read in the server zone either way,
        // or the panel would disagree with the cron job by the user's UTC offset.
        $prev = null;
        $r = @mysqli_query($db, 'SELECT @@session.time_zone, @@global.time_zone');
        $tz = $r ? mysqli_fetch_row($r) : null;
        if ($tz && $tz[0] !== $tz[1] && @mysqli_query($db, 'SET SESSION time_zone = @@global.time_zone')) {
            $prev = (string) $tz[0];
        }
        try {
            return self::readSnapshot($db, $rmmModuleOn);
        } finally {
            if ($prev !== null) {
                @mysqli_query($db, "SET SESSION time_zone = '" . mysqli_real_escape_string($db, $prev) . "'");
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private static function readSnapshot(\mysqli $db, bool $rmmModuleOn): array
    {
        $res = @mysqli_query($db, 'SELECT UNIX_TIMESTAMP() AS n');
        $nowTs = $res ? (int) (mysqli_fetch_assoc($res)['n'] ?? time()) : time();
        $threshold = RecoverySettings::int($db, 'sync_error_threshold');
        $dormantDays = max(0, RecoverySettings::int($db, 'sync_dormant_days'));
        $overrides = WatchOverrides::all($db);
        $skipTypes = "'" . implode("','", self::UNWATCHED_RMM_TYPES) . "'";

        $sources = [
            ['rmm', 'RMM', 'rmm_sync_log', 'integration_id',
                $rmmModuleOn ? "SELECT id, name, UNIX_TIMESTAMP(created_at) AS created FROM rmm_integrations WHERE enabled = 1 AND type NOT IN ($skipTypes)" : null],
            ['unifi', 'UniFi', 'unifi_sync_log', 'integration_id',
                'SELECT id, name, UNIX_TIMESTAMP(created_at) AS created FROM unifi_integrations WHERE enabled = 1'],
        ];
        $out = [];
        foreach ($sources as [$key, $label, $logTable, $idCol, $listSql]) {
            if ($listSql === null) {
                continue;
            }
            $list = @mysqli_query($db, $listSql);
            while ($list && ($intg = mysqli_fetch_assoc($list))) {
                $id = (int) $intg['id'];
                $runs = [];
                $q = @mysqli_query($db, "SELECT status, errors, UNIX_TIMESTAMP(started_at) AS s, UNIX_TIMESTAMP(finished_at) AS f FROM `$logTable` WHERE `$idCol` = $id ORDER BY id DESC LIMIT 40");
                while ($q && ($r = mysqli_fetch_assoc($q))) {
                    if (self::isSentinelError($r['errors'])) {
                        continue;   // written against this row by someone else's loop: not this integration's run
                    }
                    $runs[] = ['status' => (string) $r['status'], 'started' => (int) $r['s'], 'finished' => $r['f'] === null ? null : (int) $r['f'], 'errors' => $r['errors']];
                }
                $override = $overrides["$key:$id"] ?? null;
                $setting = RecoverySettings::get($db, 'sync_interval_' . $key);
                $interval = $setting === 'auto' ? self::inferInterval(array_column($runs, 'started')) : (int) $setting;
                $e = self::evaluate($runs, $intg['created'] === null ? null : (int) $intg['created'], $nowTs, $interval, $threshold, $dormantDays, $override);
                $out[] = ['source' => $key, 'label' => $label, 'id' => $id, 'name' => (string) $intg['name'], 'override' => $override, 'runs' => count($runs)] + $e;
            }
        }

        return $out;
    }

    /**
     * The cron entry point: evaluates, then raises / clears alerts.
     *
     * @return list<array{source:string,id:int,name:string,state:string,detail:string,alerted:bool}> only the failing and stale ones
     */
    public static function run(\mysqli $db, bool $rmmModuleOn = true): array
    {
        $problems = [];
        foreach (self::snapshot($db, $rmmModuleOn) as $i) {
            $alertKey = 'sync.' . $i['source'] . '.' . $i['id'] . '.';
            $name = $i['name'];
            $link = '/admin/backup.php#sync-watch';
            if ($i['state'] === 'healthy') {
                // Announce each recovery once (only when the problem was actually mailed), then forget it.
                foreach (['failing' => 'is working again', 'stale' => 'is running again'] as $kind => $words) {
                    RecoveryAlerts::recovered($db, $alertKey . $kind, substr($i['label'] . ' sync ' . $words . " ($name)", 0, 220),
                        $i['label'] . " sync ($name) " . $words . ': ' . $i['detail'] . '.', $link);
                }
                RecoveryAlerts::clear($db, $alertKey . 'dormant');
                continue;
            }
            if ($i['state'] === 'muted') {
                foreach (['failing', 'stale', 'dormant'] as $kind) {
                    RecoveryAlerts::clear($db, $alertKey . $kind);
                }
                continue;
            }
            if ($i['state'] === 'dormant') {
                RecoveryAlerts::clear($db, $alertKey . 'failing');
                RecoveryAlerts::clear($db, $alertKey . 'stale');
                if ($i['notice']) {
                    $subject = $i['label'] . ' sync is no longer running (' . $name . '): ' . $i['detail'];
                    RecoveryAlerts::raise($db, $alertKey . 'dormant', substr($subject, 0, 220),
                        $subject . ".\n\nIt is now listed as dormant and will not be reported again. If something else syncs it (an external script), open Admin > Backup > Integration sync watcher and declare how often it should run, or mute it. If it is retired, disable the integration.",
                        'integration.sync_stale', ['source' => $i['source'], 'integration_id' => $i['id'], 'name' => $name, 'detail' => $i['detail'], 'dormant' => true],
                        $link, self::DORMANT_NOTICE_HOURS);
                }
                continue;
            }
            // failing or stale: the other kind no longer applies (it would otherwise produce a second recovery notice later)
            RecoveryAlerts::clear($db, $alertKey . ($i['state'] === 'failing' ? 'stale' : 'failing'));
            $label = $i['label'];
            $subject = "$label sync " . ($i['state'] === 'failing' ? 'is failing' : 'has stopped') . " ($name): " . $i['detail'];
            $sent = RecoveryAlerts::raise($db, $alertKey . $i['state'], substr($subject, 0, 220),
                $subject . ".\n\nOpen the integration (Admin > Integrations, or Endpoints > RMM) and run a manual sync to see the error. If the cron job itself stopped, check Admin > Cron Manager. If an external script syncs this integration, declare its interval or mute it under Admin > Backup > Integration sync watcher.",
                $i['state'] === 'failing' ? 'integration.sync_failed' : 'integration.sync_stale',
                ['source' => $i['source'], 'integration_id' => $i['id'], 'name' => $name, 'detail' => $i['detail']], $link);
            $problems[] = ['source' => $i['source'], 'id' => $i['id'], 'name' => $name, 'state' => $i['state'], 'detail' => $i['detail'], 'alerted' => $sent];
        }

        return $problems;
    }
}
