<?php

/*
 * RivetMSP - Backup page: recovery settings and "Run drill now".
 * Included by admin/post/backup.php (admin/post.php picks the handler from the Referer: admin/backup.php).
 * Actions: save_recovery_settings, run_restore_drill, save_watch_override, mute_watch, unmute_watch, forget_watch_history
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use RivetMSP\Recovery\RecoverySettings;
use RivetMSP\Recovery\RestoreDrill;
use RivetMSP\Recovery\WatchOverrides;

if (isset($_POST['save_recovery_settings'])) {
    validateCSRFToken($_POST['csrf_token']);

    if (!RecoverySettings::ready($mysqli)) {
        flash_alert('Run the database update first (Admin > Update): the recovery tables are not there yet.', 'error');
        redirect();
    }

    $clamp = fn (string $k, int $min, int $max) => (string) max($min, min($max, intval($_POST[$k] ?? RecoverySettings::DEFAULTS[$k] ?? 0)));
    $interval = function (string $k): string {
        $v = trim((string) ($_POST[$k] ?? ''));
        return $v === 'auto' && $k === 'sync_interval_unifi' ? 'auto' : (string) max(0, min(1440, intval($v)));
    };

    RecoverySettings::set($mysqli, 'drill_enabled', isset($_POST['drill_enabled']) ? '1' : '0');
    RecoverySettings::set($mysqli, 'drill_db_host', trim(preg_replace('/[^A-Za-z0-9_.:\-]/', '', (string) ($_POST['drill_db_host'] ?? ''))));
    RecoverySettings::set($mysqli, 'drill_db_user', trim(preg_replace('/[^A-Za-z0-9_.@\-]/', '', (string) ($_POST['drill_db_user'] ?? ''))));
    // Blank = keep the saved password (same convention as the S3 secret key and the backup passphrase on this page).
    if (trim((string) ($_POST['drill_db_pass'] ?? '')) !== '') {
        RecoverySettings::set($mysqli, 'drill_db_pass', encryptSetting(trim((string) $_POST['drill_db_pass'])));
    }
    RecoverySettings::set($mysqli, 'drill_tolerance_pct', $clamp('drill_tolerance_pct', 1, 50));
    RecoverySettings::set($mysqli, 'backup_stale_hours', $clamp('backup_stale_hours', 2, 720));
    RecoverySettings::set($mysqli, 'sync_error_threshold', $clamp('sync_error_threshold', 1, 20));
    RecoverySettings::set($mysqli, 'sync_dormant_days', $clamp('sync_dormant_days', 0, 365));
    RecoverySettings::set($mysqli, 'alert_repeat_hours', $clamp('alert_repeat_hours', 1, 720));
    foreach (['sync_interval_rmm', 'sync_interval_unifi'] as $k) {
        RecoverySettings::set($mysqli, $k, $interval($k));
    }
    $emails = array_filter(preg_split('/[,;\s]+/', (string) ($_POST['alert_email'] ?? '')) ?: [], fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
    RecoverySettings::set($mysqli, 'alert_email', implode(', ', $emails));

    logAction('Settings', 'Edit', "$session_name updated recovery settings (restore drill, backup and sync alerts)");
    flash_alert('Recovery settings saved');
    redirect();
}

if (isset($_POST['run_restore_drill'])) {
    validateCSRFToken($_POST['csrf_token']);

    $drill = new RestoreDrill($mysqli, dirname(__DIR__, 2), ['live_user' => $dbusername ?? '']);
    $cfg = $drill->configuration();
    if (!$cfg['configured']) {
        flash_alert('The restore drill is not set up: ' . nullable_htmlentities(implode(' ', $cfg['problems'])) . ' See the setup steps below.', 'error');
        redirect();
    }
    if (RestoreDrill::spawn(dirname(__DIR__, 2), 'manual')) {
        logAction('Backup', 'Restore Drill', "$session_name started a restore drill");
        flash_alert('Restore drill started. It restores the newest backup into a scratch database and removes it again; refresh this page in a minute for the result.');
    } else {
        flash_alert('Could not start the drill from the web server. Run it from a shell instead: <code>sudo -u www-data php cron/restore_drill.php --force</code>', 'error');
    }
    redirect();
}

/*
 * Integration sync watcher: per-integration overrides (mute, expected interval, note) and "forget history". Admin only (this whole page is),
 * CSRF-checked, and every change is written to the audit log with who, which integration and the new values.
 */
