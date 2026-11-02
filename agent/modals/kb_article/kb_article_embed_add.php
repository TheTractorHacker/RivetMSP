<?php
// Roadmap #7 ("KB v2" slice 2): interactive embeds, ported from RivetIT. See
// agent/includes/kb_embed_serve.php for why the pasted HTML is never purified
// and how the sandbox CSP contains it instead.
require_once '../../../includes/modal_header.php';
$kb_article_id = intval($_GET['kb_article_id']);
ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fa fa-fw fa-puzzle-piece me-2"></i>Add Embed</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="kb_article_id" value="<?= $kb_article_id ?>">
    <div class="modal-body">
        <p class="text-secondary small">
            Paste a small, self-contained HTML tool (a calculator, a sizing sheet). It will be served in a
            sandboxed frame with no access to this site's cookies, storage or network - the containment is in
            how it is served, not in any filtering of what you paste.
        </p>
        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="embed_name" maxlength="255" required>
        </div>
        <div class="form-group">
            <label>HTML <strong class="text-danger">*</strong></label>
            <textarea class="form-control" name="embed_html" rows="10" required></textarea>
        </div>
        <div class="form-group">
            <label>Height (px)</label>
            <input type="number" class="form-control" name="embed_height" min="120" max="4000" value="480">
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_kb_article_embed" class="btn btn-primary"><i class="fa fa-check me-2"></i>Add</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php require_once '../../../includes/modal_footer.php'; ?>
