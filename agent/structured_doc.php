<?php

require_once "includes/inc_all_client.php";

enforceUserPermission('module_support');

$doc_id = intval($_GET['id'] ?? 0);

$sql = mysqli_query($mysqli, "SELECT d.*, t.type_name, t.type_icon FROM structured_documents d LEFT JOIN structured_doc_types t ON t.type_id = d.doc_type_id WHERE d.doc_id = $doc_id LIMIT 1");
if (mysqli_num_rows($sql) == 0) {
    echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='#' data-history-back><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";
    require_once "../includes/footer.php";
    exit;
}
$row = mysqli_fetch_assoc($sql);
$doc_client_id = intval($row['doc_client_id']);
enforceClientAccess($doc_client_id);

$doc_name = nullable_htmlentities($row['doc_name']);
$type_id = intval($row['doc_type_id']);
$type_name = nullable_htmlentities($row['type_name']);
$type_icon = nullable_htmlentities($row['type_icon']);

$sql_fields = mysqli_query($mysqli, "SELECT * FROM structured_doc_fields WHERE field_type_id = $type_id AND field_archived_at IS NULL ORDER BY field_order ASC, field_id ASC");
$sql_values = mysqli_query($mysqli, "SELECT * FROM structured_document_values WHERE doc_id = $doc_id");
$values = [];
while ($v = mysqli_fetch_assoc($sql_values)) {
    $values[(int) $v['field_id']] = $v;
}

?>

<div class="alga-theme">

    <ol class="breadcrumb d-print-none">
        <li class="breadcrumb-item">
            <a href="structured_docs.php?client_id=<?= $doc_client_id ?>"><i class="fas fa-fw <?= $type_icon ?> me-1"></i>Structured Documentation</a>
        </li>
        <li class="breadcrumb-item active"><?= $doc_name ?></li>
    </ol>

    <div class="row">
        <div class="col-md-9">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0"><i class="fas fa-fw <?= $type_icon ?> me-2"></i><?= $doc_name ?> <span class="badge text-bg-secondary ms-2"><?= $type_name ?></span></h4>
                </div>
                <div class="card-body">
                    <?php if (mysqli_num_rows($sql_fields) == 0) { ?>
                        <p class="text-secondary mb-0">This document type has no fields defined.</p>
                    <?php } ?>
                    <dl class="row mb-0">
                        <?php while ($f = mysqli_fetch_assoc($sql_fields)) {
                            $field_id = intval($f['field_id']);
                            $kind = $f['field_kind'];
                            $label = nullable_htmlentities($f['field_label']);
                            $v = $values[$field_id] ?? null;
                            if ($kind === 'password') {
                                // A lighter-weight secret than the Credentials vault (no step-up password, no
                                // per-user reveal limit) - appropriate for a "flexible asset" field (a device's
                                // local admin note, a WAN circuit's PPPoE password), not a replacement for the
                                // vault. Still CSRF-protected, permission- and client-access-checked, and
                                // audited the same way every other reveal in this app is.
                                $display = $v && !empty($v['value_enc'])
                                    ? '<span class="js-sdv-masked" data-field-id="' . $field_id . '">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull; <button type="button" class="btn btn-sm btn-outline-secondary ms-2 js-sdv-reveal" data-doc-id="' . $doc_id . '" data-field-id="' . $field_id . '">Reveal</button></span>'
                                    : '<span class="text-muted">&ndash;</span>';
                            } elseif ($kind === 'checkbox') {
                                $display = !empty($v['value_text']) ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-muted"></i>';
                            } elseif ($kind === 'url' && !empty($v['value_text'])) {
                                $url = nullable_htmlentities($v['value_text']);
                                $display = '<a href="' . htmlspecialchars(sanitize_url($v['value_text']), ENT_QUOTES) . '" target="_blank" rel="noopener">' . $url . '</a>';
                            } elseif ($kind === 'textarea') {
                                $display = !empty($v['value_text']) ? nl2br(nullable_htmlentities($v['value_text'])) : '<span class="text-muted">&ndash;</span>';
                            } else {
                                $display = !empty($v['value_text']) ? nullable_htmlentities($v['value_text']) : '<span class="text-muted">&ndash;</span>';
                            }
                        ?>
                            <dt class="col-sm-4"><?= $label ?><?php if ($f['field_required']) { ?> <span class="text-danger">*</span><?php } ?></dt>
                            <dd class="col-sm-8"><?= $display ?></dd>
                        <?php } ?>
                    </dl>
                </div>
                <div class="card-footer">
                    <button type="button" class="btn btn-primary ajax-modal" data-modal-size="lg" data-modal-url="modals/structured_doc/structured_doc_edit.php?id=<?= $doc_id ?>">
                        <i class="fas fa-fw fa-edit me-2"></i>Edit
                    </button>
                    <a class="btn btn-danger confirm-link" href="post.php?delete_structured_doc=<?= $doc_id ?>&client_id=<?= $doc_client_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                        <i class="fas fa-fw fa-trash-alt me-2"></i>Delete
                    </a>
                </div>
            </div>
        </div>
    </div>

</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES) ?>">
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-sdv-reveal');
    if (!btn) { return; }
    var fieldId = btn.dataset.fieldId;
    var docId = btn.dataset.docId;
    fetch('post.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ reveal_structured_doc_field: '1', doc_id: docId, field_id: fieldId, csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>' }),
    }).then(function (r) { return r.json(); }).then(function (j) {
        var wrap = document.querySelector('.js-sdv-masked[data-field-id="' + fieldId + '"]');
        if (!wrap) { return; }
        if (j && j.ok) {
            wrap.textContent = j.value;
        } else {
            wrap.textContent = 'Could not reveal this value.';
        }
    }).catch(function () {});
});
</script>

<?php
require_once "../includes/footer.php";
