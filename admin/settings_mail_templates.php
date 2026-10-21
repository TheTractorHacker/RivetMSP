<?php
/*
 * Admin > Settings > Email templates (ported from RivetIT's admin/settings_mail_templates.php).
 * One collapsed card per email event (includes/mail_templates.php): a drag-and-drop block builder, a live preview rendered by the
 * real send path (admin/post/settings_mail_templates.php "preview_mail_template"), Send test email to me, Reset to default; plus
 * the optional shared header/footer. Admin-only: includes/inc_all_admin.php stops anyone whose role is not an admin role.
 */

require_once "includes/inc_all_admin.php";

$mt_events = mailTemplateEvents();
$mt_descriptions = mailTemplateEventDescriptions();
$mt_defaults = mailTemplateDefaults();
$mt_hf = mailTemplateHeaderFooter($mysqli);
$mt_rows = [];
$sql_mt = mysqli_query($mysqli, "SELECT * FROM mail_templates");
while ($sql_mt && $row = mysqli_fetch_assoc($sql_mt)) {
    $mt_rows[$row['mail_template_event']] = $row;
}

?>

<style>
.mt-card-toggle { cursor: pointer; user-select: none; }
.mt-card-caret { transition: transform .15s ease; display: inline-block; }
.mt-card-toggle[aria-expanded="true"] .mt-card-caret { transform: rotate(90deg); }
.mt-add-block-bar { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: .75rem; }
.mt-add-block {
    display: flex; flex-direction: column; align-items: center; gap: .25rem;
    width: 5.5rem; padding: .6rem .25rem; border-radius: .5rem;
    border: 1px dashed var(--color-border, #dee2e6); background: transparent;
    color: var(--tblr-secondary, #6c757d); font-size: .72rem; line-height: 1.1;
    transition: border-color .15s ease, background .15s ease, color .15s ease, transform .1s ease;
}
.mt-add-block i { font-size: 1.05rem; }
.mt-add-block:hover, .mt-add-block:focus-visible { border-style: solid; border-color: var(--color-accent, #206bc4); color: var(--color-accent, #206bc4); transform: translateY(-1px); }

.mt-block-list { background: rgba(0,0,0,.015); border: 1px solid var(--color-border, #dee2e6); border-radius: .6rem; padding: .6rem; min-height: 3.5rem; }
.mt-block-list:empty::after { content: 'No blocks yet - add one below.'; display: block; text-align: center; padding: 1rem; color: var(--tblr-secondary, #6c757d); font-size: .8rem; }
.mt-block { border: 1px solid var(--color-border, #dee2e6); border-left-width: 4px; border-radius: .5rem; box-shadow: 0 1px 2px rgba(0,0,0,.04); transition: box-shadow .15s ease; }
.mt-block:hover { box-shadow: 0 2px 6px rgba(0,0,0,.08); }
.mt-block.sortable-ghost { opacity: .4; }
.mt-block.sortable-chosen { box-shadow: 0 4px 12px rgba(0,0,0,.12); }
.mt-block[data-type="text"] { border-left-color: #206bc4; }
.mt-block[data-type="quote"] { border-left-color: #0ca678; }
.mt-block[data-type="button"] { border-left-color: #2fb344; }
.mt-block[data-type="divider"], .mt-block[data-type="spacer"] { border-left-color: #a5a5a5; }
.mt-block[data-type="html"] { border-left-color: #ae3ec9; }
.mt-block[data-type="reply_marker"] { border-left-color: #f59f00; }
.mt-block .card-header { background: rgba(0,0,0,.015); }
.mt-drag-handle { cursor: grab; }
.mt-drag-handle:active { cursor: grabbing; }
.mt-ph-btn { font-size: .68rem; padding: .1rem .4rem; line-height: 1.3; }

.mt-preview-pane { border: 1px solid var(--color-border, #dee2e6); border-radius: .6rem; overflow: hidden; height: 100%; display: flex; flex-direction: column; background: var(--color-surface, #fff); }
.mt-preview-pane-header { background: rgba(0,0,0,.03); border-bottom: 1px solid var(--color-border, #dee2e6); padding: .5rem .75rem; font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--tblr-secondary, #6c757d); display: flex; align-items: center; gap: .4rem; }
.mt-preview-pane-subject { padding: .5rem .75rem; font-size: .82rem; font-weight: 600; border-bottom: 1px solid var(--color-border, #dee2e6); word-break: break-word; }
.mt-preview-iframe { flex: 1; width: 100%; min-height: 360px; border: 0; background: #fff; }
@media (max-width: 991.98px) { .mt-preview-pane { margin-top: 1rem; } .mt-preview-iframe { min-height: 260px; } }
</style>

<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-envelope-open-text me-2" aria-hidden="true"></i>Email templates</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-0">These are the emails <?= nullable_htmlentities(APP_NAME) ?> sends to a client contact or a technician for a ticket event, and the client portal's password reset emails. Each one ships with the text it has always sent; nothing changes until you edit and save one. Placeholders in curly braces (e.g. <code>{contact_name}</code>) are filled in when the email is sent; an unrecognized one is left blank, never shown as raw text. Every email is sent inside a branded frame (your logo or company name, and your theme colour from Settings &gt; Theme).</p>
    </div>
</div>

<?php foreach ($mt_events as $mt_event => $mt_label) {
    $mt_row = $mt_rows[$mt_event] ?? null;
    $mt_subject = (string) ($mt_row['mail_template_subject'] ?? '') !== '' ? $mt_row['mail_template_subject'] : $mt_defaults[$mt_event]['subject'];
    $mt_body = (string) ($mt_row['mail_template_body'] ?? '') !== '' ? $mt_row['mail_template_body'] : $mt_defaults[$mt_event]['body'];
    $mt_enabled = $mt_row === null || (int) $mt_row['mail_template_enabled'] === 1;
    $mt_customized = $mt_row !== null && (int) $mt_row['mail_template_updated_by'] !== 0;
    $mt_needs_marker = mailTemplateRequiresReplyMarker($mt_event);
    $mt_needs_link = mailTemplateRequiresPortalLink($mt_event);
    $mt_placeholders = mailTemplatePlaceholdersFor($mt_event);
    $mt_id = nullable_htmlentities($mt_event);
    ?>
    <div class="card mb-3 mail-template-card" id="mt-card-<?= $mt_id ?>">
        <div class="card-header d-flex align-items-center justify-content-between mt-card-toggle" role="button" tabindex="0"
             data-bs-toggle="collapse" data-bs-target="#mt-collapse-<?= $mt_id ?>" aria-expanded="false" aria-controls="mt-collapse-<?= $mt_id ?>">
            <div>
                <h3 class="card-title mb-0"><i class="fas fa-chevron-right me-2 mt-card-caret" aria-hidden="true"></i><?= nullable_htmlentities($mt_label) ?></h3>
                <div class="small text-muted fw-normal mt-1"><?= nullable_htmlentities($mt_descriptions[$mt_event] ?? '') ?></div>
            </div>
            <div class="d-flex gap-1 flex-shrink-0 ms-2">
                <?php if ($mt_customized) { ?><span class="badge text-bg-info">Customized</span><?php } ?>
                <span class="badge <?= $mt_enabled ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $mt_enabled ? 'Enabled' : 'Disabled' ?></span>
            </div>
        </div>
        <div class="collapse" id="mt-collapse-<?= $mt_id ?>">
        <div class="card-body">
            <form action="post.php" method="post" class="mail-template-form" data-event="<?= $mt_id ?>"
                  data-needs-marker="<?= $mt_needs_marker ? '1' : '0' ?>" data-needs-link="<?= $mt_needs_link ? '1' : '0' ?>">
                <input type="hidden" name="csrf_token" value="<?= nullable_htmlentities($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="event" value="<?= $mt_id ?>">

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="enabled" id="mt-enabled-<?= $mt_id ?>" value="1" <?= $mt_enabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mt-enabled-<?= $mt_id ?>">Send this email</label>
                </div>

                <div class="form-group mb-3">
                    <label class="small text-muted" for="mt-subject-<?= $mt_id ?>">Subject</label>
                    <input type="text" class="form-control mt-subject-input" id="mt-subject-<?= $mt_id ?>" name="subject" maxlength="<?= MAIL_TEMPLATE_SUBJECT_MAX ?>" value="<?= nullable_htmlentities($mt_subject) ?>" required>
                    <div class="mt-2">
                        <span class="small text-muted me-2">Insert into subject:</span>
                        <?php foreach ($mt_placeholders as $mt_ph) {
                            if (in_array($mt_ph, MAIL_TEMPLATE_RAW_KEYS, true)) { continue; } // HTML values never go into a subject ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary mb-1 mt-ph-btn mt-subject-placeholder-btn" data-placeholder="{<?= nullable_htmlentities($mt_ph) ?>}"><?= nullable_htmlentities($mt_ph) ?></button>
                        <?php } ?>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-7">
                        <label class="small text-muted d-block mb-1"><i class="fas fa-layer-group me-1" aria-hidden="true"></i>Body - drag blocks to reorder</label>
                        <div class="mt-block-list mb-2" id="mt-blocks-<?= $mt_id ?>"
                             data-event="<?= $mt_id ?>"
                             data-placeholders="<?= nullable_htmlentities(json_encode($mt_placeholders)) ?>"></div>

                        <div class="mt-add-block-bar" role="group" aria-label="Add a block">
                            <button type="button" class="mt-add-block" data-type="text"><i class="fas fa-font" aria-hidden="true"></i>Text</button>
                            <button type="button" class="mt-add-block" data-type="quote"><i class="fas fa-quote-left" aria-hidden="true"></i>Quote box</button>
                            <button type="button" class="mt-add-block" data-type="button"><i class="fas fa-square" aria-hidden="true"></i>Button</button>
                            <button type="button" class="mt-add-block" data-type="divider"><i class="fas fa-minus" aria-hidden="true"></i>Divider</button>
                            <button type="button" class="mt-add-block" data-type="spacer"><i class="fas fa-arrows-alt-v" aria-hidden="true"></i>Spacer</button>
                            <button type="button" class="mt-add-block" data-type="reply_marker"><i class="fas fa-reply" aria-hidden="true"></i>Reply marker</button>
                            <button type="button" class="mt-add-block" data-type="html"><i class="fas fa-code" aria-hidden="true"></i>Advanced HTML</button>
                        </div>

                        <textarea class="d-none mt-body-hidden" name="body" id="mt-body-<?= $mt_id ?>" aria-hidden="true"><?= nullable_htmlentities($mt_body) ?></textarea>

                        <div class="small mb-3 mt-warnings" aria-live="polite"></div>

                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" name="save_mail_template" class="btn btn-primary"><i class="fas fa-save me-1" aria-hidden="true"></i>Save</button>
                            <button type="button" class="btn btn-outline-secondary js-mt-test" data-event="<?= $mt_id ?>"><i class="fas fa-paper-plane me-1" aria-hidden="true"></i>Send test email to me</button>
                            <button type="submit" name="reset_mail_template" class="btn btn-outline-danger ms-auto" formnovalidate
                                    data-rivet-confirm="Reset this email to its default text? Your changes to it will be lost." data-confirm-variant="danger" data-confirm-ok="Reset"><i class="fas fa-undo me-1" aria-hidden="true"></i>Reset to default</button>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="mt-preview-pane">
                            <div class="mt-preview-pane-header"><i class="fas fa-eye" aria-hidden="true"></i>Live preview <span class="fw-normal text-lowercase">(sample data)</span></div>
                            <div class="mt-preview-pane-subject js-mt-preview-subject text-muted">&nbsp;</div>
                            <iframe class="mt-preview-iframe js-mt-preview-frame" sandbox="" title="Preview of the <?= nullable_htmlentities($mt_label) ?> email"></iframe>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        </div>
    </div>
<?php } ?>

<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-palette me-2" aria-hidden="true"></i>Shared header and footer</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small">Optional. When set, the header replaces the logo/company name at the top of every email above, and the footer is added at the bottom (a disclaimer, an address). Neither has placeholders.</p>
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= nullable_htmlentities($_SESSION['csrf_token']) ?>">
            <div class="form-group mb-2">
                <label class="small text-muted" for="mt-header-html">Header HTML</label>
                <ul class="nav nav-tabs nav-tabs-sm mb-2 mt-editor-tabs" role="tablist">
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link active small" data-bs-toggle="tab" data-bs-target="#mt-visual-header" data-pane="visual">Visual</button></li>
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link small" data-bs-toggle="tab" data-bs-target="#mt-source-header" data-pane="source" data-for="mt-header-html">HTML source</button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane show active" id="mt-visual-header">
                        <textarea class="form-control tinymceMailTemplate" id="mt-header-html" name="header_html" rows="4"><?= nullable_htmlentities($mt_hf['header']) ?></textarea>
                    </div>
                    <div class="tab-pane" id="mt-source-header">
                        <textarea class="form-control mt-source-view" data-for="mt-header-html" rows="4" style="font-family:monospace;font-size:.8rem;" spellcheck="false" aria-label="Header HTML source"></textarea>
                    </div>
                </div>
            </div>
            <div class="form-group mb-3">
                <label class="small text-muted" for="mt-footer-html">Footer HTML</label>
                <ul class="nav nav-tabs nav-tabs-sm mb-2 mt-editor-tabs" role="tablist">
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link active small" data-bs-toggle="tab" data-bs-target="#mt-visual-footer" data-pane="visual">Visual</button></li>
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link small" data-bs-toggle="tab" data-bs-target="#mt-source-footer" data-pane="source" data-for="mt-footer-html">HTML source</button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane show active" id="mt-visual-footer">
                        <textarea class="form-control tinymceMailTemplate" id="mt-footer-html" name="footer_html" rows="4"><?= nullable_htmlentities($mt_hf['footer']) ?></textarea>
                    </div>
                    <div class="tab-pane" id="mt-source-footer">
                        <textarea class="form-control mt-source-view" data-for="mt-footer-html" rows="4" style="font-family:monospace;font-size:.8rem;" spellcheck="false" aria-label="Footer HTML source"></textarea>
                    </div>
                </div>
            </div>
            <button type="submit" name="save_mail_template_header_footer" class="btn btn-primary"><i class="fas fa-save me-1" aria-hidden="true"></i>Save</button>
        </form>
    </div>
</div>

<script src="/plugins/SortableJS/Sortable.min.js"></script>
<script src="/js/mail_template_blocks.js?v=<?= (int) @filemtime(__DIR__ . '/../js/mail_template_blocks.js') ?>"></script>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
// tinymce.min.js and bootstrap.bundle.min.js load with `defer` (includes/footer.php), so neither is defined while this inline script is
// parsed. Deferred scripts run before DOMContentLoaded fires, so everything below waits for it instead of running immediately.
document.addEventListener('DOMContentLoaded', function () {
var MTB = window.MailTemplateBlocks;   // js/mail_template_blocks.js: the compiler, byte-identical to includes/mail_templates.php
var CSRF = <?= json_encode((string) ($_SESSION['csrf_token'] ?? '')) ?>;

// The shared header/footer editors (two, small, at the bottom of the page).
tinymce.init({
    selector: '.tinymceMailTemplate',
    browser_spellcheck: true, contextmenu: false, resize: true, min_height: 220, max_height: 600,
    promotion: false, branding: false, menubar: false, statusbar: false,
    toolbar: [
        { name: 'styles', items: ['styles'] },
        { name: 'formatting', items: ['bold', 'italic', 'forecolor'] },
        { name: 'link', items: ['link'] },
        { name: 'lists', items: ['bullist', 'numlist'] },
        { name: 'indentation', items: ['outdent', 'indent'] },
        { name: 'undo', items: ['undo', 'redo'] },
    ],
    convert_urls: false,
    plugins: 'link lists fullscreen',
    license_key: 'gpl',
    setup: function (editor) {
        editor.on('change keyup SetContent', function () { editor.save(); });
    },
});

// Visual / HTML source tabs (header/footer only - each template body is the block builder below): switching TO the source tab dumps
// the editor's HTML into the plain textarea; switching back pushes it into the editor.
document.addEventListener('show.bs.tab', function (e) {
    var btn = e.target;
    if (!btn.classList.contains('nav-link') || !btn.closest('.mt-editor-tabs')) { return; }
    var group = btn.closest('.mt-editor-tabs');
    var sourceBtn = group.querySelector('[data-pane="source"]');
    var forId = sourceBtn ? sourceBtn.dataset.for : null;
    if (!forId) { return; }
    var ed = tinymce.get(forId);
    var sourceTa = document.querySelector('.mt-source-view[data-for="' + forId + '"]');
    if (!ed || !sourceTa) { return; }
    if (btn.dataset.pane === 'source') {
        sourceTa.value = ed.getContent();
    } else {
        ed.setContent(sourceTa.value);
        ed.save();
    }
});

// ---------------------------------------------------------------------------------------------------------------------------------
// Block builder: a {placeholder}-aware drag-and-drop editor for one template's body. It writes the serialized body (blocks comment +
// compiled HTML, MTB.serialize) into the form's hidden "body" textarea; Save/Preview/Send test only ever see that ordinary field.
var MT = {
    uid: 0,
    nextId: function (prefix) { return prefix + '-' + (++MT.uid); },

    labelFor: { text: 'Text', quote: 'Quote box', button: 'Button', divider: 'Divider', spacer: 'Spacer', html: 'Advanced HTML', reply_marker: 'Reply marker' },
    iconFor: { text: 'font', quote: 'quote-left', button: 'square', divider: 'minus', spacer: 'arrows-alt-v', html: 'code', reply_marker: 'reply' },

    placeholders: function (container) {
        try { return JSON.parse(container.dataset.placeholders || '[]'); } catch (e) { return []; }
    },

    // A small "Insert: {x} {y} ..." row for one field.
    placeholderRow: function (placeholders, targetId) {
        var row = document.createElement('div');
        row.className = 'mt-1';
        var label = document.createElement('span');
        label.className = 'small text-muted me-1';
        label.textContent = 'Insert:';
        row.appendChild(label);
        placeholders.forEach(function (p) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-sm btn-outline-secondary mt-1 me-1 mt-ph-btn mt-inline-placeholder-btn';
            b.dataset.target = targetId;
            b.dataset.placeholder = '{' + p + '}';
            b.textContent = p;
            row.appendChild(b);
        });
        return row;
    },

    // One block's DOM. The DOM is the state: MT.collect() reads every field back out of it.
    renderBlock: function (data, placeholders) {
        data = (data && typeof data === 'object') ? data : {};
        var type = typeof data.type === 'string' ? data.type : '';
        var el = document.createElement('div');
        el.className = 'mt-block card card-sm mb-2';
        el.dataset.type = type;

        var head = document.createElement('div');
        head.className = 'card-header d-flex align-items-center py-1 px-2';
        var handle = document.createElement('span');
        handle.className = 'mt-drag-handle me-2';
        handle.title = 'Drag to reorder';
        handle.innerHTML = '<i class="fas fa-grip-vertical text-muted" aria-hidden="true"></i>';
        head.appendChild(handle);
        var title = document.createElement('span');
        title.className = 'small fw-bold flex-grow-1';
        title.innerHTML = '<i class="fas fa-' + (MT.iconFor[type] || 'cube') + ' me-1 text-muted" aria-hidden="true"></i>';
        title.appendChild(document.createTextNode(MT.labelFor[type] || (type || 'Block')));
        head.appendChild(title);
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn btn-sm btn-outline-danger mt-ph-btn mt-block-delete';
        del.setAttribute('aria-label', 'Remove this block');
        del.innerHTML = '<i class="fas fa-trash" aria-hidden="true"></i>';
        head.appendChild(del);
        el.appendChild(head);

        var body = document.createElement('div');
        body.className = 'card-body py-2 px-2';

        if (type === 'reply_marker') {
            body.innerHTML = '<span class="small text-muted"><i style="color:#808080;">##- Please type your reply above this line -##</i> - keep this as the first block: a customer\'s reply is cut at this exact line, and anything above it ends up in the ticket.</span>';
        } else if (type === 'divider') {
            body.innerHTML = '<hr class="my-1">';
        } else if (type === 'spacer') {
            var sel = document.createElement('select');
            sel.className = 'form-select form-select-sm mt-block-field';
            sel.dataset.field = 'size';
            sel.setAttribute('aria-label', 'Spacer height');
            var size = String(MTB.normalize(data).size);
            var known = false;
            [['8', 'Small'], ['16', 'Medium'], ['32', 'Large']].forEach(function (o) {
                var opt = document.createElement('option');
                opt.value = o[0]; opt.textContent = o[1];
                if (size === o[0]) { opt.selected = true; known = true; }
                sel.appendChild(opt);
            });
            if (!known) {   // a saved size the menu does not list: keep it rather than silently changing it
                var custom = document.createElement('option');
                custom.value = size; custom.textContent = size + 'px'; custom.selected = true;
                sel.appendChild(custom);
            }
            body.appendChild(sel);
        } else if (type === 'button') {
            var labelInput = document.createElement('input');
            labelInput.type = 'text'; labelInput.className = 'form-control form-control-sm mb-2 mt-block-field'; labelInput.dataset.field = 'label';
            labelInput.placeholder = 'Button text'; labelInput.setAttribute('aria-label', 'Button text');
            labelInput.value = typeof data.label === 'string' && data.label !== '' ? data.label : 'Click here';
            body.appendChild(labelInput);
            var linkSel = document.createElement('select');
            linkSel.className = 'form-select form-select-sm mt-block-field'; linkSel.dataset.field = 'link';
            linkSel.setAttribute('aria-label', 'Button link');
            var linkKnown = false;
            placeholders.filter(function (p) { return /link$/.test(p); }).forEach(function (p) {
                var opt = document.createElement('option');
                opt.value = '{' + p + '}'; opt.textContent = 'Links to: {' + p + '}';
                if (data.link === '{' + p + '}') { opt.selected = true; linkKnown = true; }
                linkSel.appendChild(opt);
            });
            if (!linkKnown && typeof data.link === 'string' && data.link !== '') {
                var keep = document.createElement('option');
                keep.value = data.link; keep.textContent = 'Links to: ' + data.link; keep.selected = true;
                linkSel.appendChild(keep);
            }
            body.appendChild(linkSel);
        } else { // text, quote, html (and an unknown type: shown as editable HTML so nothing is lost)
            var taId = MT.nextId('mt-field');
            var ta = document.createElement('textarea');
            ta.id = taId; ta.className = 'form-control mt-block-field'; ta.dataset.field = 'html';
            ta.rows = type === 'html' ? 6 : 3;
            ta.value = typeof data.html === 'string' ? data.html : '';
            ta.setAttribute('aria-label', (MT.labelFor[type] || 'Block') + ' content');
            // The text this block was loaded with. Until the block itself is edited, collect() returns exactly this (not TinyMCE's
            // re-serialization of it), so opening a template and saving an unrelated change never rewrites its other blocks.
            ta.mtOriginal = ta.value;
            body.appendChild(ta);
            body.appendChild(MT.placeholderRow(placeholders, taId));
            setTimeout(function () { MT.initBlockEditor(taId, type); }, 0);   // the textarea must be in the DOM first
        }

        el.appendChild(body);
        return el;
    },

    initBlockEditor: function (id, type) {
        var toolbar = type === 'html'
            ? [{ name: 'formatting', items: ['bold', 'italic', 'forecolor'] }, { name: 'link', items: ['link'] }, { name: 'lists', items: ['bullist', 'numlist'] }, { name: 'code', items: ['code'] }]
            : [{ name: 'formatting', items: ['bold', 'italic'] }, { name: 'link', items: ['link'] }];
        tinymce.init({
            selector: '#' + id,
            browser_spellcheck: true, contextmenu: false, resize: true,
            min_height: type === 'html' ? 160 : 90, max_height: 400,
            promotion: false, branding: false, menubar: false, statusbar: false,
            toolbar: toolbar, convert_urls: false,   // keep {placeholders} in links exactly as typed
            plugins: type === 'html' ? 'link lists code' : 'link lists',
            license_key: 'gpl',
            setup: function (editor) {
                editor.on('input change undo redo', function () {
                    var ta = editor.getElement();
                    ta.mtDirty = true;
                    editor.save();
                    var container = ta.closest('.mt-block-list');
                    if (container) { MT.sync(container); }
                });
            },
        });
    },

    defaultBlock: function (type, placeholders) {
        var links = placeholders.filter(function (p) { return /link$/.test(p); });
        if (type === 'button') { return { type: 'button', label: 'Click here', link: links.length ? '{' + links[0] + '}' : '' }; }
        if (type === 'spacer') { return { type: 'spacer', size: 16 }; }
        if (type === 'text' || type === 'html' || type === 'quote') { return { type: type, html: '' }; }
        return { type: type };
    },

    render: function (container) {
        var hidden = document.getElementById('mt-body-' + container.dataset.event);
        var placeholders = MT.placeholders(container);
        var blocks = MTB.decompile(hidden ? hidden.value : '');
        container.innerHTML = '';
        blocks.forEach(function (b) { container.appendChild(MT.renderBlock(b, placeholders)); });
        new Sortable(container, { handle: '.mt-drag-handle', animation: 150, onEnd: function () { MT.sync(container); } });
    },

    collect: function (container) {
        return Array.from(container.children).map(function (el) {
            var b = { type: el.dataset.type };
            el.querySelectorAll('.mt-block-field').forEach(function (f) {
                if (f.tagName === 'TEXTAREA') {
                    var ed = tinymce.get(f.id);
                    b[f.dataset.field] = (f.mtDirty && ed) ? ed.getContent() : (f.mtDirty ? f.value : f.mtOriginal);
                } else {
                    b[f.dataset.field] = f.value;
                }
            });
            return b;
        });
    },

    sync: function (container) {
        var hidden = document.getElementById('mt-body-' + container.dataset.event);
        if (!hidden || container.dataset.mtInitialized !== '1') { return; }
        hidden.value = MTB.serialize(MT.collect(container));
        MT.showWarnings(container.closest('form'));
        MT.schedulePreview(container.closest('form'));
    },

    warnings: function (form) {
        var hidden = form.querySelector('.mt-body-hidden');
        return MTB.warnings(hidden ? hidden.value : '', form.dataset.needsMarker === '1', form.dataset.needsLink === '1');
    },
    showWarnings: function (form) {
        var box = form.querySelector('.mt-warnings');
        if (!box) { return; }
        box.innerHTML = '';
        MT.warnings(form).forEach(function (w) {
            var d = document.createElement('div');
            d.className = 'text-warning';
            d.innerHTML = '<i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>';
            d.appendChild(document.createTextNode('Heads up: ' + w + '.'));
            box.appendChild(d);
        });
    },

    // The live preview and "Send test email to me" both post the UNSAVED draft to the same preview_mail_template action, which renders
    // it through the real send path (sample data, real branding/shell). Debounced: 400ms after the last edit.
    previewTimers: {},
    previewSeq: {},
    draftForm: function (form, extra) {
        var event = form.dataset.event;
        var fd = new FormData();
        fd.append('preview_mail_template', '1');
        fd.append('event', event);
        fd.append('subject', form.querySelector('.mt-subject-input').value);
        fd.append('body', document.getElementById('mt-body-' + event).value);
        fd.append('csrf_token', CSRF);
        Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
        return fd;
    },
    schedulePreview: function (form) {
        if (!form) { return; }
        var event = form.dataset.event;
        clearTimeout(MT.previewTimers[event]);
        MT.previewTimers[event] = setTimeout(function () { MT.refreshPreview(form); }, 400);
    },
    refreshPreview: function (form) {
        var event = form.dataset.event;
        var seq = (MT.previewSeq[event] || 0) + 1;
        MT.previewSeq[event] = seq;
        var subjectEl = form.querySelector('.js-mt-preview-subject');
        var frameEl = form.querySelector('.js-mt-preview-frame');
        fetch('post.php', { method: 'POST', body: MT.draftForm(form), credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (MT.previewSeq[event] !== seq || !j.ok) { return; }   // a newer edit's preview is already on its way
                subjectEl.textContent = j.subject;
                subjectEl.classList.remove('text-muted');
                frameEl.srcdoc = j.body;
            })
            .catch(function () { /* the preview is a convenience: stay quiet on a transient network error */ });
    },
};

// Seven templates, each with its own blocks, several TinyMCE instances and a preview request, are slow to build at once: a card's
// builder and preview are created the first time it is opened, not on page load.
document.addEventListener('shown.bs.collapse', function (e) {
    var container = e.target.querySelector('.mt-block-list');
    if (!container || container.dataset.mtInitialized === '1') { return; }
    MT.render(container);
    container.dataset.mtInitialized = '1';
    var form = container.closest('form');
    MT.showWarnings(form);
    MT.schedulePreview(form);
});
// The card headers toggle with the keyboard too.
document.querySelectorAll('.mt-card-toggle').forEach(function (h) {
    h.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); h.click(); }
    });
});

document.addEventListener('input', function (e) {
    var field = e.target.closest('.mt-block-field');
    if (field) {
        if (field.tagName === 'TEXTAREA') { field.mtDirty = true; }
        var container = field.closest('.mt-block-list');
        if (container) { MT.sync(container); }
        return;
    }
    if (e.target.classList.contains('mt-subject-input')) {
        MT.schedulePreview(e.target.closest('form'));
    }
});
document.addEventListener('change', function (e) {
    var field = e.target.closest('.mt-block-field');
    if (field && field.tagName !== 'TEXTAREA') {
        var container = field.closest('.mt-block-list');
        if (container) { MT.sync(container); }
    }
});

document.addEventListener('click', function (e) {
    var del = e.target.closest('.mt-block-delete');
    if (del) {
        var block = del.closest('.mt-block');
        var container = block.closest('.mt-block-list');
        block.querySelectorAll('textarea').forEach(function (t) { var ed = tinymce.get(t.id); if (ed) { ed.remove(); } });
        block.remove();
        MT.sync(container);
        return;
    }
    var add = e.target.closest('.mt-add-block');
    if (add) {
        var container2 = add.closest('.mail-template-card').querySelector('.mt-block-list');
        var placeholders = MT.placeholders(container2);
        var el = MT.renderBlock(MT.defaultBlock(add.dataset.type, placeholders), placeholders);
        container2.appendChild(el);
        el.querySelectorAll('textarea').forEach(function (t) { t.mtDirty = true; });
        MT.sync(container2);
        return;
    }
    var inline = e.target.closest('.mt-inline-placeholder-btn');
    if (inline) {
        var ta = document.getElementById(inline.dataset.target);
        var ed2 = tinymce.get(inline.dataset.target);
        if (ta) { ta.mtDirty = true; }
        if (ed2) { ed2.insertContent(inline.dataset.placeholder); ed2.save(); }
        else if (ta) { ta.value += inline.dataset.placeholder; }
        var container3 = inline.closest('.mt-block-list');
        if (container3) { MT.sync(container3); }
        return;
    }
    var subjBtn = e.target.closest('.mt-subject-placeholder-btn');
    if (subjBtn) {
        var subjInput = subjBtn.closest('.form-group').querySelector('.mt-subject-input');
        if (subjInput) {
            subjInput.value += subjBtn.dataset.placeholder;
            subjInput.focus();
            MT.schedulePreview(subjInput.closest('form'));
        }
        return;
    }
    var test = e.target.closest('.js-mt-test');
    if (test) {
        var form3 = test.closest('form');
        RivetDialog.confirm('Send a test copy of this email, as it is on screen now (with sample data), to your own email address?', { okText: 'Send test' }).then(function (yes) {
            if (!yes) { return; }
            var container4 = form3.querySelector('.mt-block-list');
            if (container4) { MT.sync(container4); }
            fetch('post.php', { method: 'POST', body: MT.draftForm(form3, { send_test: '1' }), credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { RivetDialog.alert(j.ok ? 'Test email queued - check your inbox in a minute.' : (j.error || 'Could not send the test email.')); })
                .catch(function () { RivetDialog.alert('Network error sending the test email.'); });
        });
    }
});

// Save: make sure the hidden body reflects the blocks on screen, then warn - never block - when a reply-capable email lost its reply
// marker or a portal email its {portal_link}. RivetDialog.gate() re-submits the form (with the same Save button) on "Save anyway".
document.querySelectorAll('.mail-template-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        var container = form.querySelector('.mt-block-list');
        if (container) { MT.sync(container); }
        if (!e.submitter || e.submitter.name !== 'save_mail_template') { return; }
        var w = MT.warnings(form);
        if (!w.length) { return; }
        RivetDialog.gate(e, form, 'Before you save: ' + w.join('; and ') + '. Save anyway?', { variant: 'danger', okText: 'Save anyway', title: 'This email may not work as expected' });
    });
});
}); // end DOMContentLoaded
</script>

<?php require_once "../includes/footer.php"; ?>
