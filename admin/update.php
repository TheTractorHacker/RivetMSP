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
$check_badge = ['ok' => 'success', 'warn' => 'warning', 'fail' => 'danger', 'info' => 'secondary'];
$check_icon = ['ok' => 'fa-check-circle', 'warn' => 'fa-exclamation-triangle', 'fail' => 'fa-times-circle', 'info' => 'fa-info-circle'];

$git_log = '';
if (!empty($git_log_raw)) {
    foreach (explode("\n", $git_log_raw) as $git_log_line) {
        if ($git_log_line === '') {
            continue;
        }
        list($git_log_hash, $git_log_when, $git_log_subject) = array_pad(explode('|', $git_log_line, 3), 3, '');
        $git_log .= '<tr><td>' . htmlspecialchars($git_log_hash) . '</td><td>' . htmlspecialchars($git_log_when) . '</td><td>' . htmlspecialchars($git_log_subject) . '</td></tr>';
    }
}

?>

    <div class="card card-dark mb-3">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-code-branch me-2"></i>Release channel</h3>
        </div>
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="row">
                    <?php foreach ($channels as $ckey => $cdef) { ?>
                        <div class="col-md-6 mb-3">
                            <label class="d-block border rounded p-3 h-100 <?= $updates->channel === $ckey ? 'border-primary' : '' ?>">
                                <input type="radio" name="release_channel" value="<?= htmlspecialchars($ckey) ?>" <?= $updates->channel === $ckey ? 'checked' : '' ?>>
                                <strong class="ms-1"><?= htmlspecialchars($cdef['label']) ?></strong>
                                <?php if ($updates->channel === $ckey) { ?><span class="badge bg-primary ms-1">This server</span><?php } ?>
                                <div class="text-secondary small mt-1"><?= htmlspecialchars($cdef['summary']) ?></div>
                                <div class="text-secondary small mt-1">Follows <code><?= htmlspecialchars(RELEASE_REMOTE . '/' . $cdef['branch']) ?></code></div>
                            </label>
                        </div>
                    <?php } ?>
                </div>
                <button type="submit" name="save_release_channel" class="btn btn-primary"><i class="fas fa-fw fa-check me-2"></i>Save channel</button>
                <span class="text-secondary small ms-2">
                    Running branch: <code><?= htmlspecialchars($channel_status['current_branch'] ?: 'unknown') ?></code>
                    <?php if (!$channel_status['same_branch'] && $channel_status['ref_exists'] && $channel_status['can_switch']) { ?>
                        &middot; <strong>Update App will switch this server to <code><?= htmlspecialchars($channel_status['branch']) ?></code>.</strong>
                    <?php } ?>
                </span>
                <?php if ($channel_status['reason'] !== '') { ?>
                    <div class="alert alert-warning mt-3 mb-0"><?= htmlspecialchars($channel_status['reason']) ?></div>
                <?php } ?>
                <p class="text-secondary small mt-3 mb-0">Switching channel never installs older code: a switch that would go backwards is refused. Take a backup first.</p>
            </form>
        </div>
    </div>

    <div class="card card-dark mb-3" id="update-checks">
        <div class="card-header py-3 d-flex align-items-center justify-content-between">
            <h3 class="card-title mb-0"><i class="fas fa-fw fa-stethoscope me-2"></i>Checks</h3>
            <?php if ($check_problems) { ?>
                <span class="badge bg-danger fs-6"><?= (int) $check_problems ?> problem<?= $check_problems === 1 ? '' : 's' ?></span>
            <?php } elseif ($check_warnings) { ?>
                <span class="badge bg-warning fs-6"><?= (int) $check_warnings ?> to look at</span>
            <?php } else { ?>
                <span class="badge bg-success fs-6">All good</span>
            <?php } ?>
        </div>
        <div class="card-body">
            <p class="text-muted small">What the updater needs, checked on this server without contacting the update source. Red items stop an update; fix them, then reload this page.</p>
            <?php foreach ($update_checks as $chk) { ?>
                <div class="d-flex align-items-start border-top py-2" data-check="<?= htmlspecialchars($chk['id']) ?>" data-status="<?= htmlspecialchars($chk['status']) ?>">
                    <span class="badge bg-<?= $check_badge[$chk['status']] ?? 'secondary' ?> me-3 mt-1" style="min-width: 4.5rem;"><i class="fas fa-fw <?= $check_icon[$chk['status']] ?? 'fa-info-circle' ?> me-1"></i><?= htmlspecialchars(['ok' => 'OK', 'warn' => 'Check', 'fail' => 'Problem', 'info' => 'Note'][$chk['status']] ?? '') ?></span>
                    <div>
                        <strong><?= htmlspecialchars($chk['title']) ?></strong>
                        <div class="small text-muted"><?= htmlspecialchars($chk['detail']) ?></div>
                        <?php if ($chk['fix'] !== '') { ?><div class="small mt-1"><strong>Fix:</strong> <?= htmlspecialchars($chk['fix']) ?></div><?php } ?>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>

    <div class="card card-dark">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-download me-2"></i>Update</h3>
        </div>
        <div class="card-body" style="text-align: center;">

            <!-- Check if git fetch result was successful (0), if not show a warning -->
            <?php if ($result !== 0) { ?>
                <div class="alert alert-danger">
                    <strong>WARNING: Could not find execute 'git fetch'.</strong>
                    <br><br>
                    <i>Error details:- <?php echo htmlspecialchars(substr(trim(implode("\n", (array) $updates->output)), 0, 600)); ?></i>
                    <br>
                    <br>See the <a href="#update-checks" class="alert-link">Checks</a> above for what to fix: git installed, the web user allowed to write the repository, and the update source.
                    <br>Seek support on the <a href="https://forum.itflow.org">Forum</a> if required - include relevant PHP error logs & RivetMSP debug output
                </div>
            <?php } ?>

            <?php if (LATEST_DATABASE_VERSION > CURRENT_DATABASE_VERSION) { ?>
                <div class="alert alert-danger">
                    <h1 class="fw-bold text-center">⚠️ DANGER ⚠️</h1>
                    <h2 class="fw-bold text-center">Do NOT run updates without first taking a backup</h2>
                    <p>VM Snapshots are highly recommended over other methods - see the <a href="https://docs.itflow.org/backups" class="alert-link" target="_blank">docs</a>. Review the <a href="https://github.com/itflow-org/itflow/blob/master/CHANGELOG.md" class="alert-link" target="_blank">changelog</a> for breaking changes that may require manual remediation.</p>
                    <p class="text-center fw-bold">Ignore this warning at your own risk.</p>
                </div>
                <br>
                <a class="btn btn-dark btn-lg my-4" href="post.php?update_db&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>"><i class="fas fa-fw fa-4x fa-download mb-1"></i><h5>Update Database</h5></a>
                <br>
                <small class="text-secondary">Current DB Version: <?php echo CURRENT_DATABASE_VERSION; ?></small>
                <br>
                <small class="text-secondary">Latest DB Version: <?php echo LATEST_DATABASE_VERSION; ?></small>
                <br>
                <small class="text-secondary">RivetCore: <?php echo htmlspecialchars(class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('rivet/rivet-core') ? (string) \Composer\InstalledVersions::getPrettyVersion('rivet/rivet-core') : 'not installed'); ?></small>
                <br>
                <hr>

            <?php } else {
                if (!empty($git_log)) { ?>
                    <div class="alert alert-danger">
                        <h1 class="fw-bold text-center">⚠️ DANGER ⚠️</h1>
                        <h2 class="fw-bold text-center">Do NOT run updates without first taking a backup</h2>
                        <p>VM Snapshots are highly recommended over other methods - see the <a href="https://docs.itflow.org/backups" class="alert-link" target="_blank">docs</a>. Review the <a href="https://github.com/itflow-org/itflow/blob/master/CHANGELOG.md" class="alert-link" target="_blank">changelog</a> for breaking changes that may require manual remediation.</p>
                        <p class="text-center fw-bold">Ignore this warning at your own risk.</p>
                    </div>

                    <a class="btn btn-primary btn-lg my-4 confirm-link" href="post.php?update&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>"><i class="fas fa-fw fa-4x fa-download mb-1"></i><h5>Update App</h5></a>
                    <a class="btn btn-danger btn-lg confirm-link" href="post.php?update&force_update=1&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>"><i class="fas fa-fw fa-4x fa-hammer mb-1"></i><h5>FORCE Update App</h5></a>

                <?php } else { ?>
                    <p><strong>Version:<br><strong class="text-dark"><?php echo htmlspecialchars($current_version_tag); ?></strong></p>
                    <p class="text-secondary">Latest Release:<br><strong class="text-dark"><a href="https://github.com/TheTractorHacker/itflow/releases" target="_blank"><?php echo htmlspecialchars($latest_version_tag); ?></a></strong></p>
                    <p class="text-secondary">Database Version:<br><strong class="text-dark"><?php echo CURRENT_DATABASE_VERSION; ?></strong></p>
                    <p class="text-secondary">RivetCore:<br><strong class="text-dark"><?php echo htmlspecialchars(class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('rivet/rivet-core') ? (string) \Composer\InstalledVersions::getPrettyVersion('rivet/rivet-core') : 'not installed'); ?></strong> <small class="text-muted">(the shared library updates with the app)</small></p>
                    <p class="text-muted">You are up to date!<br>Everything is going to be alright</p>
                    <i class="far fa-3x text-dark fa-smile-wink"></i><br>

                    <?php if (rand(1,10) == 1) { ?>
                        <br>
                        <div class="alert alert-info alert-dismissible fade show" role="alert">
                            You're up to date, but when was the last time you checked your RivetMSP backup works?
                            <button type="button" class="close" data-bs-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                    <?php } ?>

                <?php }
            }

            if (!empty($git_log)) { ?>
                <table class="table ">
                    <thead>
                    <tr>
                        <th>Commit</th>
                        <th>When</th>
                        <th>Description</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    echo $git_log;
                    ?>
                    </tbody>
                </table>
                <?php
            }

            ?>

        </div>
    </div>

<?php

require_once "../includes/footer.php";

