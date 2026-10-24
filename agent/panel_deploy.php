<?php

/*
 * RivetPanel deploy page (GitHub roadmap "Prompt 2"). Mint enrollment tokens (single-use or a capped multi-use
 * "fleet" token for an RMM mass deployment), list/revoke tokens and devices, toggle "allow tickets from
 * client-level devices" - all per client. Panel has no module switch of its own in RivetCore (works whether or
 * not the RMM module is enabled), but this edition adds settings.config_core_panel_enabled (default OFF) the
 * same way Problems & Changes/Workflows do (includes/core_module_gates.php), so the page is gated the same way.
 *
 * module_support level 1 (read) / 2 (write) - not admin-only, same as RivetIT's reference page.
 */

require_once "includes/inc_all_client.php";
require_once "../includes/panel_bootstrap.php";

enforceUserPermission('module_support', 1);

if (!rivetModulePageOn('core.panel.enabled', 'RivetPanel')) {
    require_once "../includes/footer.php";
    exit;
}

$panel = rivetPanelModule($mysqli);
// PanelAdmin::tokens()/devices() are deliberately narrow (Core's own contract); this page also wants max_uses (to
// show the fleet badge) and a human contact name, neither of which Core's admin API returns, so those two lists
// are read directly off the documented schema (vendor/rivet/rivet-core/src/Panel/Migration/PanelSchema.php) -
// same tables, same "kind = 'panel'" scoping, just the extra columns a Deploy page needs. All writes/revokes
// still go through PanelAdmin/PanelEnrollment (agent/post/panel_deploy.php), never raw SQL.
$tokens = array_map(static function (array $r): array {
    $r['token_id'] = (int) $r['token_id'];
    $r['client_id'] = (int) $r['client_id'];
    $r['contact_id'] = $r['contact_id'] !== null ? (int) $r['contact_id'] : null;
    $r['max_uses'] = (int) $r['max_uses'];
    $r['use_count'] = (int) $r['use_count'];

    return $r;
}, mysqli_fetch_all(mysqli_query($mysqli, "SELECT t.token_id, t.client_id, t.contact_id, c.contact_name, t.label, t.expires_at, t.max_uses, t.use_count, t.revoked_at
    FROM endpoint_agent_enrollment_tokens t LEFT JOIN contacts c ON c.contact_id = t.contact_id
    WHERE t.kind = 'panel' AND t.client_id = $client_id ORDER BY t.token_id DESC LIMIT 500"), MYSQLI_ASSOC) ?: []);
$devices = array_map(static function (array $r): array {
    $r['device_id'] = (int) $r['device_id'];
    $r['client_id'] = (int) $r['client_id'];
    $r['contact_id'] = $r['contact_id'] !== null ? (int) $r['contact_id'] : null;

    return $r;
}, mysqli_fetch_all(mysqli_query($mysqli, "SELECT d.device_id, d.client_id, d.contact_id, c.contact_name, d.hostname, d.os, d.first_seen_at, d.revoked_at
    FROM endpoint_agent_devices d LEFT JOIN contacts c ON c.contact_id = d.contact_id
    WHERE d.kind = 'panel' AND d.client_id = $client_id ORDER BY d.device_id DESC LIMIT 1000"), MYSQLI_ASSOC) ?: []);
$allowClientLevel = $panel->settings()->allowClientLevelTickets($client_id);
$maxFleetUses = $panel->limits()->enrollMaxUses;

$contacts_sql = mysqli_query($mysqli, "SELECT contact_id, contact_name FROM contacts WHERE contact_client_id = $client_id AND contact_archived_at IS NULL ORDER BY contact_name ASC");

$new_token = $_SESSION['panel_new_token'] ?? null;
unset($_SESSION['panel_new_token']);

?>

<div class="card card-dark mb-3">
    <div class="card-body">
        <h5 class="mb-1"><i class="fas fa-fw fa-comment-dots me-2"></i>RivetPanel</h5>
        <p class="text-muted mb-0">A desktop helper that lets someone at this client's PCs open a ticket and chat with a technician, without a client portal login. Mint an enrollment token below, run it through the installer once per PC (or once for a whole fleet with a multi-use token), and devices appear here.</p>
    </div>
</div>

<?php if ($new_token) { ?>
<div class="card card-dark mb-3 border-success">
    <div class="card-header py-2"><h5 class="card-title mb-0 text-success"><i class="fas fa-fw fa-key me-2"></i>New enrollment token - shown once</h5></div>
    <div class="card-body">
        <div class="input-group mb-3">
            <input type="text" class="form-control" readonly value="<?= nullable_htmlentities($new_token['token']) ?>" id="panelNewTokenValue">
            <button class="btn btn-outline-secondary clipboardjs" type="button" title="Copy token" data-clipboard-text="<?= nullable_htmlentities($new_token['token']) ?>"><i class="far fa-copy"></i></button>
        </div>
        <p class="text-muted small mb-2">This is the only time the token is shown. Use it with one of these (replace <code>&lt;TOKEN&gt;</code>):</p>
        <ul class="list-unstyled small mb-0" style="font-family:monospace;">
            <li class="mb-1"><strong>Windows (silent):</strong> <code>rivetpanel-setup.exe /VERYSILENT /ENROLLTOKEN=<?= nullable_htmlentities($new_token['token']) ?></code></li>
            <li class="mb-1"><strong>RMM push (<code>deploy_rivetpanel.ps1</code>):</strong> <code>powershell -ExecutionPolicy Bypass -File deploy_rivetpanel.ps1 -EnrollToken "<?= nullable_htmlentities($new_token['token']) ?>"</code></li>
            <li class="mb-1"><strong>Linux / macOS:</strong> <code>curl -fsSL https://github.com/TheTractorHacker/rivetpanel/releases/latest/download/install.sh | sh -s -- --enroll-token <?= nullable_htmlentities($new_token['token']) ?></code></li>
        </ul>
        <p class="text-muted small mt-2 mb-0">Download the installer from the <a href="https://github.com/TheTractorHacker/rivetpanel/releases/latest" target="_blank" rel="noopener noreferrer">latest RivetPanel release</a>.</p>
    </div>
</div>
<?php } ?>

<div class="row">
    <div class="col-md-5">

        <div class="card card-dark mb-3">
            <div class="card-header py-2"><h5 class="card-title mb-0">Client-level devices</h5></div>
            <div class="card-body">
                <p class="text-muted small">A device enrolled without a specific contact can only see/open tickets it created itself, and only while this is on.</p>
                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="client_id" value="<?= $client_id ?>">
                    <input type="hidden" name="allow_client_level" value="<?= $allowClientLevel ? 0 : 1 ?>">
                    <button type="submit" name="set_client_level" class="btn btn-sm <?= $allowClientLevel ? 'btn-success' : 'btn-outline-secondary' ?>">
                        <?= $allowClientLevel ? 'On - allow tickets from client-level devices' : 'Off - client-level devices cannot open tickets' ?>
                    </button>
                </form>
            </div>
        </div>

        <div class="card card-dark">
            <div class="card-header py-2"><h5 class="card-title mb-0">New enrollment token</h5></div>
            <div class="card-body">
                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="client_id" value="<?= $client_id ?>">
                    <div class="form-group">
                        <label>Contact</label>
                        <select class="form-control select2" name="contact_id">
                            <option value="">None (client-level)</option>
                            <?php while ($c = mysqli_fetch_assoc($contacts_sql)) { ?>
                                <option value="<?= (int) $c['contact_id'] ?>"><?= nullable_htmlentities($c['contact_name']) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Label</label>
                        <input type="text" class="form-control" name="label" maxlength="100" placeholder="e.g. Front desk PC, or a fleet rollout name">
                    </div>
                    <div class="form-group">
                        <label>Expires in (hours)</label>
                        <input type="number" class="form-control" name="ttl_hours" value="24" min="1" max="72">
                    </div>
                    <div class="form-group">
                        <label>Number of PCs</label>
                        <input type="number" class="form-control" name="max_uses" value="1" min="1" max="<?= (int) $maxFleetUses ?>">
                        <small class="text-muted">1 = single-use (one PC). More than 1 mints a capped multi-use "fleet" token, for an RMM mass deployment of the same installer line to several PCs - each one still gets its own credential and is revocable on its own.</small>
                    </div>
                    <button type="submit" name="create_token" class="btn btn-primary"><i class="fas fa-fw fa-plus me-1"></i>Create token</button>
                </form>
            </div>
        </div>

    </div>

    <div class="col-md-7">

        <div class="card card-dark mb-3">
            <div class="card-header py-2"><h5 class="card-title mb-0">Outstanding tokens</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-vcenter mb-0">
                        <thead><tr><th>Label</th><th>Contact</th><th>Expires</th><th>Used</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php if (empty($tokens)) { ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">No outstanding tokens.</td></tr>
                        <?php } foreach ($tokens as $t) {
                            $revoked = !empty($t['revoked_at']);
                            $expired = !$revoked && strtotime((string) $t['expires_at']) < time();
                            $usedUp = !$revoked && !$expired && (int) $t['use_count'] >= (int) $t['max_uses'];
                            $statusLabel = $revoked ? 'Revoked' : ($expired ? 'Expired' : ($usedUp ? 'Used up' : 'Active'));
                            $statusBadge = $revoked || $expired ? 'text-bg-secondary' : ($usedUp ? 'text-bg-warning' : 'text-bg-success');
                            ?>
                            <tr>
                                <td><?= nullable_htmlentities($t['label'] ?: '(no label)') ?><?php if ((int) $t['max_uses'] > 1) { ?> <span class="badge text-bg-info ms-1" title="Fleet token">x<?= (int) $t['max_uses'] ?></span><?php } ?></td>
                                <td><?= $t['contact_id'] ? nullable_htmlentities($t['contact_name'] ?? ('#' . (int) $t['contact_id'])) : '<span class="text-muted">Client-level</span>' ?></td>
                                <td><?= nullable_htmlentities($t['expires_at']) ?></td>
                                <td><?= (int) $t['use_count'] ?> / <?= (int) $t['max_uses'] ?></td>
                                <td><span class="badge <?= $statusBadge ?>"><?= $statusLabel ?></span></td>
                                <td class="text-end">
                                    <?php if (!$revoked) { ?>
                                        <a class="btn btn-sm btn-outline-danger confirm-link" href="post.php?revoke_token=<?= (int) $t['token_id'] ?>&client_id=<?= $client_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">Revoke</a>
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card card-dark">
            <div class="card-header py-2"><h5 class="card-title mb-0">Enrolled panel devices</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-vcenter mb-0">
                        <thead><tr><th>Hostname</th><th>OS</th><th>Contact</th><th>First seen</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php if (empty($devices)) { ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">No devices enrolled yet.</td></tr>
                        <?php } foreach ($devices as $d) {
                            $devRevoked = !empty($d['revoked_at']);
                            ?>
                            <tr>
                                <td><?= nullable_htmlentities($d['hostname'] ?: '(unknown)') ?></td>
                                <td><?= nullable_htmlentities($d['os'] ?? '') ?></td>
                                <td><?= $d['contact_id'] ? nullable_htmlentities($d['contact_name'] ?? ('#' . (int) $d['contact_id'])) : '<span class="text-muted">Client-level</span>' ?></td>
                                <td><?= nullable_htmlentities($d['first_seen_at'] ?? '') ?></td>
                                <td><span class="badge <?= $devRevoked ? 'text-bg-secondary' : 'text-bg-success' ?>"><?= $devRevoked ? 'Revoked' : 'Active' ?></span></td>
                                <td class="text-end">
                                    <?php if (!$devRevoked) { ?>
                                        <a class="btn btn-sm btn-outline-danger confirm-link" href="post.php?revoke_panel_device=<?= (int) $d['device_id'] ?>&client_id=<?= $client_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">Revoke</a>
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
