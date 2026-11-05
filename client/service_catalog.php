<?php
/*
 * Client Portal
 * Request Something (GitHub roadmap #6) - a curated menu of tickets a contact can raise in one click. Picking an
 * item opens ticket_add.php?catalog_item_id=N, which reads the item back from the database and prefills the form.
 */

require_once 'includes/inc_all.php';

$sql = mysqli_query(
    $mysqli,
    "SELECT sci.*, c.category_name
     FROM service_catalog_items sci
     LEFT JOIN categories c ON c.category_id = sci.ticket_category_id AND c.category_archived_at IS NULL
     WHERE sci.is_active = 1
     ORDER BY sci.sort_order ASC, sci.name ASC"
);

$catalog_trending = serviceCatalogTrending($mysqli, 5, 30);
$catalog_recent = serviceCatalogRecentForContact($mysqli, intval($session_contact_id), intval($session_client_id), 5);

?>

    <ol class="breadcrumb d-print-none">
        <li class="breadcrumb-item">
            <a href="index.php">Home</a>
        </li>
        <li class="breadcrumb-item active">Request Something</li>
    </ol>

    <h3>What do you need?</h3>
    <p class="text-secondary">Pick a request and we open the ticket for you, with the subject, category and priority already filled in.</p>

    <?php foreach ([['Popular this month', $catalog_trending], ['Recently used by you', $catalog_recent]] as [$shelf_title, $shelf_rows]) { if (!$shelf_rows) { continue; } ?>
        <div class="mb-3">
            <div class="small text-secondary mb-1"><?= $shelf_title ?></div>
            <?php foreach ($shelf_rows as $shelf) { ?>
                <a href="ticket_add.php?catalog_item_id=<?= intval($shelf['catalog_item_id']) ?>" class="badge text-bg-light me-1"><?= nullable_htmlentities($shelf['name']) ?></a>
            <?php } ?>
        </div>
    <?php } ?>

    <?php if (mysqli_num_rows($sql) == 0) { ?>
        <div class="card">
            <div class="card-body text-center text-secondary py-4">
                Nothing is on the request menu yet.
                <div class="mt-3"><a href="ticket_add.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Raise a ticket</a></div>
            </div>
        </div>
    <?php } else { ?>
        <div class="row">
            <?php while ($row = mysqli_fetch_assoc($sql)) {
                $catalog_item_id = intval($row['catalog_item_id']);
                $name = nullable_htmlentities($row['name']);
                $description = nullable_htmlentities($row['description']);
                $icon = nullable_htmlentities($row['icon']) ?: 'fa-ticket-alt';
                $category_name = nullable_htmlentities($row['category_name']);
                $default_priority = in_array($row['default_priority'], ['Low', 'Medium', 'High'], true) ? $row['default_priority'] : '';
            ?>
                <div class="col-md-4 mb-4">
                    <a href="ticket_add.php?catalog_item_id=<?= $catalog_item_id ?>" class="card h-100 text-decoration-none text-reset shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-fw <?= $icon ?> me-2 text-primary"></i><?= $name ?></h5>
                            <?php if ($description) { ?><p class="card-text text-secondary small"><?= $description ?></p><?php } ?>
                        </div>
                        <?php if ($category_name || $default_priority || intval($row['requires_approval'])) { ?>
                            <div class="card-footer">
                                <?php if ($category_name) { ?><span class="badge text-bg-secondary"><?= $category_name ?></span><?php } ?>
                                <?php if ($default_priority) { ?><span class="badge text-bg-light"><?= $default_priority ?> Priority</span><?php } ?>
                                <?php if (intval($row['requires_approval'])) { ?><span class="badge text-bg-warning">Needs approval</span><?php } ?>
                            </div>
                        <?php } ?>
                    </a>
                </div>
            <?php } ?>
            <div class="col-md-4 mb-4">
                <a href="ticket_add.php" class="card h-100 text-decoration-none text-reset shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-fw fa-pen me-2 text-secondary"></i>Something else</h5>
                        <p class="card-text text-secondary small">Don't see it here? Describe the problem or request in your own words.</p>
                    </div>
                </a>
            </div>
        </div>
    <?php } ?>

<?php
require_once 'includes/footer.php';
