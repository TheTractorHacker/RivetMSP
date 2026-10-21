<?php
require_once "includes/inc_all_admin.php";

require_once "../includes/database_version.php";
require_once "../includes/update_checks.php";

$updates = fetchUpdates();

$latest_version      = $updates->latest_version;
$current_version     = $updates->current_version;
$current_version_tag = $updates->current_version_tag;
$latest_version_tag  = $updates->latest_version_tag;
$result = $updates->result;

$repo_branch = $updates->branch;   // the release channel's branch (Production or Beta), not config.php's old fixed value
$git_log_raw = shell_exec("git log HEAD.." . escapeshellarg(RELEASE_REMOTE . '/' . $repo_branch) . " --pretty=format:'%h|%ar|%s'");
$channel_status = $updates->channel_status;
$channels = releaseChannels();

$update_checks = updateChecks(dirname(__DIR__), defined('CURRENT_DATABASE_VERSION') ? (string) CURRENT_DATABASE_VERSION : null, (string) LATEST_DATABASE_VERSION);
$check_problems = count(array_filter($update_checks, static fn($c) => $c['status'] === 'fail'));
$check_warnings = count(array_filter($update_checks, static fn($c) => $c['status'] === 'warn'));

// The commits the release channel has that this server does not ("What this update installs").
$pending_commits = [];
if (!empty($git_log_raw)) {
    foreach (explode("\n", $git_log_raw) as $git_log_line) {
        if ($git_log_line === '') {
            continue;
        }
        list($git_log_hash, $git_log_when, $git_log_subject) = array_pad(explode('|', $git_log_line, 3), 3, '');
        $pending_commits[] = ['hash' => $git_log_hash, 'when' => $git_log_when, 'subject' => $git_log_subject];
    }
}

/*
 * Presentation only from here on. Which buttons appear is decided exactly as before:
 *   - the database is behind the code (LATEST_DATABASE_VERSION > CURRENT_DATABASE_VERSION): Update Database;
 *   - otherwise, the channel has commits this server does not: Update App and FORCE Update App;
 *   - otherwise: nothing to run.
 * The headline below only describes that state (plus the two cases the old page showed as "up to date": a database newer
 * than the code, and an update check that could not reach the update source).
 */
$db_current = (string) CURRENT_DATABASE_VERSION;
$db_latest = (string) LATEST_DATABASE_VERSION;
$db_update_pending = LATEST_DATABASE_VERSION > CURRENT_DATABASE_VERSION;
$app_update_pending = !$db_update_pending && $pending_commits !== [];
$db_cmp = version_compare($db_latest, $db_current);
$fetch_failed = $result !== 0;
$channel_label = $channels[$updates->channel]['label'] ?? ucfirst((string) $updates->channel);
$rivetcore_version = class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('rivet/rivet-core')
    ? (string) \Composer\InstalledVersions::getPrettyVersion('rivet/rivet-core') : 'not installed';
$csrf_url = urlencode($_SESSION['csrf_token']);
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$short_hash = static fn($hash): string => strlen((string) $hash) > 12 ? substr((string) $hash, 0, 9) : (string) $hash;

if ($db_update_pending) {
    $status = ['warn', 'Database update needed', 'The code on this server expects database version ' . $db_latest . ', but the database is at ' . $db_current . '. Run the database update to finish installing this release.'];
} elseif ($app_update_pending) {
    $status = ['info', 'Update available', count($pending_commits) . ' change' . (count($pending_commits) === 1 ? ' is' : 's are') . ' waiting on the ' . $channel_label . ' channel'
        . ($latest_version_tag !== $current_version_tag ? ': ' . $current_version_tag . ' to ' . $latest_version_tag . '.' : ' (newer commits, no new release number yet).')];
} elseif ($db_cmp < 0) {
    $status = ['crit', 'Database is newer than this code', 'The database (' . $db_current . ') is ahead of this code (' . $db_latest . '). Do not run more updates; see Checks below.'];
} elseif ($db_cmp > 0) {
    $status = ['warn', 'Database and code do not match', 'The code expects database version ' . $db_latest . ', but the database is at ' . $db_current . '. See Checks below.'];
} elseif ($fetch_failed) {
    $status = ['warn', 'Could not check for updates', 'The update source could not be reached, so this page cannot tell whether a newer release exists. What is installed is shown below.'];
} else {
    $status = ['ok', 'Up to date', 'This server runs the latest release on the ' . $channel_label . ' channel.'];
}

