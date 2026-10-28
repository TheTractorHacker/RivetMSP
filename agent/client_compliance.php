<?php

require_once "includes/inc_all_client.php";
require_once dirname(__DIR__) . '/includes/rmm_bootstrap.php';

use RivetCore\Compliance\ClientChecklist;
use RivetCore\Compliance\Framework;
use RivetMSP\Compliance\ComplianceService;

// Perms
enforceUserPermission('module_client');
$can_edit = lookupUserPermission('module_client') >= 2;

// RivetCore 1.0.0-rc.54 compliance baseline (Windows security event-log presets): installation-wide, not per client (see
// includes/rmm_bootstrap.php, rivetRmmLogPresetsComplianceFact()). Shown on every client's page with the SAME result and labelled as such; never
// faked per client. The device count is a separate, explicitly-labelled figure (this client's Windows devices online with the logs capability),
// never presented as proof that any of them is actually audited.
$log_preset_fact = null;
$log_preset_device_count = null;
$log_preset_rmm_admin = false;
try {
    if (rivetRmmEnabled($mysqli)) {
        $log_preset_fact = rivetRmmLogPresetsComplianceFact($mysqli);
        $log_preset_device_count = rivetRmmLogCapableWindowsDeviceCount($mysqli, $client_id);
        $log_preset_rmm_admin = rivetRmmLogPresetAdmin((int) $session_user_id, $mysqli);
    }
} catch (\Throwable) {
    $log_preset_fact = null;
}
$log_preset_onboarding_hint = $log_preset_fact !== null && $log_preset_rmm_admin && !empty($log_preset_fact['logs_on']) && empty($log_preset_fact['chosen']) && ($log_preset_device_count ?? 0) > 0;

$csrf = $_SESSION['csrf_token'];
$ready = ComplianceService::subjectsReady($mysqli);
$svc = $ready ? ComplianceService::subjects($mysqli) : null;
$frameworks = $ready ? $svc->frameworks($client_id) : [];
$assessment = $ready && $frameworks ? $svc->assess($client_id) : null;
$snapshots = $ready ? $svc->snapshots($client_id)->list(12) : [];
$shared = $ready ? $svc->shared($client_id) : null;

$state_badge = ['current' => ['Current', 'success'], 'due_soon' => ['Due soon', 'warning text-dark'], 'overdue' => ['Overdue', 'danger'], 'never' => ['Never reviewed', 'secondary']];
$controls_text = static function (array $controls): string {
    $parts = [];
    foreach ($controls as $f => $refs) {
        $parts[] = (Framework::LABELS[$f] ?? $f) . ': ' . implode(', ', $refs);
    }

    return implode(' · ', $parts);
};
?>

<div class="card card-dark mb-3">
    <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-clipboard-list me-2"></i>Compliance</h3>
        <?php if ($assessment) { ?>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener" href="client_compliance_report.php?client_id=<?= $client_id ?>&format=html"><i class="fa fa-print me-1"></i>Printable report</a>
            <a class="btn btn-outline-secondary btn-sm" href="client_compliance_report.php?client_id=<?= $client_id ?>&format=csv"><i class="fa fa-file-csv me-1"></i>CSV</a>
            <?php if ($can_edit) { ?>
            <form action="post.php" method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="client_id" value="<?= $client_id ?>"><button class="btn btn-primary btn-sm" type="submit" name="take_client_compliance_snapshot"><i class="fa fa-camera me-1"></i>Save snapshot</button></form>
            <?php } ?>
        </div>
        <?php } ?>
    </div>
    <div class="card-body">
        <p class="text-muted mb-1">Track this client's compliance work: choose the standards that apply to them, then record each review with who did it, when and where the evidence is.
        It is a working checklist for you and the client, <strong>not</strong> a certification or an audit opinion; control references are indicative.</p>
    </div>
</div>

