<?php

/*
 * Structured documentation (roadmap #22) - documents built from an admin-defined field layout
 * (Administration > Structured Documentation Types), one client at a time. A plain text/rich-text
 * document still belongs on the existing Documents page; this is for the Hudu/IT Glue "flexible asset"
 * kind of record - fixed fields to fill in, not free text.
 */

require_once "includes/inc_all_client.php";

enforceUserPermission('module_support');

$sql_types = mysqli_query($mysqli, "SELECT * FROM structured_doc_types WHERE type_archived_at IS NULL ORDER BY type_order ASC, type_name ASC");
$types = [];
while ($t = mysqli_fetch_assoc($sql_types)) {
    $types[(int) $t['type_id']] = $t;
}

$sql_docs = mysqli_query($mysqli, "SELECT * FROM structured_documents WHERE doc_client_id = $client_id AND doc_archived_at IS NULL ORDER BY doc_favorite DESC, doc_name ASC");
$docs_by_type = [];
while ($d = mysqli_fetch_assoc($sql_docs)) {
    $docs_by_type[(int) $d['doc_type_id']][] = $d;
}

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-sitemap me-2"></i>Structured Documentation</h3>
        <?php if (lookupUserPermission('module_support') >= 2 && !empty($types)) { ?>
            <div class="card-tools">
                <div class="dropdown d-inline-block">
                    <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-plus me-2"></i>New
                    </button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <?php foreach ($types as $t) { ?>
                            <a class="dropdown-item ajax-modal" href="#" data-modal-size="lg" data-modal-url="modals/structured_doc/structured_doc_add.php?client_id=<?= $client_id ?>&type_id=<?= intval($t['type_id']) ?>">
                                <i class="fas fa-fw <?= nullable_htmlentities($t['type_icon']) ?> me-2"></i><?= nullable_htmlentities($t['type_name']) ?>
                            </a>
                        <?php } ?>
                    </div>
                </div>
            </div>
        <?php } ?>
    </div>
    <div class="card-body">
        <?php if (empty($types)) { ?>
            <p class="text-secondary text-center py-4 mb-0">No document types have been set up yet. An administrator can add one under Administration &gt; Structured Documentation Types.</p>
        <?php } elseif (empty($docs_by_type)) { ?>
            <p class="text-secondary text-center py-4 mb-0">No structured documents for this department yet.</p>
        <?php } else { ?>
            <?php foreach ($types as $type_id => $t) {
                if (empty($docs_by_type[$type_id])) {
                    continue;
                }
            ?>
                <h5 class="mt-3 mb-3"><i class="fas fa-fw <?= nullable_htmlentities($t['type_icon']) ?> text-secondary me-2"></i><?= nullable_htmlentities($t['type_name']) ?></h5>
                <div class="row">
                    <?php foreach ($docs_by_type[$type_id] as $d) {
                        $doc_id = intval($d['doc_id']);
                        $doc_name = nullable_htmlentities($d['doc_name']);
                    ?>
                        <div class="col-md-4 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h5 class="card-title mb-0"><a href="structured_doc.php?id=<?= $doc_id ?>&client_id=<?= $client_id ?>"><?= $doc_name ?></a></h5>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            <?php } ?>
        <?php } ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
