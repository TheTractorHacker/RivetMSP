<?php
// MSP-DS-155: cron lock files live in an app-owned 0700 directory (not the shared, world-listable /tmp), and a lock that is
// unowned by this process, or has an mtime in the future, is never treated as live.

/** Directory for the lock files; falls back to the system temp dir only when the app directory cannot be created. */
function rivetCronLockDir(): string
{
    $dir = dirname(__DIR__) . '/uploads/cron_locks';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        return $dir;
    }
    return sys_get_temp_dir();
}

/**
 * Lock file name for a cron job. The installation id is a secret (the legacy calendar-feed token was derived from it), so it is never
 * put in a file name: the name carries a one-way digest of it instead. MSP-DS-49 (re-review MRR-9).
 */
function rivetCronLockName(string $job, ?string $installation_id = null): string
{
    $id = $installation_id ?? (string) ($GLOBALS['installation_id'] ?? '');
    return 'itflow_' . $job . '_' . substr(hash('sha256', 'cron-lock|' . $id), 0, 16) . '.lock';
}

/** True when an existing lock file still counts as held: it is ours, not in the future, and younger than $ttl seconds. */
function rivetCronLockIsLive(string $path, int $ttl): bool
{
    if (!file_exists($path)) {
        return false;
    }
    clearstatcache(true, $path);
    $mtime = (int) @filemtime($path);
    $owner = function_exists('posix_geteuid') ? @fileowner($path) === posix_geteuid() : true;
    if (!$owner || $mtime > time()) {
        return false;   // planted by someone else, or dated in the future: never "live"
    }
    return (time() - $mtime) <= $ttl;
}
