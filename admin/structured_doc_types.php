<?php

/*
 * Structured documentation field builder (roadmap #22). An admin defines a document TYPE's own field
 * layout once (e.g. "Firewall": WAN IP, management URL, admin note) and every document of that type then
 * has the same fields to fill in - the Hudu/IT Glue "flexible assets" idea. Deliberately NOT drag-and-drop
 * with a live preview (Hudu's own feature, per the roadmap issue) - a plain "Order" number per field,
 * matching this app's existing ordering convention (category_order, item_order, ...) elsewhere.
 */

require_once "includes/inc_all_admin.php";

$type_id = intval($_GET['type_id'] ?? 0);

if ($type_id > 0) {
    // ---- one type's field list -------------------------------------------------------------------
    $type_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM structured_doc_types WHERE type_id = $type_id LIMIT 1"));
    if (!$type_row) {
        flash_alert('Document type not found.', 'error');
        redirect('structured_doc_types.php');
    }
    $type_name = nullable_htmlentities($type_row['type_name']);
    $sql_fields = mysqli_query($mysqli, "SELECT * FROM structured_doc_fields WHERE field_type_id = $type_id AND field_archived_at IS NULL ORDER BY field_order ASC, field_id ASC");
    ?>
    <div class="card card-dark">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fas fa-fw fa-list me-2"></i>Fields: <?= $type_name ?></h3>
            <div class="card-tools">
                <a href="structured_doc_types.php" class="btn btn-secondary me-1"><i class="fas fa-arrow-left me-2"></i>All Types</a>
                <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/structured_doc_type/structured_doc_field_add.php?type_id=<?= $type_id ?>"><i class="fas fa-plus me-2"></i>New Field</button>
            </div>
        </div>
        <div class="card-body">
            <p class="text-muted">Every document of type "<?= $type_name ?>" will have these fields to fill in. "Order" controls the order fields appear in the add/edit form and on the document page - lower numbers first.</p>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Order</th><th>Label</th><th>Key</th><th>Type</th><th>Required</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                        <?php if (mysqli_num_rows($sql_fields) == 0) { ?>
                            <tr><td colspan="6" class="text-center text-secondary py-4">No fields yet - add one above.</td></tr>
                        <?php } ?>
                        <?php while ($f = mysqli_fetch_assoc($sql_fields)) {
                            $field_id = intval($f['field_id']);
                        ?>
                            <tr>
                                <td><?= intval($f['field_order']) ?></td>
                                <td><?= nullable_htmlentities($f['field_label']) ?></td>
                                <td><code><?= nullable_htmlentities($f['field_key']) ?></code></td>
                                <td><?= nullable_htmlentities(ucfirst($f['field_kind'])) ?></td>
                                <td><?= intval($f['field_required']) ? '<i class="fas fa-check text-success"></i>' : '' ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-secondary ajax-modal" data-modal-url="modals/structured_doc_type/structured_doc_field_edit.php?field_id=<?= $field_id ?>"><i class="fas fa-fw fa-edit"></i></button>
                                    <a class="btn btn-sm btn-danger confirm-link" href="post.php?archive_structured_doc_field=<?= $field_id ?>&type_id=<?= $type_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"><i class="fas fa-fw fa-trash-alt"></i></a>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
    require_once "../includes/footer.php";
    return;
}

// ---- the type list -----------------------------------------------------------------------------
$sql_types = mysqli_query($mysqli, "SELECT t.*, (SELECT COUNT(*) FROM structured_doc_fields f WHERE f.field_type_id = t.type_id AND f.field_archived_at IS NULL) AS field_count, (SELECT COUNT(*) FROM structured_documents d WHERE d.doc_type_id = t.type_id AND d.doc_archived_at IS NULL) AS doc_count FROM structured_doc_types t WHERE t.type_archived_at IS NULL ORDER BY t.type_order ASC, t.type_name ASC");
?>
<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-sitemap me-2"></i>Structured Documentation Types</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/structured_doc_type/structured_doc_type_add.php"><i class="fas fa-plus me-2"></i>New Type</button>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted">A type is a reusable field layout - "Firewall", "Domain Controller", "ISP Circuit" - filled in per document under Documentation on each client's own page.</p>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Type</th><th>Fields</th><th>Documents</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    <?php if (mysqli_num_rows($sql_types) == 0) { ?>
                        <tr><td colspan="4" class="text-center text-secondary py-4">No document types yet - add one above.</td></tr>
                    <?php } ?>
                    <?php while ($t = mysqli_fetch_assoc($sql_types)) {
                        $tid = intval($t['type_id']);
                    ?>
                        <tr>
                            <td><a href="structured_doc_types.php?type_id=<?= $tid ?>"><i class="fas fa-fw <?= nullable_htmlentities($t['type_icon']) ?> me-2"></i><?= nullable_htmlentities($t['type_name']) ?></a></td>
                            <td><?= intval($t['field_count']) ?></td>
                            <td><?= intval($t['doc_count']) ?></td>
                            <td class="text-end">
                                <a href="structured_doc_types.php?type_id=<?= $tid ?>" class="btn btn-sm btn-secondary"><i class="fas fa-fw fa-list"></i> Fields</a>
                                <a class="btn btn-sm btn-danger confirm-link" href="post.php?archive_structured_doc_type=<?= $tid ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"><i class="fas fa-fw fa-trash-alt"></i></a>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
require_once "../includes/footer.php";
