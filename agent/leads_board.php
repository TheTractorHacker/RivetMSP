<?php

require_once "includes/inc_all.php";

enforceUserPermission('module_client');

$stages = ['New', 'Contacted', 'Qualified', 'Proposal', 'Negotiation', 'Lost'];
$stage_icons = ['New' => 'fa-seedling', 'Contacted' => 'fa-phone', 'Qualified' => 'fa-check-double', 'Proposal' => 'fa-file-signature', 'Negotiation' => 'fa-handshake', 'Lost' => 'fa-ban'];

$sql = mysqli_query($mysqli, "SELECT clients.client_id, client_name, client_type, client_lead_source, client_lead_status, client_lead_score, client_created_at, u.user_name AS owner_name
    FROM clients LEFT JOIN users u ON u.user_id = clients.client_lead_owner
    WHERE client_lead = 1 AND client_archived_at IS NULL $access_permission_query
    ORDER BY COALESCE(client_lead_score, 0) DESC, client_created_at DESC");
$cols = array_fill_keys($stages, []);
while ($r = mysqli_fetch_assoc($sql)) {
    $st = $r['client_lead_status'] ?? '';
    if (!in_array($st, $stages, true)) { $st = 'New'; }   // blank or custom stage lands in New so no lead is hidden
    $cols[$st][] = $r;
}
$total = array_sum(array_map('count', $cols));
$open = $total - count($cols['Lost']);
?>
<link rel="stylesheet" href="css/leads_board.css">

<div class="card card-dark">
    <div class="card-header py-2">
        <h1 class="card-title mt-2 h3"><i class="fa fa-fw fa-bullhorn me-2"></i>Lead pipeline</h1>
        <div class="card-tools">
            <a href="clients.php?leads=1" class="btn btn-default"><i class="fa fa-fw fa-list me-2"></i>List view</a>
            <?php if (lookupUserPermission('module_client') >= 2) { ?>
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/client/client_add.php?lead=1"><i class="fa fa-fw fa-plus me-2"></i>Lead</button>
            <?php } ?>
        </div>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-3"><span id="lbOpen"><?php echo $open; ?></span> open lead(s). Drag a card to another stage; drop it on <strong>Convert to client</strong> when the deal is won.</p>
        <div class="lb-board" id="leadsBoard" data-csrf="<?php echo $_SESSION['csrf_token']; ?>">
            <?php foreach ($stages as $st) { ?>
            <section class="lb-col" aria-label="<?php echo $st; ?>">
                <header class="lb-col-head">
                    <span><i class="fa fa-fw <?php echo $stage_icons[$st]; ?> me-1"></i><?php echo $st; ?></span>
                    <span class="badge text-bg-secondary lb-count"><?php echo count($cols[$st]); ?></span>
                </header>
                <div class="lb-list" data-stage="<?php echo $st; ?>">
                    <?php foreach ($cols[$st] as $l) {
                        $score = $l['client_lead_score'] !== null ? intval($l['client_lead_score']) : null; ?>
                    <article class="lb-card" data-client-id="<?php echo intval($l['client_id']); ?>">
                        <a class="lb-name" href="client_overview.php?client_id=<?php echo intval($l['client_id']); ?>"><?php echo nullable_htmlentities($l['client_name']); ?></a>
                        <div class="lb-meta">
                            <?php if ($l['client_lead_source']) { ?><span><i class="fa fa-fw fa-link me-1"></i><?php echo nullable_htmlentities($l['client_lead_source']); ?></span><?php } ?>
                            <?php if ($l['owner_name']) { ?><span><i class="fa fa-fw fa-user-tie me-1"></i><?php echo nullable_htmlentities($l['owner_name']); ?></span><?php } ?>
                            <?php if ($score !== null) { ?><span class="lb-score"><i class="fa fa-fw fa-star me-1"></i><?php echo $score; ?></span><?php } ?>
                        </div>
                    </article>
                    <?php } ?>
                    <p class="lb-empty">Nothing here</p>
                </div>
            </section>
            <?php } ?>
        </div>
        <?php if (lookupUserPermission('module_client') >= 2) { ?>
        <div class="lb-convert" id="leadsConvert" data-stage="Converted">
            <i class="fa fa-fw fa-trophy me-2"></i>Convert to client: drop a won lead here
        </div>
        <?php } ?>
        <div class="visually-hidden" id="lbLive" role="status" aria-live="polite"></div>
    </div>
</div>

<script src="../plugins/SortableJS/Sortable.min.js"></script>
<script src="js/leads_board.js"></script>

<?php require_once "../includes/footer.php";
