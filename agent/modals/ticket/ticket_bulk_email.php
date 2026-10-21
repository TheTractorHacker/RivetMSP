<?php

require_once '../../../includes/modal_header.php';

$ticket_ids = array_map('intval', (array) ($_GET['ticket_ids'] ?? []));

$count = count($ticket_ids);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-envelope me-2"></i>Email Notifications for <strong><?= $count ?></strong> Tickets</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <?php foreach ($ticket_ids as $ticket_id) { ?><input type="hidden" name="ticket_ids[]" value="<?= $ticket_id ?>"><?php } ?>

    <div class="modal-body">

        <div class="form-group">
            <label>Email notifications</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-envelope"></i></span>
                </div>
                <select class="form-control select2" name="bulk_send_email">
                    <option value="0">Off - send no email for these tickets</option>
                    <option value="1">On - send email as usual</option>
                </select>
            </div>
            <small class="form-text text-muted">Off stops every email about the ticket (new ticket, replies, status changes, assignment, closing, satisfaction surveys) and cancels email already queued for it. Internal notes, in-app notifications and webhooks are not affected. Tickets created from an email always keep email on and are skipped.</small>
        </div>

    </div>

    <div class="modal-footer">
        <button type="submit" name="bulk_set_ticket_send_email" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
