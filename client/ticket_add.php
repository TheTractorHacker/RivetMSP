<?php
/*
 * Client Portal
 * New ticket form
 */

require_once 'includes/inc_all.php';

// Allow clients to select a related asset when raising a ticket
$sql_assets = mysqli_query($mysqli, "SELECT asset_id, asset_name, asset_type FROM assets WHERE asset_contact_id = $session_contact_id AND asset_client_id = $session_client_id AND asset_archived_at IS NULL ORDER BY asset_name ASC");

// Opened from Request Something (service_catalog.php): prefill only - post.php re-reads the item itself before
// deciding whether to hold the ticket.
$catalog_item_id = intval($_GET['catalog_item_id'] ?? 0);
$catalog_item = null;
if ($catalog_item_id > 0) {
    $catalog_item = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM service_catalog_items WHERE catalog_item_id = $catalog_item_id AND is_active = 1 LIMIT 1"));
    if (!$catalog_item) {
        $catalog_item_id = 0;
    }
}

?>

    <ol class="breadcrumb d-print-none">
        <li class="breadcrumb-item">
            <a href="index.php">Home</a>
        </li>
        <li class="breadcrumb-item">
            <a href="tickets.php">Tickets</a>
        </li>
        <li class="breadcrumb-item active">New Ticket</li>
    </ol>

    <h3>Raise a new ticket</h3>

    <?php if ($catalog_item && intval($catalog_item['requires_approval'])) { ?>
        <div class="alert alert-warning">This request needs approval - it will be held until a technician releases it.</div>
    <?php } ?>

    <div class="col-md-8">
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <?php if ($catalog_item_id) { ?>
                <input type="hidden" name="catalog_item_id" value="<?= $catalog_item_id ?>">
            <?php } ?>

            <div class="form-group">
                <label>Subject <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                    </div>
                    <input type="text" class="form-control" name="subject" placeholder="Subject" required value="<?= $catalog_item ? nullable_htmlentities($catalog_item['ticket_subject_template']) : '' ?>">
                </div>
            </div>

            <div class="row">
                <div class="col">
                    <div class="form-group">
                        <label>Priority <strong class="text-danger">*</strong></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-thermometer-half"></i></span>
                            </div>
                            <?php $catalog_priority = $catalog_item['default_priority'] ?? null; ?>
                            <select class="form-control select2" name="priority" required>
                                <option <?php if ($catalog_priority === 'Low') { echo 'selected'; } ?>>Low</option>
                                <option <?php if ($catalog_priority === 'Medium' || !$catalog_priority) { echo 'selected'; } ?>>Medium</option>
                                <option <?php if ($catalog_priority === 'High') { echo 'selected'; } ?>>High</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="col">
                    <div class="form-group">
                    <label>Category</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-layer-group"></i></span>
                        </div>
                        <?php $catalog_category_id = intval($catalog_item['ticket_category_id'] ?? 0); ?>
                        <select class="form-control select2" name="category">
                            <option value="0">- No Category -</option>
                            <?php
                            $sql_categories = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL");
                            while ($row = mysqli_fetch_assoc($sql_categories)) {
                                $category_id = intval($row['category_id']);
                                $category_name = nullable_htmlentities($row['category_name']);

                                ?>
                                <option value="<?php echo $category_id; ?>" <?php if ($category_id === $catalog_category_id) { echo 'selected'; } ?>><?php echo $category_name; ?></option>
                            <?php } ?>

                        </select>
                    </div>
                </div>
                </div>
            </div>

            <?php if (mysqli_num_rows($sql_assets) > 0) { ?>
                <div class="form-group">
                    <label>Asset</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-desktop"></i></span>
                        </div>
                        <select class="form-control select2" name="asset">
                            <option value="0">- None -</option>
                            <?php

                            while ($row = mysqli_fetch_assoc($sql_assets)) {
                                $asset_id = intval($row['asset_id']);
                                $asset_name = sanitizeInput($row['asset_name']);
                                $asset_type = sanitizeInput($row['asset_type']);
                                ?>
                                <option value="<?php echo $asset_id ?>"><?php echo "$asset_name ($asset_type)"; ?></option>
                                <?php
                            }
                            ?>
                        </select>
                    </div>
                </div>
            <?php } ?>


            <div class="form-group">
                <label>Details <strong class="text-danger">*</strong></label>
                <textarea class="form-control tinymce" name="details"></textarea>
            </div>

            <button class="btn btn-primary" name="add_ticket">Raise ticket</button>

        </form>
    </div>

<?php
require_once 'includes/footer.php';
