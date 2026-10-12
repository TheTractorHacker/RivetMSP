<?php

namespace RivetMSP\Recovery;

/**
 * One way to raise a recovery alert: an in-app notification, an email to the administrator address, and (when the event bus is
 * loaded) a webhook/automation event. Alerts are de-duplicated per key for alert_repeat_hours (recovery setting, default 24) so a
 * problem that stays broken produces one message a day, not one per cron tick. The first occurrence is always immediate, and a
 * recovery is announced once (recovered()).
 */
final class RecoveryAlerts
{
    public const DEDUPE_HOURS = 24;   // the default; the live value is the recovery setting alert_repeat_hours

    /** The configured repeat window in hours (1 to 720), falling back to the default when unset or invalid. */
    public static function repeatHours(\mysqli $db): int
    {
        $h = RecoverySettings::int($db, 'alert_repeat_hours');

        return $h >= 1 && $h <= 720 ? $h : self::DEDUPE_HOURS;
    }

    /** Pure: may an alert whose last send was $lastSentTs be sent again at $nowTs? */
    public static function dueAgain(?int $lastSentTs, int $nowTs, int $dedupeHours = self::DEDUPE_HOURS): bool
    {
        return $lastSentTs === null || ($nowTs - $lastSentTs) >= $dedupeHours * 3600;
    }

