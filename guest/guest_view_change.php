<?php

/*
 * Client-facing change approval (GitHub roadmap #5) - token-based, no login required, same shape as
 * guest_view_quote.php/guest_view_ticket.php. Only reachable once an agent has sent the link
 * (agent/post/change.php's send_change_approval_request), and only answerable while the change is still
 * awaiting the client's decision.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/guest/includes/inc_all_guest.php';

if (!isset($_GET['change_id'], $_GET['url_key'])) {
    echo "<div class='card mt-4'><div class='card-body text-center py-5'><h1 class='h3 mb-0'>Oops, something went wrong! Please raise a ticket if you believe this is an error.</h1></div></div>";
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    exit();
}

$url_key = sanitizeInput($_GET['url_key']);
$change_id = intval($_GET['change_id']);

$sql = mysqli_query(
    $mysqli,
    "SELECT changes.*, clients.client_name
     FROM changes
     LEFT JOIN clients ON clients.client_id = changes.client_id
     WHERE changes.change_id = $change_id
     AND changes.url_key = '$url_key'
     AND changes.requires_client_approval = 1
     AND changes.client_id > 0"
);

if (mysqli_num_rows($sql) !== 1) {
    echo "<div class='card mt-4'><div class='card-body text-center py-5'><h1 class='h3 mb-0'>Oops, something went wrong! Please raise a ticket if you believe this is an error.</h1></div></div>";
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    exit();
}

$row = mysqli_fetch_assoc($sql);

$change_title = nullable_htmlentities($row['title']);
$change_reason = nullable_htmlentities($row['reason']);
$change_impact = nullable_htmlentities($row['impact']);
$change_risk = nullable_htmlentities($row['risk']);
$change_scheduled_at = nullable_htmlentities($row['scheduled_at']);
$change_status = $row['status'];
$change_decision = $row['client_decision'];
$client_name = nullable_htmlentities($row['client_name']);

$change_risk_badge = ['low' => 'text-bg-success', 'medium' => 'text-bg-warning', 'high' => 'text-bg-danger'][$row['risk']] ?? 'text-bg-secondary';
$change_decision_badge = ['pending' => 'text-bg-warning', 'approved' => 'text-bg-success', 'rejected' => 'text-bg-danger'][$change_decision] ?? 'text-bg-secondary';

// Answerable only while the client hasn't decided yet and the change itself hasn't already moved past the point
// where a client decision would still matter (cancelled/rolled back/already executed).
$can_decide = $change_decision === 'pending' && !in_array($change_status, ['successful', 'failed', 'rolled_back', 'cancelled'], true);

?>

<div class="card">

    <div class="card-header d-print-none">
        <div class="float-end">
            <a class="btn btn-primary js-print-page" href="#"><i class="fas fa-fw fa-print me-2"></i>Print</a>
        </div>
    </div>

    <div class="card-body">

        <div class="row mb-3">
            <div class="col">
                <h3><?= $change_title ?> <span class="badge <?= $change_risk_badge ?> ms-2"><?= ucfirst($change_risk) ?> Risk</span></h3>
                <p class="text-muted mb-0">For <?= $client_name ?></p>
            </div>
            <div class="col-auto">
                <h5 class="badge <?= $change_decision_badge ?> p-2"><?= ucfirst($change_decision) ?></h5>
            </div>
        </div>

        <?php if ($change_reason) { ?>
            <h6 class="text-muted">Reason</h6>
            <p style="white-space: pre-wrap;"><?= $change_reason ?></p>
        <?php } ?>
        <?php if ($change_impact) { ?>
            <h6 class="text-muted">Impact</h6>
            <p style="white-space: pre-wrap;"><?= $change_impact ?></p>
        <?php } ?>
        <?php if ($change_scheduled_at) { ?>
            <h6 class="text-muted">Scheduled For</h6>
            <p><?= $change_scheduled_at ?></p>
        <?php } ?>

        <hr class="mt-4">

        <?php if ($can_decide) { ?>
            <form method="post" action="guest_post.php" class="d-inline">
                <input type="hidden" name="approve_change" value="<?= $change_id ?>">
                <input type="hidden" name="url_key" value="<?= htmlspecialchars($url_key, ENT_QUOTES) ?>">
                <button type="submit" class="btn btn-success confirm-link">
                    <i class="fas fa-fw fa-thumbs-up me-2"></i>Approve
                </button>
            </form>
            <form method="post" action="guest_post.php" class="d-inline">
                <input type="hidden" name="reject_change" value="<?= $change_id ?>">
                <input type="hidden" name="url_key" value="<?= htmlspecialchars($url_key, ENT_QUOTES) ?>">
                <button type="submit" class="btn btn-danger confirm-link">
                    <i class="fas fa-fw fa-thumbs-down me-2"></i>Reject
                </button>
            </form>
        <?php } elseif ($change_decision !== 'pending') { ?>
            <p class="text-muted mb-0">This change was already <?= nullable_htmlentities($change_decision) ?>. No further action is needed.</p>
        <?php } else { ?>
            <p class="text-muted mb-0">This change is no longer awaiting a decision.</p>
        <?php } ?>

    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
