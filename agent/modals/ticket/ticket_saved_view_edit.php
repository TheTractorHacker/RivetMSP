<?php

require_once '../../../includes/modal_header.php';
require_once __DIR__ . '/../../includes/ticket_view_filters.php';
require_once __DIR__ . '/../../../includes/icon_picker.php';

$ticket_saved_view_id = intval($_GET['id']);

// Saved views are an agent tool, and a view is only editable by its owner (or, for a shared view, an agent with full support access).
enforceUserPermission('module_support', 2);

$view = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM ticket_saved_views WHERE ticket_saved_view_id = $ticket_saved_view_id AND ticket_saved_view_archived_at IS NULL LIMIT 1"));
$view_owner = intval($view['ticket_saved_view_user_id'] ?? -1);
if (!$view || !($view_owner === intval($session_user_id) || ($view_owner === 0 && lookupUserPermission("module_support") === 3))) {
    exit('That saved view is not available to you.');
}
$default_ids = ticketViewDefaultIds($mysqli, intval($session_user_id));

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-thumbtack me-2"></i>Edit Saved View</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="ticket_saved_view_id" value="<?= $ticket_saved_view_id ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="name" maxlength="100" value="<?= nullable_htmlentities($view['ticket_saved_view_name']) ?>" required autofocus>
        </div>

        <div class="form-group">
            <label>Icon</label>
            <div><?php iconPickerField('icon', (string) $view['ticket_saved_view_icon'], 'fa-filter'); ?></div>
        </div>

        <hr>
        <h6 class="mb-3"><i class="fa fa-fw fa-filter me-2"></i>Filters this view uses</h6>
        <?php ticketViewFilterFields($mysqli, ticketViewParseQuery((string) $view['ticket_saved_view_query']), 'tvfe'); ?>
        <small class="form-text text-muted">Currently: <?= nullable_htmlentities(ticketViewDescribe($mysqli, (string) $view['ticket_saved_view_query'])) ?></small>


        <hr>
        <input type="hidden" name="default_present" value="1">
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" name="make_default" value="1" id="savedViewDefaultE" <?= $default_ids['personal'] === $ticket_saved_view_id ? 'checked' : '' ?>>
            <label class="form-check-label" for="savedViewDefaultE">Make this my default view <small class="text-muted">(Tickets opens with it)</small></label>
        </div>
        <?php if (lookupUserPermission("module_support") === 3 && $view_owner === 0) { ?>
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" name="make_default_shared" value="1" id="savedViewDefaultSharedE" <?= $default_ids['shared'] === $ticket_saved_view_id ? 'checked' : '' ?>>
            <label class="form-check-label" for="savedViewDefaultSharedE">Default for agents without a personal default</label>
        </div>
        <?php } ?>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_ticket_saved_view" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