    /**
     * Claims the right to send $key (atomic: only the caller whose UPDATE/INSERT changes the row proceeds), returning true when
     * this call should send. Fails open to "send" when the table is missing, so an alert is never lost to a missing migration.
     */
    public static function claim(\mysqli $db, string $key, string $message, ?int $dedupeHours = null): bool
    {
        $r = @mysqli_query($db, "SHOW TABLES LIKE 'recovery_alerts'");
        if (!$r || mysqli_num_rows($r) === 0) {
            return true;
        }
        $hours = $dedupeHours !== null ? max(1, $dedupeHours) : self::repeatHours($db);
        $k = mysqli_real_escape_string($db, substr($key, 0, 120));
        $m = mysqli_real_escape_string($db, substr($message, 0, 500));
        // First alert for this key.
        if (@mysqli_query($db, "INSERT IGNORE INTO recovery_alerts (alert_key, alert_last_sent_at, alert_last_message) VALUES ('$k', NOW(), '$m')")
            && mysqli_affected_rows($db) === 1) {
            return true;
        }
        // Existing row: re-arm only when the dedupe window has passed. The conditional UPDATE is the atomic claim.
        @mysqli_query($db, "UPDATE recovery_alerts SET alert_last_sent_at = NOW(), alert_last_message = '$m', alert_send_count = alert_send_count + 1
                            WHERE alert_key = '$k' AND alert_last_sent_at <= NOW() - INTERVAL $hours HOUR");

        return mysqli_affected_rows($db) === 1;
    }

    /** Forget a key so the next failure alerts immediately (used when the condition clears). */
    public static function clear(\mysqli $db, string $key): bool
    {
        $k = mysqli_real_escape_string($db, substr($key, 0, 120));
        @mysqli_query($db, "DELETE FROM recovery_alerts WHERE alert_key = '$k'");

        return mysqli_affected_rows($db) > 0;
    }

    /**
     * Announces a recovery once: only when an alert for $key had actually been raised (its de-dupe row exists), which is then
     * removed so the next failure alerts immediately. In-app notification and email only (no event).
     *
     * @return bool true when a notice was sent
     */
    public static function recovered(\mysqli $db, string $key, string $subject, string $body, string $link = '/admin/backup.php'): bool
    {
        if (!self::clear($db, $key)) {
            return false;
        }
        self::notify($db, $subject, $body, $link);

        return true;
    }

    /** The in-app notification and the email, without any de-dupe (the caller decided). */
    private static function notify(\mysqli $db, string $subject, string $body, string $link): void
    {
        if (function_exists('appNotify')) {
            try {
                appNotify('Recovery', $subject, $link);
            } catch (\Throwable $e) {
                error_log('RecoveryAlerts: appNotify failed: ' . $e->getMessage());
            }
        }
        self::email($db, $subject, $body);
    }

    /** @return list<string> distinct recipient addresses */
    public static function recipients(\mysqli $db): array
    {
        $explicit = trim(RecoverySettings::get($db, 'alert_email'));
        $list = [];
        if ($explicit !== '') {
            foreach (preg_split('/[,;\s]+/', $explicit) ?: [] as $a) {
                if (filter_var($a, FILTER_VALIDATE_EMAIL)) {
                    $list[strtolower($a)] = $a;
                }
            }
        }
        if ($list === []) {
            $res = @mysqli_query($db, "SELECT u.user_email FROM users u JOIN user_roles r ON r.role_id = u.user_role_id
                                      WHERE u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL AND r.role_is_admin = 1 AND u.user_email <> ''");
            while ($res && ($row = mysqli_fetch_assoc($res))) {
                if (filter_var($row['user_email'], FILTER_VALIDATE_EMAIL)) {
                    $list[strtolower($row['user_email'])] = $row['user_email'];
                }
            }
        }

        return array_values($list);
    }

    /**
     * @param string               $key     de-dupe key, e.g. 'backup.failed' or 'sync.rmm.3'
     * @param string               $event   event-bus id (backup.failed, backup.stale, restore_drill.failed, integration.sync_failed ...)
     * @param array<string,mixed>  $payload event data
     * @return bool true when the alert was sent, false when suppressed by the de-dupe window
     */
    public static function raise(\mysqli $db, string $key, string $subject, string $body, string $event, array $payload = [], string $link = '/admin/backup.php', ?int $dedupeHours = null): bool
    {
        if (!self::claim($db, $key, $subject, $dedupeHours)) {
            return false;
        }
        self::notify($db, $subject, $body, $link);
        if (!function_exists('rivetEmitEvent') && is_file(dirname(__DIR__, 2) . '/includes/event_bus.php')) {
            require_once dirname(__DIR__, 2) . '/includes/event_bus.php';   // the same lazy load queueWebhookEvent() does
        }
        if (function_exists('rivetEmitEvent')) {
            try {
                rivetEmitEvent($event, $payload + ['message' => $subject]);
            } catch (\Throwable $e) {
                error_log('RecoveryAlerts: event failed: ' . $e->getMessage());
            }
        }
        if (function_exists('logApp')) {
            logApp('Recovery', 'error', $subject);
        }

        return true;
    }

    private static function email(\mysqli $db, string $subject, string $body): void
    {
        if (!function_exists('addToMailQueue')) {
            return;
        }
        $to = self::recipients($db);
        if ($to === []) {
            return;
        }
        $res = @mysqli_query($db, 'SELECT config_mail_from_email, config_mail_from_name FROM settings WHERE company_id = 1');
        $row = $res ? (mysqli_fetch_assoc($res) ?: []) : [];
        $from = (string) ($row['config_mail_from_email'] ?? '');
        if ($from === '') {
            return;   // no sender configured: the in-app notification still went out
        }
        $fromName = (string) ($row['config_mail_from_name'] ?? '') ?: (defined('APP_NAME') ? APP_NAME : 'RivetMSP');
        $html = '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</p><p style="color:#888">Sent by the ' . (defined('APP_NAME') ? APP_NAME : 'RivetMSP') . ' recovery monitor. The same alert is not repeated for ' . self::repeatHours($db) . ' hours.</p>';
        $queue = [];
        foreach ($to as $addr) {
            $queue[] = ['from' => $from, 'from_name' => $fromName, 'recipient' => $addr, 'recipient_name' => $addr,
                'subject' => '[' . (defined('APP_NAME') ? APP_NAME : 'RivetMSP') . '] ' . $subject, 'body' => $html];
        }
        try {
            addToMailQueue($queue);
        } catch (\Throwable $e) {
            error_log('RecoveryAlerts: mail queue failed: ' . $e->getMessage());
        }
    }
}
