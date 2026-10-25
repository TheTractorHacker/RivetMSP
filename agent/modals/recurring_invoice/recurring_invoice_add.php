<?php

require_once '../../../includes/modal_header.php';

$client_id = intval($_GET['client_id'] ?? 0);

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-redo-alt me-2"></i>New Recurring Invoice</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Scope</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-comment"></i></span>
                </div>
                <input type="text" class="form-control" name="scope" placeholder="Quick description" maxlength="255">
            </div>
        </div>

        <?php if ($client_id) { ?>
            <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">

            <!-- roadmap #24: link to a contract so this recurring invoice pauses automatically if the
                 contract is marked Expired/Cancelled or deleted. Only offered here when the client is
                 already known (opening this modal from that client's own page) - with no client chosen
                 yet, the list could not be scoped to anything; link one after creating it instead. -->
            <div class="form-group">
                <label>Contract <small class="text-secondary">(optional)</small></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-file-contract"></i></span>
                    </div>
                    <select class="form-control select2" name="contract_id">
                        <option value="">- No contract -</option>
                        <?php
                        $sql_contracts = mysqli_query($mysqli, "SELECT contract_id, contract_name, contract_status FROM contracts WHERE contract_client_id = $client_id AND contract_archived_at IS NULL ORDER BY contract_name ASC");
                        while ($c = mysqli_fetch_assoc($sql_contracts)) {
                            $cid = intval($c['contract_id']);
                            $cname = nullable_htmlentities($c['contract_name']);
                            $cstatus = nullable_htmlentities($c['contract_status']);
                        ?>
                            <option value="<?php echo $cid; ?>"><?php echo "$cname ($cstatus)"; ?></option>
                        <?php } ?>
                    </select>
                </div>
                <small class="form-text text-muted">If this contract is later set to Expired or Cancelled (or deleted), this recurring invoice is paused automatically.</small>
            </div>
        <?php } else { ?>

            <div class="form-group">
                <label>Client <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                    </div>
                    <select class="form-control select2" name="client_id" required>
                        <option value="">- Client -</option>
                        <?php
                        //select unarchived clients
                        $sql = mysqli_query($mysqli, "SELECT * FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
                        while ($row = mysqli_fetch_assoc($sql)) {
                            $client_id = intval($row['client_id']);
                            $client_name = nullable_htmlentities($row['client_name']);
                        ?>
                            <option value="<?php echo $client_id; ?>"><?php echo "$client_name"; ?></option>

                        <?php
                        }
                        ?>
                    </select>
                </div>
            </div>

        <?php } ?>

        <div class="form-group">
            <label>Start Date <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-calendar"></i></span>
                </div>
                <input type="date" class="form-control" name="start_date" max="2999-12-31" value="<?php echo date("Y-m-d"); ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label>Frequency <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                </div>
                <select class="form-control select2" name="frequency" required>
                    <option value="">- Frequency -</option>
                    <option value="month">Monthly</option>
                    <option value="year">Yearly</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Category <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <select class="form-control select2" name="category" required>
                    <option value="">- Category -</option>
                    <?php

                    $sql = mysqli_query($mysqli, "SELECT * FROM categories WHERE category_type = 'Income' AND category_archived_at IS NULL ORDER BY category_name ASC");
                    while ($row = mysqli_fetch_assoc($sql)) {
                        $category_id = intval($row['category_id']);
                        $category_name = nullable_htmlentities($row['category_name']);
                    ?>
                        <option value="<?php echo $category_id; ?>"><?php echo $category_name; ?></option>

                    <?php
                    }
                    ?>
                </select>
                <div class="input-group-append">
                    <button class="btn btn-secondary ajax-modal" type="button"
                        data-modal-url="../admin/modals/category/category_add.php?category=Income">
                        <i class="fas fa-fw fa-plus"></i>
                    </button>
                </div>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_recurring_invoice" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
