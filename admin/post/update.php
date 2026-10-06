<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Choose the release channel (Production or Beta). Refuses a channel this server cannot move to without going backwards.
if (isset($_POST['save_release_channel'])) {

    validateCSRFToken($_POST['csrf_token'] ?? '');

    validateAdminRole(); // Old function

    require_once __DIR__ . '/../../includes/release_channel.php';
    $new_channel = (string) ($_POST['release_channel'] ?? '');
    if (!isset(releaseChannels()[$new_channel])) {
        flash_alert('Choose Production or Beta.', 'error');
        redirect();
    }
    $old_channel = releaseChannelConfigured($mysqli, dirname(__DIR__, 2));
    if ($new_channel !== $old_channel) {
        exec("timeout 30 git fetch " . escapeshellarg(RELEASE_REMOTE) . " 2>&1");
        $status = releaseChannelStatus(dirname(__DIR__, 2), $new_channel);
        if (!$status['ref_exists'] || !$status['can_switch']) {
            flash_alert(htmlspecialchars($status['reason'], ENT_QUOTES), 'error');
            redirect();
        }
    }
    // The setting is added by a database update; until it has run, say so instead of failing.
    $channel_column = mysqli_query($mysqli, "SHOW COLUMNS FROM settings LIKE 'config_release_channel'");
    if (!$channel_column || mysqli_num_rows($channel_column) === 0) {
        flash_alert('Run <strong>Update Database</strong> first: the release channel setting is added by that update.', 'error');
        redirect();
    }
    mysqli_query($mysqli, "UPDATE settings SET config_release_channel = '" . mysqli_real_escape_string($mysqli, $new_channel) . "' WHERE company_id = 1");
    logAction('App', 'Update', "$session_name set the release channel to $new_channel (was $old_channel)");
    flash_alert('Release channel set to <strong>' . htmlspecialchars(releaseChannels()[$new_channel]['label'], ENT_QUOTES) . '</strong>.' . ($new_channel !== $old_channel ? ' Run <strong>Update App</strong> to move this server onto it.' : ''));
    redirect();

}

if (isset($_GET['update'])) {

    validateCSRFToken($_GET['csrf_token'] ?? '');

    validateAdminRole(); // Old function

    // Stop with a plain explanation (not a half-run git command) when the server cannot update itself; the Checks panel has the details.
    require_once __DIR__ . '/../../includes/update_checks.php';
    foreach (updateChecks(dirname(__DIR__, 2)) as $update_check) {
        if ($update_check['status'] === 'fail' && in_array($update_check['id'], ['exec', 'git', 'repo', 'gitwrite', 'treewrite'], true)) {
            flash_alert('The update did not run: ' . htmlspecialchars($update_check['detail'] . ' ' . $update_check['fix'], ENT_QUOTES), 'error');
            redirect();
        }
    }

    //git fetch downloads the latest from remote without trying to merge or rebase anything. Then the git reset resets the master branch to what you just fetched. The --hard option changes all the files in your working tree to match the files in origin/master

    // Follow the release channel: move onto its branch first (forward only; refused if it would install older code), then update from it.
    require_once __DIR__ . '/../../includes/release_channel.php';
    $release_channel = releaseChannelConfigured($mysqli, dirname(__DIR__, 2));
    $release_branch  = releaseChannelBranch($release_channel);
    exec("timeout 30 git fetch " . escapeshellarg(RELEASE_REMOTE) . " 2>&1");
    $ensure = releaseChannelEnsureBranch(dirname(__DIR__, 2), $release_channel);
    if (!$ensure['ok']) {
        flash_alert('The update did not run: ' . htmlspecialchars($ensure['message'], ENT_QUOTES), 'error');
        redirect();
    }
    releaseResetGeneratedFiles(dirname(__DIR__, 2));
    $git_output = [];
    $git_code = 0;
    if (isset($_GET['force_update']) == 1) {
        exec("git reset --hard " . escapeshellarg(RELEASE_REMOTE . '/' . $release_branch) . " 2>&1", $git_output, $git_code);
    } else {
        exec("git pull " . escapeshellarg(RELEASE_REMOTE) . " " . escapeshellarg($release_branch) . " 2>&1", $git_output, $git_code);
    }
    if ($git_code !== 0) {
        $git_reason = trim(implode(' ', array_slice(array_filter(array_map('trim', $git_output)), 0, 2)));
        flash_alert('The update did not run: ' . htmlspecialchars(substr($git_reason, 0, 300), ENT_QUOTES)
            . ' If local files were changed by hand, use the force update. If it says permission denied, the web server user cannot write to the install folder.', 'error');
        redirect();
    }
    //header("Location: post.php?update_db");


    // Telemetry was removed: this used to POST the company name, website, location and usage counts to a third-party
    // server on every update (and, because of a stray "=" in its condition, regardless of the Telemetry setting).
    // RivetMSP sends nothing anywhere.

    logAction("App", "Update", "$session_name ran updates");

    flash_alert("Update successful");

    sleep(1);

    redirect();

}

if (isset($_GET['update_db'])) {

    validateCSRFToken($_GET['csrf_token'] ?? '');

    //validateAdminRole(); // Old function

    // One database update at a time: a second click or a CLI run while one is going must not run the same steps twice.
    require_once __DIR__ . '/../../includes/redis_guards.php';
    $update_lock = rivetLocks() ? rivetLocks()->acquire('update_db', 900) : null;
    if ($update_lock !== null && !$update_lock->held()) {
        flash_alert('A database update is already running. Wait for it to finish.', 'error');
        redirect();
    }

    // Get the current version
    require_once ('../includes/database_version.php');

    // Perform upgrades, if required
    try {
        require_once ('database_updates.php');
    } catch (\RivetCore\Migration\MigrationInProgressException $e) {
        // Another process holds the RivetCore migration lock for this schema: not a failure, just try again shortly.
        if ($update_lock !== null) {
            $update_lock->release();
        }
        flash_alert('Another database update is running (RivetCore migration lock). Wait for it to finish and try again.', 'error');
        redirect();
    }

    if ($update_lock !== null) {
        $update_lock->release();
    }

    logAction("Database", "Update", "$session_name updated the database structure");

    flash_alert("Database structure update successful");

    sleep(1);

    redirect();

}