if (isset($_POST['save_watch_override']) || isset($_POST['mute_watch']) || isset($_POST['unmute_watch']) || isset($_POST['forget_watch_history'])) {
    validateCSRFToken($_POST['csrf_token']);

    $w_source = (string) ($_POST['watch_source'] ?? '');
    $w_id = intval($_POST['watch_integration_id'] ?? 0);
    $w_table = ['rmm' => 'rmm_integrations', 'unifi' => 'unifi_integrations'][$w_source] ?? null;
    if (!WatchOverrides::ready($mysqli)) {
        flash_alert('Run the database update first (Admin > Update): the watcher override table is not there yet.', 'error');
        redirect();
    }
    $w_name = null;
    if ($w_table !== null && $w_id > 0) {
        $w_res = mysqli_query($mysqli, "SELECT name FROM `$w_table` WHERE id = $w_id");
        $w_name = $w_res && ($w_row = mysqli_fetch_assoc($w_res)) ? (string) $w_row['name'] : null;
    }
    if ($w_name === null) {
        flash_alert('That integration does not exist.', 'error');
        redirect();
    }
    $w_label = strtoupper($w_source) . " integration #$w_id ($w_name)";
    $w_user = intval($session_user_id ?? 0);
    $w_cur = WatchOverrides::all($mysqli)["$w_source:$w_id"] ?? ['muted' => false, 'interval_s' => null, 'note' => ''];

    if (isset($_POST['save_watch_override'])) {
        $w_muted = isset($_POST['watch_muted']);
        $w_hours = trim((string) ($_POST['watch_expected_hours'] ?? ''));
        $w_interval = $w_hours !== '' && is_numeric($w_hours) ? WatchOverrides::cleanInterval((int) round(((float) $w_hours) * 3600)) : null;
        $w_note = trim(preg_replace('/\s+/', ' ', (string) ($_POST['watch_note'] ?? '')));
        WatchOverrides::set($mysqli, $w_source, $w_id, $w_muted, $w_interval, $w_note, $w_user);
        logAction('Settings', 'Edit', "$session_name set the sync watcher override for $w_label: " . ($w_muted ? 'muted' : 'not muted') . ', expected every '
            . ($w_interval === null ? 'default' : round($w_interval / 3600, 2) . ' h') . ($w_note !== '' ? ', note: ' . substr($w_note, 0, 255) : '') . ' (was ' . ($w_cur['muted'] ? 'muted' : 'not muted') . ', '
            . ($w_cur['interval_s'] === null ? 'default' : round($w_cur['interval_s'] / 3600, 2) . ' h') . ')');
        flash_alert('Watcher setting saved for ' . nullable_htmlentities($w_name));
        redirect();
    }
    if (isset($_POST['mute_watch'])) {
        WatchOverrides::mute($mysqli, $w_source, $w_id, $w_user);
        logAction('Settings', 'Edit', "$session_name muted the sync watcher for $w_label");
        flash_alert('Muted ' . nullable_htmlentities($w_name) . '. It will not be reported until you unmute it.');
        redirect();
    }
    if (isset($_POST['unmute_watch'])) {
        WatchOverrides::set($mysqli, $w_source, $w_id, false, $w_cur['interval_s'], $w_cur['note'], $w_user);
        logAction('Settings', 'Edit', "$session_name unmuted the sync watcher for $w_label");
        flash_alert('Unmuted ' . nullable_htmlentities($w_name));
        redirect();
    }
    if (empty($_POST['watch_confirm'])) {
        flash_alert('Tick the box to confirm removing the old sync runs.', 'error');
        redirect();
    }
    $w_n = WatchOverrides::forgetHistory($mysqli, $w_source, $w_id);
    logAction('Settings', 'Delete', "$session_name forgot the sync history of $w_label ($w_n log rows removed)");
    flash_alert("Forgot the sync history of " . nullable_htmlentities($w_name) . " ($w_n runs removed).");
    redirect();
}
