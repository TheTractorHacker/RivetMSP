<?php
/*
 * Backup page: recovery status tiles, restore drill (setup, settings, run now, history) and alert settings.
 * Included by admin/backup.php. Reads only; the handlers are in admin/post/backup_recovery.php.
 */

if (!isset($mysqli, $backup_dir)) { http_response_code(404); exit; }   // only ever included by admin/backup.php

use RivetMSP\Recovery\BackupStatus;
use RivetMSP\Recovery\DrillVerifier;
use RivetMSP\Recovery\RecoverySettings;
use RivetMSP\Recovery\RestoreDrill;
use RivetMSP\Recovery\SyncWatch;
use RivetMSP\Recovery\WatchOverrides;

$rec_ready = RecoverySettings::ready($mysqli);
$rec_last_run = $rec_ready ? BackupStatus::last($mysqli) : null;
$rec_age = BackupStatus::newestGoodAge($mysqli, $backup_dir);
$rec_stale_h = max(1, RecoverySettings::int($mysqli, 'backup_stale_hours'));
$rec_state = BackupStatus::staleState($rec_age, $rec_stale_h);
$rec_drill = null;
$rec_history = [];
if ($rec_ready) {
    $q = mysqli_query($mysqli, "SELECT *, TIMESTAMPDIFF(SECOND, drill_finished_at, NOW()) AS age_s FROM restore_drill_log ORDER BY drill_id DESC LIMIT 10");
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $rec_history[] = $r;
    }
    $rec_drill = $rec_history[0] ?? null;
}
$rec_enabled = RecoverySettings::get($mysqli, 'drill_enabled') === '1';
$rec_cfg = (new RestoreDrill($mysqli, dirname(__DIR__), ['live_user' => $dbusername ?? '']))->configuration();
$rec_badge = fn (string $s) => ['pass' => 'success', 'warn' => 'warning', 'fail' => 'danger', 'error' => 'danger', 'not_configured' => 'secondary', 'running' => 'info', 'skip' => 'secondary'][$s] ?? 'secondary';
$sw_rows = $rec_ready ? SyncWatch::snapshot($mysqli, (bool) intval($config_module_enable_rmm ?? 0)) : [];
$sw_ready = WatchOverrides::ready($mysqli);
$sw_badge = ['healthy' => 'success', 'failing' => 'danger', 'stale' => 'warning', 'dormant' => 'secondary', 'muted' => 'info'];
$rec_ago = function (?int $sec): string {
    if ($sec === null) { return 'never'; }
    return $sec < 5400 ? max(1, (int) round($sec / 60)) . ' min ago' : ($sec < 172800 ? round($sec / 3600, 1) . ' h ago' : round($sec / 86400, 1) . ' days ago');
};
?>

<!-- ── Recovery status ───────────────────────────────────────────────────── -->
<div class="card mb-3" id="recovery">
    <div class="card-header py-2">
        <h3 class="card-title"><i class="fas fa-fw fa-life-ring me-2"></i>Recovery status</h3>
    </div>
    <div class="card-body">
        <?php if (!$rec_ready): ?>
            <div class="alert alert-warning mb-0">The recovery tables are not in this database yet. Run the database update (Admin &gt; Update) to switch on backup status, alerts and the restore drill.</div>
        <?php else: ?>
        <div class="row text-center">
            <div class="col-md-4 mb-2">
                <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;">Newest good backup</div>
                <div class="fw-bold mt-1">
                    <span class="badge text-bg-<?= $rec_state === 'ok' ? 'success' : 'danger' ?>"><?= $rec_state === 'ok' ? 'Fresh' : ($rec_state === 'none' ? 'None' : 'Stale') ?></span>
                    <?= $rec_age === null ? 'no backup yet' : nullable_htmlentities($rec_ago($rec_age)) ?>
                </div>
                <small class="text-muted">alerts after <?= $rec_stale_h ?> h</small>
            </div>
            <div class="col-md-4 mb-2">
                <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;">Last backup run</div>
                <div class="fw-bold mt-1">
                    <?php if ($rec_last_run): ?>
                        <span class="badge text-bg-<?= $rec_last_run['run_ok'] ? 'success' : 'danger' ?>"><?= $rec_last_run['run_ok'] ? 'OK' : 'Failed' ?></span>
                        <?= nullable_htmlentities($rec_ago((int) $rec_last_run['age_seconds'])) ?>
                        <small class="text-muted d-block"><?= nullable_htmlentities($rec_last_run['run_kind']) ?><?= $rec_last_run['run_size'] ? ', ' . round($rec_last_run['run_size'] / 1048576, 1) . ' MB' : '' ?><?= $rec_last_run['run_offsite_result'] ? ', off-site: ' . nullable_htmlentities($rec_last_run['run_offsite_result']) : '' ?></small>
                        <?php if (!$rec_last_run['run_ok'] && $rec_last_run['run_error']): ?><small class="text-danger d-block"><?= nullable_htmlentities($rec_last_run['run_error']) ?></small><?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">none recorded yet</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-4 mb-2">
                <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;">Last restore drill</div>
                <div class="fw-bold mt-1">
                    <?php if ($rec_drill): ?>
                        <span class="badge text-bg-<?= $rec_badge($rec_drill['drill_status']) ?>"><?= nullable_htmlentities(str_replace('_', ' ', $rec_drill['drill_status'])) ?></span>
                        <?= $rec_drill['age_s'] !== null ? nullable_htmlentities($rec_ago((int) $rec_drill['age_s'])) : 'running now' ?>
                        <?php if ($rec_drill['drill_restore_seconds'] !== null): ?><small class="text-muted d-block">measured restore time (RTO evidence): <?= nullable_htmlentities(DrillVerifier::duration((float) $rec_drill['drill_restore_seconds'])) ?></small><?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">never run</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Integration sync watcher ──────────────────────────────────────────── -->
