<?php

require_once "includes/inc_all.php";

use RivetMSP\Mcp\McpWriteActions;
use RivetMSP\Mcp\PendingActions;
use RivetMSP\Mcp\WriteConfig;

// Approving changes tickets, so the page itself needs write access to the service desk.
enforceUserPermission('module_support', 2);

$pa = new PendingActions($mysqli);
$feature_on = WriteConfig::enabled($mysqli);
$self_ok = WriteConfig::selfApprove($mysqli);
$csrf = $_SESSION['csrf_token'];
$status_badge = ['pending' => 'warning', 'approved' => 'info', 'executed' => 'success', 'failed' => 'danger', 'rejected' => 'secondary', 'expired' => 'secondary'];

if (!WriteConfig::tableReady($mysqli)) { ?>
    <div class="alert alert-warning">Run the database update first (Administration &rarr; Updates).</div>
    <?php require_once "../includes/footer.php";
    exit;
}

$pa->expireStale();   // keep the page honest even if the cron job has not run yet
$detail_id = intval($_GET['action_id'] ?? 0);
$detail = $detail_id > 0 ? $pa->getVisible($detail_id, (int) $session_user_id) : null;
?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-user-check me-2"></i>AI actions waiting for approval</h3>
    </div>
    <div class="card-body">
        <?php if (!$feature_on) { ?>
            <div class="alert alert-secondary">MCP write actions are switched off (Administration &rarr; Remote MCP). Existing proposals can be reviewed and rejected but not approved.</div>
        <?php } ?>

<?php if ($detail_id > 0 && $detail === null) { ?>
        <div class="alert alert-danger">Not found.</div>
        <a href="mcp_actions.php" class="btn btn-secondary">Back to the list</a>
<?php } elseif ($detail !== null) {
    $args = json_decode((string) $detail['args_json'], true) ?: [];
    $desc = McpWriteActions::describe($mysqli, (int) $session_user_id, (string) $detail['tool'], $args);
    $is_pending = $detail['status'] === 'pending' && (int) $detail['is_live'] === 1;
    $mine = (int) $detail['requested_by'] === (int) $session_user_id;
?>
        <a href="mcp_actions.php" class="btn btn-sm btn-secondary mb-3"><i class="fas fa-arrow-left me-1"></i>All actions</a>
        <h4>Action #<?= intval($detail['action_id']) ?> <span class="badge text-bg-<?= $status_badge[$detail['status']] ?? 'secondary' ?>"><?= nullable_htmlentities($detail['status']) ?></span></h4>
        <p class="lead"><?= nullable_htmlentities($desc['summary']) ?></p>
        <table class="table table-sm table-borderless">
            <?php foreach ($desc['lines'] as [$label, $value]) { ?>
                <tr><th style="width:10rem" class="text-muted"><?= nullable_htmlentities($label) ?></th><td style="white-space:pre-wrap"><?= nullable_htmlentities($value) ?></td></tr>
            <?php } ?>
            <tr><th class="text-muted">Requested by</th><td><?= nullable_htmlentities($detail['requester_name'] ?? 'Unknown') ?> through an MCP app (<code><?= nullable_htmlentities($detail['tool']) ?></code>)</td></tr>
            <tr><th class="text-muted">Client</th><td><?= nullable_htmlentities($detail['client_name'] ?? '') ?></td></tr>
            <tr><th class="text-muted">Queued</th><td><?= nullable_htmlentities($detail['created_at']) ?></td></tr>
            <tr><th class="text-muted">Expires</th><td><?= nullable_htmlentities($detail['expires_at']) ?></td></tr>
            <?php if ($detail['decided_by']) { ?>
                <tr><th class="text-muted">Decided by</th><td><?= nullable_htmlentities($detail['decider_name'] ?? '') ?> at <?= nullable_htmlentities($detail['decided_at']) ?></td></tr>
            <?php } ?>
            <?php if (!empty($detail['result_message'])) { ?>
                <tr><th class="text-muted">Result</th><td><?= nullable_htmlentities($detail['result_message']) ?></td></tr>
            <?php } ?>
        </table>
        <?php if ($is_pending) { ?>
            <p class="text-muted">Approving makes this change as <strong>you</strong>, with your permissions and client access, and records it in the ticket history and the audit log. Nothing has changed yet.</p>
            <form action="post.php" method="post" class="d-flex flex-wrap gap-2 align-items-start">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action_id" value="<?= intval($detail['action_id']) ?>">
                <?php if ($feature_on && (!$mine || $self_ok)) { ?>
                    <button type="submit" name="approve_mcp_action" class="btn btn-success"><i class="fas fa-check me-2"></i>Approve and apply</button>
                <?php } elseif ($mine) { ?>
                    <span class="text-muted align-self-center">You requested this, so another technician has to approve it.</span>
                <?php } ?>
                <input type="text" name="note" class="form-control" style="max-width:20rem" maxlength="255" placeholder="Reason (optional)">
                <button type="submit" name="reject_mcp_action" class="btn btn-outline-danger"><i class="fas fa-times me-2"></i>Reject</button>
            </form>
        <?php } ?>
<?php } else {
    $filter = in_array($_GET['show'] ?? '', ['pending', 'done', 'all'], true) ? $_GET['show'] : 'pending';
    $rows = $pa->listVisible((int) $session_user_id, $filter);
?>
        <p class="text-muted">An MCP app (such as Claude) can only <em>propose</em> changes. Nothing happens until you approve it here. You see proposals for the clients you have access to.</p>
        <div class="btn-group mb-3">
            <?php foreach (['pending' => 'Waiting', 'done' => 'Decided', 'all' => 'All'] as $k => $label) { ?>
                <a href="mcp_actions.php?show=<?= $k ?>" class="btn btn-sm btn-<?= $filter === $k ? 'primary' : 'outline-secondary' ?>"><?= $label ?></a>
            <?php } ?>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark"><tr><th>#</th><th>What</th><th>Client</th><th>Requested by</th><th>Queued</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r) {
                    $a = json_decode((string) $r['args_json'], true) ?: [];
                    $eff = ($r['status'] === 'pending' && (int) $r['is_live'] !== 1) ? 'expired' : $r['status'];
                ?>
                    <tr>
                        <td><?= intval($r['action_id']) ?></td>
                        <td><?= nullable_htmlentities(str_replace(['rivetmsp_propose_', '_'], ['', ' '], (string) $r['tool'])) ?><?= isset($a['ticket_id']) ? ' &middot; ticket #' . intval($a['ticket_id']) : '' ?></td>
                        <td><?= nullable_htmlentities($r['client_name'] ?? '') ?></td>
                        <td><?= nullable_htmlentities($r['requester_name'] ?? '') ?></td>
                        <td><?= nullable_htmlentities($r['created_at']) ?></td>
                        <td><span class="badge text-bg-<?= $status_badge[$eff] ?? 'secondary' ?>"><?= nullable_htmlentities($eff) ?></span></td>
                        <td><a class="btn btn-xs btn-secondary" href="mcp_actions.php?action_id=<?= intval($r['action_id']) ?>"><i class="fas fa-eye"></i> Review</a></td>
                    </tr>
                <?php } ?>
                <?php if (!$rows) { ?><tr><td colspan="7" class="text-muted text-center">Nothing here.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
<?php } ?>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
