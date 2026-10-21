<?php
/*
 * When cron/cron.php checks for a new RivetMSP release (functions.php fetchUpdates(): a `git fetch` of the release remote, then an
 * "Update" in-app notification to every technician when this server is behind).
 *
 * This used to happen on EVERY cron run (every 5 minutes with deploy/install.sh's cron entry and docker/supervisord.conf's loop),
 * so a server that was behind sent a fresh "Update" notification to every user every 5 minutes. It now runs once a day: on the
 * first cron run at or after UPDATE_CHECK_HOUR o'clock in the app's time zone (Settings > Localization; cron/cron.php sets it via
 * includes/inc_set_timezone.php before this runs). 03:00 sits in the same overnight window as the other daily jobs: the backup
 * timer (02:30, deploy/templates/itflow-backup.timer) and the restore drill (03:30 timer / 03:45 cron entry). A day whose 03:00
 * run was missed (cron off, server down) checks on the next run that day instead of waiting until tomorrow.
 *
 * Administration > Update still checks on demand every time the page is opened; this only paces the background check.
 * The schedule is not related to the endpoint agent's release feed (includes/rmm_agent_feed.php).
 */

/** Hour of the day (0-23, app time zone) of the daily update check. */
const UPDATE_CHECK_HOUR = 3;

/**
 * The most recent scheduled check time at or before $now: today at UPDATE_CHECK_HOUR:00, or yesterday's when $now is earlier
 * than that. Calendar arithmetic in the default time zone, so a daylight-saving change does not shift it by an hour.
 */
function updateCheckLatestSlot(int $now): DateTimeImmutable
{
    $at = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $slot = $at->setTime(UPDATE_CHECK_HOUR, 0, 0);
    if ($slot > $at) {
        $slot = $slot->modify('-1 day')->setTime(UPDATE_CHECK_HOUR, 0, 0);
    }
    return $slot;
}

/**
 * Claim today's update check. True exactly once per slot: the first caller after the slot records $now and gets true; every
 * later call before the next slot gets false. One atomic UPDATE, so two overlapping cron runs (the Redis cron lock is skipped
 * when Redis is down) cannot both check. False as well when the column does not exist yet (code newer than the database:
 * no check until DB 2.6.105 is applied, rather than falling back to checking on every run).
 */
function updateCheckClaim(mysqli $db, int $now): bool
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $slot = updateCheckLatestSlot($now)->format('Y-m-d H:i:s');
    $now_at = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->format('Y-m-d H:i:s');
    try {
        $ok = mysqli_query($db, "UPDATE settings SET config_update_check_last_at = '$now_at'
            WHERE company_id = 1 AND (config_update_check_last_at IS NULL OR config_update_check_last_at < '$slot')");
    } catch (\Throwable $e) {
        return false;
    }
    return $ok && mysqli_affected_rows($db) === 1;
}