<div class="card mb-3" id="sync-watch">
    <div class="card-header py-2">
        <h3 class="card-title"><i class="fas fa-fw fa-sync-alt me-2"></i>Integration sync watcher</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            Every enabled RMM and UniFi integration, as the watcher sees it. <strong>Failing</strong>: the last runs all ended in a real error.
            <strong>Stale</strong>: no run in three times the expected interval. <strong>Dormant</strong>: no run for <?= (int) RecoverySettings::get($mysqli, 'sync_dormant_days') ?> days or more, so nothing is mailed.
            <strong>Muted</strong>: you switched it off. If an external script syncs an integration (and does not write to <code>rmm_sync_log</code> / <code>unifi_sync_log</code>),
            declare how often it runs, for example 24 hours. Endpoint agent devices (<code>rivetit_agent</code>) have no vendor sync and are not watched.
        </p>
        <?php if (!$sw_ready): ?>
            <div class="alert alert-warning">The watcher override table is not in this database yet. Run the database update (Admin &gt; Update) to mute integrations or declare intervals.</div>
        <?php endif; ?>
        <?php if (!$sw_rows): ?>
            <div class="text-muted">No watched integrations.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead><tr><th>Integration</th><th>State</th><th>Last run</th><th>Expected</th><th>Reason</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($sw_rows as $sw): $ov = $sw['override']; $sw_form = 'swf_' . $sw['source'] . '_' . $sw['id']; ?>
                    <tr>
                        <td><?= nullable_htmlentities($sw['name']) ?> <small class="text-muted"><?= nullable_htmlentities($sw['label']) ?></small></td>
                        <td><span class="badge text-bg-<?= $sw_badge[$sw['state']] ?? 'secondary' ?>"><?= nullable_htmlentities($sw['state']) ?></span></td>
                        <td class="text-nowrap"><?= $sw['last_ts'] === null ? 'never' : nullable_htmlentities(rivetRmmTime((int) $sw['last_ts'])) ?></td>
                        <td class="text-nowrap"><?= $sw['interval_s'] === null ? '<span class="text-muted">not watched</span>' : 'every ' . nullable_htmlentities(SyncWatch::humanInterval((int) $sw['interval_s'])) . ($ov && $ov['interval_s'] ? ' <small class="text-muted">(declared)</small>' : '') ?></td>
                        <td class="small"><?= nullable_htmlentities($sw['detail']) ?><?= $ov && $ov['note'] !== '' ? '<br><span class="text-muted">' . nullable_htmlentities($ov['note']) . '</span>' : '' ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($sw_ready): ?>
                            <form action="post.php" method="post" class="d-inline m-0">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="watch_source" value="<?= nullable_htmlentities($sw['source']) ?>">
                                <input type="hidden" name="watch_integration_id" value="<?= (int) $sw['id'] ?>">
                                <?php if ($sw['state'] === 'muted'): ?>
                                    <button type="submit" name="unmute_watch" formnovalidate class="btn btn-sm btn-outline-secondary">Unmute</button>
                                <?php else: ?>
                                    <button type="submit" name="mute_watch" formnovalidate class="btn btn-sm btn-outline-secondary">Mute</button>
                                <?php endif; ?>
                                <?php if ($sw['state'] === 'dormant'): ?>
                                    <label class="small ms-1"><input type="checkbox" class="form-check-input" name="watch_confirm" value="1" required> remove old runs</label>
                                    <button type="submit" name="forget_watch_history" class="btn btn-sm btn-outline-danger">Forget history</button>
                                <?php endif; ?>
                            </form>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#<?= $sw_form ?>">Edit</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($sw_ready): ?>
                    <tr class="collapse" id="<?= $sw_form ?>">
                        <td colspan="6">
                            <form action="post.php" method="post" class="row g-2 align-items-end m-0">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="watch_source" value="<?= nullable_htmlentities($sw['source']) ?>">
                                <input type="hidden" name="watch_integration_id" value="<?= (int) $sw['id'] ?>">
                                <div class="col-md-3"><label class="small" for="<?= $sw_form ?>_h">Expected to run every (hours, blank = default)</label>
                                    <input type="number" step="0.01" min="0.02" max="720" class="form-control form-control-sm" id="<?= $sw_form ?>_h" name="watch_expected_hours" value="<?= $ov && $ov['interval_s'] ? nullable_htmlentities((string) round($ov['interval_s'] / 3600, 2)) : '' ?>" placeholder="24"></div>
                                <div class="col-md-5"><label class="small" for="<?= $sw_form ?>_n">Note (for example: synced by /opt/scripts/unifi_itflow_sync.py)</label>
                                    <input type="text" maxlength="255" class="form-control form-control-sm" id="<?= $sw_form ?>_n" name="watch_note" value="<?= $ov ? nullable_htmlentities($ov['note']) : '' ?>"></div>
                                <div class="col-md-2"><div class="form-check"><input type="checkbox" class="form-check-input" id="<?= $sw_form ?>_m" name="watch_muted" value="1" <?= $ov && $ov['muted'] ? 'checked' : '' ?>><label class="form-check-label small" for="<?= $sw_form ?>_m">Muted</label></div></div>
                                <div class="col-md-2"><button type="submit" name="save_watch_override" class="btn btn-sm btn-primary">Save</button></div>
                            </form>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Restore drill ─────────────────────────────────────────────────────── -->