/** A status pill: always an icon and a word, never colour alone (same look as rivetRmmUiPill(), without loading the RMM module on this page). */
$pill = static function (string $kind, string $text, string $extra = '') use ($h): string {
    [$col, $icon] = ['ok' => ['success', 'fa-check-circle'], 'warn' => ['warning', 'fa-exclamation-triangle'], 'crit' => ['danger', 'fa-times-circle'],
        'info' => ['primary', 'fa-arrow-circle-up'], 'off' => ['secondary', 'fa-minus-circle']][$kind] ?? ['secondary', 'fa-minus-circle'];

    return '<span class="badge text-bg-' . $col . ($extra !== '' ? ' ' . $extra : '') . '"><i class="fas ' . $icon . ' me-1" aria-hidden="true"></i>' . $h($text) . '</span>';
};
$tint = ['ok' => 'success', 'warn' => 'warning', 'crit' => 'danger', 'info' => 'primary', 'off' => 'slate'];
$status_icon = ['ok' => 'fa-check', 'warn' => 'fa-exclamation', 'crit' => 'fa-times', 'info' => 'fa-arrow-up', 'off' => 'fa-minus'];

// Update history: what the update handlers already write to the activity log (admin/post/update.php). Read-only.
$update_history = [];
try {
    $history_rows = mysqli_query($mysqli, "SELECT log_id, log_type, log_description, log_created_at FROM logs
        WHERE log_action = 'Update' AND log_type IN ('App', 'Database') ORDER BY log_id DESC LIMIT 10");
    while ($history_rows && ($history_row = mysqli_fetch_assoc($history_rows))) {
        $update_history[] = $history_row;
    }
} catch (\Throwable $e) {
    $update_history = [];
}
$history_kind = static function (array $row): array {
    if ($row['log_type'] === 'Database') {
        return ['ok', 'Database', 'database'];
    }

    return stripos((string) $row['log_description'], 'release channel') !== false ? ['off', 'Channel', 'channel'] : ['ok', 'App', 'app'];
};
$last_run = null;
foreach ($update_history as $history_row) {
    if ($history_kind($history_row)[2] !== 'channel') {
        $last_run = $history_row;
        break;
    }
}
$history_last_ids = ['app' => 0, 'database' => 0];
foreach ($update_history as $history_row) {
    $hk = $history_kind($history_row)[2];
    if (isset($history_last_ids[$hk]) && $history_last_ids[$hk] === 0) {
        $history_last_ids[$hk] = (int) $history_row['log_id'];
    }
}

$checks_attention = array_values(array_filter($update_checks, static fn($c) => in_array($c['status'], ['fail', 'warn'], true)));
$checks_passed = array_values(array_filter($update_checks, static fn($c) => !in_array($c['status'], ['fail', 'warn'], true)));
$check_kind = ['ok' => 'ok', 'warn' => 'warn', 'fail' => 'crit', 'info' => 'off'];
$check_word = ['ok' => 'OK', 'warn' => 'Check', 'fail' => 'Problem', 'info' => 'Note'];
$render_check = static function (array $chk) use ($h, $pill, $check_kind, $check_word): string {
    return '<li class="upd-check" data-check="' . $h($chk['id']) . '" data-status="' . $h($chk['status']) . '">'
        . '<span class="upd-check-pill">' . $pill($check_kind[$chk['status']] ?? 'off', $check_word[$chk['status']] ?? '') . '</span>'
        . '<div class="upd-check-body"><strong>' . $h($chk['title']) . '</strong>'
        . '<div class="small text-muted upd-wrap">' . $h($chk['detail']) . '</div>'
        . ($chk['fix'] !== '' ? '<div class="small mt-1 upd-wrap"><strong>Fix:</strong> ' . $h($chk['fix']) . '</div>' : '')
        . '</div></li>';
};

?>
<link rel="stylesheet" href="/css/admin_update.css?v=<?= (int) @filemtime(__DIR__ . '/../css/admin_update.css') ?>">

<div class="upd-page" data-upd-last-app="<?= (int) $history_last_ids['app'] ?>" data-upd-last-database="<?= (int) $history_last_ids['database'] ?>">

    <!-- Outcome of the update this browser just started (filled by js/admin_update.js from the result message; hidden otherwise). -->
    <div class="alert upd-result mb-3" id="upd-result" role="status" hidden>
        <div class="d-flex align-items-start gap-2">
            <i class="fas fa-fw mt-1" aria-hidden="true" data-upd-result-icon></i>
            <div class="min-w-0">
                <strong data-upd-result-title></strong>
                <div class="small upd-wrap" data-upd-result-text></div>
            </div>
        </div>
    </div>

    <section class="card card-dark mb-3 upd-hero upd-hero-<?= $h($status[0]) ?>" id="update-status" aria-labelledby="upd-status-title" data-upd-state="<?= $h($status[0]) ?>">
        <div class="card-body">
            <div class="d-flex align-items-start gap-3 upd-hero-head">
                <div class="it-stat-icon it-tint-<?= $h($tint[$status[0]]) ?> upd-hero-icon" aria-hidden="true"><i class="fas <?= $h($status_icon[$status[0]]) ?>"></i></div>
                <div class="min-w-0 flex-fill">
                    <div class="upd-eyebrow">Software update</div>
                    <h2 class="upd-hero-title mb-1" id="upd-status-title"><?= $h($status[1]) ?></h2>
                    <p class="text-muted mb-0 upd-wrap"><?= $h($status[2]) ?></p>
                    <p class="small text-muted mb-0 mt-1">
                        <i class="fas fa-code-branch me-1" aria-hidden="true"></i><?= $h($channel_label) ?> channel
                        &middot; follows <code><?= $h(RELEASE_REMOTE . '/' . $repo_branch) ?></code>
                        <?php if ($last_run !== null) { ?>
                            &middot; last update recorded <?= $h(date('Y-m-d H:i', strtotime((string) $last_run['log_created_at']))) ?>
                        <?php } ?>
                    </p>
                </div>
            </div>

            <div class="upd-tiles mt-3">
                <div class="upd-tile">
                    <div class="upd-tile-label">Running</div>
                    <div class="upd-tile-value"><?= $h($current_version_tag) ?></div>
                    <div class="upd-tile-note"><?php if ($current_version_tag !== $current_version) { ?><code><?= $h($short_hash($current_version)) ?></code> &middot; <?php } ?>branch <code><?= $h($channel_status['current_branch'] ?: 'unknown') ?></code></div>
                </div>
                <div class="upd-tile">
                    <div class="upd-tile-label">Latest on <?= $h($channel_label) ?></div>
                    <div class="upd-tile-value"><a href="https://github.com/TheTractorHacker/itflow/releases" target="_blank" rel="noopener"><?= $h($latest_version_tag) ?></a></div>
                    <div class="upd-tile-note">
                        <?php if ($latest_version_tag !== $latest_version && $latest_version !== $current_version && $latest_version !== '') { ?><code><?= $h($short_hash($latest_version)) ?></code> &middot; <?php } ?>
                        <?php if ($fetch_failed) { ?>
                            <?= $pill('warn', 'Not checked') ?>
                        <?php } elseif ($pending_commits !== []) { ?>
                            <?= $pill('info', count($pending_commits) . ' new') ?>
                        <?php } else { ?>
                            <?= $pill('ok', 'Installed') ?>
                        <?php } ?>
                    </div>
                </div>
                <div class="upd-tile">
                    <div class="upd-tile-label">Database</div>
                    <div class="upd-tile-value"><?= $h($db_current) ?></div>
                    <div class="upd-tile-note">
                        <?php if ($db_update_pending) { ?>
                            <?= $pill('warn', 'Needs ' . $db_latest) ?>
                        <?php } elseif ($db_cmp < 0) { ?>
                            <?= $pill('crit', 'Newer than code (' . $db_latest . ')') ?>
                        <?php } elseif ($db_cmp > 0) { ?>
                            <?= $pill('warn', 'Code expects ' . $db_latest) ?>
                        <?php } else { ?>
                            <?= $pill('ok', 'Matches the code') ?>
                        <?php } ?>
                    </div>
                </div>
                <div class="upd-tile">
                    <div class="upd-tile-label">RivetCore</div>
                    <div class="upd-tile-value"><?= $h($rivetcore_version) ?></div>
                    <div class="upd-tile-note text-muted">updates with the app</div>
                </div>
            </div>

            <?php if ($db_update_pending || $app_update_pending) { ?>
                <div class="alert alert-danger upd-backup mt-3 mb-0" role="note">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas fa-fw fa-exclamation-triangle mt-1" aria-hidden="true"></i>
                        <div>
                            <strong>Do NOT run updates without first taking a backup.</strong>
                            VM Snapshots are highly recommended over other methods - see the <a href="https://docs.itflow.org/backups" class="alert-link" target="_blank">docs</a>.
                            Review the <a href="https://github.com/itflow-org/itflow/blob/master/CHANGELOG.md" class="alert-link" target="_blank">changelog</a> for breaking changes that may require manual remediation.
                            <span class="fw-bold">Ignore this warning at your own risk.</span>
                        </div>
                    </div>
                </div>

                <div class="upd-actions mt-3">
                    <?php if ($db_update_pending) { ?>
                        <a class="btn btn-primary" href="post.php?update_db&csrf_token=<?php echo $csrf_url; ?>" data-upd-action="database"><i class="fas fa-fw fa-database me-2" aria-hidden="true"></i>Update Database</a>
                        <span class="small text-muted"><?= $h($db_current) ?> &rarr; <?= $h($db_latest) ?></span>
                    <?php } else { ?>
                        <a class="btn btn-primary confirm-link" href="post.php?update&csrf_token=<?php echo $csrf_url; ?>" data-upd-action="app"><i class="fas fa-fw fa-download me-2" aria-hidden="true"></i>Update App</a>
                        <a class="btn btn-outline-danger confirm-link" href="post.php?update&force_update=1&csrf_token=<?php echo $csrf_url; ?>" data-upd-action="app"><i class="fas fa-fw fa-hammer me-2" aria-hidden="true"></i>FORCE Update App</a>
                        <span class="small text-muted upd-wrap">FORCE discards files changed by hand on this server.</span>
                    <?php } ?>
                </div>
            <?php } else { ?>
                <p class="small text-muted mt-3 mb-0">
                    <i class="fas fa-shield-alt me-1" aria-hidden="true"></i><?= $fetch_failed ? '' : 'Nothing to install. ' ?>When did you last check that a backup of this server restores? <a href="backup.php">Backups</a>
                </p>
            <?php } ?>

            <!-- Shown by js/admin_update.js the moment an update starts; the server answers with this page again when it is done. -->
            <div class="upd-progress mt-3" id="upd-progress" role="status" aria-live="polite" tabindex="-1" hidden>
                <div class="d-flex align-items-center gap-2">
                    <span class="spinner-border spinner-border-sm text-primary" aria-hidden="true"></span>
                    <strong data-upd-progress-title>Updating</strong>
                    <span class="small text-muted ms-auto" data-upd-elapsed></span>
                </div>
                <p class="small text-muted mt-2 mb-2">Keep this page open. It reloads by itself with the result when the server finishes; the server runs these steps in order:</p>
                <ol class="small mb-0 upd-steps" data-upd-steps="app" hidden>
                    <li>Check that this server can update itself (git, folder permissions).</li>
                    <li>Download the latest code from the <?= $h($channel_label) ?> channel.</li>
                    <li>Move onto the channel's branch if the channel was changed.</li>
                    <li>Install the new code.</li>
                    <li>Come back here with the result.</li>
                </ol>
                <ol class="small mb-0 upd-steps" data-upd-steps="database" hidden>
                    <li>Make sure no other database update is running.</li>
                    <li>Apply each database step from <?= $h($db_current) ?> to <?= $h($db_latest) ?>, in order.</li>
                    <li>Come back here with the result.</li>
                </ol>
            </div>
        </div>
    </section>

    <?php if ($fetch_failed) { ?>
        <div class="alert alert-danger mb-3" role="alert">
            <div class="d-flex align-items-start gap-2">
                <i class="fas fa-fw fa-plug mt-1" aria-hidden="true"></i>
                <div class="min-w-0 flex-fill">
                    <strong>Could not find execute 'git fetch'.</strong>
                    <div class="small mt-1">Error details:</div>
                    <pre class="upd-pre mt-1 mb-2"><?php echo htmlspecialchars(substr(trim(implode("\n", (array) $updates->output)), 0, 600)); ?></pre>
                    <div class="small">See the <a href="#update-checks" class="alert-link">Checks</a> below for what to fix: git installed, the web user allowed to write the repository, and the update source.
                        Seek support on the <a href="https://forum.itflow.org" class="alert-link">Forum</a> if required - include relevant PHP error logs &amp; RivetMSP debug output.</div>
                </div>
            </div>
        </div>
    <?php } ?>

    <?php if ($pending_commits !== []) { ?>
        <section class="card card-dark mb-3" id="update-changes">
            <div class="card-header py-2 d-flex align-items-center flex-wrap gap-2">
                <h3 class="card-title mb-0 me-auto"><i class="fas fa-fw fa-list-ul me-2" aria-hidden="true"></i>What this update installs</h3>
                <?= $pill('info', count($pending_commits) . ' change' . (count($pending_commits) === 1 ? '' : 's')) ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-vcenter mb-0">
                        <caption class="visually-hidden">Changes on the <?= $h($channel_label) ?> channel that this server does not have yet</caption>
                        <thead class="upd-thead">
                        <tr>
                            <th scope="col" class="ps-3">Description</th>
                            <th scope="col">Commit</th>
                            <th scope="col" class="pe-3">When</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pending_commits as $commit) { ?>
                            <tr>
                                <td class="ps-3 upd-wrap"><?= $h($commit['subject']) ?></td>
                                <td><code><?= $h($commit['hash']) ?></code></td>
                                <td class="pe-3 text-muted text-nowrap"><?= $h($commit['when']) ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php } ?>

    <section class="card card-dark mb-3" id="update-checks">
        <div class="card-header py-2 d-flex align-items-center flex-wrap gap-2">
            <h3 class="card-title mb-0 me-auto"><i class="fas fa-fw fa-stethoscope me-2" aria-hidden="true"></i>Checks</h3>
            <?php if ($check_problems) { ?>
                <?= $pill('crit', (int) $check_problems . ' problem' . ($check_problems === 1 ? '' : 's')) ?>
            <?php } elseif ($check_warnings) { ?>
                <?= $pill('warn', (int) $check_warnings . ' to look at') ?>
            <?php } else { ?>
                <?= $pill('ok', 'All good') ?>
            <?php } ?>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">What the updater needs, checked on this server without contacting the update source. Red items stop an update; fix them, then reload this page.</p>
            <?php if ($checks_attention !== []) { ?>
                <ul class="upd-checks list-unstyled mb-0">
                    <?php foreach ($checks_attention as $chk) { echo $render_check($chk); } ?>
                </ul>
            <?php } ?>
            <?php if ($checks_passed !== []) { ?>
                <details class="upd-passed<?= $checks_attention !== [] ? ' mt-2' : '' ?>">
                    <summary><?= count($checks_passed) ?> check<?= count($checks_passed) === 1 ? '' : 's' ?> passed<?= $checks_attention === [] ? ': everything the updater needs is in place' : '' ?></summary>
                    <ul class="upd-checks list-unstyled mb-0">
                        <?php foreach ($checks_passed as $chk) { echo $render_check($chk); } ?>
                    </ul>
                </details>
            <?php } ?>
        </div>
    </section>

    <section class="card card-dark mb-3" id="update-channel">
        <div class="card-header py-2">
            <h3 class="card-title mb-0"><i class="fas fa-fw fa-code-branch me-2" aria-hidden="true"></i>Release channel</h3>
        </div>
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="row g-3 mb-3">
                    <?php foreach ($channels as $ckey => $cdef) { ?>
                        <div class="col-md-6">
                            <label class="upd-channel h-100<?= $updates->channel === $ckey ? ' upd-channel-current' : '' ?>">
                                <input type="radio" class="form-check-input mt-0" name="release_channel" value="<?= htmlspecialchars($ckey) ?>" <?= $updates->channel === $ckey ? 'checked' : '' ?>>
                                <span class="min-w-0">
                                    <strong><?= htmlspecialchars($cdef['label']) ?></strong>
                                    <?php if ($updates->channel === $ckey) { ?><span class="badge text-bg-primary ms-1">This server</span><?php } ?>
                                    <span class="d-block text-muted small mt-1"><?= htmlspecialchars($cdef['summary']) ?></span>
                                    <span class="d-block text-muted small mt-1">Follows <code><?= htmlspecialchars(RELEASE_REMOTE . '/' . $cdef['branch']) ?></code></span>
                                </span>
                            </label>
                        </div>
                    <?php } ?>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="submit" name="save_release_channel" class="btn btn-outline-primary"><i class="fas fa-fw fa-check me-2" aria-hidden="true"></i>Save channel</button>
                    <span class="text-muted small">
                        Running branch: <code><?= htmlspecialchars($channel_status['current_branch'] ?: 'unknown') ?></code>
                        <?php if (!$channel_status['same_branch'] && $channel_status['ref_exists'] && $channel_status['can_switch']) { ?>
                            &middot; <strong>Update App will switch this server to <code><?= htmlspecialchars($channel_status['branch']) ?></code>.</strong>
                        <?php } ?>
                    </span>
                </div>
                <?php if ($channel_status['reason'] !== '') { ?>
                    <div class="alert alert-warning mt-3 mb-0"><?= htmlspecialchars($channel_status['reason']) ?></div>
                <?php } ?>
                <p class="text-muted small mt-3 mb-0">Switching channel never installs older code: a switch that would go backwards is refused. Take a backup first.</p>
            </form>
        </div>
    </section>

    <section class="card card-dark mb-3" id="update-history">
        <div class="card-header py-2 d-flex align-items-center flex-wrap gap-2">
            <h3 class="card-title mb-0 me-auto"><i class="fas fa-fw fa-history me-2" aria-hidden="true"></i>Update history</h3>
            <a class="btn btn-sm btn-outline-secondary" href="audit_log.php?action=Update">Audit log</a>
        </div>
        <?php if ($update_history === []) { ?>
            <div class="card-body">
                <div class="it-empty-state">
                    <div class="it-empty-icon"><i class="fas fa-history" aria-hidden="true"></i></div>
                    <p class="it-empty-title">No updates recorded yet</p>
                    <p class="it-empty-subtitle">App updates, database updates and channel changes made from this page appear here, newest first.</p>
                </div>
            </div>
        <?php } else { ?>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-vcenter mb-0">
                        <caption class="visually-hidden">The last <?= count($update_history) ?> updates recorded in the activity log</caption>
                        <thead class="upd-thead">
                        <tr>
                            <th scope="col" class="ps-3">When</th>
                            <th scope="col">What</th>
                            <th scope="col" class="pe-3">Details</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($update_history as $history_row) {
                            [$hk_kind, $hk_word] = $history_kind($history_row); ?>
                            <tr data-upd-history="<?= (int) $history_row['log_id'] ?>">
                                <td class="ps-3 text-nowrap"><?= $h(date('Y-m-d H:i', strtotime((string) $history_row['log_created_at']))) ?></td>
                                <td><?= $pill($hk_kind, $hk_word) ?></td>
                                <td class="pe-3 upd-wrap"><?= $h($history_row['log_description']) ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer small text-muted">From the activity log: who ran each update and when (the log does not record version numbers). Older entries follow the log retention setting.</div>
        <?php } ?>
    </section>

</div>

<script src="/js/admin_update.js?v=<?= (int) @filemtime(__DIR__ . '/../js/admin_update.js') ?>" defer></script>

<?php

require_once "../includes/footer.php";
