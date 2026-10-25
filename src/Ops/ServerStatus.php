<?php

declare(strict_types=1);

namespace RivetMSP\Ops;

/**
 * One-screen health of the server this app runs on (roadmap #12's remaining gap: the Maintenance page's
 * Server section only had Redis - this adds CPU load, memory and disk, plus the application/database basics).
 * Read-only: it never changes anything. Every probe is wrapped so one broken dependency becomes one WARN/FAIL
 * line instead of a broken page.
 *
 * Deliberately does NOT re-check Redis, scheduled jobs or backups: those already have their own dedicated
 * Maintenance pages in this edition (Administration > Maintenance > Redis / Scheduled jobs / Backups) - this
 * page links to them instead of duplicating the same checks on two pages that could silently drift apart.
 */
final class ServerStatus
{
    public function __construct(
        private \mysqli $db,
        private string $appRoot,
        private ?int $now = null,
    ) {
        $this->appRoot = realpath($appRoot) ?: rtrim($appRoot, '/');
        $this->now ??= time();
    }

    private static function c(string $status, string $label, string $detail): array
    {
        return ['status' => $status, 'label' => $label, 'detail' => $detail];
    }

    private static function bytes(float $b): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) {
            if ($b < 1024 || $u === 'TB') {
                return round($b, $u === 'B' ? 0 : 1) . " $u";
            }
            $b /= 1024;
        }
        return '';
    }

    /** @return list<array{title:string, checks:list<array{status:string,label:string,detail:string}>}> */
    public function run(): array
    {
        return [
            ['title' => 'Application and database', 'checks' => $this->app()],
            ['title' => 'CPU, memory and disk', 'checks' => $this->resources()],
        ];
    }

    /**
     * The database version this code needs: the constant when the page loaded it, otherwise read from
     * includes/database_version.php (admin pages do not load that file, so the constant is usually undefined there).
     */
    public static function latestDatabaseVersion(string $appRoot): ?string
    {
        if (defined('LATEST_DATABASE_VERSION')) {
            return (string) LATEST_DATABASE_VERSION;
        }
        $file = rtrim($appRoot, '/') . '/includes/database_version.php';
        $src = is_file($file) ? @file_get_contents($file) : false;
        if ($src !== false && preg_match('/LATEST_DATABASE_VERSION["\']?\s*,\s*["\']([0-9][0-9.]*)["\']/i', $src, $m)) {
            return $m[1];
        }

        return null;
    }

    private function app(): array
    {
        $out = [];
        $current = null;
        try {
            $r = $this->db->query('SELECT config_current_database_version AS v FROM settings WHERE company_id = 1');
            $current = $r ? ($r->fetch_assoc()['v'] ?? null) : null;
        } catch (\Throwable) {
        }
        $latest = self::latestDatabaseVersion($this->appRoot);
        if ($current === null || $latest === null) {
            $out[] = self::c('warn', 'Database version', 'Could not read the database version.');
        } elseif (version_compare($latest, $current, '>')) {
            $out[] = self::c('warn', 'Database version', "Database is at $current but this code needs $latest. Open Administration > Update and press Update database.");
        } else {
            $out[] = self::c('ok', 'Database version', "Up to date ($current).");
        }
        $out[] = self::c('ok', 'Application version', defined('APP_VERSION') ? (string) APP_VERSION : 'unknown');
        $missing = array_values(array_filter(['mysqli', 'curl', 'mbstring', 'gd', 'zip', 'openssl', 'json'], static fn ($e) => !extension_loaded($e)));
        $out[] = $missing ? self::c('fail', 'PHP extensions', 'Missing: ' . implode(', ', $missing) . '.')
            : self::c('ok', 'PHP extensions', 'PHP ' . PHP_VERSION . ' with every required extension.');
        return $out;
    }

    /** CPU load average and memory, read from /proc (Linux only - the deployment target); disk, portable via disk_free_space()/disk_total_space(). */
    private function resources(): array
    {
        $out = [];
        $out[] = $this->cpuCheck();
        $out[] = $this->memoryCheck();
        $out[] = $this->diskCheck();
        return array_values(array_filter($out));
    }

    private function cpuCheck(): array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        if ($load === false) {
            return self::c('warn', 'CPU load', 'Could not read the load average on this platform.');
        }
        [$load1, $load5, $load15] = $load;

        $cores = 0;
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo !== false) {
            $cores = (int) preg_match_all('/^processor\s*:/m', $cpuinfo);
        }
        if ($cores < 1 && function_exists('shell_exec')) {
            $nproc = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
            $cores = $nproc > 0 ? $nproc : 0;
        }

        $detail = sprintf('%.2f, %.2f, %.2f (1, 5, 15 min)', $load1, $load5, $load15);
        if ($cores > 0) {
            $perCore = $load1 / $cores;
            $detail .= sprintf(' on %d core(s), %.0f%% of one core-equivalent per core right now.', $cores, $perCore * 100);
            return $perCore >= 1.5 ? self::c('fail', 'CPU load', $detail . ' Sustained load above capacity - investigate what is running.')
                : ($perCore >= 0.9 ? self::c('warn', 'CPU load', $detail) : self::c('ok', 'CPU load', $detail));
        }

        return self::c('ok', 'CPU load', $detail . ' (core count unknown, so no threshold was applied).');
    }

    private function memoryCheck(): array
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo === false) {
            return self::c('warn', 'Memory', 'Could not read /proc/meminfo on this platform.');
        }
        $kv = [];
        foreach (explode("\n", $meminfo) as $line) {
            if (preg_match('/^(\w+):\s*(\d+)\s*kB/', $line, $m)) {
                $kv[$m[1]] = (int) $m[2] * 1024;
            }
        }
        $total = $kv['MemTotal'] ?? 0;
        // MemAvailable (kernel 3.14+) already accounts for reclaimable cache/buffers - the right number for
        // "how much can a new process actually get", unlike MemFree alone which looks falsely low on a
        // healthy box that is simply using RAM for disk cache.
        $available = $kv['MemAvailable'] ?? ($kv['MemFree'] ?? 0) + ($kv['Buffers'] ?? 0) + ($kv['Cached'] ?? 0);
        if ($total <= 0) {
            return self::c('warn', 'Memory', 'Could not determine total memory.');
        }
        $usedPct = (1 - $available / $total) * 100;
        $detail = self::bytes($total - $available) . ' used of ' . self::bytes($total) . ' (' . round($usedPct) . '%).';
        return $usedPct >= 95 ? self::c('fail', 'Memory', $detail . ' Close to exhausted - the OOM killer may start stopping processes.')
            : ($usedPct >= 85 ? self::c('warn', 'Memory', $detail) : self::c('ok', 'Memory', $detail));
    }

    private function diskCheck(): array
    {
        $free = @disk_free_space($this->appRoot);
        $total = @disk_total_space($this->appRoot);
        if ($free === false || !$total) {
            return self::c('warn', 'Disk space', 'Could not read disk space for this install.');
        }
        $pct = $free / $total * 100;
        $detail = self::bytes($free) . ' free of ' . self::bytes($total) . ' (' . round($pct) . '%).';
        return ($pct < 5 || $free < 2 * 1073741824) ? self::c('fail', 'Disk space', $detail . ' Free some space before backups fail.')
            : (($pct < 15 || $free < 5 * 1073741824) ? self::c('warn', 'Disk space', $detail) : self::c('ok', 'Disk space', $detail));
    }

    /** Overall counts for the page header. */
    public static function summarize(array $groups): array
    {
        $n = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($groups as $g) {
            foreach ($g['checks'] as $c) {
                if (isset($n[$c['status']])) {
                    $n[$c['status']]++;
                }
            }
        }
        return $n;
    }
}