<?php if ($log_preset_fact !== null) { ?>
<?php if ($log_preset_onboarding_hint) { ?>
<div class="alert alert-info alert-dismissible fade show" role="alert">
    <i class="fas fa-info-circle me-1" aria-hidden="true"></i>
    This client has <?= (int) $log_preset_device_count ?> Windows device<?= (int) $log_preset_device_count === 1 ? '' : 's' ?> online with log collection, but nobody has chosen the Windows security event-log presets yet. This is an installation-wide setting (it is not specific to this client). Use <strong>Apply compliance baseline</strong> on the "Security event log presets" card below; it never turns log collection on or off by itself.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php } ?>
<div class="card mb-3">
    <div class="card-header py-2"><h4 class="card-title mt-2 mb-0">Security event log presets</h4></div>
    <div class="card-body">
        <p class="mb-2"><span class="badge text-bg-<?= $log_preset_fact['status'] === 'pass' ? 'success' : ($log_preset_fact['status'] === 'na' ? 'secondary' : 'danger') ?>"><?= $log_preset_fact['status'] === 'pass' ? 'Pass' : ($log_preset_fact['status'] === 'na' ? 'Not available' : 'Fail') ?></span>
        <strong class="ms-2"><?= nullable_htmlentities($log_preset_fact['summary']) ?></strong></p>
        <p class="small text-muted mb-2">The Windows security event-log presets are ONE setting for the whole installation (Administration &rarr; Endpoint agent), not a per-client setting: this result is collected for all clients, and every client's page shows the exact same fact.</p>
        <?php if ($log_preset_fact['lines']) { ?>
        <ul class="small text-muted mb-2">
            <?php foreach ($log_preset_fact['lines'] as $line) { ?><li><?= nullable_htmlentities($line) ?></li><?php } ?>
        </ul>
        <?php } ?>
        <?php if ($log_preset_device_count !== null) { ?>
        <p class="small text-muted mb-2"><i class="fas fa-desktop me-1" aria-hidden="true"></i><strong><?= (int) $log_preset_device_count ?></strong> of this client's Windows devices <?= (int) $log_preset_device_count === 1 ? 'is' : 'are' ?> online and announce the log-collection capability. This is a device count, not proof that any of them is actually auditing: Windows must still write the events (Advanced Audit Policy).</p>
        <?php } ?>
        <?php if ($can_edit) { ?>
        <?php if ($log_preset_rmm_admin) { ?>
        <form action="post.php" method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="client_id" value="<?= $client_id ?>"><button class="btn btn-outline-primary btn-sm" type="submit" name="apply_log_preset_baseline_client"><i class="fas fa-shield-alt me-1" aria-hidden="true"></i>Apply compliance baseline</button></form>
        <span class="small text-muted ms-2">Changes the installation-wide setting; never unticks anything and never switches log collection on or off.</span>
        <?php } else { ?>
        <p class="small text-muted mb-0">Changing this needs the RMM administrator ability (Administration &rarr; Endpoint agent).</p>
        <?php } ?>
        <?php } ?>
    </div>
</div>
<?php } ?>