<div class="card mb-3" id="restore-drill">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mr-auto"><i class="fas fa-fw fa-vial me-2"></i>Restore drill</h3>
        <form action="post.php" method="post" class="m-0">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <button type="submit" name="run_restore_drill" class="btn btn-sm btn-outline-primary" <?= $rec_ready ? '' : 'disabled' ?>
                    title="Restores the newest backup into a scratch database, verifies it and removes it again. Never touches live data.">
                <i class="fas fa-play me-1"></i>Run drill now
            </button>
        </form>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            A backup is only proven when a restore has worked. Every night the newest backup is restored into a scratch database
            (<code>drill_&lt;date&gt;</code>) using a database account that can only touch <code>drill_*</code> databases, then checked:
            file checksums, schema version, table list, row counts against the snapshot stored in the backup, the training ledger chain,
            one stored secret, and the uploads archive. The scratch database and files are always removed. The application is never
            started against it, and no sync or cron job runs against it.
        </p>
        <?php if (!$rec_cfg['configured']): ?>
            <div class="alert alert-warning">
                <strong>Not set up<?= $rec_enabled ? ' (enabled, but cannot run)' : ' (off by default)' ?>.</strong>
                <?= nullable_htmlentities(implode(' ', $rec_cfg['problems'])) ?>
                Create the scoped account once, then enter it below:
                <pre class="mt-2 mb-0 small"><?= nullable_htmlentities(RestoreDrill::setupSteps()) ?></pre>
            </div>
        <?php else: ?>
            <div class="alert alert-success py-2">The drill account works and can create <code>drill_*</code> databases.<?= $rec_enabled ? '' : ' Tick Enable below to run it every night.' ?></div>
        <?php endif; ?>

        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="form-check form-switch mb-3">
                <input type="checkbox" class="form-check-input" id="drill_enabled" name="drill_enabled" value="1" <?= $rec_enabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="drill_enabled">Run the restore drill every night (cron <code>restore_drill.php</code>)</label>
            </div>
            <div class="row">
                <div class="col-md-3 form-group"><label class="small">Drill DB user</label>
                    <input type="text" class="form-control form-control-sm" name="drill_db_user" value="<?= nullable_htmlentities(RecoverySettings::get($mysqli, 'drill_db_user')) ?>" placeholder="rivet_drill"></div>
                <div class="col-md-3 form-group"><label class="small">Drill DB password</label>
                    <input type="password" class="form-control form-control-sm" name="drill_db_pass" placeholder="<?= RecoverySettings::get($mysqli, 'drill_db_pass') !== '' ? 'saved - leave blank to keep' : '' ?>" autocomplete="new-password"></div>
                <div class="col-md-3 form-group"><label class="small">DB host (blank = this server's)</label>
                    <input type="text" class="form-control form-control-sm" name="drill_db_host" value="<?= nullable_htmlentities(RecoverySettings::get($mysqli, 'drill_db_host')) ?>" placeholder="localhost"></div>
                <div class="col-md-3 form-group"><label class="small">Row-count tolerance %</label>
                    <input type="number" min="1" max="50" class="form-control form-control-sm" name="drill_tolerance_pct" value="<?= (int) RecoverySettings::get($mysqli, 'drill_tolerance_pct') ?>"></div>
            </div>
            <h6 class="mt-3">Alerts</h6>
            <div class="row">
                <div class="col-md-3 form-group"><label class="small">Backup stale after (hours)</label>
                    <input type="number" min="2" max="720" class="form-control form-control-sm" name="backup_stale_hours" value="<?= $rec_stale_h ?>"></div>
                <div class="col-md-3 form-group"><label class="small">Failed sync runs in a row</label>
                    <input type="number" min="1" max="20" class="form-control form-control-sm" name="sync_error_threshold" value="<?= (int) RecoverySettings::get($mysqli, 'sync_error_threshold') ?>"></div>
                <div class="col-md-3 form-group"><label class="small">Dormant after (days without a run, 0 = never)</label>
                    <input type="number" min="0" max="365" class="form-control form-control-sm" name="sync_dormant_days" value="<?= (int) RecoverySettings::get($mysqli, 'sync_dormant_days') ?>"></div>
                <div class="col-md-3 form-group"><label class="small">Repeat the same alert after (hours)</label>
                    <input type="number" min="1" max="720" class="form-control form-control-sm" name="alert_repeat_hours" value="<?= (int) RecoverySettings::get($mysqli, 'alert_repeat_hours') ?>"></div>
                <div class="col-md-6 form-group"><label class="small">Alert email (blank = every administrator)</label>
                    <input type="text" class="form-control form-control-sm" name="alert_email" value="<?= nullable_htmlentities(RecoverySettings::get($mysqli, 'alert_email')) ?>" placeholder="it-alerts@example.com"></div>
            </div>
            <div class="row">
                <div class="col-md-6 form-group"><label class="small">RMM sync interval (min, 0 = off; Tactical, Level, Action1, Sophos)</label>
                    <input type="number" min="0" max="1440" class="form-control form-control-sm" name="sync_interval_rmm" value="<?= (int) RecoverySettings::get($mysqli, 'sync_interval_rmm') ?>"></div>
                <div class="col-md-6 form-group"><label class="small">UniFi sync interval (min, <code>auto</code>, 0 = off)</label>
                    <input type="text" class="form-control form-control-sm" name="sync_interval_unifi" value="<?= nullable_htmlentities(RecoverySettings::get($mysqli, 'sync_interval_unifi')) ?>"></div>
            </div>
            <small class="text-muted d-block mb-2">A sync is reported as stopped after three times its interval with no run, and as failing after the number of errored runs above. A failed run counts only when it has a real error message. An integration with no run for the dormant period is listed as dormant instead of being mailed. Each alert repeats at most every <?= (int) RecoverySettings::get($mysqli, 'alert_repeat_hours') ?> hours, and a recovery is announced once.</small>
            <button type="submit" name="save_recovery_settings" class="btn btn-primary btn-sm" <?= $rec_ready ? '' : 'disabled' ?>><i class="fas fa-check me-1"></i>Save</button>
        </form>

        <?php if ($rec_history): ?>
        <h6 class="mt-4">Recent drills</h6>
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead><tr><th>When</th><th>Result</th><th>Backup</th><th>Restore time</th><th>Summary</th></tr></thead>
                <tbody>
                <?php foreach ($rec_history as $h): $checks = json_decode((string) $h['drill_checks'], true)['checks'] ?? []; ?>
                    <tr>
                        <td class="text-nowrap"><?= nullable_htmlentities($h['drill_started_at']) ?> <small class="text-muted">(<?= nullable_htmlentities($h['drill_trigger']) ?>)</small></td>
                        <td><span class="badge text-bg-<?= $rec_badge($h['drill_status']) ?>"><?= nullable_htmlentities(str_replace('_', ' ', $h['drill_status'])) ?></span></td>
                        <td><?= nullable_htmlentities((string) $h['drill_backup_file']) ?></td>
                        <td><?= $h['drill_restore_seconds'] !== null ? nullable_htmlentities(DrillVerifier::duration((float) $h['drill_restore_seconds'])) : '-' ?></td>
                        <td>
                            <?= nullable_htmlentities((string) $h['drill_message']) ?>
                            <?php if ($checks): ?>
                                <details><summary class="small text-muted">checks</summary>
                                    <ul class="small mb-0">
                                    <?php foreach ($checks as $c): ?>
                                        <li><span class="badge text-bg-<?= $rec_badge($c['status']) ?>"><?= nullable_htmlentities($c['status']) ?></span> <strong><?= nullable_htmlentities($c['label']) ?></strong>: <?= nullable_htmlentities($c['detail']) ?></li>
                                    <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