<?php if (!$ready) { ?>
    <div class="alert alert-warning">Run the database update first (Administration &rarr; Update) to turn on customer compliance.</div>
<?php } else { ?>

<div class="card mb-3">
    <div class="card-header py-2"><h4 class="card-title mt-2 mb-0">Standards that apply</h4></div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="client_id" value="<?= $client_id ?>">
            <div class="d-flex flex-wrap gap-3 mb-3">
                <?php foreach (Framework::LABELS as $key => $label) { ?>
                    <label class="form-check"><input class="form-check-input" type="checkbox" name="frameworks[]" value="<?= nullable_htmlentities($key) ?>" <?= in_array($key, $frameworks, true) ? 'checked' : '' ?> <?= $can_edit ? '' : 'disabled' ?>> <span class="form-check-label"><?= nullable_htmlentities($label) ?></span></label>
                <?php } ?>
            </div>
            <?php if ($can_edit) { ?><button class="btn btn-primary btn-sm" type="submit" name="set_client_compliance_frameworks">Save</button><?php } ?>
            <span class="small text-muted ms-2">Only checklist items that help evidence a chosen standard are listed and scored.</span>
        </form>
    </div>
</div>

<?php if ($assessment) { ?>
<div class="row g-3 mb-3">
    <?php foreach (['all' => 'Overall'] + array_intersect_key(Framework::LABELS, array_flip($frameworks)) as $key => $label) {
        $s = $assessment->summaries[$key]; $score = $s['score'];
        $tone = $score === null ? 'secondary' : ($score >= 80 ? 'success' : ($score >= 50 ? 'warning' : 'danger')); ?>
        <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-3">
            <div class="small text-muted"><?= nullable_htmlentities($label) ?></div>
            <div class="fs-3 fw-semibold text-<?= $tone ?>"><?= $score === null ? '&mdash;' : nullable_htmlentities((string) $score) . '%' ?></div>
            <div class="small text-muted"><?= (int) $s['manual_current'] ?> of <?= (int) $s['items'] ?> current</div>
        </div></div></div>
    <?php } ?>
</div>

<div class="card mb-3">
    <div class="card-header py-2"><h4 class="card-title mt-2 mb-0">Checklist</h4></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead><tr><th>Item</th><th>State</th><th>Last review</th><th class="d-none d-lg-table-cell">Controls</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($assessment->manual as $r) { $sb = $state_badge[$r['state']] ?? [$r['state'], 'secondary']; ?>
                <tr>
                    <td><strong><?= nullable_htmlentities($r['title']) ?></strong><div class="small text-muted"><?= nullable_htmlentities($r['category']) ?> &middot; every <?= (int) $r['interval_days'] ?> days &middot; <?= nullable_htmlentities($r['why']) ?></div></td>
                    <td><span class="badge text-bg-<?= $sb[1] ?>"><?= nullable_htmlentities($sb[0]) ?></span></td>
                    <td class="small"><?php if ($r['reviewed_on']) { ?><?= nullable_htmlentities($r['reviewed_on']) ?> by <?= nullable_htmlentities((string) $r['reviewer_name']) ?><br>next due <?= nullable_htmlentities((string) $r['next_due_on']) ?><?php if ($r['note']) { ?><div class="text-muted"><?= nullable_htmlentities((string) $r['note']) ?></div><?php } ?><?php } else { ?>&mdash;<?php } ?></td>
                    <td class="small text-muted d-none d-lg-table-cell"><?= nullable_htmlentities($controls_text($r['controls'])) ?></td>
                    <td class="text-end"><?php if ($can_edit) { ?><button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#rev_<?= nullable_htmlentities($r['id']) ?>">Record review</button><?php } ?></td>
                </tr>
                <?php if ($can_edit) { ?>
                <tr class="collapse" id="rev_<?= nullable_htmlentities($r['id']) ?>"><td colspan="5" class="bg-light">
                    <form action="post.php" method="post" autocomplete="off" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                        <input type="hidden" name="item_id" value="<?= nullable_htmlentities($r['id']) ?>">
                        <div class="col-md-3"><label class="form-label small mb-0">Reviewed by</label><input class="form-control form-control-sm" name="reviewer_name" maxlength="200" required value="<?= nullable_htmlentities((string) $session_name) ?>"></div>
                        <div class="col-md-2"><label class="form-label small mb-0">Reviewed on</label><input class="form-control form-control-sm" type="date" name="reviewed_on" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
                        <div class="col-md-2"><label class="form-label small mb-0">Next due <span class="text-muted">(optional)</span></label><input class="form-control form-control-sm" type="date" name="next_due_on"></div>
                        <div class="col-md-4"><label class="form-label small mb-0">Note / where the evidence is</label><input class="form-control form-control-sm" name="note" maxlength="2000"></div>
                        <div class="col-md-1"><button class="btn btn-primary btn-sm w-100" type="submit" name="record_client_compliance_review">Save</button></div>
                    </form>
                </td></tr>
                <?php } ?>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-2"><h4 class="card-title mt-2 mb-0">Share with the client</h4></div>
    <div class="card-body">
        <?php if ($shared) { ?>
            <p class="mb-2">The client's portal users can see the snapshot taken <strong><?= nullable_htmlentities($shared['taken_at']) ?></strong> (published <?= nullable_htmlentities($shared['published_at']) ?>) under <em>Security</em>.</p>
            <?php if ($can_edit) { ?><form action="post.php" method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="client_id" value="<?= $client_id ?>"><button class="btn btn-outline-danger btn-sm" type="submit" name="unshare_client_compliance">Stop sharing</button></form><?php } ?>
        <?php } else { ?>
            <p class="mb-0 text-muted">Nothing is shared with this client. Choose <strong>Share</strong> on a snapshot below.</p>
        <?php } ?>
        <p class="small text-muted mt-2 mb-0">Only a reduced view is shown to the client: scores, and each item's title, status and last-review date. Reviewer names, notes and evidence locations are never shared. A newer snapshot does not replace what is shared.</p>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-2"><h4 class="card-title mt-2 mb-0">Snapshots</h4></div>
    <div class="card-body">
        <?php if (!$snapshots) { ?><p class="mb-0 text-muted">No snapshots yet.</p><?php } else { ?>
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>Taken</th><th>Overall</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($snapshots as $sn) { $sc = $sn['summaries']['all']['score'] ?? null; ?>
                <tr>
                    <td><?= nullable_htmlentities($sn['taken_at']) ?></td>
                    <td><?= $sc === null ? '&mdash;' : nullable_htmlentities((string) $sc) . '%' ?></td>
                    <td class="text-end">
                        <a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener" href="client_compliance_report.php?client_id=<?= $client_id ?>&format=html&snapshot=<?= (int) $sn['snapshot_id'] ?>">Report</a>
                        <a class="btn btn-outline-secondary btn-sm" href="client_compliance_report.php?client_id=<?= $client_id ?>&format=csv&snapshot=<?= (int) $sn['snapshot_id'] ?>">CSV</a>
                        <?php if ($can_edit) { ?><button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#pub_<?= (int) $sn['snapshot_id'] ?>"><?= $shared && $shared['snapshot_id'] === (int) $sn['snapshot_id'] ? 'Shared' : 'Share' ?></button><?php } ?>
                    </td>
                </tr>
                <?php if ($can_edit) { ?>
                <tr class="collapse" id="pub_<?= (int) $sn['snapshot_id'] ?>"><td colspan="3" class="bg-light">
                    <form action="post.php" method="post" autocomplete="off" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                        <input type="hidden" name="snapshot_id" value="<?= (int) $sn['snapshot_id'] ?>">
                        <div class="col-md-9"><label class="form-label small mb-0">Message shown above the report (optional)</label><input class="form-control form-control-sm" name="note" maxlength="1000"></div>
                        <div class="col-md-3"><button class="btn btn-primary btn-sm w-100" type="submit" name="share_client_compliance">Share this snapshot</button></div>
                    </form>
                </td></tr>
                <?php } ?>
            <?php } ?>
            </tbody>
        </table></div>
        <?php } ?>
    </div>
</div>
<?php } elseif ($ready) { ?>
    <div class="alert alert-info">Choose at least one standard above to start the checklist.</div>
<?php } ?>

<?php } ?>

<?php require_once "../includes/footer.php";
